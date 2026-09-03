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
use App\Models\MesaReserva;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\PedidoMesaRegistro;
use App\Models\PedidosWebRegistro;
use App\Models\Persona;
use App\Models\Plato;
use App\Models\PreventaMesa;
use App\Models\SerieCorrelativo;
use App\Models\User;
use App\Models\Venta;
use App\Services\EstadoPedidoController;
use App\Services\ImpresionService;
use Carbon\Carbon;
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
            $mesasQuery = Mesa::with('preventas.plato')->get();
            $hoy = now()->toDateString();
            $ahora = now();

            $reservasHoy = MesaReserva::where('fecha_reserva', $hoy)
                ->where('estado', 1)
                ->get()
                ->groupBy('idMesa');

            // =========================================================
            // 🔥 OPTIMIZACIÓN: Cargar todos los datos anexos de golpe
            // =========================================================

            // 1. Extraemos todos los IDs de los pedidos actuales
            $idsPedidos = $mesasQuery->flatMap(function ($mesa) {
                return $mesa->preventas->pluck('idPedido');
            })->filter()->unique();

            // 2. Traemos todos los registros de esos pedidos a la vez
            $pedidosRegistros = DB::table('pedido_mesa_registros')
                ->whereIn('id', $idsPedidos)
                ->get()
                ->keyBy('id'); // Facilita la búsqueda por ID luego

            // 3. Traemos todos los usuarios (meseros) implicados a la vez
            $idsUsuarios = $pedidosRegistros->pluck('idUsuario')->filter()->unique();
            $usuarios = User::with('empleado.persona')
                ->whereIn('id', $idsUsuarios)
                ->get()
                ->keyBy('id');

            // 4. Traemos todos los estados de cocina a la vez
            $estadosCocina = DB::table('estado_pedidos')
                ->whereIn('idPedidoMesa', $idsPedidos)
                ->get()
                ->keyBy('idPedidoMesa');

            // =========================================================

            $mesas = $mesasQuery->map(function ($mesa) use ($reservasHoy, $ahora, $pedidosRegistros, $usuarios, $estadosCocina) {

                // Calculamos el total
                $mesa->total = $mesa->preventas->sum(function ($preventa) {
                    $precio = $preventa->precio_unitario ?? $preventa->precio ?? 0;
                    return $preventa->cantidad * $precio;
                });

                // Lógica para el Mesero y el Tiempo (Mesa Ocupada)
                if ($mesa->estado == 0 && $mesa->preventas->isNotEmpty()) {
                    $idPedido = $mesa->preventas->first()->idPedido;

                    if ($idPedido) {
                        // 🔥 Buscamos en la colección en memoria, no en la DB
                        $pedidoRegistro = $pedidosRegistros->get($idPedido);

                        if ($pedidoRegistro) {
                            $usuario = $usuarios->get($pedidoRegistro->idUsuario);
                            $nombreMesero = "Usuario Desconocido";
                            $fotoMesero = null;

                            if ($usuario) {
                                $fotoMesero = $usuario->foto_url;
                                if ($usuario->empleado && $usuario->empleado->persona) {
                                    $nombreMesero = $usuario->empleado->persona->nombre . ' ' . $usuario->empleado->persona->apellidos;
                                }
                            }
                            $mesa->mesero = trim($nombreMesero);
                            $mesa->foto_mesero = $fotoMesero;
                            $mesa->tiempo_apertura = Carbon::parse($pedidoRegistro->created_at)->toIso8601String();
                        }

                        $estadoCocina = $estadosCocina->get($idPedido);
                        $mesa->estado_cocina = $estadoCocina ? $estadoCocina->estado : null;
                    }
                }

                // Lógica de Reservas (Mesa Libre)
                if ($mesa->estado == 1 && isset($reservasHoy[$mesa->id])) {
                    $reservaProxima = $reservasHoy[$mesa->id]->filter(function ($reserva) use ($ahora) {
                        $fechaHoraReserva = Carbon::parse($reserva->fecha_reserva . ' ' . $reserva->hora_reserva);
                        $minutosDiferencia = $ahora->diffInMinutes($fechaHoraReserva, false);
                        return $minutosDiferencia >= -30 && $minutosDiferencia <= 60;
                    })->sortBy('hora_reserva')->first();

                    if ($reservaProxima) {
                        $mesa->estado = 2;
                        $mesa->reserva_cliente = $reservaProxima->nombre_cliente;
                        $mesa->reserva_hora = Carbon::parse($reservaProxima->hora_reserva)->format('h:i A');
                    }
                }

                return $mesa;
            });

            return response()->json(['success' => true, 'data' => $mesas], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener las mesas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las mesas: ' . $e->getMessage() // Devuelve el error real para depurar si falla
            ], 500);
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



    public function getPreventaMesa($idMesa, $idCaja)
    {
        try {
            $user = Auth()->user();
            log::info($user->id); // Ahora correctamente accedemos al ID del usuario
            $preVenta = PreventaMesa::with('pedido', 'usuario', 'mesa', 'caja', 'plato')->where('idCaja', $idCaja)
                ->where('idMesa', $idMesa)
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
            DB::beginTransaction();

            $mesaDestinoId = $request->mesaDestino;

            if ($idMesa == $mesaDestinoId) {
                throw new \Exception('No puedes transferir a la misma mesa.');
            }

            $mesaOrigen = Mesa::find($idMesa);
            $mesaDestino = Mesa::find($mesaDestinoId);

            if (!$mesaOrigen || !$mesaDestino) {
                throw new \Exception('Una de las mesas no existe.');
            }

            // 1. Obtener todos los pedidos de la mesa original
            $preventas = PreventaMesa::where('idMesa', $idMesa)->get();

            if ($preventas->isEmpty()) {
                throw new \Exception('La mesa original no tiene pedidos para transferir.');
            }

            // Extraemos los IDs de los pedidos únicos para actualizar los tickets de cocina
            $idsPedidos = $preventas->pluck('idPedido')->unique();

            // 2. Mover los platos a la nueva mesa
            PreventaMesa::where('idMesa', $idMesa)->update(['idMesa' => $mesaDestinoId]);

            // 3. Actualizar la referencia (número de mesa) en los tickets de cocina
            // Asumiendo que tu modelo es EstadoPedido y usa el campo 'referencia' o similar
            if (class_exists('EstadoPedido')) {
                EstadoPedido::whereIn('idPedidoMesa', $idsPedidos)
                    ->where('tipo_pedido', 'mesa')
                    ->update(['numeroMesa' => $mesaDestino->numero]);
            }

            // 4. Actualizar estados de las mesas
            $mesaOrigen->estado = 1; // 1 = Disponible
            $mesaOrigen->save();

            $mesaDestino->estado = 0; // 0 = Ocupada
            $mesaDestino->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'mesaOrigen' => $mesaOrigen,
                'mesaDestino' => $mesaDestino,
                'message' => "Transferencia exitosa a Mesa {$mesaDestino->numero}"
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en transferencia de mesa: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
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

            //  NUEVO: NORMALIZACIÓN DE LOS DATOS DEL CLIENTE Y EXTRACCIÓN DEL FRONTEND 
            // --- 1. RECEPCIÓN DE DATOS ---
            $datosCliente = $request->input('datosCliente');

            // SOLUCIÓN: Leer 'imprimirTicket' y convertirlo a booleano real desde la raíz del Request
            $imprimirTicketInput = $request->input('imprimirTicket', true);
            $imprimirTicket = filter_var($imprimirTicketInput, FILTER_VALIDATE_BOOLEAN);

            // React ya envía las observaciones combinadas, solo las recibimos
            $observacion = $request->input('observacion');

            // Datos de LLEVAR / WEB
            $pedidoToLlevar = $request->input('pedidoToLlevar');
            $idPedidoWeb = $request->input('idPedidoWeb');

            $tipoVenta = $request->input('tipoVenta'); // 'mesa', 'llevar', 'web'
            $numeroCuotas = $request->input('cuotas');

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

            // Mostrar el metodo de pago
            Log::info('metodo pago' . $nombreMetodo);

            // Validaciones básicas
            if ($idUsuarioAuth != $idUsuario) {
                return response()->json(['success' => false, 'message' => 'Su código no pertenece a esta cuenta.']);
            }

            $metodoPago = MetodoPago::where('nombre', $nombreMetodo)->first();
            if (!$metodoPago) {
                return response()->json(['success' => false, 'message' => 'Método de pago no encontrado.']);
            }

            $caja = Caja::findOrFail($idCaja);
            $pedidosToVender = collect([]);

            // --- 2. PREPARACIÓN DE ÍTEMS A VENDER ---

            if ($tipoVenta === 'llevar') {
                if (empty($pedidoToLlevar) || !is_array($pedidoToLlevar)) {
                    return response()->json(['success' => false, 'message' => 'No se recibieron pedidos válidos para llevar.']);
                }
                $pedidosToVender = collect($pedidoToLlevar)->map(function ($pedido) use ($factorDivisor) {
                    $precioTotal = (float)$pedido['precio'] * $pedido['cantidad'];

                    // 👉 VALIDAMOS SI ES COMIDA O INVENTARIO
                    $esComida = isset($pedido['tipo']) ? ($pedido['tipo'] === 'restaurante') : true;

                    return (object)[
                        "idPlato" => $esComida ? $pedido['id'] : null,        // Solo llena idPlato si es comida
                        "idInventario" => !$esComida ? $pedido['id'] : null,  // Solo llena idInventario si NO es comida
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
                        "idInventario" => null,
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
                    $pedidosToVender = collect($pedidosSeleccionados)->map(function ($item) use ($factorDivisor) {

                        $registroOriginal = PreventaMesa::find($item['id']);

                        if (!$registroOriginal) {
                            $nombrePlato = $item['plato']['nombre'] ?? ($item['plato'] ?? 'Item');
                            $idPlato = $item['plato']['id'] ?? 0;
                            $precioUnit = (float)$item['precio'];
                        } else {
                            $plato = Plato::find($registroOriginal->idPlato);
                            $nombrePlato = $plato->nombre ?? 'Plato';
                            $idPlato = $registroOriginal->idPlato;
                            $precioUnit = (float)$registroOriginal->precio;
                        }

                        $cantidadAPagar = $item['cantidad'];
                        $precioTotal = $precioUnit * $cantidadAPagar;

                        return (object)[
                            "id_preventa" => $item['id'],
                            "idPlato" => $idPlato,
                            "idInventario" => null,
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
                            "id_preventa" => $preventa->id,
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
            $detallesParaInsertar = [];

            foreach ($pedidosToVender as $itemVenta) {
                $totalPrecio += $itemVenta->cantidad * $itemVenta->precio_unitario;

                // Guardamos el detalle en un array para JSON (Para llevar)
                $detallePlatosArray[] = [
                    'nombre' => $itemVenta->descripcion,
                    'cantidad' => $itemVenta->cantidad
                ];

                if ($tipoVenta !== 'web') {
                    $detallesParaInsertar[] = [
                        'idEmpresa' => Auth::user()->idEmpresa,
                        'idPedido' => $nuevoPedido->id,
                        'idPlato' => $itemVenta->idPlato,
                        'idInventario' => $itemVenta->idInventario ?? null,
                        'producto' => $itemVenta->descripcion,
                        'cantidad' => $itemVenta->cantidad,
                        'precio_unitario' => $itemVenta->precio_unitario,
                        'estado' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            // Inserción en masa (Bulk Insert)
            if (!empty($detallesParaInsertar)) {
                DetallePedido::insert($detallesParaInsertar);
            }
            // Registrar estado y observación para llevar
            if ($tipoVenta === 'llevar') {
                $detallePlatos = json_encode($detallePlatosArray);
                $nombreCliente = is_array($datosCliente) && isset($datosCliente['nombre'])
                    ? $datosCliente['nombre']
                    : (is_string($datosCliente) ? $datosCliente : null);

                $estadoService = new EstadoPedidoController('llevar', $idCaja, $detallePlatos, $nuevoPedido->id, $nombreCliente);
                $estadoService->registrar();

                if (!empty($observacion)) {
                    EstadoPedido::where('idPedidoLLevar', $nuevoPedido->id)->update(['detalles_extras' => $observacion]);
                }
            }

            // Cálculos finales monetarios
            $subtotal = $totalPrecio / $factorDivisor;
            $igv = $totalPrecio - $subtotal;
            $total = $totalPrecio;

            // Procesar Cliente (Factura/Boleta)
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
                    foreach ($pedidosToVender as $itemVendido) {
                        $preventaRow = PreventaMesa::find($itemVendido->id_preventa);

                        if ($preventaRow) {
                            if ($itemVendido->cantidad >= $preventaRow->cantidad) {
                                $preventaRow->delete();
                            } else {
                                $preventaRow->cantidad = $preventaRow->cantidad - $itemVendido->cantidad;
                                $preventaRow->save();
                            }
                        }
                    }
                } else {
                    PreventaMesa::where('idCaja', $idCaja)->where('idMesa', $idMesa)->delete();
                }

                // --- VERIFICACIÓN DE ESTADO DE MESA ---
                $itemsRestantes = PreventaMesa::where('idMesa', $idMesa)->count();

                if ($itemsRestantes == 0) {
                    $mesaEncontrar = Mesa::find($idMesa);
                    if ($mesaEncontrar) {
                        $mesaEncontrar->estado = 1; // 1 = Disponible
                        $mesaEncontrar->save();
                    }
                } else {
                    Log::info("La mesa $idMesa aun tiene $itemsRestantes items pendientes. No se libera.");
                }
            }

            // Registrar Venta Final en tabla `ventas`
            if ($tipoVenta === 'web') {
                $venta = $this->registrarVentaWeb($idPedidoWeb, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
                $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);
                if ($pedidoWeb) {
                    $pedidoWeb->estado_pedido = 6;
                    $pedidoWeb->estado_pago = "pagado";
                    $pedidoWeb->save();
                }
            } else {
                $venta = $this->registrarVenta($nuevoPedido->id, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId, $idCaja);
            }

            // =================================================================
            // =========== 4. DESCUENTO DE STOCK INVENTARIO  ============
            // =================================================================
            foreach ($pedidosToVender as $itemVendido) {
                if (isset($itemVendido->idInventario) && !is_null($itemVendido->idInventario)) {
                    $productoInventario = Inventario::find($itemVendido->idInventario);

                    if ($productoInventario) {
                        if ($productoInventario->stock < $itemVendido->cantidad) {
                            throw new \Exception("Stock insuficiente para el producto: " . $itemVendido->descripcion . ". Stock actual: " . $productoInventario->stock);
                        }
                        $productoInventario->stock = $productoInventario->stock - $itemVendido->cantidad;
                        $productoInventario->save();
                        Log::info("Stock descontado para: {$itemVendido->descripcion}. Nuevo stock: {$productoInventario->stock}");
                    }
                }
            }
            // =================================================================
            // Crédito y Caja
            if (in_array($metodoPago->nombre, ['credito', 'tarjeta credito'])) {
                $cuentasPorCobrar = $this->registrarCuentasPorCobrar($venta, $ClienteId, $idUsuario, $total, $numeroCuotas);
                $this->registrarCuotas($cuentasPorCobrar->id, $numeroCuotas, $total);
            }
            $caja->montoVendido += $total;
            $caja->save();

            // ==========================================
            // =========== FACTURACIÓN SUNAT ============
            // ==========================================
            try {
                $sunatConfig = Configuraciones::where('nombre', 'sunat')->first();
                $sunatActivo = ($sunatConfig && $sunatConfig->estado == 1);

                $serieReal = 'T001';
                $correlativoReal = str_pad($venta->id, 8, '0', STR_PAD_LEFT);

                if ($tipoComprobante === 'F' || $tipoComprobante === 'B') {
                    $serieReal = $tipoComprobante === 'F' ? 'F001' : 'B001';
                    $modeloClase = $tipoComprobante === 'F' ? Factura::class : Boleta::class;

                    $ultimoNumero = $modeloClase::where('idEmpresa', Auth::user()->idEmpresa)->max('numero') ?? 0;
                    $correlativoReal = str_pad((int)$ultimoNumero + 1, 8, '0', STR_PAD_LEFT);
                }

                if ($tipoComprobante !== 'S') {
                    if ($sunatActivo) {
                        Log::info("🟡 Generando {$tipoComprobante} electrónico: {$serieReal}-{$correlativoReal}");

                        $datosFactura = [
                            'venta_id' => $venta->id,
                            'tipo_comprobante' => $tipoComprobante,
                            'serie' => $serieReal,
                            'correlativo' => $correlativoReal,
                            'cliente' => $datosCliente,
                            'detalle' => $pedidosToVender,
                            'subtotal' => $subtotal,
                            'igv' => $igv,
                            'total' => $total,
                        ];

                        $facturacionSunatController = new FacturacionSunatController();
                        $respuesta = $facturacionSunatController->generarFactura($datosFactura);

                        $estadoFinal = isset($respuesta['estado']) ? (int)$respuesta['estado'] : 0;

                        $this->registrarComprobante(
                            $venta,
                            $tipoComprobante,
                            $estadoFinal,
                            !empty($respuesta['observaciones']) ? implode(', ', $respuesta['observaciones']) : null,
                            $respuesta['rutaXml'] ?? null,
                            $respuesta['rutaCdr'] ?? null,
                            $serieReal,
                            $correlativoReal
                        );
                    } else {
                        Log::info("⚠️ Módulo SUNAT inactivo. Guardando comprobante como Pendiente.");
                        $this->registrarComprobante($venta, $tipoComprobante, 0, 'SUNAT Inactivo - Pendiente', null, null, $serieReal, $correlativoReal);
                    }
                } else {
                    Log::info("✅ Comprobante Simple (S) procesado internamente. No requiere SUNAT.");
                }
            } catch (\Exception $eSunat) {
                Log::error("❌ ERROR CRÍTICO SUNAT: " . $eSunat->getMessage());
            }

            DB::commit();

            // Respuesta Final
            $ticketData = [
                'id' => $venta->id,
                'serie_correlativo' => $serieReal . '-' . $correlativoReal,
                'tipo_comprobante' => $tipoComprobante == 'F' ? 'FACTURA ELECTRÓNICA' : ($tipoComprobante == 'B' ? 'BOLETA DE VENTA' : 'TICKET'),
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
                'observacion' => $observacion, // Ahora incluye las notas ingresadas en caja
                'cajero' => Auth::user()->empleado->persona->nombre . " " . Auth::user()->empleado->persona->apellidos ?? 'Cajero'
            ];

            //  INTEGRAMOS LA OPCIÓN DEL FRONTEND PARA IMPRIMIR
            try {
                if ($imprimirTicket) {
                    $impresionService = new ImpresionService();

                    // 1. Imprime el comprobante o ticket del cliente
                    $impresionService->imprimirTicketVenta($ticketData);

                    // 2. Imprime la comanda para cocina/despacho si es para llevar
                    if ($tipoVenta === 'llevar') {
                        $productosComanda = collect($pedidosToVender)->map(function ($item) {
                            return [
                                'cantidad' => $item->cantidad,
                                'nombre' => $item->descripcion
                            ];
                        })->toArray();

                        // Obtenemos el nombre del cliente o ponemos uno genérico
                        $nombreCliente = $datosCliente['nombre'] ?? ($datosCliente['razonSocial'] ?? 'PÚBLICO EN GENERAL');

                        $comandaData = [
                            'mesa' => 'PARA LLEVAR',
                            'fecha' => date('d/m/Y H:i:s'),
                            'usuario' => Auth::user()->empleado->persona->nombre ?? 'Cajero',
                            'cliente' => $nombreCliente, // 🔥 AQUÍ PASAMOS EL CLIENTE
                            'productos' => $productosComanda,
                            'nota' => $observacion
                        ];

                        $impresionService->imprimirComandaCocina($comandaData);
                        Log::info("🖨️ Ticket de despacho/cocina para LLEVAR impreso correctamente.");
                    }
                } else {
                    Log::info("🖨️ Impresión de ticket omitida desde caja.");
                }
            } catch (\Exception $eImpresion) {
                Log::error("⚠️ Error al intentar imprimir el ticket: " . $eImpresion->getMessage());
            }

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
    private function registrarVenta($idPedido, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId, $idCaja = null) // Cambiamos aquí
    {
        // Verificar si ya existe una venta registrada para este pedido
        $ventaExistente = Venta::where('idPedido', $idPedido)->first();
        if ($ventaExistente) {
            return $ventaExistente; // Retornar la venta existente si ya fue registrada
        }

        $venta = new Venta();

        // Determinar el estado de la venta y asignar $ClienteId según sea necesario
        $estadoVenta = $this->determinarEstadoVenta($nombreMetodo);
        $venta->idCliente =  $ClienteId; // Asigna ClienteId solo si es crédito

        $venta->idUsuario = $idUsuario;
        $venta->idMetodo = $nombreMetodo;
        $venta->idPedido = $idPedido;
        $venta->idCaja = $idCaja;
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
    private function crearNuevoPedido($tipoVenta)
    {
        $nuevoPedido = new Pedido();
        $nuevoPedido->fechaPedido = now();
        $nuevoPedido->estado = 1;
        $nuevoPedido->tipoVenta = $tipoVenta;
        $nuevoPedido->save();
        return $nuevoPedido;
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

    private function obtenerPrecioUnitario($idPlato)
    {
        $productoInventario = Plato::findOrFail($idPlato);
        return $productoInventario->precio;
    }
    private function registrarVentaWeb($idPedidWeb, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId) // Cambiamos aquí
    {
        $venta = new Venta();

        // Determinar el estado de la venta y asignar el ClienteId según sea necesario
        $estadoVenta = $this->determinarEstadoVenta($nombreMetodo);
        $venta->idCliente =  $ClienteId; // Asigna ClienteId solo si es crédito

        $venta->idUsuario = $idUsuario;
        $venta->idMetodo = $nombreMetodo;
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



    private function determinarEstadoVenta($nombreMetodo)
    {
        $metodoPago = MetodoPago::find($nombreMetodo);
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
    // Agregamos $serieReal y $correlativoReal como parámetros obligatorios al final
    private function registrarComprobante($venta, $tipoComprobante, $estado = 1, $observaciones = null, $rutaXml = null, $rutaCdr = null, $serieReal = null, $correlativoReal = null)
    {
        // Si es ticket (S), no guardamos en las tablas electrónicas
        if ($tipoComprobante === 'S') return;

        try {
            if ($tipoComprobante == 'F') {
                $factura = Factura::where('idVenta', $venta->id)->first() ?? new Factura();
                $factura->idVenta = $venta->id;
                $factura->numSerie = $serieReal;
                $factura->numero = $correlativoReal;
                $factura->estado = $estado;
                $factura->observaciones = $observaciones;
                $factura->rutaXml = $rutaXml;
                $factura->rutaCdr = $rutaCdr;
                $factura->save();
            } else {
                $boleta = Boleta::where('idVenta', $venta->id)->first() ?? new Boleta();
                $boleta->idVenta = $venta->id;
                $boleta->numSerie = $serieReal;
                $boleta->numero = $correlativoReal;
                $boleta->estado = $estado;
                $boleta->observaciones = $observaciones;
                $boleta->rutaXml = $rutaXml;
                $boleta->rutaCdr = $rutaCdr;
                $boleta->save();
            }
        } catch (\Exception $e) {
            throw new \Exception("Error al registrar el comprobante en BD: " . $e->getMessage());
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
    public function actualizarCantidad(Request $request, $idPlato, $idMesa)
    {
        try {
            Log::info("Actualizando cantidad preventa mesa", ['idMesa' => $idMesa, 'idPlato' => $idPlato, 'body' => $request->all()]);

            $nuevaCantidad = $request->input('cantidad');

            if (!$nuevaCantidad || $nuevaCantidad < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'La cantidad enviada no es válida (mínimo 1)'
                ], 200); // Cambiado a 200 para que React lo maneje en el Toast
            }

            // 1. Añadimos with('plato') para poder obtener el nombre del plato y buscarlo en el JSON
            $preventa = PreventaMesa::with('plato')
                ->where('idMesa', $idMesa)
                ->where('idPlato', $idPlato)
                ->first();

            if ($preventa) {
                // --- VALIDACIÓN Y ACTUALIZACIÓN PARA LA COCINA ---
                if ($preventa->idPedido) {
                    $estadoPedido = EstadoPedido::where('idPedidoMesa', $preventa->idPedido)->first();

                    if ($estadoPedido) {
                        // 🚀 CAMBIO CLAVE: Bloqueamos si el estado YA NO ES 0 (En espera)
                        if ($estadoPedido->estado != 0) {
                            return response()->json([
                                'success' => false,
                                'message' => 'No se puede modificar la cantidad. El pedido ya está en preparación o listo.'
                            ], 422);
                            // Nota: Dejamos 422 para que la mutación de React Query falle a propósito,
                            // ejecute su 'onError', revierta el '+1' visual de la pantalla y muestre el Toast.
                        }

                        // Extraemos y decodificamos el JSON
                        $nombrePlato = $preventa->plato->nombre;
                        $detalles = is_string($estadoPedido->detalle_platos)
                            ? json_decode($estadoPedido->detalle_platos, true)
                            : $estadoPedido->detalle_platos;

                        $jsonModificado = false;

                        // Buscamos el plato y cambiamos su cantidad
                        if (is_array($detalles)) {
                            foreach ($detalles as &$item) {
                                if (isset($item['nombre']) && $item['nombre'] === $nombrePlato) {
                                    $item['cantidad'] = $nuevaCantidad;
                                    $jsonModificado = true;
                                    break;
                                }
                            }
                        }

                        // Si hubo cambios, guardamos el JSON y emitimos el evento
                        if ($jsonModificado) {
                            $estadoPedido->detalle_platos = json_encode($detalles);
                            $estadoPedido->save();

                            // 🔥 Disparamos el evento para que el frontend de cocina reaccione al instante
                            event(new PedidoCocinaEvent($estadoPedido->id, $detalles, 'mesa', (string)$estadoPedido->estado));
                        }
                    }
                }
                // --------------------------------------------------

                // 2. ACTUALIZAMOS LA PREVENTA PARA LA CAJA
                $preventa->cantidad = $nuevaCantidad;
                $preventa->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Cantidad sincronizada correctamente en Caja y Cocina',
                    'nuevaCantidad' => $preventa->cantidad
                ]);
            } else {
                return response()->json(['success' => false, 'message' => 'Preventa no encontrada'], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error al actualizar la cantidad en preventa mesa: ' . $e->getMessage(), [
                'idMesa' => $idMesa,
                'idPlato' => $idPlato
            ]);
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    public function eliminarPreventaMesa($idMesa)
    {
        try {
            Log::info('========== INICIO eliminarPreventaMesa ==========', [
                'idMesa' => $idMesa,
            ]);

            // 1. Obtener la primera preventa para saber a qué Pedido pertenece
            $primerPreventa = PreventaMesa::where('idMesa', $idMesa)->first();

            if (!$primerPreventa) {
                Log::warning('No se encontraron preventas para la mesa', ['idMesa' => $idMesa]);
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron preventas para la mesa especificada.'
                ], 200); // 200 para que tu frontend lea el mensaje en el bloque else
            }

            $idPedido = $primerPreventa->idPedido;

            // 2.  VALIDACIÓN CRUCIAL: Verificamos la cocina ANTES de borrar nada
            if ($idPedido) {
                $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)->first();

                // Si existe en cocina y el estado NO es 0 (En espera), bloqueamos la anulación
                if ($estadoPedido && $estadoPedido->estado != 0) {
                    Log::warning('Intento de anular pedido bloqueado', [
                        'idMesa' => $idMesa,
                        'idPedido' => $idPedido,
                        'estadoActual' => $estadoPedido->estado
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'No se puede anular la mesa. El pedido ya está en preparación o listo.'
                    ], 200); // Retornamos 200 con success false para que React muestre el ToastAlert correcto
                }
            }

            // 3. INICIO DE TRANSACCIÓN: Si llegamos aquí, el estado es 0 y es seguro borrar todo
            DB::beginTransaction();

            // A. Cambiar estado de la mesa a disponible (1)
            $mesa = Mesa::find($idMesa);
            if ($mesa) {
                $mesa->estado = 1;
                $mesa->save();
            }

            // B. Eliminar las preventas
            PreventaMesa::where('idMesa', $idMesa)->delete();

            // C. Eliminar EstadoPedido y disparar evento de limpieza
            if ($idPedido && isset($estadoPedido) && $estadoPedido) {
                $idEstadoPedido = $estadoPedido->id;
                $estadoPedido->delete();

                // Lanzamos evento con arreglo vacío para que el Frontend de Cocina borre el ticket
                event(new PedidoCocinaEvent($idEstadoPedido, [], 'mesa', 0));
            }

            // D. Eliminar la cabecera PedidoMesaRegistro
            if ($idPedido) {
                PedidoMesaRegistro::where('id', $idPedido)->delete();
            }

            // Confirmamos todos los borrados en la BD
            DB::commit();

            Log::info('========== FIN eliminarPreventaMesa - ÉXITO ==========', [
                'idMesa' => $idMesa,
                'idPedido' => $idPedido,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mesa anulada y ticket de cocina eliminado correctamente.'
            ], 200);
        } catch (\Exception $e) {
            // Si algo falla, revertimos todos los cambios
            DB::rollBack();

            Log::error('========== ERROR eliminarPreventaMesa ==========', [
                'idMesa' => $idMesa,
                'error' => $e->getMessage(),
                'linea' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error crítico al anular el pedido.'
            ], 500);
        }
    }
    public function deletePlatoPreventa($idProducto, $idMesa)
    {
        try {
            DB::beginTransaction();

            $platoToDelete = PreventaMesa::where('id', $idProducto)
                ->where('idMesa', $idMesa)
                ->lockForUpdate()
                ->first();

            if (!$platoToDelete) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 200);
            }

            $idPedido = $platoToDelete->idPedido;

            // Validación de cocina...
            if ($idPedido) {
                $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)->first();
                if ($estadoPedido && $estadoPedido->estado != 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'No se puede eliminar el plato. El pedido ya está en preparación o listo.'
                    ], 200);
                }
            }

            // Eliminamos el plato
            $platoToDelete->delete();

            $platosRestantesCount = PreventaMesa::where('idPedido', $idPedido)->count();
            $mesaLiberada = false; // 🔥 Bandera inicializada en falso

            if ($platosRestantesCount === 0) {
                // El pedido quedó vacío
                $this->eliminarPedidoCompleto($idPedido);
                Mesa::where('id', $idMesa)->update(['estado' => 1]);

                $mesaLiberada = true; // 🔥 Activamos la bandera
            } else {
                $this->actualizarEstadoPedido($idPedido);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plato eliminado',
                'mesaLiberada' => $mesaLiberada // 🔥 Enviamos la bandera al Frontend
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
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


    // IMPRESION GENERICA
    public function imprimirGenerico(Request $request)
    {
        try {
            $data = $request->all();

            // Validación básica
            if (!isset($data['titulo']) || !isset($data['contenido'])) {
                return response()->json(['success' => false, 'message' => 'Faltan campos requeridos: titulo o contenido'], 400);
            }

            // Llamamos a nuestro servicio de impresión
            $impresionService = new ImpresionService();
            $impresionService->imprimirGenerico($data);

            return response()->json(['success' => true, 'message' => 'Impresión generica enviada correctamente']);
        } catch (\Exception $e) {
            Log::error("Error al imprimir generico: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al imprimir: ' . $e->getMessage()], 500);
        }
    }

    public function imprimirCocina(Request $request)
    {
        try {
            $data = $request->all();
            Log::info("Datos recibidos para imprimir comanda de cocina: ", $data);
            // Validamos lo que realmente necesitamos para la cocina
            if (!isset($data['mesa']) || !isset($data['productos'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Faltan campos requeridos: mesa o productos'
                ], 400);
            }

            // Si por alguna razón el frontend no envía fecha o usuario, les damos un valor por defecto
            $data['fecha'] = $data['fecha'] ?? now()->format('Y-m-d H:i:s');
            $data['usuario'] = $data['usuario'] ?? 'Sistema';
            $data['nota'] = $data['nota'] ?? '';

            // Llamamos a nuestro servicio de impresión
            $impresionService = new ImpresionService();
            $impresionService->imprimirComandaCocina($data);


            return response()->json([
                'success' => true,
                'message' => 'Comanda de cocina enviada correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error("Error al imprimir comanda de cocina: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al imprimir: ' . $e->getMessage()
            ], 500);
        }
    }
}
