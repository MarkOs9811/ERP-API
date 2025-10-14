<?php

namespace App\Http\Controllers\api;

use App\Events\PedidoCocinaEvent;
use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Api\FacturacionSunatController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\CuentasPorCobrar;
use App\Models\Cuota;
use App\Models\DetallePedido;
use App\Models\detallePedidosWeb;
use App\Models\Empresa;
use App\Models\EstadoPedido;
use App\Models\Factura;
use App\Models\Inventario;
use App\Models\Mesa;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoMesaRegistro;
use App\Models\PedidosWebRegistro;
use App\Models\Persona;
use App\Models\Plato;
use App\Models\PreventaMesa;
use App\Models\Venta;
use App\Services\EstadoPedidoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VenderController extends Controller
{
    public function getMesas()
    {
        try {
            $mesas = Mesa::get();
            return response()->json(['success' => true, 'data' => $mesas], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener las mesas: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al obtener las mesas'], 500);
        }
    }

    public function getPlatos()
    {
        try {
            $productos = Plato::with('categoria')->where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $productos], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener los platos: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al obtener los platos'], 500);
        }
    }

    public function addPlatosPreVentaMesa(Request $request)
    {
        $data = $request->input('pedidos'); // Se espera un array de pedidos en el campo 'pedidos'
        // Si el array viene vacío, salir sin registrar nada
        if (empty($data)) {
            return response()->json([
                'success' => true,
                'message' => 'No se enviaron platos para registrar.'
            ], 200);
        }

        Log::info($data);
        // Validar la estructura de los datos entrantes
        $validated = $request->validate([
            'pedidos' => 'required|array',
            'pedidos.*.idCaja' => 'required|integer|exists:cajas,id',
            'pedidos.*.idPlato' => 'required|integer|exists:platos,id',
            'pedidos.*.idMesa' => 'required|integer|exists:mesas,id',
            'pedidos.*.cantidad' => 'required|integer|min:1',
            'pedidos.*.precio' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();
            $user = auth()->user();
            // Buscar si ya existe un pedido abierto para la mesa y usuario
            $idMesa = $data[0]['idMesa'];
            $pedidoExistente = PedidoMesaRegistro::where('idUsuario', $user->id)
                ->where('estado', 0)
                ->whereHas('preVentas', function ($q) use ($idMesa) {
                    $q->where('idMesa', $idMesa);
                })
                ->first();

            if ($pedidoExistente) {
                $idPedido = $pedidoExistente->id;
            } else {
                // Registrar nuevo pedido en PedidoMesaRegistro
                $registroPedido = new PedidoMesaRegistro();
                $registroPedido->idUsuario = $user->id;
                $registroPedido->fechaPedido = now();
                $registroPedido->estado = 0;
                $registroPedido->save();

                $idPedido = $registroPedido->id;
            }
            $detallePlatosArray = [];
            foreach ($data as $pedido) {


                $preventaExistente = PreventaMesa::where('idCaja', $pedido['idCaja'])
                    ->where('idPlato', $pedido['idPlato'])
                    ->where('idMesa', $pedido['idMesa'])
                    ->where('idUsuario', $user->id)
                    ->first();

                if ($preventaExistente) {
                    // Si ya existe el plato en preventa, sumamos la cantidad
                    $preventaExistente->cantidad += $pedido['cantidad'];
                    $preventaExistente->precio = $pedido['precio']; // Opcional: actualizar el precio
                    $preventaExistente->idPedido = $idPedido;
                    $preventaExistente->save();
                } else {
                    // Si no existe, creamos un nuevo registro
                    $preventaMesa = new PreventaMesa();
                    $preventaMesa->idUsuario = $user->id;
                    $preventaMesa->idCaja = $pedido['idCaja'];
                    $preventaMesa->idPlato = $pedido['idPlato'];
                    $preventaMesa->idMesa = $pedido['idMesa'];
                    $preventaMesa->cantidad = $pedido['cantidad'];
                    $preventaMesa->precio = $pedido['precio'];
                    $preventaMesa->idPedido = $idPedido;
                    $preventaMesa->save();
                }

                // Cambiar el estado de la mesa a ocupado (estado = 0)
                $idMesa = $pedido['idMesa'];
                $mesa = Mesa::find($idMesa);
                if (!$mesa) {
                    return response()->json(['success' => false, 'message' => 'Mesa no encontrada al cambiar el estado'], 422);
                }

                $mesa->estado = 0;
                $mesa->save();

                $plato = Plato::find($pedido['idPlato']);
                if (!$plato) {
                    return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 422);
                }

                $detallePlatosArray[] = [
                    'nombre' => $plato->nombre, // ✅ Nombre del plato
                    'cantidad' => $pedido['cantidad']
                ];
            }
            // Ahora sí, convertir todos los platos en un solo JSON
            $detallePlatos = json_encode($detallePlatosArray);

            // Buscar EstadoPedido abierto para este pedido
            $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
                ->where('estado', 0)
                ->first();

            if ($estadoPedido) {
                // Decodificar el JSON actual
                $detalleActual = json_decode($estadoPedido->detalle_platos, true) ?? [];

                // Indexar por nombre para sumar cantidades fácilmente
                $platosIndexados = [];
                foreach ($detalleActual as $item) {
                    $platosIndexados[$item['nombre']] = $item['cantidad'];
                }

                // Sumar o agregar los nuevos platos
                foreach ($detallePlatosArray as $nuevo) {
                    if (isset($platosIndexados[$nuevo['nombre']])) {
                        $platosIndexados[$nuevo['nombre']] += $nuevo['cantidad'];
                    } else {
                        $platosIndexados[$nuevo['nombre']] = $nuevo['cantidad'];
                    }
                }

                // Reconstruir el array para guardar como JSON
                $nuevoDetalle = [];
                foreach ($platosIndexados as $nombre => $cantidad) {
                    $nuevoDetalle[] = [
                        'nombre' => $nombre,
                        'cantidad' => $cantidad
                    ];
                }

                $estadoPedido->detalle_platos = json_encode($nuevoDetalle);
                $estadoPedido->save();
                // Lanzar el evento en tiempo real
                event(new PedidoCocinaEvent(
                    $estadoPedido->id,
                    $nuevoDetalle,
                    'mesa',
                    $estadoPedido->estado
                ));
            } else {
                // Crear uno nuevo solo si no existe
                $estadoService = new EstadoPedidoController(
                    'mesa',
                    $data[0]['idCaja'],
                    $detallePlatos,
                    $idPedido,
                    null // detalle_cliente no es necesario para mesas
                );
                $estadoService->registrar();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pedidos registrados exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar los pedidos: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function getPreventaMesa($idMesa, $idCaja)
    {
        try {
            $user = Auth()->user();
            log::info($user->id); // Ahora correctamente accedemos al ID del usuario
            $preVenta = PreventaMesa::with('usuario', 'mesa', 'caja', 'plato')->where('idCaja', $idCaja)
                ->where('idMesa', $idMesa)
                ->where('idUsuario', $user->id)
                ->get();
            return response()->json(['success' => true, 'data' => $preVenta, 'message' => 'Preventa Encontrada'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 200);
        }
    }


    public function eliminarPreventaMesa($idMesa)
    {
        try {
            // Buscar todas las preventas que coincidan con el idMesa
            $preventasMesa = PreventaMesa::where('idMesa', $idMesa);

            // Verificar si existen registros para eliminar
            if (!$preventasMesa->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron preventas para la mesa especificada.'
                ], 404);
            }

            // Obtener el idPedido de la primera preventa (todas deberían tener el mismo)
            $primerPreventa = $preventasMesa->first();
            $idPedido = $primerPreventa ? $primerPreventa->idPedido : null;

            // Cambiar el estado de la mesa a disponible
            $mesa = Mesa::find($idMesa);
            if ($mesa) {
                $mesa->estado = 1;
                $mesa->save();
            }

            // Eliminar los registros encontrados en PreventaMesa
            $preventasMesa->delete();

            // Eliminar el registro de EstadoPedido relacionado
            if ($idPedido) {
                $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)->where('estado', 0)->first();
                if ($estadoPedido) {
                    $idEstadoPedido = $estadoPedido->id;
                    $estadoPedido->delete();

                    // Lanzar evento indicando que ya no hay platos para esa mesa
                    event(new PedidoCocinaEvent(
                        $idEstadoPedido,
                        [], // Array vacío porque ya no hay platos
                        'mesa',
                        0 // Estado 0 (puedes ajustar según tu lógica)
                    ));
                    Log::info("EstadoPedido eliminado al eliminar todas las preventas de la mesa", [
                        'idMesa' => $idMesa,
                        'idPedidoMesa' => $idPedido
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Preventas y estado de cocina eliminados correctamente.'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al eliminar preventas y estado de cocina: ' . $e->getMessage(), [
                'idMesa' => $idMesa
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar las preventas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getMesasFree()
    {
        try {
            $mesasFree = Mesa::where('estado', '1')->get();
            return response()->json(['success' => true, 'mesasFree' => $mesasFree, 'message' => 'Mesas disponibles'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false,  'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    public function transferirToMesa($idMesa, Request $request)
    {
        try {
            // Obtener el ID de la mesa de destino desde la solicitud
            $mesaDestino = $request->mesaDestino;

            // Verificar que la mesa de destino exista
            $mesaDestinoObj = Mesa::find($mesaDestino);
            if (!$mesaDestinoObj) {
                return response()->json(['success' => false, 'message' => 'La mesa de destino no existe'], 404);
            }

            // Cambiar el estado de la mesa original a disponible (estado = 0)
            $mesa = Mesa::find($idMesa);
            if ($mesa) {
                $mesa->estado = 1;
                $mesa->save();
            } else {
                return response()->json(['success' => false, 'message' => 'La mesa original no existe'], 404);
            }

            // Obtener todos los registros de PreventaMesa relacionados con la mesa original
            $preventas = PreventaMesa::where('idMesa', $idMesa)->get();

            // Actualizar el idMesa de cada registro encontrado
            foreach ($preventas as $preventa) {
                $preventa->idMesa = $mesaDestino;
                $preventa->save();
            }

            // Cambiar el estado de la mesa de destino a ocupada (estado = 1)
            $mesaDestinoObj->estado = 0;
            $mesaDestinoObj->save();

            return response()->json(['success' => true, 'mesaOrigen' => $mesa, 'mesaDestino' => $mesaDestinoObj, 'message' => 'Transferencia Desde '], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function deletePlatoPreventa($idProducto, $idMesa)
    {
        try {
            Log::info("Intentando eliminar plato preventa", ['idProducto' => $idProducto, 'idMesa' => $idMesa]);

            // Buscar el plato a eliminar
            $platoToDelete = PreventaMesa::where('id', $idProducto)->where('idMesa', $idMesa)->first();

            // Verificar si el plato existe
            if (!$platoToDelete) {
                Log::warning("Plato no encontrado para eliminar", ['idProducto' => $idProducto, 'idMesa' => $idMesa]);
                return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 404);
            }

            // Guardar el idPedido antes de eliminar
            $idPedido = $platoToDelete->idPedido;
            Log::info("ID de pedido asociado al plato a eliminar", ['idPedido' => $idPedido]);

            // Eliminar el plato
            $platoToDelete->delete();
            Log::info("Plato eliminado correctamente", ['idProducto' => $idProducto]);

            // Actualizar el detalle de platos en EstadoPedido
            $platosRestantes = PreventaMesa::where('idPedido', $idPedido)->get();
            $detallePlatosArray = [];
            foreach ($platosRestantes as $plato) {
                $detallePlatosArray[] = [
                    'nombre' => $plato->plato->nombre ?? 'Plato desconocido',
                    'cantidad' => $plato->cantidad
                ];
            }
            Log::info("Detalle de platos actualizado tras eliminación", ['detallePlatos' => $detallePlatosArray]);

            $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
                ->where('estado', 0)
                ->first();

            if ($estadoPedido) {
                $estadoPedido->detalle_platos = json_encode($detallePlatosArray);
                $estadoPedido->save();
                Log::info("EstadoPedido actualizado correctamente", ['idEstadoPedido' => $estadoPedido->id]);

                // Lanzar evento en tiempo real
                event(new PedidoCocinaEvent(
                    $estadoPedido->id,
                    $detallePlatosArray,
                    'mesa',
                    $estadoPedido->estado
                ));
                Log::info("Evento PedidoCocinaEvent lanzado", [
                    'idEstadoPedido' => $estadoPedido->id,
                    'detallePlatos' => $detallePlatosArray
                ]);
            } else {
                Log::warning("No se encontró EstadoPedido para actualizar", ['idPedidoMesa' => $idPedido]);
            }

            // Verificar si existen otros registros en PreventaMesa con el mismo idMesa
            $existenOtrosPlatos = PreventaMesa::where('idMesa', $idMesa)->exists();
            Log::info("¿Existen otros platos en la mesa?", ['idMesa' => $idMesa, 'existen' => $existenOtrosPlatos]);

            // Si no existen más registros con el mismo idMesa, actualizar el estado de la mesa
            if (!$existenOtrosPlatos) {
                $mesa = Mesa::find($idMesa);
                if ($mesa) {
                    $mesa->estado = 1; // Actualizamos el estado de la mesa a "disponible"
                    $mesa->save();
                    Log::info("Estado de la mesa actualizado a disponible", ['idMesa' => $idMesa]);
                } else {
                    Log::warning("No se encontró la mesa para actualizar estado", ['idMesa' => $idMesa]);
                }
            }

            return response()->json(['success' => true, 'message' => 'Plato Eliminado Correctamente'], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar el plato de preventa: ' . $e->getMessage(), [
                'idProducto' => $idProducto,
                'idMesa' => $idMesa
            ]);
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // TODO PARA PODER REALIZAR LA VENTA TANTO PARA CREDITO O AL CONTADO
    public function venderTodo(Request $request)
    {
        try {

            // Obtener los datos del formulario
            $idCaja = $request->input('idCaja');
            $idMesa = $request->input('idMesa');
            $nombreMetodo = $request->input('metodoPago');
            $tipoComprobante = $request->input('comprobante');
            $idUsuario = $request->input('idUsuario');
            $datosCliente = $request->input('datosCliente');
            $pedidoToLlevar = $request->input('pedidoToLlevar');
            $idPedidoWeb = $request->input('idPedidoWeb');
            $tipoVenta = $request->input('tipoVenta');
            $numeroCuotas = $request->input('cuotas');



            $dniCliente = null;
            $ClienteId = null;
            $idUsuarioAuth = Auth::id();

            if ($idUsuarioAuth != $idUsuario) {

                return response()->json(['success' => false, 'message' => 'Su codigo no pertenece a esta cuenta.']);
            }

            // Obtener el método de pago
            $metodoPago = MetodoPago::where('nombre', $nombreMetodo)->first();

            if (!$metodoPago) {

                return response()->json(['success' => false, 'message' => 'Método de pago no encontrado.']);
            }

            $metodoPagoId = $metodoPago->id;


            // Buscar la caja
            $caja = Caja::findOrFail($idCaja);


            $pedidosToVender = "";



            // Lógica para diferentes tipos de venta
            if ($tipoVenta === 'llevar') {


                if (empty($pedidoToLlevar) || !is_array($pedidoToLlevar)) {

                    return response()->json([
                        'success' => false,
                        'message' => 'No se recibieron pedidos válidos para llevar.',
                    ]);
                }
                $pedidosToVender = collect($pedidoToLlevar)->map(function ($pedido) {
                    return (object)[
                        "idPlato" => $pedido['id'],
                        "cantidad" => $pedido['cantidad'],
                        "descripcion" => $pedido['nombre'],
                        "valor_unitario" => (float)$pedido['precio'] / 1.18,
                        "valor_total" => (float)$pedido['precio'] * $pedido['cantidad'] / 1.18,
                        "precio_unitario" => (float)$pedido['precio'],
                        "igv" => ((float)$pedido['precio'] * $pedido['cantidad']) - ((float)$pedido['precio'] * $pedido['cantidad'] / 1.18),
                    ];
                });
            } elseif ($tipoVenta === 'web') {


                $pedidosToVender = DetallePedidosWeb::where('idPedido', $idPedidoWeb)->get();

                $pedidosToVender = $pedidosToVender->map(function ($preventa) {
                    $platoNombre = Plato::find($preventa->idPlato)->nombre ?? 'Plato desconocido';
                    return (object)[
                        "idPlato" => $preventa->idPlato,
                        "cantidad" => $preventa->cantidad,
                        "descripcion" => $platoNombre,
                        "valor_unitario" => (float)$preventa->precio / 1.18,
                        "valor_total" => (float)$preventa->precio * $preventa->cantidad / 1.18,
                        "precio_unitario" => (float)$preventa->precio,
                        "igv" => ((float)$preventa->precio * $preventa->cantidad) - ((float)$preventa->precio * $preventa->cantidad / 1.18),
                    ];
                });
            } else {
                // Venta de mesa

                $pedidosToVender = PreventaMesa::where('idCaja', $idCaja)
                    ->where('idMesa', $idMesa)
                    ->get();

                if ($pedidosToVender->isEmpty()) {

                    return response()->json([
                        'success' => false,
                        'message' => 'No hay preventas para la caja y mesa especificadas.',
                    ]);
                }

                $pedidosToVender = $pedidosToVender->map(function ($preventa) {
                    $platoNombre = Plato::find($preventa->idPlato)->nombre ?? 'Plato desconocido';


                    return (object)[
                        "idPlato" => $preventa->idPlato,
                        "cantidad" => $preventa->cantidad,
                        "descripcion" => $platoNombre,
                        "valor_unitario" => (float)$preventa->precio / 1.18,
                        "valor_total" => (float)$preventa->precio * $preventa->cantidad / 1.18,
                        "precio_unitario" => (float)$preventa->precio,
                        "igv" => ((float)$preventa->precio * $preventa->cantidad) - ((float)$preventa->precio * $preventa->cantidad / 1.18),
                    ];
                });
            }

            // Iniciar transacción
            DB::beginTransaction();


            // Crear nuevo pedido
            $nuevoPedido = $this->crearNuevoPedido($tipoVenta);


            $totalPrecio = 0;

            $detallePlatosArray = [];
            foreach ($pedidosToVender as $preventa) {


                $producto = Plato::find($preventa->idPlato);
                if (!$producto) {

                    throw new \Exception('Producto no encontrado.');
                }

                $precioUnitario = $this->obtenerPrecioUnitario($preventa->idPlato);


                if ($tipoVenta !== 'web') {

                    $this->crearDetallePedido($nuevoPedido->id, $preventa->idPlato, $preventa->cantidad, $precioUnitario);
                }


                $totalPrecio += $preventa->cantidad * $precioUnitario;
                $detallePlatosArray[] = [
                    'nombre' => $producto->nombre, // ✅ Nombre del plato
                    'cantidad' => $preventa->cantidad
                ];
            }

            // ENVIAR Y REISTRAR EL ESTADO DEL PEDIDO A COCINA
            if ($tipoVenta === 'llevar') {
                // Ahora sí, convertir todos los platos en un solo JSON
                $detallePlatos = json_encode($detallePlatosArray);
                $estadoService = new EstadoPedidoController(
                    'llevar',
                    $idCaja,
                    $detallePlatos,
                    $nuevoPedido->id,
                    null // detalle_cliente no es necesario para mesas
                );
                $estadoService->registrar();
            }

            // Calcular totales
            $igv = $totalPrecio * 0.18;
            $subtotal = $totalPrecio - $igv;
            $total = $totalPrecio;


            // Procesar cliente según tipo de comprobante
            if ($tipoComprobante === 'F') {

                $dniCliente = $datosCliente['ruc'];
                if (!$dniCliente || !$datosCliente['razonSocial'] || !$datosCliente['direccion']) {

                    throw new \Exception('Debe proporcionar todos los datos del cliente para una factura.');
                }
                $ClienteId = $this->obtenerORegistrarCliente($dniCliente, $datosCliente);
            } elseif ($tipoComprobante === 'B') {

                if (isset($datosCliente['dni'])) {
                    $dniCliente = $datosCliente['dni'];
                    $ClienteId = $this->obtenerORegistrarCliente($dniCliente, $datosCliente);
                } else {

                    $datosCliente = [
                        'tipo_documento' => '0',
                        'numero_documento' => '00000000',
                        'nombre' => 'CLIENTE GENERICO',
                    ];
                }
            }

            // Eliminar preventas (solo para ventas de mesa)
            if ($tipoVenta === 'mesa') {

                $deleted = PreventaMesa::where('idCaja', $idCaja)
                    ->where('idMesa', $idMesa)
                    ->delete();
            }

            // Cambiar estado de la mesa si es venta de mesa
            if ($tipoVenta === 'mesa') {

                $mesaEncontrar = Mesa::find($idMesa);

                if (!$mesaEncontrar) {

                    return response()->json([
                        'success' => false,
                        'message' => 'Mesa no encontrada.',
                    ], 404);
                }

                $mesaEncontrar->estado = 1;
                $mesaEncontrar->save();
            }

            // Registrar la venta según tipo
            if ($tipoVenta === 'web') {

                $venta = $this->registrarVentaWeb($idPedidoWeb, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);


                $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);
                $pedidoWeb->estado_pedido = 6;
                $pedidoWeb->estado_pago = "pagado";
                $pedidoWeb->save();


                // Notificar al cliente
                $request = new \Illuminate\Http\Request();
                $request->merge([
                    'numero_cliente' => $pedidoWeb->numero_cliente,
                    'estado_pedido' => $pedidoWeb->estado_pedido,
                    'codigo_pedido' => $pedidoWeb->codigo_pedido,
                ]);


                $controller = new PedidosWebController();
                $controller->notificarEstadoCliente($request);
            } else {

                $venta = $this->registrarVenta($nuevoPedido->id, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
            }


            // Procesar crédito si aplica
            if (in_array($metodoPago->nombre, ['credito', 'tarjeta credito'])) {

                $cuentasPorCobrar = $this->registrarCuentasPorCobrar($venta, $ClienteId, $idUsuario, $total, $numeroCuotas);

                $this->registrarCuotas($cuentasPorCobrar->id, $numeroCuotas, $total);
            }

            // Actualizar caja
            $caja->montoVendido += $total;
            $caja->save();

            // Procesar SUNAT si está activo
            $sunatConfig = ConfiguracionHelper::get('sunat');
            $sunatActivo = $sunatConfig && $sunatConfig->estado == 1;


            if ($sunatActivo) {


                $datosFactura = [
                    'venta_id' => $venta->id,
                    'tipo_comprobante' => $tipoComprobante,
                    'cliente' => $datosCliente,
                    'detalle' => $pedidosToVender,
                    'subtotal' => $subtotal,
                    'igv' => $igv,
                    'total' => $total,
                ];



                $facturacionSunatController = new FacturacionSunatController();
                $respuesta = $facturacionSunatController->generarFactura($datosFactura);

                $estado = $respuesta['estado'];
                $observaciones = !empty($respuesta['observaciones']) ? implode(', ', $respuesta['observaciones']) : null;
                $rutaXml = $respuesta['rutaXml'] ?? null;
                $rutaCdr = $respuesta['rutaCdr'] ?? null;



                $this->registrarComprobante($venta, $tipoComprobante, $estado, $observaciones, $rutaXml, $rutaCdr);
            }

            DB::commit();
            Log::info('==== VENTA FINALIZADA CON ÉXITO ====');

            return response()->json([
                'success' => true,
                'message' => 'Venta realizada correctamente.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("ERROR EN VENTA: " . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'error' => true,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    private function crearNuevoPedido($tipoVenta)
    {
        $nuevoPedido = new Pedido();
        $nuevoPedido->fechaPedido = now();
        $nuevoPedido->estado = 1;
        $nuevoPedido->tipoVenta = $tipoVenta;
        $nuevoPedido->save();
        return $nuevoPedido;
    }

    private function obtenerPrecioUnitario($idPlato)
    {
        $productoInventario = Plato::findOrFail($idPlato);
        return $productoInventario->precio;
    }

    private function crearDetallePedido($idPedido, $idPlato, $cantidad, $precioUnitario)
    {
        $detallePedido = new DetallePedido();
        $detallePedido->idPedido = $idPedido;
        $detallePedido->idPlato = $idPlato;
        $detallePedido->cantidad = $cantidad;
        $detallePedido->precio_unitario = $precioUnitario;
        $detallePedido->estado = 1;
        $detallePedido->save();
    }

    private function registrarVentaWeb($idPedidWeb, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId) // Cambiamos aquí
    {
        $venta = new Venta();

        // Determinar el estado de la venta y asignar el ClienteId según sea necesario
        $estadoVenta = $this->determinarEstadoVenta($metodoPagoId);
        $venta->idCliente =  $ClienteId; // Asigna ClienteId solo si es crédito

        $venta->idUsuario = $idUsuario;
        $venta->idMetodo = $metodoPagoId;
        $venta->idPedidoWeb = $idPedidWeb;
        $venta->igv = $igv;
        $venta->subtotal = $subtotal;
        $venta->descuento = 0;
        $venta->total = $total;
        $venta->fechaVenta = now();
        $venta->documento = $tipoComprobante;
        $venta->estado = $estadoVenta; // Se puede asignar el estado calculado aquí
        $venta->save();

        return $venta;
    }

    private function registrarVenta($idPedido, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId) // Cambiamos aquí
    {
        // Verificar si ya existe una venta registrada para este pedido
        $ventaExistente = Venta::where('idPedido', $idPedido)->first();
        if ($ventaExistente) {
            return $ventaExistente; // Retornar la venta existente si ya fue registrada
        }

        $venta = new Venta();

        // Determinar el estado de la venta y asignar $ClienteId según sea necesario
        $estadoVenta = $this->determinarEstadoVenta($metodoPagoId);
        $venta->idCliente =  $ClienteId; // Asigna ClienteId solo si es crédito

        $venta->idUsuario = $idUsuario;
        $venta->idMetodo = $metodoPagoId;
        $venta->idPedido = $idPedido;
        $venta->igv = $igv;
        $venta->subtotal = $subtotal;
        $venta->descuento = 0;
        $venta->total = $total;
        $venta->fechaVenta = now();
        $venta->documento = $tipoComprobante;
        $venta->estado = $estadoVenta; // Se puede asignar el estado calculado aquí
        $venta->save();

        return $venta;
    }

    private function determinarEstadoVenta($metodoPagoId)
    {
        $metodoPago = MetodoPago::find($metodoPagoId);
        // Consideramos que 'tarjeta credito' implica que la venta es a crédito
        return $metodoPago && $metodoPago->nombre === 'tarjeta credito' ? 0 : 1; // 0 si es crédito, 1 cualquier otro
    }

    private function descontarStock($pedidos)
    {
        foreach ($pedidos as $pedido) {
            $producto = Plato::findOrFail($pedido->idPlato);
            $producto->stock -= $pedido->cantidad;
            $producto->save();
        }
    }

    private function registrarComprobante($venta, $tipoComprobante, $estado = 1, $observaciones = null, $rutaXml = null, $rutaCdr = null)
    {
        if ($tipoComprobante == 'F') {
            $factura = Factura::where('idVenta', $venta->id)->first() ?? new Factura();
            $factura->idVenta = $venta->id;
            $factura->numSerie = 'F001';
            $ultimoNumero = Factura::max('numero');
            $factura->numero = str_pad($ultimoNumero + 1, 3, '0', STR_PAD_LEFT);
            $factura->estado = $estado; // Actualizar el estado
            $factura->observaciones = $observaciones; // Actualizar las observaciones
            $factura->rutaXml = $rutaXml; // Guardar la ruta del XML
            $factura->rutaCdr = $rutaCdr; // Guardar la ruta del CDR
            $factura->save();
        } else {
            $boleta = Boleta::where('idVenta', $venta->id)->first() ?? new Boleta();
            $boleta->idVenta = $venta->id;
            $boleta->numSerie = 'B001';
            $ultimoNumero = Boleta::max('numero');
            $boleta->numero = str_pad($ultimoNumero + 1, 3, '0', STR_PAD_LEFT);
            $boleta->estado = $estado; // Actualizar el estado
            $boleta->observaciones = $observaciones; // Actualizar las observaciones
            $boleta->rutaXml = $rutaXml; // Guardar la ruta del XML
            $boleta->rutaCdr = $rutaCdr; // Guardar la ruta del CDR
            $boleta->save();
        }
    }

    private function obtenerORegistrarCliente($documento, $datosCliente)
    {
        // Determinamos si es un DNI o un RUC
        $esDNI = preg_match('/^\d{8}$/', $documento); // Asumimos que el DNI tiene 8 dígitos
        $esRUC = preg_match('/^\d{11}$/', $documento); // Asumimos que el RUC tiene 11 dígitos

        if ($esDNI) {
            // Buscamos la persona por DNI
            $persona = Persona::where('documento_identidad', $documento)->first();

            if ($persona) {
                // Si la persona existe, registramos el cliente con el ID de la persona
                $cliente = Cliente::where('idPersona', $persona->id)->first();
                if ($cliente) {
                    return $cliente->id; // Retornamos el ID del cliente existente
                } else {
                    // Registrar nuevo cliente
                    $cliente = new Cliente();
                    $cliente->idPersona = $persona->id;
                    $cliente->idEmpresa = null; // Sin ID de empresa para DNI
                    $cliente->estado = 1;
                    $cliente->save();

                    return $cliente->id;
                }
            } else {
                // Registrar nueva persona
                $persona = new Persona();
                $persona->nombre = $datosCliente['nombre'] ?? 'Nombre'; // Asigna valor por defecto
                $persona->apellidos = $datosCliente['apellidos'] ?? 'Apellidos'; // Asigna valor por defecto
                $persona->documento_identidad = $documento;
                $persona->save();

                // Registrar nuevo cliente
                $cliente = new Cliente();
                $cliente->idPersona = $persona->id;
                $cliente->idEmpresa = null; // Sin ID de empresa para DNI
                $cliente->estado = 1;
                $cliente->save();

                return $cliente->id;
            }
        } elseif ($esRUC) {
            // Buscamos la empresa por RUC
            $empresa = Empresa::where('ruc', $documento)->first();

            if ($empresa) {
                $cliente = Cliente::where('idEmpresa', $empresa->id)->first();

                return $cliente->id; // Empresa existente
            } else {
                try {
                    // Registrar nueva empresa
                    $empresa = new Empresa();
                    $empresa->nombre = $datosCliente['razonSocial'];
                    $empresa->ruc = $documento;
                    $empresa->direccion = $datosCliente['direccion'] ?? 'Sin dirección';
                    $empresa->estado = 1;
                    $empresa->save();

                    // Depuración
                    Log::info('Empresa registrada correctamente con ID: ' . $empresa->id);

                    // Registrar cliente como empresa
                    $cliente = new Cliente();
                    $cliente->idPersona = null; // Cliente no asociado a persona
                    $cliente->idEmpresa = $empresa->id; // Asociamos empresa
                    $cliente->estado = 1;

                    // Depuración
                    Log::info('Intentando registrar cliente con idEmpresa: ' . $empresa->id);

                    $cliente->save(); // Registrar cliente

                    Log::info('Cliente registrado correctamente con ID: ' . $cliente->id);

                    return $cliente->id;
                } catch (\Exception $e) {
                    // Registro de errores
                    Log::error('Error al registrar cliente como empresa: ' . $e->getMessage());
                    return null;
                }
            }
        }

        // Opcional: retornar un valor si no es ni DNI ni RUC válido
        return null;
    }

    private function registrarCuentasPorCobrar($venta, $idCliente, $idUsuario, $total, $numeroCuotas) // Añadir $numeroCuotas
    {

        $cuentasPorCobrar = new CuentasPorCobrar();
        $cuentasPorCobrar->idCliente = $idCliente;
        $cuentasPorCobrar->idVenta = $venta->id;
        $cuentasPorCobrar->idUsuario = $idUsuario;
        $cuentasPorCobrar->nombreTransaccion = 'Venta al crédito';
        $cuentasPorCobrar->fecha_inicio = now()->addMonth(); // Fecha de inicio es hoy + 1 mes
        $cuentasPorCobrar->fecha_fin = now()->addMonth($numeroCuotas); // Ajusta esto según el número de cuotas
        $cuentasPorCobrar->cuotas = $numeroCuotas; // Define cuántas cuotas
        $cuentasPorCobrar->cuotas_pagadas = 0;
        $cuentasPorCobrar->monto = $total;
        $cuentasPorCobrar->save();

        return $cuentasPorCobrar;
    }

    private function registrarCuotas($cuentasPorCobrarId, $numCuotas, $montoTotal)
    {
        $montoCuota = $montoTotal / $numCuotas;
        for ($i = 1; $i <= $numCuotas; $i++) {
            $cuota = new Cuota();
            $cuota->cuenta_por_cobrar_id = $cuentasPorCobrarId;
            $cuota->numero_cuota = $i;
            $cuota->monto = $montoCuota;
            $cuota->estado = 'pendiente';
            $cuota->fecha_pago = now()->addMonth($i + 1); // Fecha de pago para la cuota
            $cuota->save();
        }
    }
}
