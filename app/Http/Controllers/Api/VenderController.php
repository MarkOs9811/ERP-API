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
use App\Services\ImpresionService;
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


            $mesas = $mesasQuery->map(function ($mesa) {
                // 2. Calculamos el total
                $mesa->total = $mesa->preventas->sum(function ($preventa) {
                    return $preventa->cantidad * $preventa->precio;
                });

                // 3. YA NO USAMOS unset($mesa->preventas); lo dejamos para que React lo use
                return $mesa;
            });

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
            'pedidos.*.nota' => 'nullable|string|max:100',
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
                    $request->nota,                // detalle_cliente (null para mesas)
                    $mesa->numero,      // Referencia (Número de mesa)
                );

                $estadoService->registrar();
            }

            DB::commit();

            // =================================================================
            // =========== IMPRESIÓN DE COMANDA DE COCINA ======================
            // =================================================================
            $datosComanda = [
                'mesa' => $mesa->numero,
                'fecha' => date('d/m/Y H:i:s'),
                'usuario' => $user->nombre ?? 'Mozo', // Puedes ajustarlo al campo real de tu tabla users
                'productos' => $detallePlatosArray,   // Esto contiene solo lo que se acaba de pedir
                'nota' => $request->nota
            ];

            try {
                // Llamamos a la clase que creamos
                $impresionService = new \App\Services\ImpresionService();
                $impresionService->imprimirComandaCocina($datosComanda);
            } catch (\Exception $eImpresion) {
                Log::error("Error al imprimir comanda de mesa: " . $eImpresion->getMessage());
            }
            // =================================================================

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
            if (class_exists('\App\Models\EstadoPedido')) {
                \App\Models\EstadoPedido::whereIn('idPedidoMesa', $idsPedidos)
                    ->where('tipo', 'mesa')
                    ->update(['referencia' => $mesaDestino->numero]);
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
            $detallesParaInsertar = [];

            foreach ($pedidosToVender as $itemVenta) {
                $totalPrecio += $itemVenta->cantidad * $itemVenta->precio_unitario;

                // Guardamos el detalle en un array para JSON (Para llevar)
                $detallePlatosArray[] = [
                    'nombre' => $itemVenta->descripcion,
                    'cantidad' => $itemVenta->cantidad
                ];

                if ($tipoVenta !== 'web') {
                    // En lugar de llamar a un método dudoso, armamos el array para insertar en masa
                    // o llamamos a un método refactorizado.
                    $detallesParaInsertar[] = [
                        'idEmpresa' => Auth::user()->idEmpresa, // Asumiendo que necesitas idEmpresa
                        'idPedido' => $nuevoPedido->id,
                        'idPlato' => $itemVenta->idPlato, // Puede ser null
                        'idInventario' => $itemVenta->idInventario ?? null, // Puede ser null
                        'producto' => $itemVenta->descripcion, // ✅ GUARDAMOS EL TEXTO SIEMPRE COMO BACKUP
                        'cantidad' => $itemVenta->cantidad,
                        'precio_unitario' => $itemVenta->precio_unitario,
                        'estado' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            // Inserción en masa (Bulk Insert) - Más rápido y seguro
            if (!empty($detallesParaInsertar)) {
                DetallePedido::insert($detallesParaInsertar);
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
                $venta = $this->registrarVentaWeb($idPedidoWeb, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
                $pedidoWeb = PedidosWebRegistro::find($idPedidoWeb);
                if ($pedidoWeb) {
                    $pedidoWeb->estado_pedido = 6;
                    $pedidoWeb->estado_pago = "pagado";
                    $pedidoWeb->save();
                }
            } else {
                $venta = $this->registrarVenta($nuevoPedido->id, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId);
            }
            // =================================================================
            // =========== 4. DESCUENTO DE STOCK INVENTARIO  ============
            // =================================================================
            foreach ($pedidosToVender as $itemVendido) {

                // Verificamos si el item tiene un idInventario válido (no es un plato)
                if (isset($itemVendido->idInventario) && !is_null($itemVendido->idInventario)) {

                    // Asegúrate de importar el modelo arriba: use App\Models\Inventario;
                    $productoInventario = Inventario::find($itemVendido->idInventario);

                    if ($productoInventario) {
                        // 1. Opcional pero recomendado: Validar si hay stock suficiente
                        if ($productoInventario->stock < $itemVendido->cantidad) {
                            // Esto lanzará un error, activará el DB::rollBack() y cancelará toda la venta
                            throw new \Exception("Stock insuficiente para el producto: " . $itemVendido->descripcion . ". Stock actual: " . $productoInventario->stock);
                        }

                        // 2. Reducir el stock
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
                $correlativoReal = str_pad($venta->id, 8, '0', STR_PAD_LEFT); // Fallback para Ticket

                // 1. SOLO calculamos correlativos oficiales si es Factura o Boleta
                if ($tipoComprobante === 'F' || $tipoComprobante === 'B') {
                    $serieReal = $tipoComprobante === 'F' ? 'F001' : 'B001';
                    $modeloClase = $tipoComprobante === 'F' ? Factura::class : Boleta::class;

                    // Buscamos el último número registrado de esta empresa
                    $ultimoNumero = $modeloClase::where('idEmpresa', Auth::user()->idEmpresa)->max('numero') ?? 0;
                    $correlativoReal = str_pad((int)$ultimoNumero + 1, 8, '0', STR_PAD_LEFT);
                }

                // 2. Lógica de Envío y Registro
                if ($tipoComprobante !== 'S') { // Si ES Boleta o Factura
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

                        // Aseguramos enviar un ENTERO al estado (0 si falla, sino el que devuelve)
                        $estadoFinal = isset($respuesta['estado']) ? (int)$respuesta['estado'] : 0;

                        $this->registrarComprobante(
                            $venta,
                            $tipoComprobante,
                            $estadoFinal,
                            !empty($respuesta['observaciones']) ? implode(', ', $respuesta['observaciones']) : null,
                            $respuesta['rutaXml'] ?? null,
                            $respuesta['rutaCdr'] ?? null,
                            $serieReal,        // 🔥 NUEVO: Pasamos la serie
                            $correlativoReal   // 🔥 NUEVO: Pasamos el correlativo exacto
                        );
                    } else {
                        // SUNAT está inactivo, pero ES una Boleta/Factura. Guardamos como Pendiente (Estado 0)
                        Log::info("⚠️ Módulo SUNAT inactivo. Guardando comprobante como Pendiente.");
                        $this->registrarComprobante($venta, $tipoComprobante, 0, 'SUNAT Inactivo - Pendiente', null, null, $serieReal, $correlativoReal);
                    }
                } else {
                    // Es un TICKET SIMPLE ('S'). No hacemos nada con Greenter ni tablas de boletas.
                    Log::info("✅ Comprobante Simple (S) procesado internamente. No requiere SUNAT.");
                }
            } catch (\Exception $eSunat) {
                Log::error("❌ ERROR CRÍTICO SUNAT: " . $eSunat->getMessage());
            }

            DB::commit();

            // Respuesta Final (Corregida para que el Ticket muestre el número real)
            $ticketData = [
                'id' => $venta->id,
                'serie_correlativo' => $serieReal . '-' . $correlativoReal, // <-- AHORA SÍ MOSTRARÁ B001-00000002
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
                'observacion' => $observacion,
                'cajero' => Auth::user()->empleado->persona->nombre . " " . Auth::user()->empleado->persona->apellidos ?? 'Cajero'
            ];


            try {
                // Llamamos a nuestro servicio de impresión
                $impresionService = new ImpresionService();
                $impresionService->imprimirTicketVenta($ticketData);
            } catch (\Exception $eImpresion) {
                // Solo logueamos el error, la venta ya se guardó correctamente
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
    private function registrarVenta($idPedido, $idUsuario, $nombreMetodo, $tipoComprobante, $igv, $subtotal, $total, $ClienteId) // Cambiamos aquí
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

            // Recibimos la cantidad exacta que nos mandó React
            $nuevaCantidad = $request->input('cantidad');

            if (!$nuevaCantidad || $nuevaCantidad < 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'La cantidad enviada no es válida (mínimo 1)'
                ], 400);
            }

            $preventa = PreventaMesa::where('idMesa', $idMesa)
                ->where('idPlato', $idPlato)
                ->first();

            if ($preventa) {
                // --- VALIDACIÓN DE ESTADO (Mantenemos tu lógica intacta) ---
                if ($preventa->idPedido) {
                    $estadoPedido = EstadoPedido::where('idPedidoMesa', $preventa->idPedido)->first();

                    // Si existe el estado y es 1 (Ya servido/despachado), bloqueamos
                    if ($estadoPedido && $estadoPedido->estado == 1) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No se puede modificar la cantidad. El pedido ya fue preparado o despachado.'
                        ], 422);
                    }
                }
                // ------------------------------------------------------------

                // Actualizamos directamente con la nueva cantidad
                $preventa->cantidad = $nuevaCantidad;
                $preventa->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Cantidad actualizada correctamente',
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

            // 1. Verificar si existen registros
            $cantidadPreventas = PreventaMesa::where('idMesa', $idMesa)->count();

            Log::info('Preventas encontradas para la mesa', [
                'idMesa' => $idMesa,
                'cantidad' => $cantidadPreventas,
            ]);

            if ($cantidadPreventas === 0) {
                Log::warning('No se encontraron preventas para la mesa', [
                    'idMesa' => $idMesa,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontraron preventas para la mesa especificada.'
                ], 404);
            }

            // 2. Obtener la primera preventa para obtener el idPedido
            $primerPreventa = PreventaMesa::where('idMesa', $idMesa)->first();

            Log::info('Primera preventa encontrada', [
                'idMesa' => $idMesa,
                'preventa' => $primerPreventa ? $primerPreventa->toArray() : null,
            ]);

            $idPedido = $primerPreventa ? $primerPreventa->idPedido : null;

            Log::info('ID de pedido obtenido', [
                'idMesa' => $idMesa,
                'idPedido' => $idPedido,
            ]);

            // 3. Buscar la mesa
            $mesa = Mesa::find($idMesa);

            if ($mesa) {
                Log::info('Mesa encontrada', [
                    'idMesa' => $idMesa,
                    'estadoActual' => $mesa->estado,
                ]);

                // Cambiar estado de la mesa a disponible
                $mesa->estado = 1;
                $mesa->save();

                Log::info('Mesa actualizada a disponible', [
                    'idMesa' => $idMesa,
                    'nuevoEstado' => $mesa->estado,
                ]);
            } else {
                Log::warning('No se encontró la mesa', [
                    'idMesa' => $idMesa,
                ]);
            }

            // 4. Eliminar todas las preventas
            $eliminadas = PreventaMesa::where('idMesa', $idMesa)->delete();

            Log::info('Preventas eliminadas', [
                'idMesa' => $idMesa,
                'cantidadEliminada' => $eliminadas,
            ]);

            // 5. Procesar EstadoPedido y PedidoMesaRegistro
            if ($idPedido) {

                Log::info('Buscando EstadoPedido', [
                    'idMesa' => $idMesa,
                    'idPedidoMesa' => $idPedido,
                    'estadoBuscado' => 0,
                ]);

                $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
                    ->where('estado', 0)
                    ->first();

                if ($estadoPedido) {

                    Log::info('EstadoPedido encontrado', [
                        'idEstadoPedido' => $estadoPedido->id,
                        'idPedidoMesa' => $estadoPedido->idPedidoMesa,
                        'estado' => $estadoPedido->estado,
                    ]);

                    $idEstadoPedido = $estadoPedido->id;

                    // Eliminar EstadoPedido
                    $estadoPedido->delete();

                    Log::info('EstadoPedido eliminado', [
                        'idEstadoPedido' => $idEstadoPedido,
                        'idPedidoMesa' => $idPedido,
                    ]);

                    // Lanzar evento para cocina
                    Log::info('Lanzando PedidoCocinaEvent', [
                        'idEstadoPedido' => $idEstadoPedido,
                        'idMesa' => $idMesa,
                        'tipo' => 'mesa',
                        'estado' => 0,
                        'platos' => [],
                    ]);

                    event(new PedidoCocinaEvent(
                        $idEstadoPedido,
                        [],
                        'mesa',
                        0
                    ));

                    Log::info('PedidoCocinaEvent lanzado correctamente', [
                        'idEstadoPedido' => $idEstadoPedido,
                        'idMesa' => $idMesa,
                    ]);
                } else {

                    Log::warning('No se encontró EstadoPedido para eliminar', [
                        'idPedidoMesa' => $idPedido,
                        'estadoBuscado' => 0,
                    ]);
                }

                // 6. Eliminar PedidoMesaRegistro
                $pedidoMesaRegistro = PedidoMesaRegistro::find($idPedido);

                if ($pedidoMesaRegistro) {

                    Log::info('PedidoMesaRegistro encontrado', [
                        'idPedido' => $idPedido,
                        'registro' => $pedidoMesaRegistro->toArray(),
                    ]);

                    $pedidoMesaRegistro->delete();

                    Log::info('PedidoMesaRegistro eliminado correctamente', [
                        'idPedido' => $idPedido,
                        'idMesa' => $idMesa,
                    ]);
                } else {

                    Log::warning('No se encontró PedidoMesaRegistro', [
                        'idPedido' => $idPedido,
                        'idMesa' => $idMesa,
                    ]);
                }
            } else {

                Log::warning('No existe idPedido asociado a las preventas', [
                    'idMesa' => $idMesa,
                ]);
            }

            Log::info('========== FIN eliminarPreventaMesa - ÉXITO ==========', [
                'idMesa' => $idMesa,
                'idPedido' => $idPedido,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Preventas y estado de cocina eliminados correctamente.'
            ]);
        } catch (\Exception $e) {

            Log::error('========== ERROR eliminarPreventaMesa ==========', [
                'idMesa' => $idMesa,
                'error' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
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
            DB::beginTransaction();

            $platoToDelete = PreventaMesa::where('id', $idProducto)->where('idMesa', $idMesa)->lockForUpdate()->first();

            if (!$platoToDelete) {
                return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 404);
            }

            $idPedido = $platoToDelete->idPedido;
            $platoToDelete->delete();

            // Verificar si el pedido se quedó vacío
            $platosRestantesCount = PreventaMesa::where('idPedido', $idPedido)->count();

            if ($platosRestantesCount === 0) {
                // El pedido quedó vacío, eliminamos todo en cascada
                $this->eliminarPedidoCompleto($idPedido);
            } else {
                // Aún quedan platos, actualizamos el ticket de cocina
                $this->actualizarEstadoPedido($idPedido);
            }

            // Verificar si la MESA completa se quedó vacía
            $existenOtrosPlatosEnMesa = PreventaMesa::where('idMesa', $idMesa)->exists();

            if (!$existenOtrosPlatosEnMesa) {
                $mesa = Mesa::find($idMesa);
                if ($mesa) {
                    $mesa->estado = 1; // 1 = Disponible
                    $mesa->save();
                }
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Plato eliminado y stock actualizado'], 200);
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
}
