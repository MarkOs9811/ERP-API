<?php

namespace App\Http\Controllers\api;

use App\Events\PedidoCreadoEvent;
use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Configuraciones;
use App\Models\detallePedidosWeb;
use App\Models\MiEmpresa;
use App\Models\PedidosChat;
use App\Models\PedidosWeb;
use App\Models\PedidosWebRegistro;
use App\Models\Plato;
use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Twilio\Rest\Client;
use Illuminate\Support\Str;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class WhatsAppController extends Controller
{


    private $twilioClient;
    private $twilioNumber;
    private $openAIService;
    private $idEmpresa;
    private $idSede;
    private $estadosPedido = [
        0 => 'Seleccionando Sede', // ✅ Nuevo estado
        1 => 'Pendiente - Sin confirmar pedido',
        2 => 'Pendiente - Sin confirmar pago',
        33 => 'Pendiente de pago',
        3 => 'Pendiente - Verificación de pago',
        4 => 'En preparación',
        5 => 'Listo para recoger',
        6 => 'Entregado y pagado',
        7 => 'Cancelado',
        8 => 'Esperando cantidad',
    ];

    /**
     * ⚡ SISTEMA DE BLOQUEO OPTIMIZADO
     * ────────────────────────────────────────────
     * 🔒 Se implementó un sistema de bloqueo por cliente (lock) para evitar:
     * 
     * ❌ Problema original: Si un cliente escribe múltiples mensajes rápidamente
     *    antes de que el bot termine de responder, se ejecutaban en paralelo
     *    causando:
     *    • Cambios de estado confusos
     *    • Duplicación de respuestas
     *    • Inconsistencia de datos
     * 
     * ✅ Solución implementada:
     * • Lock por cliente durante el procesamiento (clave: whatsapp_processing_NUMERO)
     * • Tiempo máximo de espera: 60 segundos
     * • Duración del lock: 30 segundos (evita bloqueos indefinidos)
     * • Si hay lock: El cliente recibe mensaje "⏳ Aún estamos procesando..."
     * • El lock se libera automáticamente al completar o en caso de error
     * 
     * ⚙️ Funcionamiento:
     * 1. Cliente envía mensaje → Verifica si hay lock
     * 2. Si hay lock → Espera 100ms y reintenta (max 60 segundos)
     * 3. Si persiste el lock → Responde "por favor espera"
     * 4. Si no hay lock → Crea lock (30s) y procesa el mensaje
     * 5. Al terminar → Libera el lock inmediatamente
     * 6. En caso de error → Libera lock en catch
     * 
     * 📊 Flujo de estados MANTIENE TODO IGUAL (números sin cambios):
     * 1, 2, 3, 33, 8, 9, 10, 11
     * La optimización es TRANSPARENTE y no interfiere con la lógica de negocio.
     * 
     * 🔧 Nota técnica:
     * Usa Cache Driver (recomendado Redis en producción)
     * Si no hay Redis: Funciona con driver:file pero más lento
     */

    public function __construct()
    {
        $this->openAIService = new OpenAIService();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function manejarMensaje(Request $request)
    {
        // [LOG 1] Inicio
        // Log::info("📡 --- NUEVO MENSAJE ---");

        $numero_cliente = $request->input('From');
        $numero_receptor = $request->input('To'); // El número de la empresa
        $mensaje = trim($request->input('Body'));

        // 1. CAPTURA DE COORDENADAS
        $latitud = $request->input('Latitude');
        $longitud = $request->input('Longitude');

        // [LOG 2] Validación Inteligente de datos vacíos
        if (empty($numero_cliente) || (empty($mensaje) && empty($latitud))) {
            Log::error("❌ ERROR: Datos incompletos (Ni texto ni ubicación).");
            return response()->json(['status' => 'error', 'message' => 'Datos incompletos'], 400);
        }

        // =================================================================================
        // 🔍 1. IDENTIFICACIÓN DE EMPRESA (MULTITENANT)
        // =================================================================================
        // Buscamos la configuración de Twilio donde valor3 coincida con el número receptor
        $config = Configuraciones::where('nombre', 'Twilio')
            ->where('valor3', $numero_receptor)
            ->first();

        if (!$config) {
            Log::error("❌ No se encontró empresa para el número receptor: " . $numero_receptor);
            return response()->json(['status' => 'error', 'message' => 'Configuración no encontrada'], 404);
        }

        // ⚙️ CONFIGURACIÓN DINÁMICA DE LA INSTANCIA
        // Usamos trim() para evitar errores 401 por espacios en blanco
        $this->idEmpresa = $config->idEmpresa;
        $this->twilioNumber = trim($config->valor3);
        $sid = trim($config->valor1);
        $token = trim($config->valor2);

        // Inicializamos el cliente Twilio para ESTA petición
        $this->twilioClient = new Client($sid, $token);

        // =================================================================================
        // 🔒 2. SISTEMA DE BLOQUEO (LOCK)
        // =================================================================================
        $lockKey = 'whatsapp_processing_' . $numero_cliente;
        $maxWaitTime = 60;
        $waited = 0;

        while (Cache::has($lockKey) && $waited < $maxWaitTime) {
            usleep(100000);
            $waited += 0.1;
        }

        if (Cache::has($lockKey)) {
            Log::warning("⏳ Cliente $numero_cliente envió mensaje mientras se procesaba el anterior");
            $this->enviarMensajeWhatsApp($numero_cliente, "⏳ Aún estamos procesando tu anterior mensaje. Por favor espera un momento...");
            return response()->json(['status' => 'processing']);
        }

        Cache::put($lockKey, true, now()->addSeconds(30));

        try {
            $estadoTwilio = ConfiguracionHelper::estado("twilio");
            if ($estadoTwilio === 0) {
                $this->enviarMensajeWhatsApp($numero_cliente, "🙋‍♂️ Nuestro servicio no está disponible por ahora. ⏳");
                Cache::forget($lockKey);
                return response()->json(['status' => 'disabled']);
            }

            // Validación: Cajas cerradas (De la empresa actual)
            $cajas = Caja::where('idEmpresa', $this->idEmpresa)->get();
            if (!$cajas->contains('estadoCaja', 1)) {
                $this->enviarMensajeWhatsApp($numero_cliente, "🕒 Estamos cerrados. Horario: 6:00 PM - 11:00 PM. 🍴");
                Cache::forget($lockKey);
                return response()->json(['status' => 'closed']);
            }

            // =================================================================================
            // 🔍 3. BUSCAR PEDIDO ACTIVO
            // =================================================================================
            $pedido = PedidosWebRegistro::where('numero_cliente', $numero_cliente)
                ->where('idEmpresa', $this->idEmpresa) // IMPORTANTE: Filtrar por empresa
                ->where('estado', 1)
                ->where('estado_pedido', '!=', 6)
                ->latest()
                ->first();

            // Si NO hay pedido, iniciamos flujo nuevo
            if (!$pedido) {
                Cache::forget($lockKey); // Liberar lock antes de iniciar
                return $this->iniciarPedido($numero_cliente, $this->idEmpresa);
            }

            // =================================================================================
            // 🚀 4. VÁLVULA DE ESCAPE (FIX "ZOMBIE")
            // =================================================================================
            $mensajeLimpio = strtolower($mensaje);

            // Si el usuario escribe hola/menu, reiniciamos forzosamente
            if (in_array($mensajeLimpio, ['hola', 'menu', 'inicio', 'empezar', 'pedir'])) {
                $pedido->update(['estado_pedido' => 6]); // Cancelamos el anterior
                Cache::forget($lockKey);
                return $this->iniciarPedido($numero_cliente, $this->idEmpresa);
            }

            $estadoActual = $pedido->estado_pedido;
            // Asignamos idSede a la instancia si ya existe en el pedido
            if ($pedido->idSede) {
                $this->idSede = $pedido->idSede;
            }

            // =================================================================================
            // 🎮 5. HANDLERS DE ESTADO
            // =================================================================================
            $handlers = [

                // [ESTADO 0] SELECCIÓN DE SEDE (CORREGIDO)
                0 => function () use ($pedido, $mensajeLimpio) {
                    // Buscamos las sedes disponibles
                    $sedesConCaja = Sede::where('idEmpresa', $pedido->idEmpresa)
                        ->where('estado', 1)
                        ->whereHas('cajas', function ($q) use ($pedido) {
                            $q->where('estadoCaja', 1)->where('idEmpresa', $pedido->idEmpresa);
                        })->get(); // (El orderBy ya no es lo crítico, pero no estorba)

                    $opcion = intval($mensajeLimpio);

                    if ($opcion > 0 && $opcion <= $sedesConCaja->count()) {
                        $sedeSeleccionada = $sedesConCaja[$opcion - 1];

                        // 1. ACTUALIZAMOS EL PEDIDO EN BD
                        $pedido->update([
                            'idSede' => $sedeSeleccionada->id, // ¡Aquí guardamos el ID correcto (ej: 5)!
                            'estado_pedido' => 1
                        ]);

                        // 2. ACTUALIZAMOS LA INSTANCIA ACTUAL (¡CRUCIAL!)
                        // Esto asegura que cualquier llamada posterior use la sede 5, no null ni 1.
                        $this->idSede = $sedeSeleccionada->id;

                        // 3. LIMPIAMOS CACHÉ DE PLATOS
                        // Para obligar a recargar el menú de la NUEVA sede seleccionada
                        Cache::forget('platos_menu_' . $this->idEmpresa . '_' . $this->idSede);

                        return $this->enviarMenu($pedido->numero_cliente);
                    } else {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Opción inválida. Responde con el número de la sede.");
                        return true;
                    }
                },

                // [ESTADO 1] CONFIRMACIÓN INICIAL
                1 => function () use ($pedido, $mensaje, $mensajeLimpio) {
                    $comando = $this->detectarComando($mensaje);

                    if ($comando === 'corregir') {
                        $pedido->update(['pedido_temporal' => null, 'estado_pedido' => 1]);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "✏️ Pedido reiniciado. Escribe tu pedido nuevamente:");
                        return true;
                    }

                    if ($comando === 'confirmar') {
                        $pedido->update(['estado_pedido' => 9]);
                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "🛵 **¿Cómo deseas recibir tu pedido?** 🥡\n\n1️⃣ Delivery (Te lo llevamos)\n2️⃣ Recojo en tienda\n\nResponde con el número."
                        );
                        return true;
                    }

                    if ($comando === 'cancelar') {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "❌ Pedido cancelado.");
                        $pedido->delete();
                        return true;
                    }

                    // Procesamiento NLP 
                    if ($this->esMensajeNLP($mensaje)) {
                        $this->procesarSeleccionPlatoNLP($pedido, $mensaje);
                    }
                    return true;
                },

                // [ESTADO 9] SELECCIÓN DELIVERY / RECOJO
                9 => function () use ($pedido, $mensajeLimpio) {
                    if ($mensajeLimpio === '1' || str_contains($mensajeLimpio, 'delivery')) {
                        $pedido->update(['estado_pedido' => 10, 'tipo_entrega' => 'delivery']);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "📝 Por favor **escribe tu Nombre y Apellido**:");
                    } elseif ($mensajeLimpio === '2' || str_contains($mensajeLimpio, 'recojo')) {
                        $pedido->update(['estado_pedido' => 10, 'tipo_entrega' => 'recojo']);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "📝 Por favor **escribe tu Nombre y Apellido**:");
                    } else {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Responde **1** para Delivery o **2** para Recojo.");
                    }
                    return true;
                },

                // [ESTADO 10] GUARDAR NOMBRE
                10 => function () use ($pedido, $mensaje) {
                    $validacion = $this->validarNombre($mensaje);
                    if (!$validacion['valido']) {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacion['error']);
                        return true;
                    }

                    $pedido->update(['nombre_cliente' => $validacion['nombre']]);
                    $pedido->refresh();

                    if ($pedido->tipo_entrega === 'delivery') {
                        $pedido->update(['estado_pedido' => 11]);
                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "📍 Hola " . $validacion['nombre'] . ", ahora envía tu ubicación.\n\n📎 Presiona el **clip (adjuntar)** en WhatsApp ➡️ Ubicación ➡️ **Enviar mi ubicación actual**."
                        );
                    } else {
                        $pedido->update(['estado_pedido' => 2]);
                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "💳 *MÉTODO DE PAGO* 💳\n\n2️⃣ Pagar en caja al recoger\n\nResponde con la opción 2."
                        );
                    }
                    return true;
                },

                // [ESTADO 11] GUARDAR UBICACIÓN
                11 => function () use ($pedido, $request) {
                    $lat = $request->input('Latitude');
                    $lon = $request->input('Longitude');

                    $validacionUbicacion = $this->validarUbicacion($lat, $lon);
                    if (!$validacionUbicacion['valido']) {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacionUbicacion['error']);
                        return true;
                    }

                    $pedido->update([
                        'latitud' => $validacionUbicacion['lat'],
                        'longitud' => $validacionUbicacion['lon'],
                        'estado_pedido' => 2
                    ]);
                    $pedido->refresh();

                    $menuPago = "✅ Ubicación recibida.\n\n💳 **MÉTODO DE PAGO** 💳\n\n2️⃣ PAGO CONTRAENTREGA (Efectivo/Yape al recibir)\n\nResponde con la opción 2.";
                    $this->enviarMensajeWhatsApp($pedido->numero_cliente, $menuPago);
                    return true;
                },

                // [ESTADO 2] SELECCIÓN DE PAGO
                2 => function () use ($pedido, $mensaje) {
                    return $this->seleccionarMetodoPago($pedido, $mensaje);
                },

                // RESTO DE ESTADOS
                8 => fn() => $this->procesarCantidadPlato($pedido, $mensaje),
                3 => function () use ($pedido) {
                    $estadoPago = ($pedido->estado_pago === 'pagado') ? "✅ Pago confirmado" : "⏳ Pago pendiente";
                    $this->enviarMensajeWhatsApp($pedido->numero_cliente, "💰 Estado: *$estadoPago*");
                    return true;
                },
                33 => fn() => $this->procesarComprobantePago($pedido, $request->all()),
            ];

            if (isset($handlers[$estadoActual])) {
                $handlers[$estadoActual]();
                Cache::forget($lockKey);
                return response()->json(['status' => 'success']);
            }

            $this->enviarMensajeWhatsApp($numero_cliente, "No entendí. Escribe *Hola* para empezar.");
            Cache::forget($lockKey);
        } catch (\Exception $e) {
            Log::error("💥 ERROR: " . $e->getMessage());
            Cache::forget($lockKey);
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    private function esMensajeNLP($mensaje): bool
    {
        return !is_numeric(trim($mensaje)) &&
            !in_array(strtolower(trim($mensaje)), ['continuar', 'hola']);
    }

    /**
     * ⚡ FUZZY MATCHING - Detecta comandos con variaciones de escritura
     * Ejemplos: "confirma", "confirmación", "cofirmar" → reconoce como "confirmar"
     */
    private function detectarComando($texto): ?string
    {
        $texto = strtolower(trim($texto));
        $comandos = [
            'confirmar' => ['confirmar', 'confirma', 'confirmación', 'confimar', 'cofirmar', 'confirmr', 'confirmo'],
            'corregir' => ['corregir', 'corrige', 'corrección', 'correguir', 'coregir', 'corregira', 'corrigir'],
            'cancelar' => ['cancelar', 'cancela', 'cancelación', 'canclar', 'canceló', 'canselar', 'cancelacion'],
        ];

        foreach ($comandos as $comando => $variaciones) {
            if (in_array($texto, $variaciones)) return $comando;
            foreach ($variaciones as $var) {
                $distancia = levenshtein($texto, $var);
                if ($distancia <= 2 && strlen($var) >= 4) return $comando;
            }
        }
        return null;
    }

    /**
     * ⚡ CACHÉS - Optimización para múltiples usuarios
     */
    private function obtenerCajasConCache()
    {
        return Cache::remember('cajas_estado_' . $this->idEmpresa, now()->addMinutes(5), function () {
            return Caja::where('idEmpresa', $this->idEmpresa)->get();
        });
    }

    private function obtenerPlatosConCache()
    {
        // El caché ahora depende ESTRICTAMENTE de la sede
        $cacheKey = 'platos_menu_' . $this->idEmpresa . '_' . $this->idSede;

        return Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Plato::where('idEmpresa', $this->idEmpresa);

            // Si hay sede definida (que DEBERÍA haber), filtramos por ella
            if ($this->idSede) {
                $query->where('idSede', $this->idSede);
            }

            // Log para depurar qué platos está trayendo
            $platos = $query->get(['id', 'nombre', 'precio'])->toArray();
            Log::info("Menú cargado para Sede {$this->idSede}: " . count($platos) . " platos.");

            return $platos;
        });
    }

    /**
     * ⚡ VALIDACIONES DE ENTRADA
     */
    private function validarNombre($nombre): array
    {
        $nombre = trim($nombre);

        if (empty($nombre)) {
            return ['valido' => false, 'error' => '⚠️ El nombre no puede estar vacío.'];
        }

        if (strlen($nombre) > 100) {
            return ['valido' => false, 'error' => '⚠️ El nombre es demasiado largo (máx 100 caracteres).'];
        }

        if (strlen($nombre) < 3) {
            return ['valido' => false, 'error' => '⚠️ El nombre es demasiado corto (mín 3 caracteres).'];
        }

        // Permitir letras, espacios, guiones y apóstrofes
        if (!preg_match("/^[a-záéíóúñ\s\-']+$/i", $nombre)) {
            return ['valido' => false, 'error' => '⚠️ El nombre contiene caracteres no permitidos. Solo letras, espacios y guiones.'];
        }

        return ['valido' => true, 'nombre' => $nombre];
    }

    private function validarCantidad($cantidad): array
    {
        $cantidad = trim($cantidad);

        if (!ctype_digit($cantidad)) {
            return ['valido' => false, 'error' => '⚠️ La cantidad debe ser un número.'];
        }

        $cantidad = (int)$cantidad;

        if ($cantidad < 1) {
            return ['valido' => false, 'error' => '⚠️ La cantidad debe ser mayor a 0.'];
        }

        if ($cantidad > 999) {
            return ['valido' => false, 'error' => '⚠️ La cantidad no puede exceder 999 unidades.'];
        }

        return ['valido' => true, 'cantidad' => $cantidad];
    }

    private function validarUbicacion($lat, $lon): array
    {
        if (empty($lat) || empty($lon)) {
            return ['valido' => false, 'error' => '⚠️ No recibí la ubicación. Por favor usa el clip 📎 y selecciona "Ubicación".'];
        }

        $lat = floatval($lat);
        $lon = floatval($lon);

        // Rangos válidos para latitud y longitud
        if ($lat < -90 || $lat > 90) {
            return ['valido' => false, 'error' => '⚠️ Ubicación inválida. Por favor intenta de nuevo.'];
        }

        if ($lon < -180 || $lon > 180) {
            return ['valido' => false, 'error' => '⚠️ Ubicación inválida. Por favor intenta de nuevo.'];
        }

        return ['valido' => true, 'lat' => $lat, 'lon' => $lon];
    }
    /**
     * INICIAR PEDIDO (SIEMPRE PREGUNTAR SEDE)
     * Modificado: Ahora siempre fuerza la selección de sede, aunque solo haya una.
     */
    private function iniciarPedido($numero_cliente, $idEmpresa)
    {
        // 1. Buscar sedes de esta empresa con caja abierta
        // 🚨 CORRECCIÓN CLAVE: Agregamos orderBy('id', 'asc') para garantizar 
        // que el orden de la lista visual coincida con el orden interno del array.
        $sedes = Sede::where('idEmpresa', $idEmpresa)
            ->where('estado', 1)
            ->whereHas('cajas', function ($q) use ($idEmpresa) {
                $q->where('estadoCaja', 1)->where('idEmpresa', $idEmpresa);
            })
            ->orderBy('id', 'asc') // <--- ESTO EVITA QUE SE CRUCEN LAS SEDES
            ->get();

        if ($sedes->isEmpty()) {
            $this->enviarMensajeWhatsApp($numero_cliente, "🕒 Lo sentimos, no hay sedes con atención disponible en este momento.");
            return response()->json(['status' => 'no_available_sedes']);
        }

        // 2. Crear el pedido siempre en Estado 0 (Selección de Sede)
        // Forzamos al usuario a elegir, incluso si solo hay una sede.
        $codigoPedido = 'PED-' . Str::upper(Str::random(6));

        PedidosWebRegistro::create([
            'codigo_pedido' => $codigoPedido,
            'numero_cliente' => $numero_cliente,
            'idEmpresa' => $idEmpresa,
            'idSede' => null, // Se queda null hasta que el cliente responda "1" o "2"
            'estado_pedido' => 0, // Estado 0: Seleccionando Sede
            'estado_pago' => 'por pagar',
            'estado' => 1
        ]);

        // 3. Generar lista de sedes visual
        $mensajeSedes = "¡Hola! Bienvenido 🔥\nPor favor selecciona la sede para tu pedido:\n\n";

        foreach ($sedes as $index => $sede) {
            // El usuario verá 1, 2, 3...
            // Gracias al orderBy, el índice 0 siempre será la sede con ID menor, etc.
            $mensajeSedes .= ($index + 1) . "️⃣ *" . $sede->nombre . "*\n";
        }

        $mensajeSedes .= "\nResponde con el número de la sede.";

        $this->enviarMensajeWhatsApp($numero_cliente, $mensajeSedes);

        return response()->json(['status' => 'success']);
    }

    private function enviarMenu($numero_cliente)
    {
        try {
            $imagenes = [
                'storage/miEmpresa/CARTAS.jpg',
                'storage/miEmpresa/CARTABURGUER.jpg',
            ];

            // foreach ($imagenes as $imgPath) {
            //     $urlOptimizada = $this->optimizarImagenV3($imgPath);
            //     $this->enviarMensajeWhatsApp($numero_cliente, '', $urlOptimizada);
            // }

            $mensaje = <<<EOT
            ¡Hola! Bienvenido a *FIRE WOK* 🔥🍔

            Te compartimos nuestra carta en imágenes para que elijas lo que deseas pedir:
            Por favor, revisa las imágenes y responde escribiendo el nombre del plato y la cantidad que deseas 😊
            EOT;

            $this->enviarMensajeWhatsApp($numero_cliente, $mensaje);
        } catch (\Throwable $e) {
            Log::error("Error al enviar menú a $numero_cliente: {$e->getMessage()}");
        }
    }



    function eliminarPedidos($pedido)
    {
        $pedidosGuardados = detallePedidosWeb::where('idPedido', $pedido->id)->delete();
    }

    private function procesarSeleccionPlatoNLP($pedido, $mensaje)
    {
        $pedido->refresh();
        // 1. Extraer lo que el cliente escribió con OpenAI
        $pedidosExtraidos = $this->openAIService->extraerPlatosYCantidades(
            $mensaje,
            $pedido->idEmpresa,
            $pedido->idSede // <--- AQUÍ ESTÁ LA CLAVE
        );
        $this->idSede = $pedido->idSede;
        $platosMenu = $this->obtenerPlatosConCache();

        $platosEncontrados = [];
        $platosNoEncontrados = [];

        foreach ($pedidosExtraidos as $item) {
            $nombreOriginal = trim($item['nombre']);
            $nombreBuscadoNorm = $this->normalizarTexto($nombreOriginal);

            $mejorCoincidencia = null;
            $mejorPorcentaje = 0;

            foreach ($platosMenu as $plato) {
                $nombreMenuNorm = $this->normalizarTexto($plato['nombre']);

                // 1. Cálculo de Similitud (Texto parecido)
                similar_text($nombreBuscadoNorm, $nombreMenuNorm, $porcentajeSimilitud);

                // 2. Cálculo Levenshtein (Errores de dedo)
                $distancia = levenshtein($nombreBuscadoNorm, $nombreMenuNorm);
                $longitudPromedio = (strlen($nombreBuscadoNorm) + strlen($nombreMenuNorm)) / 2;
                $porcentajeLevenshtein = ($longitudPromedio > 0) ? (1 - $distancia / $longitudPromedio) * 100 : 0;

                // 3. 🚀 LÓGICA DE INCLUSIÓN (El truco para "Chaufa" -> "Chaufa de Pollo")
                // Si lo que pide el cliente ("chaufa") está DENTRO del nombre del menú ("chaufa de pollo")
                $bonoInclusion = 0;
                if (str_contains($nombreMenuNorm, $nombreBuscadoNorm) && strlen($nombreBuscadoNorm) > 3) {
                    $bonoInclusion = 20; // Le regalamos 20 puntos por ser parte del nombre
                }

                $puntajeTotal = (($porcentajeSimilitud + $porcentajeLevenshtein) / 2) + $bonoInclusion;

                if ($puntajeTotal > $mejorPorcentaje) {
                    $mejorPorcentaje = $puntajeTotal;
                    $mejorCoincidencia = $plato;
                }
            }

            // 🎯 NUEVO UMBRAL MÁS FLEXIBLE
            // Antes 75, ahora 60 para capturar más ventas.
            // O si tiene bono de inclusión, es casi seguro que es ese.
            if ($mejorPorcentaje >= 60) {
                $platosEncontrados[] = [
                    'id' => $mejorCoincidencia['id'],
                    'nombre' => $mejorCoincidencia['nombre'], // Usamos el nombre REAL de la carta
                    'cantidad' => $item['cantidad'],
                    'precio' => $mejorCoincidencia['precio'],
                    'subtotal' => $item['cantidad'] * $mejorCoincidencia['precio'],
                    'nombre_solicitado' => $nombreOriginal
                ];
            } else {
                // Solo si realmente no se parece a nada (ej: "zapatillas"), va a error.
                $platosNoEncontrados[] = $nombreOriginal;
            }
        }

        // Bloque de Avisos (Solo para lo que DE VERDAD no existe)
        if (!empty($platosNoEncontrados)) {
            $mensajeAviso = "❌ *Nota:* No contamos con este plato:\n";
            foreach ($platosNoEncontrados as $plato) {
                $mensajeAviso .= "• \"$plato\"\n";
                $sugerencias = $this->buscarSugerencias($plato, $platosMenu);
                if (!empty($sugerencias)) {
                    $mensajeAviso .= "  _Quizás quisiste decir: " . implode(", ", $sugerencias) . "_\n";
                }
            }
            $mensajeAviso .= "\nTalves puedas pedir otros.";
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $mensajeAviso);
        }

        // Generar Resumen
        if (!empty($platosEncontrados)) {
            $this->generarResumenPedido($pedido, $platosEncontrados);
        } else if (empty($platosNoEncontrados)) {
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, "No pude entender tu pedido. Intenta escribir el nombre del plato.");
        }
    }
    private function normalizarTexto($texto)
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', ' de ', ' con '],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n', ' ', ' '],
            $texto
        );
        return preg_replace('/[^a-z0-9 ]/', '', $texto);
    }
    private function buscarSugerencias($platoBuscado, $platosMenu)
    {
        $sugerencias = [];
        $platoNormalizado = $this->normalizarTexto($platoBuscado); // ✅ Uso de método clase

        foreach ($platosMenu as $plato) {
            $nombreNormalizado = $this->normalizarTexto($plato['nombre']);
            similar_text($platoNormalizado, $nombreNormalizado, $porcentaje);

            if ($porcentaje > 60) {
                $sugerencias[] = $plato['nombre'];
                if (count($sugerencias) >= 3) break;
            }
        }
        return $sugerencias;
    }

    private function generarResumenPedido($pedido, $platosEncontrados)
    {
        $resumen = "📋 *CONFIRMA TU PEDIDO* 📋\n";
        $total = 0;

        foreach ($platosEncontrados as $item) {
            $resumen .= "➡️ {$item['cantidad']} x {$item['nombre']} - S/ " . number_format($item['subtotal'], 2) . "\n";
            if ($item['nombre'] !== $item['nombre_solicitado']) {
                $resumen .= "   (Pediste: \"{$item['nombre_solicitado']}\")\n";
            }
            $total += $item['subtotal'];
        }

        $resumen .= "\n💰 *Total: S/ " . number_format($total, 2) . "*";
        $resumen .= "\n\nEscribe *Confirmar* ✅ para proceder, *Corregir* para modificar tu pedido. \n  O escriba *Cancelar* para eliminar el pedido";

        $pedido->update([
            'estado_pedido' => 1,
            'pedido_temporal' => json_encode($platosEncontrados)
        ]);

        $this->enviarMensajeWhatsApp($pedido->numero_cliente, $resumen);
    }

    private function procesarCantidadPlato($pedido, $mensaje)
    {
        $validacion = $this->validarCantidad($mensaje);
        if (!$validacion['valido']) {
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacion['error']);
            return;
        }

        $cantidad = $validacion['cantidad'];

        try {
            $detalle = detallePedidosWeb::where('idPedido', $pedido->id)->latest()->first();

            if ($detalle) {
                $detalle->update([
                    'cantidad' => $cantidad,
                    'precio' => $detalle->plato->precio * $cantidad
                ]);

                $pedido->update(['estado_pedido' => 1]);

                $this->enviarMensajeWhatsApp(
                    $pedido->numero_cliente,
                    "✅ Actualizado: {$cantidad} x {$detalle->plato->nombre}\n" .
                        "Subtotal: S/ " . number_format($detalle->plato->precio * $cantidad, 2) . "\n\n" .
                        "¿Deseas agregar otro plato? (Escribe el número) o escribe *Continuar*"
                );
            }
        } catch (\Exception $e) {
            Log::error("Error al actualizar cantidad: " . $e->getMessage());
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Error al actualizar tu pedido.");
        }
    }

    private function seleccionarMetodoPago($pedido, $mensaje)
    {
        if ($pedido->tipo_entrega === 'delivery' && $mensaje !== '2') {
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Para delivery solo está disponible CONTRAENTREGA.\n\nResponde *2*");
            return;
        } elseif ($pedido->tipo_entrega === 'recojo' && $mensaje !== '2') {
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Para recojo solo está disponible PAGO EN CAJA.\n\nResponde *2*");
            return;
        }

        $conteoDetalles = detallePedidosWeb::where('idPedido', $pedido->id)->count();

        if ($conteoDetalles == 0 && !empty($pedido->pedido_temporal)) {
            $items = json_decode($pedido->pedido_temporal, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    detallePedidosWeb::create([
                        'idPedido' => $pedido->id,
                        'idPlato'  => $item['id'],
                        'cantidad' => $item['cantidad'],
                        'precio'   => $item['precio'],
                        'subtotal' => $item['cantidad'] * $item['precio'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                Log::info("✅ Platos migrados de JSON a Tabla Detalles.");
            }
        }

        if ($mensaje === '1') {
            // ... (Tu lógica de YAPE original si la activas en el futuro)
        } elseif ($mensaje === '2') {
            $detalles = detallePedidosWeb::with('plato')->where('idPedido', $pedido->id)->get();
            $resumenPlatos = "";
            $totalPagar = 0;

            foreach ($detalles as $detalle) {
                $subtotal = $detalle->cantidad * $detalle->plato->precio;
                $resumenPlatos .= "🍽️ {$detalle->plato->nombre}\n";
                $resumenPlatos .= "   Cant: {$detalle->cantidad} x S/ {$detalle->plato->precio} = S/ {$subtotal}\n\n";
                $totalPagar += $subtotal;
            }

            $pedido->update(['estado_pedido' => 3, 'estado_pago' => 'por pagar']);

            if ($pedido->tipo_entrega === 'delivery') {
                $titulo = "🛵 PAGO CONTRAENTREGA";
                $instruccion1 = "Esperar en la ubicación enviada";
                $instruccion3 = "Pagas al recibir el pedido";
            } else {
                $titulo = "💰 PAGO EN CAJA";
                $instruccion1 = "Presenta este código al recoger: *{$pedido->codigo_pedido}*";
                $instruccion3 = "Pagas al momento de recoger";
            }

            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "{$titulo} - RESUMEN 📄\n" .
                    "Te enviaremos una notificación cuando salga tu pedido.\n" .
                    "══════════════════════════\n" .
                    "📋 *Pedido #{$pedido->codigo_pedido}*\n" .
                    "🕒 Fecha: " . now()->format('d/m/Y H:i') . "\n" .
                    "══════════════════════════\n\n" .
                    "📦 *Tu pedido:*\n" .
                    $resumenPlatos .
                    "══════════════════════════\n" .
                    "💰 *Total a pagar:* S/ {$totalPagar}\n" .
                    "══════════════════════════\n\n" .
                    "📌 *Instrucciones:*\n" .
                    "1. {$instruccion1}\n" .
                    "2. Horario: 9am - 10pm\n" .
                    "3. {$instruccion3}\n\n" .
                    "¡Gracias por tu compra! 🔥🍔"
            );

            Event::dispatch(new PedidoCreadoEvent($pedido->codigo_pedido, $pedido->numero_cliente, $pedido->estado_pago));
        } else {
            // Reenvío de opciones
            if ($pedido->tipo_entrega === 'delivery') {
                $this->enviarMensajeWhatsApp($pedido->numero_cliente, "🔷 *MÉTODO DE PAGO* 🔷\n\n2️⃣ *PAGO CONTRAENTREGA*\nResponde *2*");
            } else {
                $this->enviarMensajeWhatsApp($pedido->numero_cliente, "🔷 *MÉTODO DE PAGO* 🔷\n\n2️⃣ *PAGAR EN CAJA*\nResponde *2*");
            }
        }
    }

    private function procesarComprobantePago($pedido, $requestData)
    {
        Log::info("Iniciando procesamiento de comprobante para pedido #" . $pedido->codigo_pedido);

        if (isset($requestData['NumMedia']) && $requestData['NumMedia'] > 0) {
            try {
                $mediaUrl = $requestData['MediaUrl0'];
                $mediaContentType = $requestData['MediaContentType0'];

                if (strpos($mediaContentType, 'image/') === 0) {
                    $sid = ConfiguracionHelper::valor1('twilio');
                    $token = ConfiguracionHelper::valor2('twilio');

                    // Descargar la imagen
                    $imageContent = file_get_contents(
                        $mediaUrl,
                        false,
                        stream_context_create([
                            'http' => [
                                'header' => "Authorization: Basic " .
                                    base64_encode($sid . ':' . $token)
                            ]
                        ])
                    );

                    // Procesamiento OCR
                    // Procesamiento OCR
                    $tempImagePath = tempnam(sys_get_temp_dir(), 'comprobante_') . '.jpg';
                    file_put_contents($tempImagePath, $imageContent);

                    try {
                        $exePath = ConfiguracionHelper::valor1('tesseract');
                        $tessdataDir = ConfiguracionHelper::valor2('tesseract');
                        $lang = ConfiguracionHelper::valor3('tesseract');

                        $ocr = new \thiagoalessio\TesseractOCR\TesseractOCR($tempImagePath);
                        $ocr->executable($exePath)
                            ->lang($lang)
                            ->tessdataDir($tessdataDir);

                        $textoOCR = $ocr->run();
                        Log::info("TEXTO RECONOCIDO EN COMPROBANTE:", ['texto_extraido' => $textoOCR]);

                        // 1. Validar que haya texto
                        if (empty($textoOCR) || !preg_match('/[0-9]/', $textoOCR)) {
                            throw new \Exception("No se pudo leer el comprobante. Asegúrate que la imagen sea clara y muestre el monto y código de transacción.");
                        }

                        // 🔍 Obtener total de los detalles del pedido
                        $detalles = detallePedidosWeb::with('plato')->where('idPedido', $pedido->id)->get();
                        $total = 0;
                        foreach ($detalles as $detalle) {
                            $total += $detalle->cantidad * $detalle->plato->precio;
                        }

                        // 🔍 Ajustar formato del total (tanto con uno como dos decimales)
                        $totalFormat1 = number_format($total, 1); // Ej: 16.0
                        $totalFormat2 = number_format($total, 2); // Ej: 16.00

                        // 🔍 Obtener los últimos 3 dígitos del número de la empresa (Yape)
                        $numeroEmpresa = DB::table('mi_empresas')->value('numero');
                        $ultimosTres = substr($numeroEmpresa, -3);

                        // Normalizar OCR (por si viene con saltos de línea o símbolos)
                        $textoOCR = strtolower(str_replace(["\n", "\r", " ", "\t"], "", $textoOCR));

                        // 🔒 Validaciones
                        $validMonto = preg_match('/' . preg_quote($totalFormat1, '/') . '(?!\d)/', $textoOCR) ||
                            preg_match('/' . preg_quote($totalFormat2, '/') . '(?!\d)/', $textoOCR);
                        $validCodigo = strpos($textoOCR, strtolower($pedido->codigo_pedido)) !== false;
                        $validTelefono = strpos($textoOCR, $ultimosTres) !== false;

                        if (!$validMonto || !$validTelefono) {
                            Log::warning("Validación OCR fallida:", [
                                'validMonto' => $validMonto,
                                'validCodigo' => $validCodigo,
                                'validTelefono' => $validTelefono,
                                'texto_ocr' => $textoOCR
                            ]);

                            $mensajeError = "❌ El comprobante no es válido. Asegúrate que la imagen muestre claramente:\n\n";
                            if (!$validMonto) $mensajeError .= "• El monto total: *S/ $totalFormat2*\n";
                            if (!$validTelefono) $mensajeError .= "• Los últimos 3 dígitos del número de Yape: *$ultimosTres*\n";
                            $mensajeError .= "\nPor favor vuelve a enviar una imagen clara del comprobante.";

                            throw new \Exception($mensajeError);
                        }

                        // Si el código no es válido o no está presente, se pasa por alto
                        if (!$validCodigo) {
                            Log::info("Código de pedido no encontrado o no válido. Pasando por alto validación del código.");
                            // Mensaje al cliente indicando que el código es aceptado aunque no esté en la imagen
                            $mensajeError = "¡Tu pago fue recibido correctamente! El código de pedido no fue encontrado en la imagen, pero hemos validado el monto y el teléfono. Tu código de pedido es: {$pedido->codigo_pedido}. Gracias por tu pago.";
                            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $mensajeError);
                        } else {
                            // Si todo es correcto, proceso normal
                            Log::info("Comprobante aprobado: Monto, código y teléfono validados.");
                            // Aquí puedes agregar el código para finalizar el proceso de validación y aprobar el comprobante.
                            $mensajeError = "¡Pago aprobado! Código de pedido: {$pedido->codigo_pedido}.";
                            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $mensajeError);
                        }
                    } catch (\Exception $e) {
                        Log::error("Error al procesar OCR: " . $e->getMessage());
                        throw new \Exception($e->getMessage());
                    } finally {
                        if (file_exists($tempImagePath)) {
                            unlink($tempImagePath);
                        }
                    }

                    // ============= AQUÍ CONTINÚA TODO TU CÓDIGO ORIGINAL =============
                    // Asegurar que el directorio existe
                    $directory = 'public/fotosComprobante';
                    if (!Storage::exists($directory)) {
                        Storage::makeDirectory($directory);
                        Log::info("Directorio creado: " . $directory);
                    }

                    // Guardar la imagen y obtener ruta relativa
                    $fileName = 'comprobante_' . $pedido->codigo_pedido . '_' . time() . '.jpg';
                    $path = $directory . '/' . $fileName;
                    $dbPath = 'fotosComprobante/' . $fileName;

                    Log::debug("Rutas generadas:", [
                        'path_completo' => $path,
                        'db_path' => $dbPath
                    ]);

                    // Guardar el archivo
                    $saveResult = Storage::put($path, $imageContent);
                    Log::info("Resultado de guardar archivo: " . ($saveResult ? 'Éxito' : 'Fallo'));

                    // Verificar que el archivo existe físicamente
                    $fileExists = Storage::exists($path);
                    Log::info("Verificación de archivo guardado: " . ($fileExists ? 'Existe' : 'No existe'));

                    if ($fileExists) {
                        Log::info("Preparando datos para actualización:", [
                            'fotoComprobante' => $dbPath,
                            'estado_pedido' => 3,
                            'estado_pago' => 'pagado'
                        ]);

                        // Actualizar pedido
                        $updateData = [
                            'fotoComprobante' => $dbPath,
                            'estado_pedido' => 3,
                            'estado_pago' => 'pagado',
                            'updated_at' => now()
                        ];

                        // Verificar si el campo es fillable
                        Log::debug("Campos fillable del modelo:", $pedido->getFillable());

                        $updateResult = $pedido->update($updateData);
                        Log::info("Resultado de actualización: " . ($updateResult ? 'Éxito' : 'Fallo'));

                        // Verificar cambios específicos
                        Log::debug("Cambios realizados:", $pedido->getChanges());

                        if ($pedido->wasChanged()) {
                            Log::info("Pedido actualizado correctamente. Cambios:", $pedido->getChanges());
                            $freshPedido = $pedido->fresh();
                            Log::debug("Datos actualizados en BD:", [
                                'fotoComprobante' => $freshPedido->fotoComprobante,
                                'estado_pedido' => $freshPedido->estado_pedido,
                                'estado_pago' => $freshPedido->estado_pago
                            ]);
                        } else {
                            Log::error("No se realizaron cambios en el pedido");
                            Log::debug("Datos actuales del pedido:", $pedido->toArray());
                        }

                        // BUSCAR PEDIDOS
                        $detalles = detallePedidosWeb::with('plato')
                            ->where('idPedido', $pedido->id)
                            ->get();

                        // Construir resumen de platos
                        $resumenPlatos = "";
                        $total = 0;

                        foreach ($detalles as $detalle) {
                            $subtotal = $detalle->cantidad * $detalle->plato->precio;
                            $total += $subtotal;
                            $resumenPlatos .= "🍽️ *{$detalle->plato->nombre}*\n";
                            $resumenPlatos .= "   Cantidad: {$detalle->cantidad} x S/ " . number_format($detalle->plato->precio, 2) . " = S/ " . number_format($subtotal, 2) . "\n\n";
                        }

                        // Formatear el total
                        $totalFormateado = number_format($total, 2);

                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "✅ *Comprobante recibido correctamente* ✅\n\n" .
                                "📝 *RESUMEN DE TU PEDIDO* 📝\n" .
                                "$resumenPlatos" .
                                "💰 *Total: S/ $totalFormateado*\n\n" .
                                "📦 *Tu pedido ha sido registrado correctamente.* ¡Gracias por tu compra! 🍽️ \n LE NOTIFICAREMOS EL ESTADO DE SU PEDIDO"
                        );

                        Event::dispatch(new PedidoCreadoEvent(
                            $pedido->codigo_pedido,
                            $pedido->numero_cliente,
                            $pedido->estado_pago
                        ));
                    } else {
                        Log::error("El archivo no se guardó correctamente en el storage");
                        throw new \Exception("Error al guardar el comprobante. Por favor inténtalo nuevamente.");
                    }
                    // ============= FIN DEL CÓDIGO ORIGINAL =============

                } else {
                    throw new \Exception("Formato de imagen no soportado. Envía una foto en formato JPG o PNG.");
                }
            } catch (\Exception $e) {
                Log::error("Error al procesar comprobante: " . $e->getMessage());
                $this->enviarMensajeWhatsApp(
                    $pedido->numero_cliente,
                    "❌ Error: " . $e->getMessage() . "\n\nPor favor inténtalo nuevamente."
                );
                return;
            }
        } else {
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "📸 Por favor envía la foto del comprobante.\nCódigo: *" . $pedido->codigo_pedido . "*"
            );
        }
    }

    /**
     * ⚡ Enviar mensaje con reintentos automáticos
     * Intenta hasta 3 veces antes de fallar
     */
    private function enviarMensajeWhatsApp($to, $message, $mediaUrl = null)
    {
        $maxReintentos = 3;
        $intento = 0;

        while ($intento < $maxReintentos) {
            try {
                if (empty($this->twilioClient)) {
                    // Si no hay cliente, ni intentamos (error de config)
                    return false;
                }

                $options = [
                    'from' => $this->twilioNumber,
                    'body' => $message,
                ];

                if ($mediaUrl) {
                    $options['mediaUrl'] = [$mediaUrl];
                }

                $this->twilioClient->messages->create($to, $options);
                Log::info("✅ Mensaje enviado a $to");
                return true;
            } catch (\Exception $e) {
                // 🛑 DETECCIÓN INTELIGENTE DE ERRORES DE TWILIO
                $msg = $e->getMessage();

                // Si es error de Límite (429) o Credenciales (401), NO REINTENTAR
                if (strpos($msg, '429') !== false || strpos($msg, '401') !== false) {
                    Log::error("⛔ ERROR BLOQUEANTE TWILIO (No se reintentará): $msg");
                    return false; // Cortamos el ciclo aquí
                }

                $intento++;
                Log::warning("⚠️ Error en intento $intento/$maxReintentos: " . $msg);

                if ($intento >= $maxReintentos) {
                    Log::error("❌ FALLO FINAL envío a $to");
                    return false;
                }
                usleep(500000);
            }
        }
        return false;
    }

    private function optimizarImagenV3($rutaOriginal, $ancho = 1000, $calidad = 75)
    {
        try {
            $manager = new ImageManager(new Driver());
            $imagen = $manager->read(public_path($rutaOriginal));
            $imagen->scale(width: $ancho);
            $nombreArchivo = 'opt_' . uniqid() . '.jpg';
            $rutaTemporal = 'storage/tmp/' . $nombreArchivo;
            $imagen->toJpeg(quality: $calidad)->save(public_path($rutaTemporal));
            return asset($rutaTemporal);
        } catch (\Throwable $e) {
            Log::error("Error al optimizar imagen: {$e->getMessage()}");
            return asset($rutaOriginal);
        }
    }



    public function obtenerChats($id)
    {
        try {
            if (!$id) return response()->json(['success' => false, 'message' => 'ID no proporcionado'], 400);
            $chat = PedidosChat::where('idPedido', $id)->orderBy('created_at', 'asc')->get();
            if ($chat->isEmpty()) return response()->json(['success' => false, 'message' => 'No hay mensajes'], 404);
            return response()->json(['success' => true, 'data' => $chat], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function obtenerPedidos()
    {
        try {
            $pedidos = PedidosWeb::where('estado', 1)->get();
            if ($pedidos->isEmpty()) return response()->json(['success' => false, 'message' => 'No hay pedidos'], 404);
            return response()->json(['success' => true, 'data' => $pedidos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error servidor'], 500);
        }
    }
}
