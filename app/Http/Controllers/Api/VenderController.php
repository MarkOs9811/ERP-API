<?php

namespace App\Http\Controllers\api;

use App\Events\PedidoCocinaEvent;
use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Api\FacturacionSunatController;
use App\Http\Controllers\Controller;
use App\Models\Boleta;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Configuraciones;
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
use App\Models\SerieCorrelativo;
use App\Models\Venta;
use App\Services\EstadoPedidoController;
use Exception;
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
        $data = $request->input('pedidos');

        // Si el array viene vacío, salir sin registrar nada
        if (empty($data)) {
            return response()->json([
                'success' => true,
                'message' => 'No se enviaron platos para registrar.'
            ], 200);
        }

        Log::info($data);

        $request->validate([
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

            // Asumimos que todos los pedidos del array van a la misma mesa (usamos el primero como referencia)
            $idMesa = $data[0]['idMesa'];
            $idCaja = $data[0]['idCaja'];

            // 1. Validar la Mesa (UNA SOLA VEZ fuera del bucle para optimizar)
            $mesa = Mesa::find($idMesa);
            if (!$mesa) {
                // Usamos Exception para que vaya al catch y haga rollback
                throw new \Exception('Mesa no encontrada.');
            }

            // 2. Buscar si ya existe un pedido abierto para la mesa y usuario
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

            // 3. Procesar cada plato
            foreach ($data as $pedido) {
                // Seguridad: verificar que no mezclen mesas en un mismo envío
                if ($pedido['idMesa'] != $idMesa) {
                    throw new \Exception('Error de integridad: Se detectaron IDs de mesa diferentes en una sola petición.');
                }

                $preventaExistente = PreventaMesa::where('idCaja', $pedido['idCaja'])
                    ->where('idPlato', $pedido['idPlato'])
                    ->where('idMesa', $pedido['idMesa'])
                    ->where('idUsuario', $user->id)
                    ->where('idPedido', $idPedido) // Asegurar que pertenece al mismo pedido padre
                    ->first();

                if ($preventaExistente) {
                    // Si ya existe el plato en preventa, sumamos la cantidad
                    $preventaExistente->cantidad += $pedido['cantidad'];
                    $preventaExistente->precio = $pedido['precio'];
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

                // Buscar nombre del plato
                $plato = Plato::find($pedido['idPlato']);
                if (!$plato) {
                    throw new \Exception('Plato con ID ' . $pedido['idPlato'] . ' no encontrado.');
                }

                $detallePlatosArray[] = [
                    'nombre' => $plato->nombre,
                    'cantidad' => $pedido['cantidad']
                ];
            }

            // 4. Cambiar el estado de la mesa a ocupado (Si no lo está ya)
            if ($mesa->estado !== 0) {
                $mesa->estado = 0;
                $mesa->save();
            }

            // Convertir todos los platos en un solo JSON para el ticket
            $detallePlatos = json_encode($detallePlatosArray);

            // 5. Lógica del Ticket de Cocina (EstadoPedido)
            $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
                ->where('estado', 0)
                ->first();

            if ($estadoPedido) {
                // === ACTUALIZAR TICKET EXISTENTE ===

                // Decodificar el JSON actual
                $detalleActual = json_decode($estadoPedido->detalle_platos, true) ?? [];

                // Indexar por nombre para sumar cantidades
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

                // Reconstruir el array
                $nuevoDetalle = [];
                foreach ($platosIndexados as $nombre => $cantidad) {
                    $nuevoDetalle[] = [
                        'nombre' => $nombre,
                        'cantidad' => $cantidad
                    ];
                }

                $estadoPedido->detalle_platos = json_encode($nuevoDetalle);
                $estadoPedido->save();

                // Evento
                event(new PedidoCocinaEvent(
                    $estadoPedido->id,
                    $nuevoDetalle,
                    'mesa',
                    $estadoPedido->estado
                ));
            } else {
                // === CREAR NUEVO TICKET USANDO TU SERVICIO ===

                // Instanciamos tu servicio (que llamaste Controller) manualmente como pediste.
                // Asegúrate de importar la clase arriba: use App\Services\EstadoPedidoController;
                $estadoService = new EstadoPedidoController(
                    'mesa',             // Tipo
                    $idCaja,            // ID Caja
                    $detallePlatos,     // JSON de platos
                    $idPedido,          // ID Pedido Mesa
                    null,                // detalle_cliente (null para mesas)
                    $mesa->numero,      // Referencia (Número de mesa)
                );

                $estadoService->registrar();
            }

            DB::commit();

            $pedidoCompleto = PedidoMesaRegistro::with(['preVentas.plato'])
                ->find($idPedido);

            return response()->json([
                'success' => true,
                'message' => 'Pedidos registrados exitosamente.',
                'data' => [
                    'pedidoRegistro' => $pedidoCompleto->preVentas
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack(); // Fundamental para revertir cambios si algo falló
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
            $preVenta = PreventaMesa::with('pedido', 'usuario', 'mesa', 'caja', 'plato')->where('idCaja', $idCaja)
                ->where('idMesa', $idMesa)
                ->where('idUsuario', $user->id)
                ->get()
                ->map(function ($item) {
                    $estadoPedido = EstadoPedido::where('idPedidoMesa', $item->idPedido)->first();
                    $item->estadoPedido = $estadoPedido;
                    return $item;
                });
            return response()->json(['success' => true, 'data' => $preVenta, 'message' => 'Preventa Encontrada'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 200);
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

    // TODO PARA PODER REALIZAR LA VENTA TANTO PARA CREDITO O AL CONTADO
    public function venderTodo(Request $request)
    {
        // [LOG] Inicio del proceso
        Log::info('🔵 === INICIO VENDER (TODO O PARCIAL) ===');
        Log::info('Datos recibidos:', $request->all());

        try {
            // --- 1. RECEPCIÓN DE DATOS ---
            $idCaja = $request->input('idCaja');
            $idMesa = $request->input('idMesa');
            $nombreMetodo = $request->input('metodoPago');
            $tipoComprobante = $request->input('comprobante');
            $idUsuario = $request->input('idUsuario');
            $datosCliente = $request->input('datosCliente');

            // Datos de LLEVAR / WEB
            $pedidoToLlevar = $request->input('pedidoToLlevar');
            $idPedidoWeb = $request->input('idPedidoWeb');

            $tipoVenta = $request->input('tipoVenta'); // 'mesa', 'llevar', 'web'
            $numeroCuotas = $request->input('cuotas');
            $observacion = $request->input('observacion');

            // === NUEVOS DATOS PARA CUENTA SEPARADA ===
            $esCuentaSeparada = $request->input('esCuentaSeparada', false); // Default false
            $pedidosSeleccionados = $request->input('pedidosSeleccionados', []);

            // Configuración impuestos
            $impuestoConfig = ConfiguracionHelper::clave('impuestos');
            $tasaIgv = (float)($impuestoConfig ?? 0.18);
            $factorDivisor = 1 + $tasaIgv;

            $dniCliente = null;
            $ClienteId = null;
            $idUsuarioAuth = Auth::id();

            // Validaciones básicas
            if ($idUsuarioAuth != $idUsuario) {
                return response()->json(['success' => false, 'message' => 'Su código no pertenece a esta cuenta.']);
            }

            $metodoPago = MetodoPago::where('nombre', $nombreMetodo)->first();
            if (!$metodoPago) {
                return response()->json(['success' => false, 'message' => 'Método de pago no encontrado.']);
            }
            $metodoPagoId = $metodoPago->id;

            $caja = Caja::findOrFail($idCaja);
            $pedidosToVender = collect([]);

            // --- 2. PREPARACIÓN DE ÍTEMS A VENDER ---

            if ($tipoVenta === 'llevar') {
                // ... (Lógica existente para llevar) ...
                if (empty($pedidoToLlevar) || !is_array($pedidoToLlevar)) {
                    return response()->json(['success' => false, 'message' => 'No se recibieron pedidos válidos para llevar.']);
                }
                $pedidosToVender = collect($pedidoToLlevar)->map(function ($pedido) use ($factorDivisor) {
                    $precioTotal = (float)$pedido['precio'] * $pedido['cantidad'];
                    return (object)[
                        "idPlato" => $pedido['id'], // ID del Plato
                        "cantidad" => $pedido['cantidad'],
                        "descripcion" => $pedido['nombre'],
                        "valor_unitario" => (float)$pedido['precio'] / $factorDivisor,
                        "valor_total" => $precioTotal / $factorDivisor,
                        "precio_unitario" => (float)$pedido['precio'],
                        "igv" => $precioTotal - ($precioTotal / $factorDivisor),
                    ];
                });
            } elseif ($tipoVenta === 'web') {
                // ... (Lógica existente para web) ...
                $pedidosToVender = DetallePedidosWeb::where('idPedido', $idPedidoWeb)->get();
                $pedidosToVender = $pedidosToVender->map(function ($preventa) use ($factorDivisor) {
                    $platoNombre = Plato::find($preventa->idPlato)->nombre ?? 'Plato desconocido';
                    $precioTotal = (float)$preventa->precio * $preventa->cantidad;
                    return (object)[
                        "idPlato" => $preventa->idPlato,
                        "cantidad" => $preventa->cantidad,
                        "descripcion" => $platoNombre,
                        "valor_unitario" => (float)$preventa->precio / $factorDivisor,
                        "valor_total" => $precioTotal / $factorDivisor,
                        "precio_unitario" => (float)$preventa->precio,
                        "igv" => $precioTotal - ($precioTotal / $factorDivisor),
                    ];
                });
            } else {
                // ==========================================
                // ============ CASO MESA (CRÍTICO) =========
                // ==========================================

                if ($esCuentaSeparada && !empty($pedidosSeleccionados)) {
                    // [A] VENTA PARCIAL (CUENTA SEPARADA)
                    // Mapeamos lo que envió el Frontend (Redux itemsSeleccionados)
                    $pedidosToVender = collect($pedidosSeleccionados)->map(function ($item) use ($factorDivisor) {

                        // Aseguramos obtener el ID real del PLATO
                        // Si viene como item.plato.id o item.id (depende de cómo guardaste en Redux)
                        // En tu frontend FilaPlatoUnificado mandas { id: pedidoId, plato: nombre, ... } 
                        // Necesitamos buscar el registro original para sacar el ID del plato real o confiar en que el frontend lo mande.
                        // Lo más seguro: Buscar en PreventaMesa por el ID de la fila ($item['id'])

                        $registroOriginal = PreventaMesa::find($item['id']);

                        if (!$registroOriginal) {
                            // Fallback si no encuentra registro (raro), intentamos datos del frontend
                            $nombrePlato = $item['plato']['nombre'] ?? ($item['plato'] ?? 'Item');
                            $idPlato = $item['plato']['id'] ?? 0; // Cuidado aqui
                            $precioUnit = (float)$item['precio'];
                        } else {
                            $plato = Plato::find($registroOriginal->idPlato);
                            $nombrePlato = $plato->nombre ?? 'Plato';
                            $idPlato = $registroOriginal->idPlato;
                            $precioUnit = (float)$registroOriginal->precio;
                        }

                        // Usamos la cantidad que el usuario ELIGIÓ pagar (no el total de la fila si es parcial)
                        $cantidadAPagar = $item['cantidad'];
                        $precioTotal = $precioUnit * $cantidadAPagar;

                        return (object)[
                            "id_preventa" => $item['id'], // ID único de la fila en preventa_mesa (IMPORTANTE PARA BORRAR LUEGO)
                            "idPlato" => $idPlato,
                            "cantidad" => $cantidadAPagar,
                            "descripcion" => $nombrePlato,
                            "valor_unitario" => $precioUnit / $factorDivisor,
                            "valor_total" => $precioTotal / $factorDivisor,
                            "precio_unitario" => $precioUnit,
                            "igv" => $precioTotal - ($precioTotal / $factorDivisor),
                        ];
                    });
                } else {
                    // [B] VENTA TOTAL (MÉTODO ANTIGUO)
                    $pedidosBD = PreventaMesa::where('idCaja', $idCaja)->where('idMesa', $idMesa)->get();

                    if ($pedidosBD->isEmpty()) {
                        return response()->json(['success' => false, 'message' => 'No hay preventas para esta mesa.']);
                    }

                    $pedidosToVender = $pedidosBD->map(function ($preventa) use ($factorDivisor) {
                        $platoNombre = Plato::find($preventa->idPlato)->nombre ?? 'Plato desconocido';
                        $precioTotal = (float)$preventa->precio * $preventa->cantidad;
                        return (object)[
                            "id_preventa" => $preventa->id, // ID para borrar luego
                            "idPlato" => $preventa->idPlato,
                            "cantidad" => $preventa->cantidad,
                            "descripcion" => $platoNombre,
                            "valor_unitario" => (float)$preventa->precio / $factorDivisor,
                            "valor_total" => $precioTotal / $factorDivisor,
                            "precio_unitario" => (float)$preventa->precio,
                            "igv" => $precioTotal - ($precioTotal / $factorDivisor),
                        ];
                    });
                }
            }

            DB::beginTransaction();

            // Crear registro en tabla PEDIDOS (Cabecera)
            $nuevoPedido = $this->crearNuevoPedido($tipoVenta);

            $totalPrecio = 0;
            $detallePlatosArray = [];

            // Procesar Detalle y Calcular Totales
            foreach ($pedidosToVender as $itemVenta) {
                $producto = Plato::find($itemVenta->idPlato);
                // Validacion opcional si el plato existe

                // Creamos detalle en la tabla `detalle_pedidos`
                if ($tipoVenta !== 'web') {
                    $this->crearDetallePedido($nuevoPedido->id, $itemVenta->idPlato, $itemVenta->cantidad, $itemVenta->precio_unitario);
                }

                $totalPrecio += $itemVenta->cantidad * $itemVenta->precio_unitario;
                $detallePlatosArray[] = [
                    'nombre' => $itemVenta->descripcion,
                    'cantidad' => $itemVenta->cantidad
                ];
            }

            // Registrar estado y observación para llevar
            if ($tipoVenta === 'llevar') {
                $detallePlatos = json_encode($detallePlatosArray);
                $estadoService = new EstadoPedidoController('llevar', $idCaja, $detallePlatos, $nuevoPedido->id, null);
                $estadoService->registrar();

                if (!empty($observacion)) {
                    EstadoPedido::where('idPedidoLLevar', $nuevoPedido->id)->update(['detalles_extras' => $observacion]);
                }
            }

            // Cálculos finales monetarios
            $subtotal = $totalPrecio / $factorDivisor;
            $igv = $totalPrecio - $subtotal;
            $total = $totalPrecio;

            // Procesar Cliente (Factura/Boleta) - Lógica sin cambios
            if ($tipoComprobante === 'F') {
                $dniCliente = $datosCliente['ruc'] ?? null;
                if (!$dniCliente || empty($datosCliente['razonSocial']) || empty($datosCliente['direccion'])) {
                    throw new \Exception('Datos incompletos para Factura.');
                }
                $ClienteId = $this->obtenerORegistrarCliente($dniCliente, $datosCliente);
            } elseif ($tipoComprobante === 'B') {
                if (isset($datosCliente['dni']) && !empty($datosCliente['dni'])) {
                    $dniCliente = $datosCliente['dni'];
                    $ClienteId = $this->obtenerORegistrarCliente($dniCliente, $datosCliente);
                } else {
                    $datosCliente = ['tipo_documento' => '0', 'numero_documento' => '00000000', 'nombre' => 'CLIENTE GENERICO'];
                }
            }

            // =================================================================
            // =========== 3. LIMPIEZA DE MESA (LA PARTE INTELIGENTE) ==========
            // =================================================================

            if ($tipoVenta === 'mesa') {
                if ($esCuentaSeparada) {
                    // A) MODO PARCIAL: Recorremos lo que se vendió
                    foreach ($pedidosToVender as $itemVendido) {
                        $preventaRow = PreventaMesa::find($itemVendido->id_preventa);

                        if ($preventaRow) {
                            // Si pagó TODO lo que había en esa fila (ej: había 2 cervezas, pagó 2)
                            if ($itemVendido->cantidad >= $preventaRow->cantidad) {
                                $preventaRow->delete();
                            } else {
                                // Si pagó PARCIALMENTE esa fila (ej: había 5, pagó 2)
                                // Restamos la cantidad y actualizamos
                                $preventaRow->cantidad = $preventaRow->cantidad - $itemVendido->cantidad;
                                $preventaRow->save();
                            }
                        }
                    }
                } else {
                    // B) MODO TOTAL: Borramos todo de un golpe
                    PreventaMesa::where('idCaja', $idCaja)->where('idMesa', $idMesa)->delete();
                }

                // --- VERIFICACIÓN DE ESTADO DE MESA ---
                // Consultamos si QUEDA ALGO pendiente en la mesa
                $itemsRestantes = PreventaMesa::where('idMesa', $idMesa)->count();

                // Solo si NO queda nada (0), liberamos la mesa
                if ($itemsRestantes == 0) {
                    $mesaEncontrar = Mesa::find($idMesa);
                    if ($mesaEncontrar) {
                        $mesaEncontrar->estado = 1; // 1 = Disponible
                        $mesaEncontrar->save();
                    }
                } else {
                    // Si itemsRestantes > 0, la mesa sigue OCUPADA (estado 0 o 2), no hacemos nada
                    Log::info("La mesa $idMesa aun tiene $itemsRestantes items pendientes. No se libera.");
                }
            }

            // Registrar Venta Final en tabla `ventas`
            if ($tipoVenta === 'web') {
                // ... logica web ...
                $venta = $this->registrarVentaWeb($idPedidoWeb, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
                $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);
                if ($pedidoWeb) {
                    $pedidoWeb->estado_pedido = 6;
                    $pedidoWeb->estado_pago = "pagado";
                    $pedidoWeb->save();
                }
            } else {
                $venta = $this->registrarVenta($nuevoPedido->id, $idUsuario, $metodoPagoId, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
            }

            // Crédito y Caja
            if (in_array($metodoPago->nombre, ['credito', 'tarjeta credito'])) {
                $cuentasPorCobrar = $this->registrarCuentasPorCobrar($venta, $ClienteId, $idUsuario, $total, $numeroCuotas);
                $this->registrarCuotas($cuentasPorCobrar->id, $numeroCuotas, $total);
            }
            $caja->montoVendido += $total;
            $caja->save();

            // Facturación SUNAT
            try {
                $sunatConfig = ConfiguracionHelper::get('sunat');
                $sunatActivo = $sunatConfig && isset($sunatConfig->estado) && $sunatConfig->estado == 1;

                if ($sunatActivo && $tipoComprobante !== 'S') {
                    $datosFactura = [
                        'venta_id' => $venta->id,
                        'tipo_comprobante' => $tipoComprobante,
                        'cliente' => $datosCliente,
                        'detalle' => $pedidosToVender, // Se envía lo que se vendió (parcial o total)
                        'subtotal' => $subtotal,
                        'igv' => $igv,
                        'total' => $total,
                    ];
                    $facturacionSunatController = new FacturacionSunatController();
                    $respuesta = $facturacionSunatController->generarFactura($datosFactura);

                    $this->registrarComprobante(
                        $venta,
                        $tipoComprobante,
                        $respuesta['estado'],
                        !empty($respuesta['observaciones']) ? implode(', ', $respuesta['observaciones']) : null,
                        $respuesta['rutaXml'] ?? null,
                        $respuesta['rutaCdr'] ?? null
                    );
                }
            } catch (\Exception $eSunat) {
                Log::error("❌ ERROR CRÍTICO SUNAT: " . $eSunat->getMessage());
            }

            DB::commit();

            // Respuesta Final
            $ticketData = [
                'id' => $venta->id,
                'serie_correlativo' => $venta->serie . '-' . $venta->correlativo,
                'tipo_comprobante' => $tipoComprobante == 'F' ? 'FACTURA ELECTRÓNICA' : ($tipoComprobante == 'B' ? 'BOLETA DE VENTA' : 'BOLETA SIMPLE'),
                'metodo_pago' => $nombreMetodo,
                'fecha' => date('d/m/Y H:i:s'),
                'cliente' => [
                    'nombre' => $datosCliente['nombre'] ?? ($datosCliente['razonSocial'] ?? 'CLIENTE GENERICO'),
                    'documento' => $datosCliente['dni'] ?? ($datosCliente['ruc'] ?? '00000000'),
                    'direccion' => $datosCliente['direccion'] ?? '',
                ],
                'productos' => $pedidosToVender,
                'subtotal' => round($subtotal, 2),
                'igv' => round($igv, 2),
                'total' => round($total, 2),
                'observacion' => $observacion
            ];

            return response()->json([
                'success' => true,
                'message' => 'Venta realizada correctamente.',
                'ticket' => $ticketData
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("🔴 ERROR FATAL EN VENDER: " . $e->getMessage());
            Log::error("Line: " . $e->getLine() . " File: " . $e->getFile());
            return response()->json(['error' => true, 'message' => 'Error del servidor: ' . $e->getMessage()], 500);
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

    /**
     * Registra el comprobante (Factura o Boleta) obteniendo la serie y 
     * el correlativo de forma segura desde la base de datos.
     */
    private function registrarComprobante($venta, $tipoComprobante, $estado = 1, $observaciones = null, $rutaXml = null, $rutaCdr = null)
    {

        $usuario = Auth::user();

        if (!$usuario) {
            // Buena práctica: manejar si el usuario no está logueado
            throw new Exception("Usuario no autenticado.");
        }
        $idEmpresa = $usuario->idEmpresa;
        $idSede = $usuario->idSede;
        $tipoSunat = ($tipoComprobante == 'F') ? '01' : '03';

        try {

            // Iniciamos la transacción SEGURA para obtener el número
            $datosSerie = DB::transaction(function () use ($idEmpresa, $idSede, $tipoSunat) {

                // Buscamos la serie por defecto Y LA BLOQUEAMOS 🔒
                $serie = SerieCorrelativo::where('idEmpresa', $idEmpresa)
                    ->where('idSede', $idSede)
                    ->where('tipo_documento_sunat', $tipoSunat)
                    ->where('is_default', 1) // <- ¡Buscando la columna 'is_default' = 1!
                    ->lockForUpdate()
                    ->first();

                if (!$serie) {
                    throw new Exception("No se encontró una serie configurada (default) para tipo $tipoSunat, Sede $idSede.");
                }

                // ¡Éxito! Incrementamos el contador
                $serie->correlativo_actual += 1;
                $serie->usado = 1;
                $serie->save(); // Guardamos el nuevo valor (ej. 182)

                // Retornamos los datos que necesitamos
                return [
                    'serie' => $serie->serie, // Ej: 'F001'
                    'correlativo' => $serie->correlativo_actual // Ej: 182
                ];
            });

            // 4. PREPARAMOS EL NÚMERO DE COMPROBANTE
            // El estándar SUNAT usa 8 dígitos para el correlativo.
            // Tu código anterior usaba 3 ('str_pad(..., 3,...)'), lo cual es muy poco.
            // Lo cambiamos a 8 para cumplir el estándar.
            $numeroComprobante = str_pad($datosSerie['correlativo'], 8, '0', STR_PAD_LEFT); // Ej: '00000182'
            $serieComprobante = $datosSerie['serie']; // Ej: 'F001'


            // 5. GUARDAR EL COMPROBANTE (FACTURA O BOLETA)
            // Esta lógica es la misma que tenías, pero usando las nuevas variables.

            if ($tipoComprobante == 'F') {
                $factura = Factura::where('idVenta', $venta->id)->first() ?? new Factura();
                $factura->idVenta = $venta->id;

                // ---- ¡LÓGICA ACTUALIZADA! ----
                $factura->numSerie = $serieComprobante; // Viene de la BD
                $factura->numero = $numeroComprobante;  // Viene de la BD (formateado)
                // -----------------------------

                $factura->estado = $estado;
                $factura->observaciones = $observaciones;
                $factura->rutaXml = $rutaXml;
                $factura->rutaCdr = $rutaCdr;
                $factura->save();
            } else { // Es Boleta
                $boleta = Boleta::where('idVenta', $venta->id)->first() ?? new Boleta();
                $boleta->idVenta = $venta->id;

                // ---- ¡LÓGICA ACTUALIZADA! ----
                $boleta->numSerie = $serieComprobante; // Viene de la BD
                $boleta->numero = $numeroComprobante;  // Viene de la BD (formateado)
                // -----------------------------

                $boleta->estado = $estado;
                $boleta->observaciones = $observaciones;
                $boleta->rutaXml = $rutaXml;
                $boleta->rutaCdr = $rutaCdr;
                $boleta->save();
            }
        } catch (Exception $e) {

            throw new Exception("Error al generar el correlativo: " . $e->getMessage());
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


    // CASOS PARA LA PREVENTEA DE MESAS
    public function aumentarPreventaMesa($idPlatoChange, $idMesaChange)
    {
        try {
            $idMesa = $idMesaChange;
            $idPlato = $idPlatoChange;
            Log::info("Aumentando preventa mesa", ['idMesa' => $idMesa, 'idPlato' => $idPlato]);

            $preventa = PreventaMesa::where('idMesa', $idMesa)
                ->where('idPlato', $idPlato)
                ->first();

            if ($preventa) {
                // --- NUEVA VALIDACIÓN DE ESTADO ---
                if ($preventa->idPedido) {
                    $estadoPedido = EstadoPedido::where('idPedidoMesa', $preventa->idPedido)->first();

                    // Si existe el estado y es 1 (Ya servido/despachado), bloqueamos
                    if ($estadoPedido && $estadoPedido->estado == 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No se puede aumentar. El pedido ya fue preparado o despachado.'
                        ], 422); // 422: Entidad no procesable
                    }
                }
                // ----------------------------------

                $preventa->cantidad += 1;
                $preventa->save();

                return response()->json(['success' => true, 'message' => 'Cantidad aumentada', 'nuevaCantidad' => $preventa->cantidad]);
            } else {
                return response()->json(['success' => false, 'message' => 'Preventa no encontrada'], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error al aumentar la cantidad en preventa mesa: ' . $e->getMessage(), [
                'idMesa' => $idMesaChange,
                'idPlato' => $idPlatoChange
            ]);
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function disminuirPreventaMesa($idPlatoChange, $idMesaChange)
    {
        try {
            $idMesa = $idMesaChange;
            $idPlato = $idPlatoChange;
            Log::info("Disminuyendo preventa mesa", ['idMesa' => $idMesa, 'idPlato' => $idPlato]);

            $preventa = PreventaMesa::where('idMesa', $idMesa)
                ->where('idPlato', $idPlato)
                ->first();

            if ($preventa) {
                // --- NUEVA VALIDACIÓN DE ESTADO ---
                if ($preventa->idPedido) {
                    $estadoPedido = EstadoPedido::where('idPedidoMesa', $preventa->idPedido)->first();

                    // Si existe el estado y es 1 (Ya servido/despachado), bloqueamos
                    if ($estadoPedido && $estadoPedido->estado == 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No se puede disminuir. El pedido ya fue preparado o despachado.'
                        ], 422);
                    }
                }
                // ----------------------------------

                $preventa->cantidad -= 1;

                if ($preventa->cantidad < 1) {
                    $preventa->cantidad = 1;
                    return response()->json(['success' => true, 'message' => 'Cantidad minima 1', 'nuevaCantidad' => 1]);
                }

                $preventa->save();

                return response()->json(['success' => true, 'message' => 'Cantidad disminuida', 'nuevaCantidad' => $preventa->cantidad]);
            } else {
                return response()->json(['success' => false, 'message' => 'Preventa no encontrada'], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error al disminuir la cantidad en preventa mesa: ' . $e->getMessage(), [
                'idMesa' => $idMesaChange,
                'idPlato' => $idPlatoChange
            ]);
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    public function eliminarPreventaMesa($idMesa)
    {
        try {
            // Verificar si existen registros para eliminar
            if (!PreventaMesa::where('idMesa', $idMesa)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron preventas para la mesa especificada.'
                ], 404);
            }

            // Obtener el idPedido de la primera preventa (todas deberían tener el mismo)
            $primerPreventa = PreventaMesa::where('idMesa', $idMesa)->first();
            $idPedido = $primerPreventa ? $primerPreventa->idPedido : null;

            // Cambiar el estado de la mesa a disponible
            $mesa = Mesa::find($idMesa);
            if ($mesa) {
                $mesa->estado = 1;
                $mesa->save();
            }

            // Eliminar TODOS los registros encontrados en PreventaMesa con este idMesa
            PreventaMesa::where('idMesa', $idMesa)->delete();

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

                // Eliminar el registro de PedidoMesaRegistro
                PedidoMesaRegistro::where('id', $idPedido)->delete();
                Log::info("PedidoMesaRegistro eliminado", [
                    'idPedido' => $idPedido,
                    'idMesa' => $idMesa
                ]);
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
    public function deletePlatoPreventa($idProducto, $idMesa)
    {
        try {
            Log::info("Intentando eliminar plato preventa", ['idProducto' => $idProducto, 'idMesa' => $idMesa]);

            // 1. Buscar el plato a eliminar
            $platoToDelete = PreventaMesa::where('id', $idProducto)->where('idMesa', $idMesa)->first();

            if (!$platoToDelete) {
                return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 404);
            }

            // 2. Guardar el idPedido antes de eliminar para verificar después
            $idPedido = $platoToDelete->idPedido;

            // 3. Eliminar el plato
            $platoToDelete->delete();
            Log::info("Plato eliminado correctamente", ['idProducto' => $idProducto]);

            // 4. VERIFICACIÓN CRÍTICA: ¿Quedan platos en este pedido?
            $platosRestantesCount = PreventaMesa::where('idPedido', $idPedido)->count();

            if ($platosRestantesCount === 0) {
                // CASO A: El pedido quedó vacío -> Limpieza profunda de las 3 tablas
                Log::info("El pedido quedó vacío. Iniciando eliminación en cascada.", ['idPedido' => $idPedido]);
                $this->eliminarPedidoCompleto($idPedido);
            } else {
                // CASO B: Aún quedan platos -> Actualizar JSON y notificar a cocina (Lógica original)
                $this->actualizarEstadoPedido($idPedido);
            }

            // 5. Verificar si la MESA quedó totalmente vacía (sin ningún pedido activo de ninguna ronda)
            $existenOtrosPlatosEnMesa = PreventaMesa::where('idMesa', $idMesa)->exists();

            if (!$existenOtrosPlatosEnMesa) {
                $mesa = Mesa::find($idMesa);
                if ($mesa) {
                    $mesa->estado = 1; // Disponible
                    $mesa->save();
                    Log::info("Mesa liberada (Disponible)", ['idMesa' => $idMesa]);
                }
            }

            return response()->json(['success' => true, 'message' => 'Plato eliminado y stock actualizado'], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar plato: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Método privado para eliminar rastro del pedido si quedó vacío y no ha sido cocinado.
     */
    private function eliminarPedidoCompleto($idPedido)
    {
        // Buscamos el estado del pedido
        $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)->first();

        // REGLA: Solo eliminar si existe y el estado es 0 (No cocinado/No enviado)
        if ($estadoPedido && $estadoPedido->estado == 0) {

            // 1. Eliminar de EstadoPedido
            $estadoPedido->delete();
            Log::info("Registro eliminado de EstadoPedido", ['idPedidoMesa' => $idPedido]);

            // 2. Eliminar de PedidoMesaRegistro (Tabla Padre)
            // Asumo que tu modelo se llama PedidoMesaRegistro. Ajusta si es diferente.
            PedidoMesaRegistro::where('id', $idPedido)->delete();
            Log::info("Registro eliminado de PedidoMesaRegistro", ['id' => $idPedido]);

            // 3. Limpiar cualquier remanente en PreventaMesa (aunque ya debería estar vacío, es por seguridad)
            PreventaMesa::where('idPedido', $idPedido)->delete();
        } else {
            Log::warning("No se eliminó el pedido completo porque el estado no es 0 o no existe", ['idPedido' => $idPedido]);
        }
    }

    /**
     * Método privado para la lógica original de actualizar JSON y Eventos
     */
    private function actualizarEstadoPedido($idPedido)
    {
        $platosRestantes = PreventaMesa::where('idPedido', $idPedido)->get();
        $detallePlatosArray = [];

        foreach ($platosRestantes as $plato) {
            $detallePlatosArray[] = [
                'nombre' => $plato->plato->nombre ?? 'Plato desconocido',
                'cantidad' => $plato->cantidad
            ];
        }

        $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
            ->where('estado', 0)
            ->first();

        if ($estadoPedido) {
            $estadoPedido->detalle_platos = json_encode($detallePlatosArray);
            $estadoPedido->save();

            // Lanzar evento
            event(new PedidoCocinaEvent(
                $estadoPedido->id,
                $detallePlatosArray,
                'mesa',
                $estadoPedido->estado
            ));
            Log::info("Pedido actualizado y evento reenviado", ['idPedido' => $idPedido]);
        }
    }
}
