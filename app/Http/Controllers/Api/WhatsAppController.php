<?php

namespace App\Http\Controllers\api;

use App\Events\PedidoCreadoEvent;
use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\detallePedidosWeb;
use App\Models\PedidosChat;
use App\Models\PedidosWeb;
use App\Models\PedidosWebRegistro;
use App\Models\Plato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Twilio\Rest\Client;
use Illuminate\Support\Str;
use App\Services\OpenAIService;
use Endroid\QrCode\Bacon\ErrorCorrectionLevelConverter;

// para reducri tamaño de img
use Intervention\Image\Facades\Image;
// para generar QR

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel; // Usamos la clase ErrorCorrectionLevel
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class WhatsAppController extends Controller
{


    private $twilioClient;
    private $twilioNumber;
    private $openAIService;
    private $estadosPedido = [
        1 => 'Pendiente - Sin confirmar pedido',
        2 => 'Pendiente - Sin confirmar pago',
        33 => 'Pendiente  de pago',
        3 => 'Pendiente - Verificación de pago',
        4 => 'En preparación',
        5 => 'Listo para recoger',
        6 => 'Entregado y pagado',
        7 => 'Cancelado',
        8 => 'Esperando cantidad', // Nuevo estado
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

        $sid = ConfiguracionHelper::valor1('twilio');
        $token = ConfiguracionHelper::valor2('twilio');
        $number = ConfiguracionHelper::valor3('twilio');

        $this->twilioClient = new Client($sid, $token);
        $this->twilioNumber = $number;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function manejarMensaje(Request $request)
    {
        // [LOG 1] Inicio
        // Log::info("📡 --- NUEVO MENSAJE ---");

        $numero_cliente = $request->input('From');
        $mensaje = trim($request->input('Body'));

        // 1. CAPTURA DE COORDENADAS (CRUCIAL PARA DELIVERY)
        $latitud = $request->input('Latitude');
        $longitud = $request->input('Longitude');

        // [LOG 2] Validación Inteligente
        // Si hay coordenadas, permitimos que el mensaje venga vacío.
        if (empty($numero_cliente) || (empty($mensaje) && empty($latitud))) {
            Log::error("❌ ERROR: Datos incompletos (Ni texto ni ubicación).");
            return response()->json(['status' => 'error', 'message' => 'Datos incompletos'], 400);
        }

        // ⚡ [OPTIMIZACIÓN] Verificar si ya hay una respuesta en proceso para este cliente
        $lockKey = 'whatsapp_processing_' . $numero_cliente;
        $maxWaitTime = 60; // Máximo 60 segundos de espera
        $waited = 0;

        // Si hay lock, esperar o rechazar
        while (Cache::has($lockKey) && $waited < $maxWaitTime) {
            usleep(100000); // Esperar 100ms antes de reintentar
            $waited += 0.1;
        }

        // Si sigue bloqueado después de esperar, responder al cliente
        if (Cache::has($lockKey)) {
            Log::warning("⏳ Cliente $numero_cliente envió mensaje mientras se procesaba el anterior");
            $this->enviarMensajeWhatsApp($numero_cliente, "⏳ Aún estamos procesando tu anterior mensaje. Por favor espera un momento...");
            return response()->json(['status' => 'processing']);
        }

        // Crear lock para esta conversación
        Cache::put($lockKey, true, now()->addSeconds(30)); // Lock de 30 segundos

        try {
            $estadoTwilio = ConfiguracionHelper::estado("twilio");

            // Validación: Twilio desactivado
            if ($estadoTwilio === 0) {
                $this->enviarMensajeWhatsApp($numero_cliente, "🙋‍♂️ Nuestro servicio no está disponible por ahora. ⏳");
                return response()->json(['status' => 'disabled']);
            }

            $cajas = $this->obtenerCajasConCache();
            // Validación: Cajas cerradas
            if (!$cajas->contains('estadoCaja', 1)) {
                $this->enviarMensajeWhatsApp($numero_cliente, "🕒 Estamos cerrados. Horario: 6:00 PM - 11:00 PM. 🍴");
                Cache::forget($lockKey); // Liberar lock
                return response()->json(['status' => 'closed']);
            }

            // Buscar pedido activo
            $pedido = PedidosWebRegistro::where('numero_cliente', $numero_cliente)
                ->where('estado', 1)
                ->where('estado_pedido', '!=', 6)
                ->latest()
                ->first(['id', 'estado_pedido', 'estado_pago', 'pedido_temporal', 'numero_cliente', 'codigo_pedido']);

            if (!$pedido) {
                Cache::forget($lockKey); // Liberar lock
                return $this->iniciarPedido($numero_cliente);
            }

            // Procesamiento del mensaje
            $mensajeLimpio = strtolower($mensaje);
            $estadoActual = $pedido->estado_pedido;

            // DEFINICIÓN DE HANDLERS
            // Importante: Agregamos 'use ($request)' para poder leer la ubicación dentro de las funciones
            $handlers = [
                // [ESTADO 1] CONFIRMACIÓN INICIAL
                1 => function () use ($pedido, $mensaje, $mensajeLimpio) {
                    $comando = $this->detectarComando($mensaje);

                    if ($comando === 'corregir') {
                        $pedido->update(['pedido_temporal' => null, 'estado_pedido' => 1]);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "✏️ Pedido reiniciado. Escribe tu pedido nuevamente:");
                        return true;
                    }

                    if ($comando === 'confirmar') {
                        // Pasamos a preguntar si es Delivery o Recojo
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

                    // Procesamiento NLP si no es comando exacto
                    if ($this->esMensajeNLP($mensaje)) {
                        $this->procesarSeleccionPlatoNLP($pedido, $mensaje);
                    }
                    return true;
                },

                // [ESTADO 9] SELECCIÓN DELIVERY / RECOJO
                9 => function () use ($pedido, $mensajeLimpio) {
                    if ($mensajeLimpio === '1' || str_contains($mensajeLimpio, 'delivery')) {
                        // Opción 1: Delivery -> Pedimos Nombre
                        $pedido->update(['estado_pedido' => 10, 'tipo_entrega' => 'delivery']);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "📝 Por favor **escribe tu Nombre y Apellido**:");
                    } elseif ($mensajeLimpio === '2' || str_contains($mensajeLimpio, 'recojo')) {
                        // Opción 2: Recojo -> Pedimos Nombre
                        $pedido->update(['estado_pedido' => 10, 'tipo_entrega' => 'recojo']);
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "📝 Por favor **escribe tu Nombre y Apellido**:");
                    } else {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, "⚠️ Responde **1** para Delivery o **2** para Recojo.");
                    }
                    return true;
                },

                // [ESTADO 10] GUARDAR NOMBRE
                10 => function () use ($pedido, $mensaje) {
                    // ⚡ Validar nombre
                    $validacion = $this->validarNombre($mensaje);
                    if (!$validacion['valido']) {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacion['error']);
                        return true;
                    }

                    $pedido->update(['nombre_cliente' => $validacion['nombre']]);

                    // ⚡ Refrescar para obtener tipo_entrega actualizado
                    $pedido->refresh();

                    // Siguiendo flujo según tipo de entrega
                    if ($pedido->tipo_entrega === 'delivery') {
                        // Para delivery: pedir ubicación (estado 11)
                        $pedido->update(['estado_pedido' => 11]);
                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "📍 Hola " . $validacion['nombre'] . ", ahora envía tu ubicación.\n\n📎 Presiona el **clip (adjuntar)** en WhatsApp ➡️ Ubicación ➡️ **Enviar mi ubicación actual**."
                        );
                    } else {
                        // Para recojo: pasar a método de pago (estado 2)
                        $pedido->update(['estado_pedido' => 2]);
                        $this->enviarMensajeWhatsApp(
                            $pedido->numero_cliente,
                            "💳 *MÉTODO DE PAGO* 💳\n\n" .
                                "2️⃣ Pagar en caja al recoger\n\n" .
                                "Responde con la opción 2."
                        );
                    }
                    return true;
                },

                // [ESTADO 11] GUARDAR UBICACIÓN Y MOSTRAR PAGO DELIVERY
                11 => function () use ($pedido, $request) {
                    $lat = $request->input('Latitude');
                    $lon = $request->input('Longitude');

                    // ⚡ Validar ubicación
                    $validacionUbicacion = $this->validarUbicacion($lat, $lon);
                    if (!$validacionUbicacion['valido']) {
                        $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacionUbicacion['error']);
                        return true;
                    }

                    // Guardamos ubicación y pasamos directo a Estado 2 (Pago)
                    $pedido->update([
                        'latitud' => $validacionUbicacion['lat'],
                        'longitud' => $validacionUbicacion['lon'],
                        'estado_pedido' => 2
                    ]);

                    // Forzamos actualización del modelo para asegurar que los datos estén frescos
                    $pedido->refresh();

                    // MENSAJE PERSONALIZADO PARA DELIVERY
                    // Solo contraentrega para delivery
                    $menuPago = "✅ Ubicación recibida.\n\n💳 **MÉTODO DE PAGO** 💳\n\n2️⃣ PAGO CONTRAENTREGA (Efectivo/Yape al recibir)\n\nResponde con la opción 2.";

                    $this->enviarMensajeWhatsApp($pedido->numero_cliente, $menuPago);
                    return true;
                },

                // [ESTADO 2] PROCESAR SELECCIÓN DE PAGO
                2 => function () use ($pedido, $mensaje) {
                    // Aquí interceptamos para asegurarnos que la lógica de "Caja" funcione para "Contraentrega"
                    // Asegúrate de que tu función seleccionarMetodoPago maneje el texto correctamente.
                    return $this->seleccionarMetodoPago($pedido, $mensaje);
                },

                // ... RESTO DE HANDLERS (8, 3, 33) IGUAL ...
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
                Cache::forget($lockKey); // ✅ Liberar lock al completar
                return response()->json(['status' => 'success']);
            }

            $this->enviarMensajeWhatsApp($numero_cliente, "No entendí. Escribe *Hola* para empezar.");
            Cache::forget($lockKey); // ✅ Liberar lock
        } catch (\Exception $e) {
            Log::error("💥 ERROR: " . $e->getMessage());
            Cache::forget($lockKey); // ✅ Liberar lock incluso en error
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'success']);
    }

    private function esMensajeNLP($mensaje): bool
    {
        // Mensajes que contienen texto descriptivo (no solo números)
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

        // Comandos permitidos con variaciones
        $comandos = [
            'confirmar' => ['confirmar', 'confirma', 'confirmación', 'confimar', 'cofirmar', 'confirmr', 'confirmo'],
            'corregir' => ['corregir', 'corrige', 'corrección', 'correguir', 'coregir', 'corregira', 'corrigir'],
            'cancelar' => ['cancelar', 'cancela', 'cancelación', 'canclar', 'canceló', 'canselar', 'cancelacion'],
        ];

        foreach ($comandos as $comando => $variaciones) {
            // Búsqueda exacta
            if (in_array($texto, $variaciones)) {
                return $comando;
            }

            // Búsqueda por similitud (Levenshtein)
            foreach ($variaciones as $var) {
                $distancia = levenshtein($texto, $var);
                // Si la distancia es <= 2 caracteres de diferencia, es válido
                if ($distancia <= 2 && strlen($var) >= 4) {
                    return $comando;
                }
            }
        }

        return null;
    }

    /**
     * ⚡ CACHÉS - Optimización para múltiples usuarios
     */
    private function obtenerCajasConCache()
    {
        return Cache::remember('cajas_estado', now()->addMinutes(5), function () {
            return Caja::get();
        });
    }

    private function obtenerPlatosConCache()
    {
        return Cache::remember('platos_menu', now()->addMinutes(15), function () {
            return Plato::all(['id', 'nombre', 'precio'])->toArray();
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
    private function iniciarPedido($numero_cliente)
    {
        // ⚡ Evitar duplicados: si ya existe un pedido activo, no crear otro
        $pedidoExistente = PedidosWebRegistro::where('numero_cliente', $numero_cliente)
            ->where('estado', 1)
            ->where('estado_pedido', '!=', 6)
            ->exists();

        if ($pedidoExistente) {
            return response()->json(['status' => 'already_exists']);
        }

        $codigoPedido = 'PED-' . Str::upper(Str::random(6));

        PedidosWebRegistro::create([
            'codigo_pedido' => $codigoPedido,
            'numero_cliente' => $numero_cliente,
            'estado_pedido' => 1, // En selección de platos
            'estado_pago' => 'por pagar',
            'estado' => 1
        ]);

        $this->enviarMenu($numero_cliente);
    }

    private function enviarMenu($numero_cliente)
    {
        try {
            // ⚡ Rutas originales
            $imagenes = [
                'storage/miEmpresa/CARTAS.jpg',
                'storage/miEmpresa/CARTABURGUER.jpg',
            ];

            // 1️⃣ PRIMERO: Enviar imágenes
            foreach ($imagenes as $imgPath) {
                $urlOptimizada = $this->optimizarImagenV3($imgPath);
                $this->enviarMensajeWhatsApp($numero_cliente, '', $urlOptimizada);
            }

            // 2️⃣ DESPUÉS: Enviar mensaje de bienvenida
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
        $pedidosGuardados = detallePedidosWeb::where('idPedido', $pedido->id)->all();
        $pedidosGuardados->delete();
    }

    private function procesarSeleccionPlatoNLP($pedido, $mensaje)
    {
        // Extraer platos con OpenAI
        $pedidosExtraidos = $this->openAIService->extraerPlatosYCantidades($mensaje);

        // ⚡ Obtener los platos del menú desde caché
        $platosMenu = $this->obtenerPlatosConCache();
        $platosEncontrados = [];
        $platosNoEncontrados = [];

        // Función mejorada para normalizar nombres
        function normalizarTexto($texto)
        {
            $texto = mb_strtolower(trim($texto), 'UTF-8');
            $texto = str_replace(
                ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', ' de ', ' con '],
                ['a', 'e', 'i', 'o', 'u', 'u', 'n', ' ', ' '],
                $texto
            );
            return preg_replace('/[^a-z0-9 ]/', '', $texto);
        }

        foreach ($pedidosExtraidos as $item) {
            $nombreOriginal = trim($item['nombre']);
            $nombrePlatoSolicitado = normalizarTexto($nombreOriginal);
            $mejorCoincidencia = null;
            $mejorPorcentaje = 0;

            foreach ($platosMenu as $plato) {
                $nombrePlatoMenu = normalizarTexto($plato['nombre']);

                // Usar similar_text y levenshtein para mejor precisión
                similar_text($nombrePlatoSolicitado, $nombrePlatoMenu, $porcentajeSimilitud);
                $distancia = levenshtein($nombrePlatoSolicitado, $nombrePlatoMenu);
                $longitudPromedio = (strlen($nombrePlatoSolicitado) + strlen($nombrePlatoMenu)) / 2;
                $porcentajeLevenshtein = (1 - $distancia / $longitudPromedio) * 100;

                $puntajeTotal = ($porcentajeSimilitud + $porcentajeLevenshtein) / 2;

                if ($puntajeTotal > $mejorPorcentaje) {
                    $mejorPorcentaje = $puntajeTotal;
                    $mejorCoincidencia = $plato;
                }
            }

            // Umbral ajustable según resultados
            $umbralCoincidencia = 75; // Puedes ajustar este valor

            if ($mejorPorcentaje >= $umbralCoincidencia) {
                $platosEncontrados[] = [
                    'id' => $mejorCoincidencia['id'],
                    'nombre' => $mejorCoincidencia['nombre'],
                    'cantidad' => $item['cantidad'],
                    'precio' => $mejorCoincidencia['precio'],
                    'subtotal' => $item['cantidad'] * $mejorCoincidencia['precio'],
                    'nombre_solicitado' => $nombreOriginal // Guardar lo que dijo el cliente
                ];
            } else {
                $platosNoEncontrados[] = $nombreOriginal;
            }
        }

        // Si hay platos no encontrados, mostrar sugerencias
        if (!empty($platosNoEncontrados)) {
            $mensajeError = "❌ *No encontré estos platos:* \n";

            foreach ($platosNoEncontrados as $plato) {
                $mensajeError .= "• $plato\n";

                // Buscar sugerencias similares
                $sugerencias = $this->buscarSugerencias($plato, $platosMenu);
                if (!empty($sugerencias)) {
                    $mensajeError .= "   ¿Quizás quisiste decir: " . implode(", ", $sugerencias) . "?\n";
                }
            }

            $mensajeError .= "\nPor favor revisa y vuelve a escribir el pedido con los nombres correctos.";

            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $mensajeError);
            return;
        }
        // [CORRECCIÓN 2] GUARDAR EN BD
        // Esto es obligatorio para que el 'Confirmar' posterior funcione
        $pedido->update([
            'pedido_temporal' => json_encode($platosEncontrados),
            'estado_pedido' => 1
        ]);
        // Generar resumen del pedido (mejorado)
        $this->generarResumenPedido($pedido, $platosEncontrados);
    }

    private function buscarSugerencias($platoBuscado, $platosMenu)
    {
        $sugerencias = [];
        $platoNormalizado = normalizarTexto($platoBuscado);

        foreach ($platosMenu as $plato) {
            $nombreNormalizado = normalizarTexto($plato['nombre']);
            similar_text($platoNormalizado, $nombreNormalizado, $porcentaje);

            if ($porcentaje > 60) { // Umbral más bajo para sugerencias
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

            // Mostrar corrección si hubo diferencia
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

    // Confirmar pedido y pasar al pago
    private function confirmarPedido($pedido, $mensaje)
    {
        if (strtolower($mensaje) === 'confirmar') {
            // Recuperar pedido temporal
            $platosEncontrados = json_decode($pedido->pedido_temporal, true);
            if (!empty($platosEncontrados)) {
                foreach ($platosEncontrados as $item) {
                    detallePedidosWeb::updateOrCreate(
                        ['idPedido' => $pedido->id, 'idPlato' => $item['id']],
                        [
                            'cantidad' => $item['cantidad'],
                            'precio' => $item['cantidad'] * $item['precio'] // Multiplicamos cantidad x precio unitario
                        ]
                    );
                }
            }

            // Actualizar estado del pedido
            $pedido->update([
                'pedido_temporal' => null
            ]);

            // Preguntar por método de pago (solo caja para recojo)
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "💳 *MÉTODO DE PAGO* 💳\n\n" .
                    "2️⃣ Pagar en caja al recoger\n\n" .
                    "Responde con la opción 2."
            );
        } else {
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "Para confirmar escribe *Confirmar*\nPara corregir, escribe tu pedido nuevamente."
            );
        }
    }

    private function procesarCantidadPlato($pedido, $mensaje)
    {
        // ⚡ Validar cantidad
        $validacion = $this->validarCantidad($mensaje);
        if (!$validacion['valido']) {
            $this->enviarMensajeWhatsApp($pedido->numero_cliente, $validacion['error']);
            return;
        }

        $cantidad = $validacion['cantidad'];

        try {
            $detalle = detallePedidosWeb::where('idPedido', $pedido->id)
                ->latest()
                ->first();

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
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "⚠️ Error al actualizar tu pedido. Por favor inicia nuevamente."
            );
        }
    }

    private function seleccionarMetodoPago($pedido, $mensaje)
    {
        // ⚡ Validación: Solo aceptar método válido según tipo de entrega
        if ($pedido->tipo_entrega === 'delivery' && $mensaje !== '2') {
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "⚠️ Para delivery solo está disponible CONTRAENTREGA.\n\nResponde *2*"
            );
            return;
        } elseif ($pedido->tipo_entrega === 'recojo' && $mensaje !== '2') {
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "⚠️ Para recojo solo está disponible PAGO EN CAJA.\n\nResponde *2*"
            );
            return;
        }

        // [CORRECCIÓN CRÍTICA] 🛠️
        // Antes de procesar el pago, verificamos si los platos ya están en la tabla de detalles.
        // Si no están (caso del bug de S/ 0), los migramos desde el JSON temporal ahora mismo.
        $conteoDetalles = detallePedidosWeb::where('idPedido', $pedido->id)->count();

        if ($conteoDetalles == 0 && !empty($pedido->pedido_temporal)) {
            $items = json_decode($pedido->pedido_temporal, true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    detallePedidosWeb::create([
                        'idPedido' => $pedido->id,
                        'idPlato'  => $item['id'], // Asegúrate que tu JSON tenga 'id' del plato
                        'cantidad' => $item['cantidad'],
                        'precio'   => $item['precio'],
                        'subtotal' => $item['cantidad'] * $item['precio'], // Recalculamos por seguridad
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                Log::info("✅ Platos migrados de JSON a Tabla Detalles para el pedido: " . $pedido->codigo_pedido);
            }
        }

        // --- OPCIÓN 1: PAGO YAPE/PLIN (Igual para ambos casos) ---
        if ($mensaje === '1') {
            $codigoPago = $pedido->codigo_pedido;
            $pedido->update([
                'estado_pedido' => 33,
                'codigo_pago' => $codigoPago
            ]);

            // Calcular Monto Total
            $detallesPrecios = detallePedidosWeb::where('idPedido', $pedido->id)->get();
            $montoTotal = 0;
            foreach ($detallesPrecios as $detalle) {
                $montoTotal += $detalle->precio * $detalle->cantidad; // Ojo: Multiplicar precio x cantidad
            }
            $montoTotal = number_format($montoTotal, 2);

            $qrUrl = asset("storage/qrs/QRPAGAR.jpeg");

            // Mensaje YAPE
            $this->enviarMensajeWhatsApp(
                $pedido->numero_cliente,
                "📱 *PAGO POR YAPE/PLIN* \n" .
                    "══════════════════════════\n" .
                    "Escanea este QR o yapea al número *977951520*.\n" .
                    "💰 Monto total: S/ {$montoTotal}\n" .
                    "📌 Código de pedido: *{$codigoPago}*\n" .
                    "⚠️ Envía la captura del comprobante aquí para validar.",
                $qrUrl
            );
        }
        // --- OPCIÓN 2: PAGO CONTRAENTREGA O CAJA ---
        elseif ($mensaje === '2') {

            // Obtener detalles del pedido
            $detalles = detallePedidosWeb::with('plato')
                ->where('idPedido', $pedido->id)
                ->get();

            // Construir resumen de platos
            $resumenPlatos = "";
            $totalPagar = 0;

            foreach ($detalles as $detalle) {
                $subtotal = $detalle->cantidad * $detalle->plato->precio; // Usamos el precio actualizado del plato o del detalle
                $resumenPlatos .= "🍽️ {$detalle->plato->nombre}\n";
                $resumenPlatos .= "   Cant: {$detalle->cantidad} x S/ {$detalle->plato->precio} = S/ {$subtotal}\n\n";
                $totalPagar += $subtotal;
            }

            // Actualizamos estado
            $pedido->update([
                'estado_pedido' => 3,
                'estado_pago' => 'por pagar'
            ]);

            // [LÓGICA DINÁMICA DE TEXTO] 🛵 vs 🏪
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

            // Evento Pusher
            Event::dispatch(new PedidoCreadoEvent(
                $pedido->codigo_pedido,
                $pedido->numero_cliente,
                $pedido->estado_pago
            ));
        }
        // --- MENÚ DE SELECCIÓN (Si manda algo que no es válido) ---
        else {
            // Solo mostrar la opción aplicable según tipo de entrega
            if ($pedido->tipo_entrega === 'delivery') {
                $this->enviarMensajeWhatsApp(
                    $pedido->numero_cliente,
                    "🔷 *MÉTODO DE PAGO* 🔷\n\n" .
                        "2️⃣ *PAGO CONTRAENTREGA*\n" .
                        "   - Pagas al recibir en tu ubicación\n\n" .
                        "Responde *2*"
                );
            } else {
                $this->enviarMensajeWhatsApp(
                    $pedido->numero_cliente,
                    "🔷 *MÉTODO DE PAGO* 🔷\n\n" .
                        "2️⃣ *PAGAR EN CAJA*\n" .
                        "   - Pagas al recoger en tienda\n\n" .
                        "Responde *2*"
                );
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
                    throw new \RuntimeException('Twilio client not initialized');
                }

                $options = [
                    'from' => $this->twilioNumber,
                    'body' => $message,
                ];

                if ($mediaUrl) {
                    $options['mediaUrl'] = $mediaUrl;
                }

                $this->twilioClient->messages->create(
                    $to,
                    $options
                );

                Log::info("✅ Mensaje enviado a $to");
                return true; // Éxito
            } catch (\Exception $e) {
                $intento++;
                Log::warning("⚠️ Error en intento $intento/$maxReintentos enviando a $to: " . $e->getMessage());

                // Si es el último intento, registrar error crítico
                if ($intento >= $maxReintentos) {
                    Log::error("❌ FALLO CRÍTICO: No se pudo enviar mensaje a $to después de $maxReintentos intentos");
                    return false;
                }

                // Esperar 500ms antes de reintentar
                usleep(500000);
            }
        }

        return false;
    }

    private function optimizarImagenV3($rutaOriginal, $ancho = 1000, $calidad = 75)
    {
        try {
            $manager = new ImageManager(new Driver());

            // Leer imagen original
            $imagen = $manager->read(public_path($rutaOriginal));

            // Escalar (resize) manteniendo proporciones
            $imagen->scale(width: $ancho);

            // Convertir a JPG y guardar en una ruta temporal
            $nombreArchivo = 'opt_' . uniqid() . '.jpg';
            $rutaTemporal = 'storage/tmp/' . $nombreArchivo;
            $imagen->toJpeg(quality: $calidad)->save(public_path($rutaTemporal));

            return asset($rutaTemporal); // Devuelve URL accesible públicamente
        } catch (\Throwable $e) {
            Log::error("Error al optimizar imagen: {$e->getMessage()}");
            return asset($rutaOriginal); // En caso de error, devuelve imagen original
        }
    }




    // public function responderWhatsApp($to, $mensaje)
    // {
    //     $sid = env('TWILIO_SID');
    //     $token = env('TWILIO_AUTH_TOKEN');
    //     $twilioNumber = env('TWILIO_WHATSAPP_NUMBER');

    //     try {
    //         $twilio = new Client($sid, $token);

    //         $twilio->messages->create(
    //             $to, // Número al que se enviará la respuesta
    //             [
    //                 "from" => $twilioNumber, // Número de Twilio
    //                 "body" => $mensaje
    //             ]
    //         );

    //         Log::info("Mensaje enviado a $to: $mensaje");
    //     } catch (\Exception $e) {
    //         Log::error("Error al enviar mensaje de WhatsApp: " . $e->getMessage());
    //     }
    // }

    public function obtenerChats($id)
    {
        try {
            $idPedido = $id;

            if (!$idPedido) {
                return response()->json(['success' => false, 'message' => 'ID de pedido no proporcionado'], 400);
            }

            $chatPersonal = PedidosChat::where('idPedido', $idPedido)
                ->orderBy('created_at', 'asc') // Ordenar los mensajes por fecha de creación (más antiguos primero)
                ->get();

            if ($chatPersonal->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No hay mensajes para este pedido'], 404);
            }

            return response()->json(['success' => true, 'data' => $chatPersonal], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    // public function store(Request $request)
    // {
    //     $request->validate([
    //         'idPedido' => 'required|exists:pedidos_webs,id',
    //         'mensaje' => 'required|string',
    //     ]);

    //     // Buscar el pedido y obtener el número de WhatsApp del cliente
    //     $pedido = PedidosWeb::find($request->idPedido);
    //     if (!$pedido || !$pedido->cliente) {
    //         return response()->json(['error' => 'El pedido no tiene un número de WhatsApp válido'], 400);
    //     }

    //     // Guardar mensaje en la base de datos
    //     $mensaje = new PedidosChat();
    //     $mensaje->idPedido = $request->idPedido;
    //     $mensaje->idUsuario = auth()->id(); // Captura el usuario autenticado
    //     $mensaje->mensaje = $request->mensaje;
    //     $mensaje->remitente = 'operador';
    //     $mensaje->save();

    //     // Enviar mensaje de respuesta a WhatsApp del cliente
    //     $this->responderWhatsApp($pedido->cliente, $request->mensaje);

    //     return response()->json(['message' => 'Mensaje enviado', 'data' => $mensaje], 201);
    // }


    public function obtenerPedidos()
    {
        try {
            $pedidos = PedidosWeb::where('estado', 1)->get(); // Sin comillas en el 1 si es int

            if ($pedidos->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No hay pedidos disponibles'], 404);
            }

            return response()->json(['success' => true, 'data' => $pedidos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error interno del servidor'], 500);
        }
    }
}
