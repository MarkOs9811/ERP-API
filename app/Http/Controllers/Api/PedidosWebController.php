<?php

namespace App\Http\Controllers\api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\detallePedidosWeb;
use App\Models\MiEmpresa;
use App\Models\PedidosWebRegistro;
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
            $pedidosPendientes = PedidosWebRegistro::with('detallesPedido.plato')->where('estado_pedido', 3)->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos pendientes obtenidos correctamente.", $pedidosPendientes->toArray());
            return response()->json(['success' => true, 'data' => $pedidosPendientes], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosEnProceso()
    {
        try {
            $pedidosEnProceso = PedidosWebRegistro::with('detallesPedido.plato')->where('estado_pedido', 4)->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos en proceso obtenidos correctamente.", $pedidosEnProceso->toArray());
            return response()->json(['success' => true, 'data' => $pedidosEnProceso], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    function getPedidosListos()
    {
        try {
            $pedidosListos = PedidosWebRegistro::with('detallesPedido.plato')->where('estado_pedido', 5)->orderBy("created_at", "desc")->get();
            Log::info("✅ Pedidos listos obtenidos correctamente.", $pedidosListos->toArray());
            return response()->json(['success' => true, 'data' => $pedidosListos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    public function cambiarEstado(Request $request)
    {
        try {
            // Validar la solicitud
            $request->validate([
                'idPedido' => 'required|exists:pedidos_web_registros,id',
                'nuevoEstado' => 'required|integer|in:3,4,5', // Acepta los estados válidos
            ]);


            // Buscar el pedido
            $pedido = PedidosWebRegistro::with('detallesPedido.plato')->findOrFail($request->idPedido);

            // Solo permitir cambios entre estados válidos
            if (in_array($pedido->estado_pedido, [3, 4, 5])) {
                $mensaje = '';

                if ($pedido->estado_pedido == 3 && $request->nuevoEstado == 4) {
                    $mensaje = "🍽️ Su pedido ahora está en proceso. Estamos preparando su comida con mucho cariño.";
                } elseif ($pedido->estado_pedido == 4 && $request->nuevoEstado == 5) {
                    $mensaje = "✅ Su pedido está listo para recoger. Puede pasar por su pedido en los próximos 10-20 minutos.";
                }

                // Actualizar el estado
                $pedido->estado_pedido = $request->nuevoEstado;
                $pedido->save();

                // Enviar mensaje solo si hay un cambio válido de estado
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
}
