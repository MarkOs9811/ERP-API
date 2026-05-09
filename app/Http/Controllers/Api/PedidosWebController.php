<?php

namespace App\Http\Controllers\api;

use App\Events\NuevaNotificacionCliente;
use App\Events\NuevaNotificacionUsuario;
use App\Events\UbicacionRiderActualizada;
use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\detallePedidosWeb;
use App\Models\MiEmpresa;
use App\Models\Notificaciones;
use App\Models\PedidosWebRegistro;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class PedidosWebController extends Controller
{
    private $twilioClient;
    private $twilioNumber;


    public function __construct()
    {
        $idEmpresa = MiEmpresa::first()?->id;

        $sid = ConfiguracionHelper::valor1('Twilio', $idEmpresa);  // o ->clave()
        $token = ConfiguracionHelper::valor2('Twilio', $idEmpresa);
        $from = ConfiguracionHelper::valor3('Twilio', $idEmpresa); // WhatsApp number

        if ($sid && $token) {
            $this->twilioClient = new Client($sid, $token);
            $this->twilioNumber = $from;
        } else {
            $this->twilioClient = null;
            $this->twilioNumber = null;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    function getPedidosPendientes()
    {
        try {
            $pedidosPendientes = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')->where('estado_pedido', 3)->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos pendientes obtenidos correctamente.", $pedidosPendientes->toArray());
            return response()->json(['success' => true, 'data' => $pedidosPendientes], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosEnProceso()
    {
        try {
            $pedidosEnProceso = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')->where('estado_pedido', 4)->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos en proceso obtenidos correctamente.", $pedidosEnProceso->toArray());
            return response()->json(['success' => true, 'data' => $pedidosEnProceso], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosListos()
    {
        try {
            $pedidosListos = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')
                ->where('estado_pedido', 5)
                ->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos listos obtenidos correctamente.", $pedidosListos->toArray());
            return response()->json(['success' => true, 'data' => $pedidosListos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosAsignados()
    {
        try {
            $user = auth()->user();
            $pedidosAsignados = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')
                ->where('idDeliveryRider', $user->id)
                ->where('estado_pedido', 54)
                ->orderBy("created_at", "desc")->get();

            return response()->json(['success' => true, 'data' => $pedidosAsignados], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosEnCamino()
    {
        try {
            $pedidosEnCamino = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')
                ->where('estado_pedido', 55)
                ->orderBy("created_at", "desc")->get();

            return response()->json(['success' => true, 'data' => $pedidosEnCamino], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    public function cambiarEstado(Request $request)
    {
        try {
            // 1. Añadimos latitud y longitud como opcionales en la validación
            $request->validate([
                'idPedido' => 'required|exists:pedidos_web_registros,id',
                'nuevoEstado' => 'required|integer|in:3,4,5,54,55,6',
                'latitud' => 'numeric',
                'longitud' => 'numeric',
            ]);

            $pedido = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato')->findOrFail($request->idPedido);

            if (in_array($pedido->estado_pedido, [3, 4, 5, 54, 55, 6]) && in_array($request->nuevoEstado, [3, 4, 5, 54, 55, 6])) {
                $mensaje = '';

                if ($pedido->estado_pedido == 3 && $request->nuevoEstado == 4) {
                    $mensaje = "Su pedido ahora está en proceso. Estamos preparando su comida con mucho cariño.";
                    $titulo = "Pedido en Proceso";
                } elseif ($pedido->estado_pedido == 4 && $request->nuevoEstado == 5) {
                    $mensaje = "Su pedido está empacado y listo en el local. En breve le asignaremos un repartidor.";
                    $titulo = "Pedido Listo";
                } elseif ($pedido->estado_pedido == 5 && $request->nuevoEstado == 54) {
                    $mensaje = "Su pedido ya fue asignado a nuestro motorizado y será recogido en breve.";
                    $titulo = "Repartidor Asignado";
                } elseif ($pedido->estado_pedido == 54 && $request->nuevoEstado == 55) {

                    // --- NUEVA VALIDACIÓN ESTRICTA OBLIGATORIA ---
                    if (!$request->filled('latitud') || !$request->filled('longitud')) {
                        return response()->json([
                            'message' => 'Es obligatorio encender el GPS y enviar tu ubicación para poner el pedido en camino.',
                        ], 400); // Retorna error 400 para que el frontend lo atrape en el 'catch'
                    }
                    // ---------------------------------------------

                    $mensaje = "Su pedido está en camino. ¡Prepárese para disfrutar de su comida pronto!";
                    $titulo = "Pedido En Camino";

                    // Guardar ubicación del rider (como ya pasó la validación de arriba, estamos 100% seguros de que hay coordenadas)
                    $userId = auth()->id();

                    if ($userId) {
                        User::where('id', $userId)->update([
                            'latitud' => $request->latitud,
                            'longitud' => $request->longitud
                        ]);
                    }
                } elseif ($pedido->estado_pedido == 55 && $request->nuevoEstado == 6) {
                    $mensaje = "¡Gracias por tu preferencia! En *FIRE WOK* 🍣🍜 estamos encantados de haber podido atenderte. 🙏 \n\n¡Hast pronto!🔥😊";
                    $titulo = "Pedido Entregado";
                } else {
                    $mensaje = "El estado de su pedido ha sido actualizado.";
                    $titulo = "Estado Actualizado";
                }

                // Actualizar el estado
                $pedido->estado_pedido = $request->nuevoEstado;
                $pedido->save();

                // Guardar notificación
                $guardarNotificacion = new Notificaciones();
                $guardarNotificacion->idCliente = $pedido->idCliente;
                $guardarNotificacion->tipo = 'delivery';
                $guardarNotificacion->prioridad = 'alta';
                $guardarNotificacion->titulo = $titulo;
                $guardarNotificacion->mensaje = $mensaje;
                $guardarNotificacion->save();

                // LOG 3: Intentar disparar Pusher
                broadcast(new NuevaNotificacionCliente($pedido->idCliente));

                if (!empty($mensaje)) {
                    $this->enviarMensajeWhatsApp($pedido->numero_cliente, $mensaje);
                }

                return response()->json([
                    'message' => 'Estado actualizado correctamente.',
                    'pedido' => $pedido
                ], 200);
            }

            return response()->json([
                'message' => 'No se puede cambiar el estado del pedido.',
            ], 400);
        } catch (\Exception $e) {
            Log::error("❌ Error al cambiar estado del pedido: " . $e->getMessage());
            return response()->json([
                'message' => 'Ocurrió un error al actualizar el estado.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function notificarEstadoCliente(Request $request)
    {
        try {
            $toCliente = $request->numero_cliente;
            $estado = $request->estado_pedido;
            if ($estado == 4) {
                $this->enviarMensajeWhatsApp(
                    $toCliente,
                    "Estimado Cliente, le informamos que su pedido está en *PROCESO*.\n*CODIGO PEDIDO:* {$request->codigo_pedido}"
                );
            } elseif ($estado == 5) {

                $this->enviarMensajeWhatsApp(
                    $toCliente,
                    "Estimado Cliente, le informamos que su pedido está *LISTO para recoger*.\n*CODIGO PEDIDO:* {$request->codigo_pedido}"
                );
            } elseif ($estado == 6) {
                $this->enviarMensajeWhatsApp(
                    $toCliente,
                    "🎉 ¡Gracias  por Tu preferencia! 🎉\n\n" .
                        "En *FIRE WOK* 🍣🍜 estamos encantados de haber podido atenderte. 🙏 \n\n" .
                        "¡Vuelva pronto!🔥😊"
                );
            }


            return response()->json(['success' => true, 'message' => 'Notificación enviada con éxito']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al enviar notificación: ' . $e->getMessage()
            ], 500);
        }
    }
    private function enviarMensajeWhatsApp($to, $message)
    {
        try {
            if (empty($this->twilioClient)) {
                throw new \RuntimeException('Twilio client not initialized');
            }

            $this->twilioClient->messages->create(
                $to,
                [
                    'from' => $this->twilioNumber,
                    'body' => $message
                ]
            );

            Log::info("Mensaje enviado a $to");
        } catch (\Exception $e) {
            Log::error("Error enviando WhatsApp: " . $e->getMessage());
            // Opcional: reintentar o notificar al administrador
        }
    }

    public function getPedidosWeb($idPedido)
    {
        try {
            $pedidoWeb = detallePedidosWeb::with('plato', 'pedido')->where('idPedido', $idPedido)->get();
            if ($pedidoWeb) {
                return response()->json(['success' => true, "data" => $pedidoWeb], 200);
            } else {
                return response()->json(['success' => false, "message" => 'Pedido no encontrado'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, "message" => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // ASIGNAR DELIVERY RIDER A PEDIDO
    public function asignarRepartidor(Request $request)
    {
        try {
            $request->validate([
                'idPedido' => 'required|exists:pedidos_web_registros,id',
                'idDeliveryRider' => 'required|exists:users,id',
            ]);
            $mensaje = "Se le ha asignado un pedido por entregar. Por favor, revise su lista de pedidos asignados para más detalles.";
            $titulo = "Pedido por entregar";

            // Guardar notificación para el delivery rider
            $guardarNotificacion = new Notificaciones();
            $guardarNotificacion->idUsuario = $request->idDeliveryRider; // Aquí se guarda el ID del delivery rider
            $guardarNotificacion->tipo = 'delivery';
            $guardarNotificacion->prioridad = 'alta';
            $guardarNotificacion->titulo = $titulo;
            $guardarNotificacion->mensaje = $mensaje;
            $guardarNotificacion->save();

            // Disparar evento para notificar al delivery rider
            broadcast(new NuevaNotificacionUsuario($request->idDeliveryRider));
            Log::info("✅ Notificación enviada al delivery rider con ID: " . $request->idDeliveryRider);

            $pedido = PedidosWebRegistro::findOrFail($request->idPedido);
            $pedido->idDeliveryRider = $request->idDeliveryRider;
            $pedido->estado_pedido = 54; // Cambiamos el estado a "Asignado"
            $pedido->save();

            return response()->json(['success' => true, 'message' => 'Delivery rider asignado correctamente.'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function pedidosAsignadosRepartidor()
    {
        try {

            $pedidosAsignados = PedidosWebRegistro::with('detallesPedido.plato', 'detallesPedido.promociones.plato', 'conductor.empleado.persona', 'direccion', 'cliente.persona',)
                ->whereIn('estado_pedido', [54, 55, 6])
                ->orderBy("created_at", "desc")->get();

            return response()->json(['success' => true, 'data' => $pedidosAsignados], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function pedidosQuitarRepartidor($idEliminar)
    {
        try {

            $pedido = PedidosWebRegistro::where('id', $idEliminar)
                ->where('estado_pedido', 54)
                ->first();
            if (!$pedido) {
                return response()->json(['success' => false, "message" => "No se ecnontró el pedido o ya está en ruta"], 404);
            }

            $pedido->estado_pedido = 5;
            $pedido->save();

            return response()->json(['success' => true, "message" => "Se Quitó al rider correctamente"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "message" => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function pedidoUbicacionRider(Request $request)
    {
        try {
            $request->validate([
                'latitud' => 'required|numeric',
                'longitud' => 'required|numeric',
            ]);

            $userId = auth()->id();

            if (!$userId) {
                return response()->json(['message' => 'Usuario no autenticado.'], 401);
            }

            // 1. Actualizamos al rider
            User::where('id', $userId)->update([
                'latitud' => $request->latitud,
                'longitud' => $request->longitud
            ]);

            // 2. NUEVO: Buscamos los pedidos "En Camino" (55) asignados a este rider
            $pedidosActivos = PedidosWebRegistro::where('idDeliveryRider', $userId)
                ->where('estado_pedido', 55)
                ->get();

            // 3. NUEVO: Emitimos el evento a cada cliente que está esperando su pedido
            foreach ($pedidosActivos as $pedido) {
                broadcast(new UbicacionRiderActualizada($pedido->idCliente));
            }

            return response()->json([
                'message' => 'Ubicación de rider actualizada silenciosamente.'
            ], 200);
        } catch (\Exception $e) {
            Log::error("❌ Error en pedidoUbicacionRider: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getMisEntregas()
    {
        try {
            $idConductorDelivery = auth()->id();

            $misPedidos = PedidosWebRegistro::where('idDeliveryRider', $idConductorDelivery)
                ->where('estado_pedido', 6)
                ->with(['cliente.persona', 'conductor.empleado.persona', 'detallesPedido.plato', 'detallesPedido.promociones.plato'])
                ->get();

            Log::info('Lista de pedidos obtenidos:', ['pedidos' => $misPedidos->toArray()]);

            return response()->json([
                'success' => true,
                'data' => $misPedidos,
                'message' => 'Entregas obtenidas correctamente.' // <-- Mensaje corregido
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las entregas: ' . $e->getMessage()
            ], 500);
        }
    }
}
