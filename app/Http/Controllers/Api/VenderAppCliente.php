<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\api\VenderController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\Cliente;
use App\Models\DetallePedidosWeb;
use App\Models\Direccione;
use App\Models\Factura;
use App\Models\MetodoPago;
use App\Models\PedidosWebRegistro;
use App\Models\Plato;

use App\Models\SerieCorrelativo;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class VenderAppCliente extends Controller
{


   public function Pagar(Request $request)
    {
        Log::info('➡️ INICIO: Proceso de Pago Delivery App Cliente', ['payload' => $request->all()]);

        $request->validate([
            'idCliente' => 'required',
            'items'     => 'required|array|min:1',
            'total'     => 'required',
            'token_mercadopago' => 'required_if:idMetodoPago,999' 
            ], [
                'token_mercadopago.required_if' => 'Error de conexión: No se recibió el token de la tarjeta.'
            ]);

        // ---------------------------------------------------------
        // 1. VALIDACIÓN PREVIA: DATOS DEL CLIENTE
        // ---------------------------------------------------------
        $clienteData = Cliente::with('persona')->find($request->idCliente);

        if (!$clienteData || !$clienteData->persona || empty($clienteData->persona->telefono)) {
            Log::warning("Venta rechazada: Cliente sin teléfono.");
            return response()->json([
                'success' => false,
                'message' => 'El cliente no tiene un teléfono registrado. Por favor actualice su perfil antes de pedir.'
            ], 400);
        }

        $telefonoCliente = $clienteData->persona->telefono;
        $nombreCliente   = trim(($clienteData->persona->nombre ?? 'Cliente') . ' ' . ($clienteData->persona->apellidos ?? ''));
        $dniCliente      = $clienteData->persona->numero_documento ?? '00000000';
        $correoCliente   = $clienteData->persona->correo ?? $clienteData->persona->email ?? "cliente@sin-correo.com";

        // ---------------------------------------------------------
        // 2. BUSCAR MÉTODO DE PAGO PARA LA VENTA ("tarjeta debito")
        // ---------------------------------------------------------
        $metodoPagoGeneral = MetodoPago::where('nombre', 'LIKE', '%tarjeta debito%')->first();
        if (!$metodoPagoGeneral) {
            $metodoPagoGeneral = MetodoPago::where('nombre', 'LIKE', '%tarjeta%')->first();
        }
        $idMetodoPagoVenta = $metodoPagoGeneral ? $metodoPagoGeneral->id : 1;
        $nombreMetodoVenta = $metodoPagoGeneral ? $metodoPagoGeneral->nombre : 'Tarjeta';

        // ---------------------------------------------------------
        // 3. IMPUESTOS
        // ---------------------------------------------------------
        $impuestoConfig = ConfiguracionHelper::clave('impuestos');
        $tasaIgv = (float)($impuestoConfig ?? 0.18);
        $factorDivisor = 1 + $tasaIgv;

        // =========================================================
        // 4. MERCADO PAGO: PROCESAMIENTO DEL TOKEN ANTES DE LA BD
        // =========================================================
        $estadoPagoFinal = 'pendiente';
        $referenciaPagoMp = null;

        if ($request->filled('token_mercadopago')) {
            try {
                // Configuramos el Access Token desde el archivo .env
                MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));

                $client = new PaymentClient();

                // Armamos la petición de cobro
                $payment = $client->create([
                    "transaction_amount" => (float) $request->total,
                    "token"              => $request->token_mercadopago,
                    "description"        => "Pedido Delivery - " . $nombreCliente,
                    "installments"       => $request->installments ?? 1,
                    "payment_method_id"  => $request->payment_method_id,
                    "issuer_id"          => $request->issuer_id,
                    "payer"              => [
                        "email" => $correoCliente,
                    ]
                ]);

                // Verificamos si el banco aprobó la tarjeta
                if ($payment->status !== 'approved') {
                    Log::warning("Pago Rechazado por Mercado Pago: " . $payment->status);
                    return response()->json([
                        'success' => false,
                        'message' => 'La tarjeta fue rechazada o el pago no pudo procesarse. Verifica tus fondos o intenta con otra tarjeta.'
                    ], 400);
                }

                // Si todo sale bien, marcamos como pagado y guardamos el ID de MP
                $estadoPagoFinal = 'pagado';
                $referenciaPagoMp = $payment->id;
                Log::info("✅ Cobro Mercado Pago Exitoso. ID: " . $referenciaPagoMp);
                
            } catch (\MercadoPago\Exceptions\MPApiException $e) {
                // AQUÍ ATRAPAMOS EL ERROR EXACTO DE VALIDACIÓN DE MERCADO PAGO
                $apiResponse = $e->getApiResponse();
                $detalleError = $apiResponse ? $apiResponse->getContent() : ['error' => 'Sin detalles adicionales'];
                
                Log::error("❌ RECHAZO DE MERCADO PAGO (PRODUCCIÓN): ", (array) $detalleError);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación con el banco o pasarela. Intenta con otra tarjeta válida.'
                ], 400);

            } catch (\Exception $e) {
                // Este atrapa errores generales (ej. caída de red)
                Log::error("Error general API Mercado Pago: " . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Hubo un error de conexión al procesar la tarjeta. Por favor intente nuevamente.'
                ], 500);
            }
        }

        // ---------------------------------------------------------
        // 5. INICIO DE TRANSACCIÓN ESTRÍCTA (Solo si el cobro fue exitoso)
        // ---------------------------------------------------------
        try {
            // MERCADO PAGO: Añadimos $estadoPagoFinal y $referenciaPagoMp al "use"
            return DB::transaction(function () use ($request, $telefonoCliente, $nombreCliente, $dniCliente, $idMetodoPagoVenta, $nombreMetodoVenta, $factorDivisor, $estadoPagoFinal, $referenciaPagoMp) {

                // ==========================================
                // A. REGISTRAR EL PEDIDO WEB
                // ==========================================
                $direccion = Direccione::find($request->idDireccion);
                $lat = $direccion ? $direccion->latitud : null;
                $lng = $direccion ? $direccion->longitud : null;

                $codigo = 'PED-' . strtoupper(Str::random(6));

                $pedidoWeb = PedidosWebRegistro::create([
                    'idEmpresa'      => $request->idEmpresa,
                    'idSede'         => $request->idSede,
                    'idCliente'      => $request->idCliente,
                    'codigo_pedido'  => $codigo,
                    'numero_cliente' => $telefonoCliente,
                    'nombre_cliente' => $nombreCliente,
                    'idDireccion'    => $request->idDireccion,
                    'latitud'        => $lat,
                    'longitud'       => $lng,
                    'tipo_entrega'   => $request->tipo_entrega ?? 'delivery',
                    // MERCADO PAGO: Pasamos el estado dinámico (pagado o pendiente)
                    'estado_pago'    => $estadoPagoFinal,
                    'estado_pedido'  => 3,
                    'propina'        => $request->propina ?? 0,
                    'costo_envio'    => $request->costo_envio ?? 0,
                    'prioridad'      => $request->prioridad ? 'true' : 'false',
                    'total'          => $request->total,
                    'fecha'          => now(),
                ]);

                // ==========================================
                // B. REGISTRAR DETALLES 
                // ==========================================
                $totalPrecio = 0;
                $pedidosToVender = [];

                foreach ($request->items as $item) {
                    DetallePedidosWeb::create([
                        'idPedido' => $pedidoWeb->id,
                        'idPlato'  => $item['idPlato'],
                        'producto' => "Plato ID " . $item['idPlato'],
                        'cantidad' => $item['cantidad'],
                        'precio'   => $item['precio'],
                        'estado'   => '1'
                    ]);

                    $plato = Plato::find($item['idPlato']);
                    $platoNombre = $plato->nombre ?? 'Plato Desconocido';
                    $precioTotalItem = (float)$item['precio'] * $item['cantidad'];

                    $pedidosToVender[] = (object)[
                        "idPlato"         => $item['idPlato'],
                        "cantidad"        => $item['cantidad'],
                        "descripcion"     => $platoNombre,
                        "valor_unitario"  => (float)$item['precio'] / $factorDivisor,
                        "valor_total"     => $precioTotalItem / $factorDivisor,
                        "precio_unitario" => (float)$item['precio'],
                        "igv"             => $precioTotalItem - ($precioTotalItem / $factorDivisor),
                    ];

                    $totalPrecio += $precioTotalItem;
                }

                $costoEnvio = (float)($request->costo_envio ?? 0);
                if ($costoEnvio > 0) {
                    $pedidosToVender[] = (object)[
                        "idPlato"         => null,
                        "cantidad"        => 1,
                        "descripcion"     => "Servicio de Delivery",
                        "valor_unitario"  => $costoEnvio / $factorDivisor,
                        "valor_total"     => $costoEnvio / $factorDivisor,
                        "precio_unitario" => $costoEnvio,
                        "igv"             => $costoEnvio - ($costoEnvio / $factorDivisor),
                    ];
                    $totalPrecio += $costoEnvio;
                }

                // ==========================================
                // C. REGISTRAR LA VENTA (CABECERA)
                // ==========================================
                $subtotal = $totalPrecio / $factorDivisor;
                $igv = $totalPrecio - $subtotal;
                $total = $totalPrecio;

                $venta = $this->registrarVentaWeb(
                    $pedidoWeb->id,
                    null,
                    $idMetodoPagoVenta,
                    'B',
                    $igv,
                    $subtotal,
                    $total,
                    $request->idCliente
                );

                // ==========================================
                // D. FACTURACIÓN SUNAT Y COMPROBANTE 
                // ==========================================
                $datosCliente = [
                    'tipo_documento'   => '1',
                    'numero_documento' => $dniCliente,
                    'nombre'           => $nombreCliente,
                    'direccion'        => $direccion ? $direccion->direccion : 'Sin dirección'
                ];

                $serieTicket = 'B001';
                $correlativoTicket = '00000000';

                try {
                    $sunatConfig = ConfiguracionHelper::get('sunat');
                    $sunatActivo = $sunatConfig && isset($sunatConfig->estado) && $sunatConfig->estado == 1;

                    if ($sunatActivo) {
                        $datosFactura = [
                            'venta_id'         => $venta->id,
                            'tipo_comprobante' => 'B',
                            'cliente'          => $datosCliente,
                            'detalle'          => collect($pedidosToVender),
                            'subtotal'         => $subtotal,
                            'igv'              => $igv,
                            'total'            => $total,
                        ];

                        $facturacionSunatController = new FacturacionSunatController();
                        $respuesta = $facturacionSunatController->generarFactura($datosFactura);

                        $this->registrarComprobante(
                            $venta,
                            'B',
                            $respuesta['estado'],
                            !empty($respuesta['observaciones']) ? implode(', ', $respuesta['observaciones']) : null,
                            $respuesta['rutaXml'] ?? null,
                            $respuesta['rutaCdr'] ?? null,
                            $referenciaPagoMp // Pasamos la referencia a la boleta si la necesitas
                        );
                    } else {
                        // SUNAT APAGADO: REGISTRO LOCAL
                        $this->registrarComprobante($venta, 'B', 1, null, null, null, $referenciaPagoMp);
                    }

                    $boletaGenerada = Boleta::where('idVenta', $venta->id)->first();
                    if ($boletaGenerada) {
                        $serieTicket = $boletaGenerada->numSerie;
                        $correlativoTicket = $boletaGenerada->numero;
                    } else {
                        throw new \Exception("La boleta no se guardó en la base de datos.");
                    }
                } catch (\Exception $eComprobante) {
                    Log::error("❌ FALLO CRÍTICO AL GUARDAR BOLETA: " . $eComprobante->getMessage());
                    throw new \Exception("Error al generar el comprobante. Por favor, inténtelo nuevamente.");
                }

                // ==========================================
                // E. RESPUESTA FINAL
                // ==========================================
                $ticketData = [
                    'id'                => $venta->id,
                    'serie_correlativo' => $serieTicket . '-' . $correlativoTicket,
                    'tipo_comprobante'  => 'BOLETA DE VENTA',
                    'metodo_pago'       => $nombreMetodoVenta,
                    'fecha'             => date('d/m/Y H:i:s'),
                    'cliente'           => [
                        'nombre'    => $nombreCliente,
                        'documento' => $dniCliente,
                        'direccion' => $datosCliente['direccion'],
                    ],
                    'productos'         => $pedidosToVender,
                    'subtotal'          => round($subtotal, 2),
                    'igv'               => round($igv, 2),
                    'total'             => round($total, 2),
                    'estado_pago'       => $estadoPagoFinal // MERCADO PAGO: 'pagado' o 'pendiente'
                ];

                Log::info("🏁 Venta Web Registrada con Éxito. Ticket: $serieTicket-$correlativoTicket");

                return response()->json([
                    'success' => true,
                    'message' => $estadoPagoFinal === 'pagado' ? '¡Pago exitoso y pedido a cocina!' : 'Pedido registrado y venta generada exitosamente. Pendiente de pago.',
                    'data'    => $pedidoWeb,
                    'ticket'  => $ticketData
                ], 201);
            });
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("🔴 ERROR FATAL EN PAGAR APP CLIENTE: " . $e->getMessage());
            Log::error("Línea: " . $e->getLine() . " Archivo: " . $e->getFile());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    protected function registrarVentaWeb($idPedidoWeb, $idUsuario, $idMetodoPago, $tipoComprobante, $igv, $subtotal, $total, $idCliente)
    {
        $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);

        return Venta::create([
            'idEmpresa'   => $pedidoWeb->idEmpresa ?? 2,
            'idSede'      => $pedidoWeb->idSede ?? 1,
            'idUsuario'   => $idUsuario,
            'idCliente'   => $idCliente,
            'idMetodo'    => $idMetodoPago,
            'idPedido'    => null,
            'idPedidoWeb' => $idPedidoWeb,
            'igv'         => $igv,
            'subTotal'    => $subtotal,
            'descuento'   => 0.00,
            'total'       => $total,
            'fechaVenta'  => now(),
            'documento'   => $tipoComprobante,
            'estado'      => 1,
        ]);
    }

    // MERCADO PAGO: Añadí $referenciaPago al final por si a futuro decides agregar una columna en Boleta para guardar el ID de Mercado Pago
    private function registrarComprobante($venta, $tipoComprobante = 'B', $estado = 1, $observaciones = null, $rutaXml = null, $rutaCdr = null, $referenciaPago = null)
    {
        $usuario = Auth::user();
        $idEmpresa = ($usuario && isset($usuario->idEmpresa)) ? $usuario->idEmpresa : $venta->idEmpresa;
        $idSede = ($usuario && isset($usuario->idSede)) ? $usuario->idSede : $venta->idSede;

        if (!$idEmpresa || !$idSede) {
            throw new \Exception("No se pudo determinar la Empresa o Sede para la Boleta.");
        }

        $tipoSunat = '03';

        try {
            $datosSerie = DB::transaction(function () use ($idEmpresa, $idSede, $tipoSunat) {
                $serie = SerieCorrelativo::where('idEmpresa', $idEmpresa)
                    ->where('idSede', $idSede)
                    ->where('tipo_documento_sunat', $tipoSunat)
                    ->where('is_default', 1)
                    ->lockForUpdate()
                    ->first();

                if (!$serie) {
                    throw new \Exception("No se encontró la serie de Boleta por defecto (03) para la Sede $idSede.");
                }

                $serie->correlativo_actual += 1;
                $serie->usado = 1;
                $serie->save();

                return [
                    'serie' => $serie->serie,
                    'correlativo' => $serie->correlativo_actual
                ];
            });

            $numeroComprobante = str_pad($datosSerie['correlativo'], 8, '0', STR_PAD_LEFT);
            $serieComprobante = $datosSerie['serie'];

            $boleta = Boleta::where('idVenta', $venta->id)->first() ?? new Boleta();
            $boleta->idEmpresa = $idEmpresa;
            $boleta->idVenta = $venta->id;
            $boleta->numSerie = $serieComprobante;
            $boleta->numero = $numeroComprobante;
            $boleta->estado = $estado;

            // Si quieres guardar el ID de Mercado Pago en las observaciones de la boleta, puedes descomentar esto:
            // if ($referenciaPago) { $observaciones = trim($observaciones . " - Pago MP: " . $referenciaPago); }

            $boleta->observaciones = $observaciones;
            $boleta->rutaXml = $rutaXml;
            $boleta->rutaCdr = $rutaCdr;
            $boleta->save();
        } catch (\Exception $e) {
            throw new \Exception("Error al generar la boleta local: " . $e->getMessage());
        }
    }
    public function getMisPedidos(Request $request)
    {
        try {
            $user = $request->user();

            // 1. Log para verificar si el usuario llega bien
            $cliente = Cliente::where('idPersona', $user->id)->first();

            $idCliente = $cliente->id;

            // 2. Ejecutamos la consulta
            $pedidos = PedidosWebRegistro::where('idCliente', $idCliente)
                ->with(['detallesPedido.plato'])
                ->get();



            return response()->json([
                'status' => 'success',
                'data' => $pedidos
            ], 200);
        } catch (\Exception $e) {
            // 3. LOG CRÍTICO: Aquí verás el error real en storage/logs/laravel.log
            Log::error('Error en getMisPedidos: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error interno en el servidor'
            ], 500);
        }
    }
}
