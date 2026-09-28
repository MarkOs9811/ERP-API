<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\EstadoPedido;
use App\Models\Mesa;
use App\Models\Plato;
use App\Models\RegistrosCajas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentePosController extends Controller
{
    public function chatear(Request $request)
    {
        set_time_limit(180);

        $systemPrompt = config('agent.prompts.pos');
        if (!$systemPrompt) {
            return response()->json(['respuesta' => 'El agente POS no está configurado.'], 422);
        }

        // REGLA DE TIEMPO (Hora Perú)
        $fechaHoy = now()->setTimezone('America/Lima')->locale('es')->translatedFormat('l, d \d\e F \d\e Y');
        $horaActual = now()->setTimezone('America/Lima')->format('H:i');
        $systemPrompt .= "\n\nREGLA DE TIEMPO: El día de HOY es $fechaHoy y la hora actual es $horaActual. Usa esta fecha exacta para calcular cuando el usuario diga 'hoy', 'mañana', o pida reservas.";

        $user = auth()->user();
        if (!$user) {
            return response()->json(['respuesta' => 'Debes iniciar sesión para usar el agente POS.'], 401);
        }

        $apiKey = ConfiguracionHelper::clave('Groq API', $user->idEmpresa ?? null) ?? env('API_GROQ');
        if (!$apiKey) {
            return response()->json(['respuesta' => 'No está configurada la clave de Groq.'], 500);
        }

        try {
            $tools = $this->obtenerHerramientasPOS();
            $cacheKey = implode('_', [
                'agente_pos_historial',
                $user->idEmpresa ?? 0,
                $user->idSede ?? 0,
                $user->id,
            ]);

            if ($request->boolean('reiniciarConversacion')) {
                Cache::forget($cacheKey);
            }

            $mensajes = array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                Cache::get($cacheKey, []),
                [['role' => 'user', 'content' => (string) $request->input('pregunta', '')]]
            );

            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $modelos = [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'minimaxai/minimax-m2.7',
                'llama-3.3-70b-versatile',
            ];
            $mensajeIA = null;
            $modeloExitoso = null;
            $erroresModelos = [];

            foreach ($modelos as $modelo) {
                $response = Http::withToken($apiKey)->timeout(60)->post($url, [
                    'model' => $modelo,
                    'messages' => $mensajes,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                    'max_tokens' => 2000,
                ]);

                $data = $response->json();
                if (!$response->failed() && !isset($data['error'])) {
                    $mensajeIA = $data['choices'][0]['message'] ?? null;
                    $modeloExitoso = $modelo;
                    if ($mensajeIA) {
                        break;
                    }
                }

                $erroresModelos[] = $modelo . ': ' . ($data['error']['message'] ?? 'HTTP ' . $response->status());
            }

            if (!$mensajeIA) {
                throw new \RuntimeException('Fallaron todos los modelos de Groq: ' . implode(' | ', $erroresModelos));
            }

            for ($iteracion = 0; !empty($mensajeIA['tool_calls']) && $iteracion < 3; $iteracion++) {
                $mensajes[] = $mensajeIA;

                foreach ($mensajeIA['tool_calls'] as $toolCall) {
                    $nombreFuncion = $toolCall['function']['name'] ?? '';
                    $argumentos = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                    $resultado = $this->ejecutarHerramientaPOS($nombreFuncion, $argumentos);

                    $mensajes[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $nombreFuncion,
                        'content' => (string) $resultado,
                    ];
                }

                $response = Http::withToken($apiKey)->timeout(60)->post($url, [
                    'model' => $modeloExitoso,
                    'messages' => $mensajes,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                ]);

                $dataFinal = $response->json();

                // MANEJO DE ERRORES DETALLADO
                if ($response->failed() || isset($dataFinal['error'])) {
                    Log::error('Error GROQ en bucle de herramientas: ' . $response->body());
                    throw new \RuntimeException('Fallo en la API de IA durante la operación de herramientas.');
                }

                $mensajeIA = $dataFinal['choices'][0]['message'] ?? null;
                if (!$mensajeIA) {
                    throw new \RuntimeException('Groq no devolvió una respuesta válida tras usar la herramienta.');
                }
            }

            $respuesta = $mensajeIA['content'] ?? 'La operación fue procesada.';
            $mensajes[] = ['role' => 'assistant', 'content' => $respuesta];
            $historial = array_slice($mensajes, 1);

            // CORTE INTELIGENTE DE CACHÉ
            while (count($historial) > 12) {
                array_shift($historial);
            }
            // REGLA DE ORO: Nunca dejar un mensaje de 'tool' o una petición de 'tool_calls' huérfana al inicio
            while (!empty($historial) && (
                $historial[0]['role'] === 'tool' ||
                !empty($historial[0]['tool_calls'])
            )) {
                array_shift($historial);
            }

            Cache::put($cacheKey, $historial, now()->addMinutes(30));

            return response()->json(['respuesta' => $respuesta]);
        } catch (\Throwable $e) {
            Log::error('Error en Agente POS: ' . $e->getMessage());
            return response()->json([
                'respuesta' => 'No se pudo completar la solicitud. Revisa los logs del servidor.',
            ], 500);
        }
    }

    private function ejecutarHerramientaPOS(string $nombre, array $argumentos): string
    {
        return match ($nombre) {
            'abrirCaja' => $this->abrirCaja($argumentos),
            'reservarMesa' => $this->reservarMesa($argumentos),
            'consultarReservas' => $this->consultarReservas(),
            'cancelarReserva' => $this->cancelarReserva($argumentos),
            'consultarMesasOcupadas' => $this->consultarMesasOcupadas(),
            'consultarPedidosParaLlevar' => $this->consultarPedidosParaLlevar(),
            'reporteCajaAbierta' => $this->reporteCajaAbierta(),
            'transferirPedidoMesa' => $this->transferirPedidoMesa($argumentos),
            'agregarPlatoMesa' => $this->agregarPlatoMesa($argumentos),
            'consultarMesasLibres' => $this->consultarMesasLibres(),
            default => 'ERROR: Esta función no está habilitada para el agente POS.',
        };
    }

    private function abrirCaja(array $argumentos): string
    {
        $registroAbierto = $this->registroCajaAbiertoUsuario();
        if ($registroAbierto && $registroAbierto->caja) {
            return 'ERROR: Ya tienes una caja abierta: ' . $registroAbierto->caja->nombreCaja . '.';
        }

        if (!isset($argumentos['montoApertura']) || !is_numeric($argumentos['montoApertura']) || $argumentos['montoApertura'] < 0) {
            return 'FALTA_DATO: Pregunta al usuario el monto inicial para abrir la caja.';
        }

        $cajas = Caja::where('estado', 1)->where('estadoCaja', 0)->get();
        if (!empty($argumentos['caja']) || !empty($argumentos['nombreCaja'])) {
            $cajaSolicitada = $argumentos['caja'] ?? $argumentos['nombreCaja'];
            $cajas = $cajas->filter(fn($caja) => (string) $caja->id === (string) $cajaSolicitada
                || mb_strtolower($caja->nombreCaja) === mb_strtolower((string) $cajaSolicitada));
        }

        if ($cajas->count() === 0) {
            return 'ERROR: No se encontró una caja activa disponible en tu sede y empresa.';
        }
        if ($cajas->count() > 1) {
            return 'FALTA_DATO: Hay varias cajas activas. Pregunta cuál desea abrir: '
                . $cajas->pluck('nombreCaja')->implode(', ') . '.';
        }

        $response = app(CajaController::class)->storeCajaApertura(Request::create('/api/cajas/storeCajaApertura', 'POST', [
            'caja' => $cajas->first()->id,
            'montoApertura' => $argumentos['montoApertura'],
        ]));

        return json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);
    }

    private function reservarMesa(array $argumentos): string
    {
        $datosObligatorios = [
            'nombreCliente' => 'nombre del cliente',
            'fecha' => 'fecha de reserva',
            'hora' => 'hora de reserva',
        ];
        foreach ($datosObligatorios as $campo => $etiqueta) {
            if (empty($argumentos[$campo])) {
                return 'FALTA_DATO: Pregunta al usuario la ' . $etiqueta . ' antes de registrar la reserva.';
            }
        }

        $mesa = $this->buscarMesa($argumentos['numeroMesa'] ?? null, $argumentos['piso'] ?? null);
        if (is_string($mesa)) {
            return $mesa;
        }
        if (!$mesa) {
            return 'ERROR: No se encontró esa mesa en tu sede.';
        }

        $datos = [
            'idMesa' => $mesa->id,
            'nombre_cliente' => $argumentos['nombreCliente'] ?? null,
            'telefono_cliente' => $argumentos['telefono'] ?? null,
            'fecha_reserva' => $argumentos['fecha'] ?? null,
            'hora_reserva' => $argumentos['hora'] ?? null,
            'cantidad_personas' => $argumentos['cantidadPersonas'] ?? 1,
            'nota' => $argumentos['nota'] ?? null,
        ];

        try {
            $response = app(ReservaMesasController::class)->store(Request::create('/api/vender/reservas', 'POST', $datos));
            return json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return 'ERROR_VALIDACION: ' . implode(' ', $e->errors()['fecha_reserva'] ?? $e->errors()['hora_reserva'] ?? ['Revisa los datos de la reserva.']);
        }
    }

    private function consultarReservas(): string
    {
        try {
            $response = app(ReservaMesasController::class)->index();
            $datos = $response->getData(true);

            if (empty($datos['data'])) {
                return 'No hay reservas pendientes programadas para hoy ni para días futuros.';
            }

            $reservas = collect($datos['data'])->map(fn($reserva) => [
                'id_reserva' => $reserva['id'],
                'cliente' => $reserva['nombre_cliente'],
                'telefono' => $reserva['telefono_cliente'] ?? 'No indicó',
                'fecha' => $reserva['fecha_reserva'],
                'hora' => $reserva['hora_reserva'],
                'mesa_numero' => $reserva['mesa']['numero'] ?? 'Sin asignar',
                'piso' => $reserva['mesa']['piso'] ?? 'N/A',
                'personas' => $reserva['cantidad_personas'],
                'nota' => $reserva['nota'] ?? 'Ninguna'
            ]);

            return 'LISTA DE RESERVAS FUTURAS Y DE HOY: ' . $reservas->toJson(JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return 'ERROR: No se pudieron consultar las reservas en este momento.';
        }
    }

    private function cancelarReserva(array $argumentos): string
    {
        if (empty($argumentos['idReserva'])) {
            return 'FALTA_DATO: Debes indicar el ID de la reserva. Si no lo sabes, usa consultarReservas primero para obtenerlo y vuelve a intentarlo.';
        }

        try {
            $response = app(ReservaMesasController::class)->destroy($argumentos['idReserva']);
            return json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Log::error('Error al cancelar reserva mediante agente: ' . $e->getMessage());
            return 'ERROR: Ocurrió un problema interno y no se pudo cancelar la reserva.';
        }
    }

    private function consultarMesasOcupadas(): string
    {
        $mesas = Mesa::with('preventas.plato')
            ->where('estado', 0)
            ->orderBy('numero')
            ->get()
            ->map(fn($mesa) => [
                'mesa' => $mesa->numero,
                'piso' => $mesa->piso,
                'pedidos' => $mesa->preventas->map(fn($preventa) => [
                    'plato' => $preventa->plato?->nombre,
                    'cantidad' => $preventa->cantidad,
                    'precio' => $preventa->precio,
                ])->values(),
                'total' => $mesa->preventas->sum(fn($preventa) => $preventa->cantidad * $preventa->precio),
                'nota_para_ia' => 'ESTA MESA ESTÁ FÍSICAMENTE OCUPADA. Si no hay pedidos, es porque los clientes recién se sentaron y están revisando la carta.'
            ]);

        return $mesas->isEmpty()
            ? 'No hay mesas ocupadas.'
            : 'ATENCIÓN: Estas mesas SÍ ESTÁN OCUPADAS en el restaurante ahora mismo: ' . $mesas->toJson(JSON_UNESCAPED_UNICODE);
    }

    private function consultarMesasLibres(): string
    {
        $mesas = Mesa::where('estado', 1)
            ->orderBy('piso')
            ->orderBy('numero')
            ->get(['numero', 'piso', 'capacidad']);

        return $mesas->isEmpty()
            ? 'No hay mesas libres en este momento. El restaurante está lleno.'
            : 'MESAS LIBRES DISPONIBLES: ' . $mesas->toJson(JSON_UNESCAPED_UNICODE);
    }

    private function consultarPedidosParaLlevar(): string
    {
        $pedidos = EstadoPedido::where('tipo_pedido', 'llevar')
            ->where('estado', 0)
            ->orderBy('created_at')
            ->get(['id', 'idPedidoLLevar', 'detalle_platos', 'detalle_cliente', 'created_at'])
            ->map(fn($pedido) => [
                'pedido' => $pedido->idPedidoLLevar,
                'cliente' => $pedido->detalle_cliente,
                'productos' => $pedido->detalle_platos,
                'fecha' => $pedido->created_at?->format('d/m/Y H:i'),
            ]);

        return $pedidos->isEmpty() ? 'No hay pedidos para llevar en cola.' : $pedidos->toJson(JSON_UNESCAPED_UNICODE);
    }

    private function reporteCajaAbierta(): string
    {
        $registro = $this->registroCajaAbiertoUsuario();
        if (!$registro || !$registro->caja) {
            return 'ERROR: No tienes una caja abierta para consultar.';
        }

        $response = app(CajaController::class)->getCajaClose(new Request(), $registro->idCaja);
        $datos = $response->getData(true);

        //  AHORA SÍ: Usamos las llaves exactas de tu JSON (camelCase)
        $totalVentas = $datos['totalVenta'] ?? 0;
        $montoInicial = $datos['montoInicial'] ?? 0;
        $totalesPorMetodo = $datos['totalesPorMetodo'] ?? [];

        $resumenCaja = [
            'Fondo_Inicial_Apertura' => floatval($montoInicial),
            'TOTAL_VENTAS_REALIZADAS_HOY' => floatval($totalVentas),
            'Ventas_Desglosadas_Por_Metodo' => $totalesPorMetodo
        ];

        return 'ATENCIÓN IA - REGLA ESTRICTA: El campo "TOTAL_VENTAS_REALIZADAS_HOY" es la cifra absoluta y exacta de todo lo vendido (sumando Yape, Tarjetas, Efectivo, etc). Usa ese número literal para responder cuánto ha vendido el usuario. Aquí tienes el reporte: ' . json_encode($resumenCaja, JSON_UNESCAPED_UNICODE);
    }

    private function transferirPedidoMesa(array $argumentos): string
    {
        $mesaOrigen = $this->buscarMesa($argumentos['numeroMesaOrigen'] ?? null, $argumentos['pisoOrigen'] ?? null);
        if (is_string($mesaOrigen)) {
            return $mesaOrigen;
        }
        $mesaDestino = $this->buscarMesa($argumentos['numeroMesaDestino'] ?? null, $argumentos['pisoDestino'] ?? null);
        if (is_string($mesaDestino)) {
            return $mesaDestino;
        }
        if (!$mesaOrigen || !$mesaDestino) {
            return 'ERROR: No se encontró la mesa de origen o destino.';
        }

        $response = app(VenderController::class)->transferirToMesa(
            $mesaOrigen->id,
            Request::create('/api/vender/transferirToMesa', 'PUT', ['mesaDestino' => $mesaDestino->id])
        );

        return json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);
    }

    private function agregarPlatoMesa(array $argumentos): string
    {
        $mesa = $this->buscarMesa($argumentos['numeroMesa'] ?? null, $argumentos['piso'] ?? null);
        if (is_string($mesa)) {
            return $mesa;
        }
        if (!$mesa || (int) $mesa->estado !== 0) {
            return 'ERROR: La mesa indicada no existe o no está abierta.';
        }

        $registroCaja = $this->registroCajaAbiertoUsuario();
        if (!$registroCaja || !$registroCaja->caja) {
            return 'ERROR: Necesitas tener una caja abierta para agregar platos a una mesa.';
        }

        $nombrePlato = trim((string) ($argumentos['nombrePlato'] ?? ''));
        if ($nombrePlato === '') {
            return 'FALTA_DATO: Indica qué plato agregar.';
        }

        $platos = Plato::where('estado', 1)
            ->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($nombrePlato) . '%'])
            ->limit(6)
            ->get();
        if ($platos->count() !== 1) {
            return $platos->isEmpty()
                ? 'ERROR: No se encontró un plato activo con ese nombre.'
                : 'FALTA_DATO: El nombre coincide con varios platos: ' . $platos->pluck('nombre')->implode(', ') . '.';
        }

        $plato = $platos->first();
        $cantidad = (int) ($argumentos['cantidad'] ?? 1);
        if ($cantidad < 1) {
            return 'ERROR: La cantidad debe ser al menos 1.';
        }

        $response = app(PreventaController::class)->addPlatosPreVentaMesa(Request::create('/api/vender/addPlatosPreVentaMesa', 'POST', [
            'pedidos' => [[
                'idCaja' => $registroCaja->idCaja,
                'idPlato' => $plato->id,
                'idMesa' => $mesa->id,
                'cantidad' => $cantidad,
                'precio' => $plato->precio,
            ]],
            'nota' => $argumentos['nota'] ?? '',
            'imprimirTicket' => false,
        ]));

        return json_encode($response->getData(true), JSON_UNESCAPED_UNICODE);
    }

    private function buscarMesa($numero, $piso = null)
    {
        if ($numero === null || trim((string) $numero) === '') {
            return 'FALTA_DATO: Indica el número de mesa.';
        }

        $consulta = Mesa::where('numero', $numero);
        if ($piso !== null) {
            $consulta->where('piso', $piso);
        }
        $mesas = $consulta->get();

        if ($mesas->count() > 1) {
            return 'FALTA_DATO: Hay varias mesas con ese número. Pregunta el piso: '
                . $mesas->pluck('piso')->unique()->implode(', ') . '.';
        }

        return $mesas->first();
    }

    private function registroCajaAbiertoUsuario(): ?RegistrosCajas
    {
        return RegistrosCajas::with('caja')
            ->where('idUsuario', auth()->id())
            ->whereNull('fechaCierre')
            ->orderByDesc('created_at')
            ->first();
    }

    private function obtenerHerramientasPOS(): array
    {
        return [
            $this->definirHerramienta('abrirCaja', 'Abre una caja disponible para el usuario autenticado. Si no indica monto, primero pregúntalo. Si hay varias cajas activas, pregunta cuál desea abrir.', [
                'montoApertura' => ['type' => 'number', 'description' => 'Monto inicial de apertura.'],
                'caja' => ['type' => 'string', 'description' => 'ID o nombre de caja, solo si el usuario la especificó.'],
            ], []),

            $this->definirHerramienta('reservarMesa', 'Registra una reserva futura. REGLA ESTRICTA: NUNCA asumas ni digas que no hay mesas libres para días futuros. Si el usuario te pide una reserva pero NO te da la fecha, hora, nombre del cliente o el número de mesa, DEBES preguntarle amablemente los datos que faltan ANTES de intentar usar esta herramienta.', [
                'numeroMesa' => ['type' => 'integer', 'description' => 'Número de la mesa a reservar. Si el usuario no lo dice, pregúntale cuál desea.'],
                'piso' => ['type' => 'integer'],
                'nombreCliente' => ['type' => 'string'],
                'telefono' => ['type' => 'string'],
                'fecha' => ['type' => 'string', 'description' => 'Fecha YYYY-MM-DD.'],
                'hora' => ['type' => 'string', 'description' => 'Hora de reserva en formato HH:MM.'],
                'cantidadPersonas' => ['type' => 'integer'],
                'nota' => ['type' => 'string'],
            ], ['numeroMesa', 'nombreCliente', 'fecha', 'hora']),

            $this->definirHerramienta(
                'consultarReservas',
                'Consulta la lista de todas las reservas de mesas pendientes programadas desde el día de hoy en adelante. Provee los IDs de las reservas.',
                [],
                []
            ),

            $this->definirHerramienta(
                'cancelarReserva',
                'Cancela o elimina una reserva. FLUJO ESTRICTO: 1) Si no conoces el ID exacto de la reserva, DEBES usar "consultarReservas" primero para buscarlo. 2) Una vez tengas el ID, pregunta al usuario si está seguro de cancelar indicando nombre y fecha. 3) Si el usuario responde "sí", ejecuta esta herramienta usando el ID.',
                [
                    'idReserva' => ['type' => 'integer', 'description' => 'El ID exacto de la reserva a cancelar.']
                ],
                ['idReserva']
            ),

            $this->definirHerramienta('consultarMesasOcupadas', 'Consulta las mesas ocupadas y los platos/cantidades que tienen.', [], []),
            $this->definirHerramienta('consultarPedidosParaLlevar', 'Consulta los pedidos para llevar que continúan en cola de cocina.', [], []),
            $this->definirHerramienta(
                'reporteCajaAbierta',
                'Obtiene el reporte financiero actual de la caja abierta del usuario. Úsalo para responder cuánto se ha vendido, el fondo inicial o desgloses de ingresos. IMPORTANTE: Lee cuidadosamente todos los métodos de pago (Yape, Tarjeta, etc.) para no dar montos erróneos.',
                [],
                []
            ),
            $this->definirHerramienta('transferirPedidoMesa', 'Transfiere todos los pedidos de una mesa a otra usando el método existente. Identifica ambas mesas por número.', [
                'numeroMesaOrigen' => ['type' => 'integer'],
                'pisoOrigen' => ['type' => 'integer'],
                'numeroMesaDestino' => ['type' => 'integer'],
                'pisoDestino' => ['type' => 'integer'],
            ], ['numeroMesaOrigen', 'numeroMesaDestino']),
            $this->definirHerramienta('agregarPlatoMesa', 'Agrega un plato existente a una mesa ya abierta usando el flujo de preventa y cocina.', [
                'numeroMesa' => ['type' => 'integer'],
                'piso' => ['type' => 'integer'],
                'nombrePlato' => ['type' => 'string'],
                'cantidad' => ['type' => 'integer'],
                'nota' => ['type' => 'string'],
            ], ['numeroMesa', 'nombrePlato', 'cantidad']),

            $this->definirHerramienta(
                'consultarMesasLibres',
                'Consulta qué mesas exactas están libres, desocupadas y disponibles en el restaurante, incluyendo su capacidad y piso.',
                [],
                []
            ),
        ];
    }

    private function definirHerramienta(string $nombre, string $descripcion, array $propiedades, array $requeridos): array
    {
        $parametros = [
            'type' => 'object',
            'properties' => empty($propiedades) ? new \stdClass() : $propiedades,
        ];

        if (!empty($requeridos)) {
            $parametros['required'] = $requeridos;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $nombre,
                'description' => $descripcion,
                'parameters' => $parametros,
            ],
        ];
    }
}
