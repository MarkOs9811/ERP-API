<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use Rap2hpoutre\FastExcel\FastExcel;
use App\Http\Controllers\Controller;
use App\Models\AdelantoSueldo;
use App\Models\Almacen;
use App\Models\Asistencia;
use App\Models\Caja;
use App\Models\Compra;
use App\Models\Empleado;
use App\Models\HoraExtras;
use App\Models\Inventario;
use App\Models\Kardex;
use App\Models\Movimiento;
use App\Models\Plato;
use App\Models\registrosCajas;
use App\Models\RegistrosCajas as ModelsRegistrosCajas;
use App\Models\Solicitud;
use App\Models\User;
use App\Models\Vacacione;
use App\Models\Venta;
use App\Services\GoogleSheetsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ReportesController extends Controller
{
    public function generarReporteGoogleSheets(Request $request)
    {
        try {
            $request->validate([
                'fechaInicio' => 'date',
                'fechaFin' => 'date|after_or_equal:fechaInicio',
                'tipo' => 'required|string'
            ]);
            Log::info('Datos recibidos para generar reporte:', $request->all());

            // Validar el tipo de reporte
            $headers = []; // <--- Agrega esto arriba del switch

            switch ($request->tipo) {
                case 'almacen':
                    $datos = Almacen::with('unidad', 'categoria', 'proveedor')
                        ->whereBetween('created_at', [$request->fechaInicio, $request->fechaFin])
                        ->get();

                    $headers = [
                        'ID',
                        'Nombre',
                        'Unidad',
                        'Categoría',
                        'Proveedor',
                        'Marca',
                        'Presentación',
                        'Descripción',
                        'Precio Unitario',
                        'Cantidad',
                        'Fecha de Vencimiento'
                    ];

                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->nombre,
                            optional($item->unidad)->nombre,
                            optional($item->categoria)->nombre,
                            optional($item->proveedor)->nombre,
                            $item->marca ?? '',
                            $item->presentacion ?? '',
                            $item->descripcion ?? '',
                            $item->precioUnit ?? '',
                            $item->cantidad ?? '',
                            $item->fecha_vencimiento ?? '',
                        ];
                    })->values()->toArray(); // 👈 esto también es importante

                    break;


                case 'planilla':
                    $datos = Empleado::with([
                        'cargo',
                        'contrato',
                        'area',
                        'persona',
                        'horario',
                        'usuario'
                    ])->where('estado', 1)->get();

                    $headers = [
                        'ID',
                        'Nombre',
                        'Apellido',
                        'DNI',
                        'Cargo',
                        'Tipo Contrato',
                        'Salario',
                        'Área',
                        'Horario',
                        'Email Usuario',
                        'Fecha de Ingreso'
                    ];

                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            optional($item->persona)->nombre,
                            optional($item->persona)->apellidos,
                            optional($item->persona)->documento_identidad,
                            optional($item->cargo)->nombre,
                            optional($item->contrato)->nombre,
                            $item->salario,
                            optional($item->area)->nombre,
                            optional($item->horario)->horaEntrada . ' - ' . optional($item->horario)->horaSalida,
                            optional($item->usuario)->email,
                            $item->created_at ? $item->created_at->format('Y-m-d') : '',
                        ];
                    })->toArray();
                    break;
                case 'horasExtras':
                    $datos = HoraExtras::with('usuario')->get();

                    $headers = [
                        'ID',
                        'Email',      // Email del usuario
                        'Nombre',
                        'Apellidos',
                        'Documento',
                        'Fecha',
                        'Horas Extras',
                        'Pago Extras',
                        'Estado',
                    ];

                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            optional($item->usuario)->email,
                            optional($item->usuario->empleado->persona)->nombre,
                            optional($item->usuario->empleado->persona)->apellidos,
                            optional($item->usuario->empleado->persona)->documento_identidad,
                            $item->fecha,
                            $item->horas_trabajadas,
                            $item->pagoTotal,
                            $item->estado == 1 ? 'Completado' : ($item->estado == 0 ? 'Rechazado' : 'Pendiente'),
                        ];
                    })->toArray();
                    break;
                case 'adelantoSueldo':
                    $datos = AdelantoSueldo::with('usuario')->get();

                    $headers = [
                        'ID',
                        'Email',      // Email del usuario
                        'Nombre',
                        'Apellidos',
                        'Documento',
                        'Fecha Pago',
                        'Monto Adelantado',
                        'Descripcion',
                        'Estado',
                    ];

                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            optional($item->usuario)->email,
                            optional($item->usuario->empleado->persona)->nombre,
                            optional($item->usuario->empleado->persona)->apellidos,
                            optional($item->usuario->empleado->persona)->documento_identidad,
                            $item->fecha,
                            $item->monto,
                            $item->descripcion,
                            $item->estado == 1 ? 'Pago confirmado' : ($item->estado == 0 ? 'Rechazado' : 'Pendiente'),
                        ];
                    })->toArray();
                    break;
                case 'asistencias':
                    $datos = Asistencia::with('empleado')
                        ->whereBetween('created_at', [$request->fechaInicio, $request->fechaFin])
                        ->get();

                    $headers = [
                        'ID',
                        'Email',
                        'Nombre',
                        'Apellidos',
                        'Documento',
                        'Fecha Entrada',
                        'Hora Entrada',
                        'Fecha Salida',
                        'Hora Salida',
                        'Horas Trabajadas',
                        'Estado Asistencia',
                        'Estado',
                    ];

                    $filas = $datos->map(function ($item) {
                        return array_values([
                            $item->id,
                            optional(optional(optional($item->empleado)->empleado)->usuario)->email ?? '',
                            optional(optional($item->empleado))->nombre,
                            optional(optional($item->empleado))->apellidos,
                            optional(optional($item->empleado))->documento_identidad,
                            $item->fechaEntrada,
                            $item->horaEntrada,
                            $item->fechaSalida ?? '',
                            $item->horaSalida ?? '',
                            $item->horasTrabajadas ?? '',
                            $item->estadoAsistencia,
                            $item->estado === 1 ? 'Activo' : 'Inactivo',
                        ]);
                    })->values()->toArray();
                    break;
                case 'movimiento':
                    $datos = Movimiento::with('areaOrigen', 'usuario', 'areaDestino', 'producto')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();
                    $headers = ['ID', 'Área Origen', 'Área Destino', 'Usuario', 'Tipo Movimiento', 'Producto', 'Cantidad', 'Fecha'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->areaOrigen->nombre,
                            $item->areaDestino->nombre,
                            $item->usuario->email,
                            $item->tipo_movimiento,
                            $item->producto->nombre,
                            $item->cantidad,
                            $item->created_at->format('Y-m-d'),
                        ];
                    })->toArray();
                    break;
                case 'vacaciones':
                    $datos = Vacacione::with('usuario')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();
                    $headers = ['ID', 'Usuario', 'Nombre', 'Apellidos', 'Documento', 'Fecha Inicio', 'Fecha Fin', 'Días Solicitados', 'Días utilizados', 'Días vendidos', 'Estado'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->usuario->email,
                            $item->usuario->empleado->persona->nombre,
                            $item->usuario->empleado->persona->apellidos,
                            $item->usuario->empleado->persona->documento_identidad,
                            $item->fecha_inicio,
                            $item->fecha_fin,
                            $item->dias_totales,
                            $item->dias_utilizados,
                            $item->dias_vendidos,
                            $item->estado == 1 ? 'Completado' : ($item->estado == 0 ? 'Rechazado' : 'Pendiente'),
                        ];
                    })->toArray();
                    break;

                case 'kardex':
                    $datos = Kardex::with('producto', 'usuario')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers = ['ID', 'Nombre Producto', 'Usuario', 'Cantidad', 'Tipo de Movimiento', 'Descripción', 'Stock Anterior', 'Stock Actual', 'Fecha del Movimiento'];

                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->producto->nombre,
                            $item->usuario->email,
                            $item->cantidad,
                            $item->tipo_movimiento,
                            $item->descripcion,
                            $item->stock_anterior,
                            $item->stock_actual,
                            $item->fecha_movimiento
                        ];
                    })->toArray();
                    break;
                case 'ventas':
                    $datos = Venta::with('usuario', 'cliente.persona', 'cliente.empresa', 'metodoPago')->whereBetween('fechaVenta', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers  = ['ID', 'Usuario', 'Cliente', 'Fecha Venta', 'Total', 'Tipo de Pago', 'Documento', 'Estado', 'Fecha y Hora'];
                    $filas = $datos->map(function ($item) {
                        // Si existe persona, usamos su nombre, si no, la razón social de empresa
                        $clienteNombre = '';
                        if ($item->cliente) {
                            if ($item->cliente->persona) {
                                $clienteNombre = ucwords($item->cliente->persona->nombre) . " " . ucwords($item->cliente->persona->apellidos);
                            } elseif ($item->cliente->empresa) {
                                $clienteNombre = $item->cliente->empresa->nombre;
                            }
                        }

                        return [
                            $item->id ?? '',
                            optional($item->usuario)->email ?? '',
                            $clienteNombre,
                            $item->fechaVenta ?? '',
                            $item->total ?? '',
                            optional($item->metodoPago)->nombre ?? '',
                            $item->documento === 'B' ? 'Boleta' : 'Factura',
                            $item->estado == 1 ? 'Pagado' : ($item->estado == 0 ? 'Pendiente' : 'Desconocido'),
                            $item->created_at->format('Y-m-d H:i:s') ?? '',
                        ];
                    })->values()->toArray();
                    break;
                case 'compras':
                    $datos = Compra::with('usuario', 'proveedor')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers  = ['ID', 'Usuario', 'Proveedor', 'fecha Compra', 'total', 'Tipo', 'Observaciones'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->usuario->email,
                            $item->proveedor->nombre,
                            $item->fecha_compra,
                            $item->totalPagado,
                            $item->tipoCompra,
                            $item->observaciones,

                        ];
                    })->toArray();
                    break;
                case 'solicitudes':
                    $datos = Solicitud::with('usuario', 'area', 'unidad', 'categoria')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers  = ['ID', 'Usuario', 'Nombre Solicitante', 'Área', 'Unidad', 'Categoría', 'Producto', 'Marca', 'Descripción', 'Cantidad', 'Precio Estimado', 'Motivo', 'Uso Previsto', 'Prioridad', 'Estado'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            optional($item->usuario)->email,
                            optional($item->nombre_solicitante) ? (string) $item->nombre_solicitante : '',
                            optional($item->area)->nombre ?? '',
                            optional($item->unidad)->nombre ?? '',
                            optional($item->categoria)->nombre ?? '',
                            $item->nombre_producto ?? '',
                            $item->marcaProd ?? '',
                            $item->descripcion ?? '',
                            $item->cantidad ?? '',
                            $item->precio_estimado ?? '',
                            $item->motivo ?? '',
                            $item->uso_previsto ?? '',
                            $item->prioridad ?? '',
                            $item->estado === 1 ? 'Aprobado' : ($item->estado === 0 ? 'Rechazado' : 'Pendiente'),
                        ];
                    })->toArray();
                    break;
                case 'cajas':
                    $datos = RegistrosCajas::with('usuario', 'caja')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers  = ['ID', 'Usuario', 'Caja', 'Monto Inicial', 'Monto Final', 'Monto Dejado', 'Fecha Apertura', 'Hora Apertura', 'Fecha Cierre', 'Hora Cierre', 'Estado'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            optional($item->usuario)->email,
                            optional($item->caja)->nombreCaja,
                            $item->montoInicial,
                            $item->montoFinal,
                            $item->montoDejado,
                            $item->fechaApertura,
                            $item->horaApertura,
                            $item->fechaCierre ?? '',
                            $item->horaCierre ?? '',
                            $item->estado === 1 ? 'Caja Cerrada' : 'Caja Abierta',
                        ];
                    })->toArray();
                    break;
                case 'inventario':
                    $datos = Inventario::with('categoria', 'unidad')->whereBetween('created_at', [
                        $request->fechaInicio,
                        $request->fechaFin
                    ])->get();

                    $headers  = ['ID', 'Producto', 'Categoría', 'Unidad', 'Stock', 'Precio Unitario', 'Fecha Vencimiento'];
                    $filas = $datos->map(function ($item) {
                        return [
                            $item->id,
                            $item->nombre,
                            optional($item->categoria)->nombre,
                            optional($item->unidad)->nombre,
                            $item->stock,
                            $item->precio,
                            $item->fecha_vencimiento,
                        ];
                    })->toArray();
                    break;
                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de reporte no válido'
                    ], 400);
            }

            $sheetService = new GoogleSheetsService();
            $spreadsheetId = $sheetService->getOrCreateSystemSpreadsheet('Reportes del Sistema');

            // 3. Preparamos y actualizamos la hoja
            $values = array_merge([$headers], $filas);
            $values = array_map('array_values', $values);
            $values = array_values($values);
            $url = $sheetService->updateSheet($spreadsheetId, $values);

            return response()->json(['success' => true, 'data' => $url]);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte'
            ], 500);
        }
    }

    public function reporteVentasExcel()
    {
        try {
            $ventas = Venta::with('usuario', 'cliente.persona', 'cliente.empresa', 'metodoPago')->get();

            // ✅ TRANSFORMAR a array SIMPLE y PLANO
            $data = $ventas->map(function ($venta) {
                return [
                    // Solo datos PRIMITIVOS: strings, numbers, dates

                    'ID Venta' => $venta->id,
                    'Sede' => $venta->usuario->sede->nombre ?? '-',
                    'Fecha' => $venta->fechaVenta,
                    'Hora' => $venta->created_at->format('H:i:s'),
                    'Cliente' => $venta->cliente->persona->nombre ?? '-',
                    'Documento' => $venta->cliente->persona->documento_identidad ?? 'N/A',
                    'Empresa' => $venta->cliente->empresa->ruc ?? '-',
                    'N° Pedido' => $venta->idPedido ?? '-',
                    'Método Pago' => $venta->metodoPago->nombre ?? '-',
                    'Moneda' => "Soles",
                    'Total' => (float) $venta->total, // ✅ Convertir a número
                    'Tipo Documento' => $venta->documento === 'B' ? 'Boleta' : 'Factura',
                    'Estado' => $venta->estado,
                    'Vendedor' => $venta->usuario->empleado->persona->nombre . $venta->usuario->empleado->persona->apellidos ?? '-'
                ];
            });

            $filename = 'reporte_ventas_' . now()->format('Ymd_His') . '.xlsx';

            // ✅ Exportar el ARRAY transformado, no los modelos
            (new FastExcel($data))->export($filename);

            return response()->download($filename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteVentasHOY()
    {
        try {
            $hoy = now()->format('Y-m-d');
            $ventas = Venta::with('usuario', 'cliente.persona', 'cliente.empresa', 'metodoPago')
                ->whereDate('fechaVenta', $hoy)
                ->get();

            // ✅ TRANSFORMAR a array SIMPLE y PLANO
            $data = $ventas->map(function ($venta) {
                return [
                    // Solo datos PRIMITIVOS: strings, numbers, dates

                    'ID Venta' => $venta->id,
                    'Sede' => $venta->usuario->sede->nombre ?? '-',
                    'Fecha' => $venta->fechaVenta,
                    'Hora' => $venta->created_at->format('H:i:s'),
                    'Cliente' => $venta->cliente->persona->nombre ?? '-',
                    'Documento' => $venta->cliente->persona->documento_identidad ?? 'N/A',
                    'Empresa' => $venta->cliente->empresa->ruc ?? '-',
                    'N° Pedido' => $venta->idPedido ?? '-',
                    'Método Pago' => $venta->metodoPago->nombre ?? '-',
                    'Moneda' => "Soles",
                    'Total' => (float) $venta->total, // ✅ Convertir a número
                    'Tipo Documento' => $venta->documento === 'B' ? 'Boleta' : 'Factura',
                    'Estado' => $venta->estado,
                    'Vendedor' => $venta->usuario->empleado->persona->nombre . $venta->usuario->empleado->persona->apellidos ?? '-'
                ];
            });

            $filename = 'reporte_ventas_hoy_' . now()->format('Ymd_His') . '.xlsx';

            // ✅ Exportar el ARRAY transformado, no los modelos
            (new FastExcel($data))->export($filename);

            return response()->download($filename)->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteKardexSalida()
    {
        try {
            $kardex = Kardex::with('producto', 'usuario')
                ->where('tipo_movimiento', 'salida')
                ->get();

            $data = $kardex->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Nombre Producto' => $item->producto->nombre,
                    'Usuario' => $item->usuario->email,
                    'Cantidad' => $item->cantidad,
                    'Tipo de Movimiento' => $item->tipo_movimiento,
                    'Descripción' => $item->descripcion,
                    'Stock Anterior' => $item->stock_anterior,
                    'Stock Actual' => $item->stock_actual,
                    'Fecha del Movimiento' => $item->fecha_movimiento,
                    'Hora del Movimiento' => $item->created_at->format('H:i:s')
                ];
            });

            $filename = 'reporte_kardex_salida_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }
    public function reporteKardexEntrada()
    {
        try {
            $kardex = Kardex::with('producto', 'usuario')
                ->where('tipo_movimiento', 'entrada')
                ->get();

            $data = $kardex->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Nombre Producto' => $item->producto->nombre,
                    'Usuario' => $item->usuario->email,
                    'Cantidad' => $item->cantidad,
                    'Tipo de Movimiento' => $item->tipo_movimiento,
                    'Descripción' => $item->descripcion,
                    'Stock Anterior' => $item->stock_anterior,
                    'Stock Actual' => $item->stock_actual,
                    'Fecha del Movimiento' => $item->fecha_movimiento,
                    'Hora del Movimiento' => $item->created_at->format('H:i:s')
                ];
            });

            $filename = 'reporte_kardex_entrada_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteInventarioTodo()
    {
        try {
            $inventario = Inventario::with('categoria', 'unidad')->get();

            $data = $inventario->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Producto' => $item->nombre,
                    'Categoría' => optional($item->categoria)->nombre,
                    'Unidad' => optional($item->unidad)->nombre,
                    'Stock' => $item->stock,
                    'Precio Unitario' => $item->precio,
                    'Fecha Vencimiento' => $item->fecha_vencimiento,
                ];
            });

            $filename = 'reporte_inventario_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reportePlatosTodos()
    {
        try {
            $platos = Plato::with('sede', 'categoria')->get();

            $data = $platos->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Nombre' => $item->nombre,
                    'Categoría' => optional($item->categoria)->nombre,
                    'Descripción' => $item->descripcion ?? '',
                    'Precio' => $item->precio ?? '',
                ];
            });

            $filename = 'reporte_platos_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteAlmacenTodo()
    {
        try {
            $almacen = Almacen::with('sede', 'unidad', 'categoria', 'proveedor')->where('estado', 1)->get();

            $data = $almacen->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Sede' => $item->sede->nombre ?? '-',
                    'Nombre' => $item->nombre,
                    'Unidad' => optional($item->unidad)->nombre,
                    'Categoría' => optional($item->categoria)->nombre,
                    'Proveedor' => optional($item->proveedor)->nombre,
                    'Marca' => $item->marca ?? '',
                    'Presentación' => $item->presentacion ?? '',
                    'Descripción' => $item->descripcion ?? '',
                    'Precio Unitario' => $item->precioUnit ?? '',
                    'Cantidad' => $item->cantidad ?? '',
                    'Fecha de Vencimiento' => $item->fecha_vencimiento ?? '',
                ];
            });

            $filename = 'reporte_almacen_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteProveedores()
    {
        try {
            $proveedores = Almacen::with('proveedor')->whereHas('proveedor')->get()->pluck('proveedor')->unique('id');

            $data = $proveedores->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Nombre' => $item->nombre,
                    'Contacto' => $item->contacto ?? '',
                    'Tipo Documento' => $item->tipo_documento ?? '',
                    'RUC' => $item->numero_documento ?? '',
                    'Teléfono' => $item->telefono ?? '',
                    'Email' => $item->email ?? '',
                    'Dirección' => $item->direccion ?? '',
                    'Estado' => $item->estado === 1 ? 'Activo' : 'Inactivo',
                ];
            });

            $filename = 'reporte_proveedores_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteCompras()
    {
        try {
            $compras = Compra::with('usuario', 'proveedor', 'cuentaPorPagar')->get();

            $data = $compras->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Usuario' => optional($item->usuario)->email ?? '',
                    'Proveedor' => optional($item->proveedor)->nombre ?? '',
                    'N° Cuenta por Pagar' => $item->cuentaPorPagar->id ?? 'N/A',
                    'N° Compra' => $item->numero_compra ?? '',
                    'Fecha Compra' => $item->fecha_compra,
                    'Total' => (float) $item->totalPagado, // Convertir a número
                    'Tipo' => $item->tipoCompra,
                    'Observaciones' => $item->observaciones ?? '',
                    'Estado' => $item->estado === 1 ? 'Completado' : ($item->estado === 0 ? 'Pendiente' : 'Cancelado'),
                ];
            });

            $filename = 'reporte_compras_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteUsuarios()
    {
        try {
            $usuarios = User::with('empleado.persona', 'roles', 'sede')->get();

            $data = $usuarios->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Email' => $item->email,
                    'Nombre' => optional($item->empleado->persona)->nombre ?? '',
                    'Apellidos' => optional($item->empleado->persona)->apellidos ?? '',
                    'Documento' => optional($item->empleado->persona)->documento_identidad ?? '',
                    'Cargo' => optional($item->empleado->cargo)->nombre ?? '',
                    'Área' => optional($item->empleado->area)->nombre ?? '',
                    'Sede' => optional($item->sede)->nombre ?? '',
                    'Roles' => $item->roles->pluck('nombre')->join(', '),
                    'Estado' => $item->estado === 1 ? 'Activo' : 'Inactivo',
                ];
            });

            $filename = 'reporte_usuarios_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteHorasExtras()
    {
        try {
            $horasExtras = HoraExtras::with('usuario.empleado.persona')->get();

            $data = $horasExtras->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Email' => $item->usuario->email,
                    'Nombre' => optional($item->usuario->empleado->persona)->nombre ?? '',
                    'Apellidos' => optional($item->usuario->empleado->persona)->apellidos ?? '',
                    'Documento' => optional($item->usuario->empleado->persona)->documento_identidad ?? '',
                    'Cargo' => optional($item->usuario->empleado->cargo)->nombre ?? '',
                    'Horas trabajadas' => $item->horas_trabajadas,
                    'Pago total' => $item->pagoTotal,
                    'Estado' => $item->estado === 1 ? 'Trabajado' : 'Pendiente',
                ];
            });

            $filename = 'reporte_horasExtras_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }
    public function reporteVacaciones()
    {
        try {
            $horasExtras = Vacacione::with('usuario.empleado.persona')->get();

            $data = $horasExtras->map(function ($item) {
                return [
                    'ID' => $item->id,
                    'Email' => $item->usuario->email,
                    'Nombre' => optional($item->usuario->empleado->persona)->nombre ?? '',
                    'Apellidos' => optional($item->usuario->empleado->persona)->apellidos ?? '',
                    'Documento' => optional($item->usuario->empleado->persona)->documento_identidad ?? '',
                    'Cargo' => optional($item->usuario->empleado->cargo)->nombre ?? '',
                    'Fecha Inicio' => $item->fecha_inicio,
                    'Fecha Fin' => $item->fecha_fin,
                    'Dias total' => $item->dias_totales,
                    'Dias utilizados' => $item->dias_utilizados,
                    'Días vendidos' => $item->dias_vendidos,
                    'Estado pago/venta' => $item->estado === 1 ? 'Vacaciones vendidas' : 'NO vendidas',
                    'Estado' => $item->estado === 1 ? 'Completado' : 'Pendiente',
                ];
            });

            $filename = 'reporte_horasExtras_' . now()->format('Ymd_His') . '.xlsx';

            // 👇 Esto devuelve el archivo al navegador directamente
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error al generar reporte: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte: ' . $e->getMessage()
            ], 500);
        }
    }


    public function reporteAsistenciaHoy()
    {
        try {
            // 1. Obtenemos la fecha de hoy
            $hoy = Carbon::today();
            $asistencias = Asistencia::with('empleado')
                ->whereDate('fechaEntrada', $hoy)
                ->orderBy('horaEntrada', 'asc')
                ->get();

            // 3. Mapeamos los datos para el Excel
            $data = $asistencias->map(function ($item) {
                // Usamos optional() por si el empleado fue borrado para que no rompa el reporte
                $nombre = optional($item->empleado)->nombre ?? 'Desconocido';
                $apellidos = optional($item->empleado)->apellidos ?? '';

                return [
                    'ID Asistencia' => $item->id,
                    'DNI'           => optional($item->empleado)->documento_identidad ?? '-',
                    'Empleado'      => "$nombre $apellidos",
                    'Fecha'         => $item->fechaEntrada,
                    'Hora Entrada'  => $item->horaEntrada,
                    'Hora Salida'   => $item->horaSalida ?? 'Sin Marcar', // Manejo de nulos
                    'Estado'        => ucfirst($item->estadoAsistencia),   // Capitalizar (Puntual/Tardanza)
                ];
            });

            // 4. Nombre del archivo
            $filename = 'reporte_asistencia_dia_' . now()->format('d-m-Y_His') . '.xlsx';

            // 5. Descarga directa
            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error reporte asistencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar excel: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reporteAsistenciaPersonalizada(Request $request)
    {
        try {

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin    = $request->input('fecha_fin');


            $query = Asistencia::with('empleado')
                ->orderBy('fechaEntrada', 'desc')
                ->orderBy('horaEntrada', 'asc');


            $textoArchivo = "";

            if ($fechaInicio && $fechaFin) {

                $query->whereBetween('fechaEntrada', [$fechaInicio, $fechaFin]);
                $textoArchivo = "del_{$fechaInicio}_al_{$fechaFin}";
            } elseif ($fechaInicio) {

                $query->where('fechaEntrada', '>=', $fechaInicio);
                $textoArchivo = "desde_{$fechaInicio}";
            } else {

                $query->whereDate('fechaEntrada', Carbon::today());
                $textoArchivo = "dia_" . now()->format('d-m-Y');
            }

            $asistencias = $query->get();

            $data = $asistencias->map(function ($item) {
                $nombre = optional($item->empleado)->nombres ?? optional($item->empleado)->nombre ?? 'Desconocido';
                $apellidos = optional($item->empleado)->apellidos ?? '';

                return [
                    'ID'            => $item->id,
                    'DNI'           => optional($item->empleado)->documento_identidad ?? '-',
                    'Empleado'      => "$nombre $apellidos",
                    'Fecha'         => $item->fechaEntrada,
                    'Hora Entrada'  => $item->horaEntrada,
                    'Hora Salida'   => $item->horaSalida ?? 'Sin Marcar',
                    'Estado'        => ucfirst($item->estadoAsistencia ?? 'N/A'),
                ];
            });

            // 5. Nombre dinámico del archivo
            $filename = "reporte_asistencia_{$textoArchivo}_" . now()->format('His') . ".xlsx";

            return (new FastExcel($data))->download($filename);
        } catch (\Exception $e) {
            Log::error('Error reporte asistencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar excel: ' . $e->getMessage()
            ], 500);
        }
    }
}
