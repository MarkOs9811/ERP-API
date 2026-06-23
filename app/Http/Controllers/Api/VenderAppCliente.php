<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\api\VenderController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\campanaPromo;
use App\Models\Cliente;
use App\Models\DetallePedidosWeb;
use App\Models\Direccione;
use App\Models\Factura;
use App\Models\MetodoPago;
use App\Models\PedidosWebRegistro;
use App\Models\Plato;
use App\Models\PromocionesApp;
use App\Models\SerieCorrelativo;
use App\Models\Venta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
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

        ///////////////////////////////////////////
        // VALIDACION SI EL PLATO ESTA ACTIVO AUN
        ///////////////////////////////////////////
        foreach ($request->items as $item) {
            $esPromocion = isset($item['tipo']) && $item['tipo'] === 'promocion';

            if ($esPromocion) {
                $prodValidador = PromocionesApp::where('id', $item['idPlato'])
                    ->where('estado', 1)
                    ->where('enWeb', 1)
                    ->first();
                $nombreError = $prodValidador->titulo ?? "Promoción ID: " . $item['idPlato'];
            } else {
                $prodValidador = Plato::where('id', $item['idPlato'])
                    ->where('estado', 1)
                    ->where('enWeb', 1)
                    ->first();
                $nombreError = $prodValidador->nombre;
            }

            if (!$prodValidador) {
                Log::warning("Intento de compra de producto no disponible", ['item' => $item]);
                return response()->json([
                    'success' => false,
                    'message' => "El producto '{$nombreError}' ya no se encuentra disponible. Por favor, retírelo de su carrito."
                ], 422);
            }
        }

        // =========================================================
        // 4. MERCADO PAGO: PROCESAMIENTO DEL TOKEN ANTES DE LA BD
        // =========================================================
        $estadoPagoFinal = 'pendiente';
        $referenciaPagoMp = null;

        if ($request->filled('token_mercadopago')) {
            try {
                Log::info('ACCESS TOKEN', [
                    'token' => substr(env('MERCADOPAGO_ACCESS_TOKEN'), 0, 20)
                ]);
                MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
                $client = new PaymentClient();

                // El $request->total aquí es el NETO exacto que paga el cliente (comida - cupón + envio + propina)
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

                if ($payment->status !== 'approved') {
                    Log::warning("Pago Rechazado. Estado: {$payment->status} | Motivo exacto: {$payment->status_detail}");
                    return response()->json([
                        'success' => false,
                        'message' => 'La tarjeta fue rechazada o el pago no pudo procesarse. Verifica tus fondos o intenta con otra tarjeta.'
                    ], 400);
                }

                $estadoPagoFinal = 'pagado';
                $referenciaPagoMp = $payment->id;
                Log::info("Cobro Mercado Pago Exitoso. ID: " . $referenciaPagoMp);
            } catch (MPApiException $e) {

                Log::error('❌ MP ERROR COMPLETO', [
                    'message' => $e->getMessage(),
                    'status' => $e->getApiResponse()
                        ? $e->getApiResponse()->getStatusCode()
                        : null,
                    'content' => $e->getApiResponse()
                        ? $e->getApiResponse()->getContent()
                        : null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Error Mercado Pago'
                ], 400);
            } catch (\Exception $e) {
                Log::error("Error general API Mercado Pago: " . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Hubo un error de conexión al procesar la tarjeta. Por favor intente nuevamente.'
                ], 500);
            }
        }

        // =========================================================
        // 5. INICIO DE TRANSACCIÓN ESTRÍCTA
        // =========================================================
        try {
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
                $totalPrecioBruto = 0; // Suma de items + delivery sin aplicar descuento
                $pedidosToVender = [];

                foreach ($request->items as $item) {
                    $esPromocion = isset($item['tipo']) && $item['tipo'] === 'promocion';
                    $idPlatoReal     = $esPromocion ? null : $item['idPlato'];
                    $idPromocionReal = $esPromocion ? $item['idPlato'] : null;

                    DetallePedidosWeb::create([
                        'idPedido'    => $pedidoWeb->id,
                        'idPlato'     => $idPlatoReal,
                        'idPromocion' => $idPromocionReal,
                        'producto'    => $esPromocion ? "Promo ID " . $idPromocionReal : "Plato ID " . $idPlatoReal,
                        'cantidad'    => $item['cantidad'],
                        'precio'      => $item['precio'],
                        'estado'      => '1'
                    ]);

                    if ($esPromocion) {
                        $promocion = PromocionesApp::find($idPromocionReal);
                        $productoNombre = $promocion->titulo ?? 'Promoción Desconocida';
                    } else {
                        $plato = Plato::find($idPlatoReal);
                        $productoNombre = $plato->nombre ?? 'Plato Desconocido';
                    }

                    $precioTotalItem = (float)$item['precio'] * $item['cantidad'];

                    $pedidosToVender[] = (object)[
                        "idPlato"         => $idPlatoReal,
                        "idPromocion"     => $idPromocionReal,
                        "cantidad"        => $item['cantidad'],
                        "descripcion"     => $productoNombre,
                        "valor_unitario"  => (float)$item['precio'] / $factorDivisor,
                        "valor_total"     => $precioTotalItem / $factorDivisor,
                        "precio_unitario" => (float)$item['precio'],
                        "igv"             => $precioTotalItem - ($precioTotalItem / $factorDivisor),
                    ];

                    $totalPrecioBruto += $precioTotalItem;
                }

                $costoEnvio = (float)($request->costo_envio ?? 0);
                if ($costoEnvio > 0) {
                    $pedidosToVender[] = (object)[
                        "idPlato"         => null,
                        "idPromocion"     => null,
                        "cantidad"        => 1,
                        "descripcion"     => "Servicio de Delivery",
                        "valor_unitario"  => $costoEnvio / $factorDivisor,
                        "valor_total"     => $costoEnvio / $factorDivisor,
                        "precio_unitario" => $costoEnvio,
                        "igv"             => $costoEnvio - ($costoEnvio / $factorDivisor),
                    ];
                    $totalPrecioBruto += $costoEnvio;
                }

                // ==========================================
                // C. REGISTRAR LA VENTA (CÁLCULOS NETOS SUNAT)
                // ==========================================
                $montoDescuento = (float)($request->monto_descuento ?? 0);

                // 1. Limpiamos el código recibido (quitamos espacios basura accidentales)
                $codigoRecibido = trim($request->codigo_cupon);

                Log::info("🔍 REVISIÓN CUPÓN -> Recibido desde React: '{$codigoRecibido}'");

                if (!empty($codigoRecibido)) {
                    $cuponAplicado = CampanaPromo::where('codigo_cupon', $codigoRecibido)
                        ->orWhere('codigo_cupon', strtoupper($codigoRecibido))
                        ->orWhere('codigo_cupon', strtolower($codigoRecibido))
                        ->first();

                    if ($cuponAplicado) {
                        //  LA MAGIA CONTRA EL NULL ESTÁ AQUÍ 
                        $cuponAplicado->usados = ($cuponAplicado->usados ?? 0) + 1;
                        $cuponAplicado->save();

                        Log::info("CUPÓN ACTUALIZADO -> ID: {$cuponAplicado->id} | Código: {$cuponAplicado->codigo_cupon} | Nuevos Usos: {$cuponAplicado->usados}");
                    } else {
                        Log::warning("⚠️ ALERTA CUPÓN -> Se recibió el código '{$codigoRecibido}' pero NO se encontró ninguna coincidencia en la columna 'codigo_cupon' de la tabla 'campanaPromo'.");
                    }
                }
                $totalNeto = $totalPrecioBruto - $montoDescuento; // Esto es lo que cobraste REALMENTE por comida+delivery

                // Base imponible e IGV calculados sobre el Total Neto
                $subtotalNeto = $totalNeto / $factorDivisor;
                $igvNeto = $totalNeto - $subtotalNeto;

                // Grabamos en tabla Venta pasándole el descuento
                $venta = $this->registrarVentaWeb(
                    $pedidoWeb->id,
                    null,
                    $nombreMetodoVenta,
                    'B',
                    $igvNeto,
                    $subtotalNeto,
                    $totalNeto,
                    $request->idCliente,
                    $montoDescuento // <-- SE ENVÍA A LA FUNCIÓN
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
                            'subtotal'         => $subtotalNeto,
                            'igv'              => $igvNeto,
                            'descuento'        => $montoDescuento, // La SUNAT necesita saber cuánto descontaste
                            'total'            => $totalNeto,
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
                            $referenciaPagoMp
                        );
                    } else {
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
                    'subtotal'          => round($subtotalNeto, 2),
                    'descuento'         => round($montoDescuento, 2),
                    'igv'               => round($igvNeto, 2),
                    'total'             => round($totalNeto, 2),
                    'estado_pago'       => $estadoPagoFinal
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

    // Actualizamos la firma para recibir el descuento
    protected function registrarVentaWeb($idPedidoWeb, $idUsuario, $nombreMetodoVenta, $tipoComprobante, $igv, $subtotal, $total, $idCliente, $descuento = 0.00)
    {
        $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);

        return Venta::create([
            'idEmpresa'   => $pedidoWeb->idEmpresa ?? 2,
            'idSede'      => $pedidoWeb->idSede ?? 1,
            'idUsuario'   => $idUsuario,
            'idCliente'   => $idCliente,
            'idMetodo'    => $nombreMetodoVenta,
            'idPedido'    => null,
            'idPedidoWeb' => $idPedidoWeb,
            'igv'         => $igv,
            'subTotal'    => $subtotal,
            'descuento'   => $descuento, // 🎯 AQUÍ SE GUARDA TU DESCUENTO
            'total'       => $total,     // Este total ya es el NETO
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
    // COMROBAR EL CUPON
    public function aplicarCupon(Request $request, $codigo_cupon)
    {
        try {

            $totalCarrito = $request->query('total', 0);

            // Buscamos el cupón exacto
            $cupon = campanaPromo::where('codigo_cupon', strtoupper($codigo_cupon))
                ->where('estado', 1)
                ->where('tipo', 'cupon')
                ->where('fecha_inicio', '<=', now())
                ->where('fecha_fin', '>=', now())
                ->first();

            // 1. Validar que exista y esté en fecha
            if (!$cupon) {
                return response()->json(['success' => false, 'message' => 'Cupón inválido o expirado.'], 404);
            }

            // 2. Validar Monto Mínimo de Compra
            if ($cupon->monto_minimo_compra > 0 && $totalCarrito < $cupon->monto_minimo_compra) {
                return response()->json(['success' => false, 'message' => "Este cupón requiere una compra mínima de S/ {$cupon->monto_minimo_compra}."], 400);
            }

            // 3. Validar Límite de Uso (Stock)
            if (!is_null($cupon->limite_uso) && $cupon->usados >= $cupon->limite_uso) {
                return response()->json(['success' => false, 'message' => 'Este cupón se ha agotado.'], 400);
            }

            // Si pasa todas las validaciones, lo devolvemos al Front!
            return response()->json([
                'success' => true,
                'message' => '¡Cupón aplicado con éxito!',
                'data'    => [
                    'id'             => $cupon->id,
                    'codigo'         => $cupon->codigo_cupon,
                    'descuento'      => $cupon->valor_descuento,
                    'tipo_descuento' => $cupon->tipo_descuento,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error en aplicarCupon: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Error interno al validar el cupón.'], 500);
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
                ->with('conductor.empleado.persona', 'detallesPedido.promociones.plato')
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
