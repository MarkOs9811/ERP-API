<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CuentasContables;
use App\Models\CuentasPorCobrar;
use App\Models\CuentasPorPagar;
use App\Models\Cuota;
use App\Models\CuotasPorPagar;
use App\Models\DetalleLibro;
use App\Models\DocumentosFirmados;
use App\Models\Inventario;
use App\Models\LibroDiario;
use App\Models\LibroMayor;
use App\Models\MiEmpresa;
use App\Models\Pago;
use App\Models\Presupuestacion;
use App\Models\Proveedore;
use App\Models\RegistrosEjercicios;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class FinanzasController extends Controller
{
    public function getInformes()
    {
        try {
            $year = now()->year;

            // Total de pagos de empleados por mes
            $pagosEmpleados = Pago::selectRaw('MONTH(fecha_pago) as mes, SUM(salario_neto) as total')
                ->whereYear('fecha_pago', $year)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Ventas por mes
            $ventasPorMes = Venta::selectRaw('MONTH(fechaVenta) as mes, SUM(total) as total')
                ->whereYear('fechaVenta', $year)
                ->where('estado', 1)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            Log::info('Ventas por mes:', ['ventasPorMes' => $ventasPorMes]);

            // Cuentas por cobrar por mes
            $cuentasPorCobrar = Cuota::selectRaw('MONTH(fecha_pagada) as mes, SUM(monto) as total')
                ->whereYear('fecha_pagada', $year)
                ->where('estado', 'pagado')
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Cuotas por pagar por mes
            $cuotasPorPagar = CuotasPorPagar::selectRaw('MONTH(fecha_pagado) as mes, SUM(monto) as total')
                ->whereYear('fecha_pagado', $year)
                ->where('estado', 'pagado')
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Compras por mes (egresos)
            $comprasPorMes = Compra::selectRaw('MONTH(fecha_compra) as mes, SUM(totalPagado) as total')
                ->whereYear('fecha_compra', $year)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Total de préstamos registrados
            $totalPrestamos = CuentasPorCobrar::sum('monto');
            $montoPagado = CuentasPorCobrar::sum('monto_pagado');

            // Inicializar meses con 0
            $meses = array_fill(1, 12, 0);

            // Calcular ingresos (ventas + cobranzas)
            $ingresos = array_replace($meses, $ventasPorMes);
            foreach ($cuentasPorCobrar as $mes => $total) {
                $ingresos[$mes] = ($ingresos[$mes] ?? 0) + $total;
            }

            // Calcular egresos (pagos + cuotas + compras)
            $egresos = $meses;
            foreach ([$pagosEmpleados, $cuotasPorPagar, $comprasPorMes] as $grupo) {
                foreach ($grupo as $mes => $total) {
                    $egresos[$mes] = ($egresos[$mes] ?? 0) + $total;
                }
            }

            // Datos de ingresos/egresos
            $totalIngresos = array_sum($ingresos);
            $datosIngresosEgresos = [
                'labels' => array_keys($ingresos),
                'ingresos' => array_values($ingresos),
                'egresos' => array_values($egresos),
                'totalIngresos' => $totalIngresos,
            ];

            // Datos para gráficos
            $datosPagosEmpleados = [
                'labels' => array_keys($pagosEmpleados),
                'data' => array_values($pagosEmpleados)
            ];

            $datosCuentasPorPagar = [
                'labels' => array_keys($cuotasPorPagar),
                'data' => array_values($cuotasPorPagar)
            ];

            $ventasPorMesData = [
                'labels' => array_keys($ventasPorMes),
                'data' => array_values($ventasPorMes)
            ];

            // Respuesta final
            return response()->json([
                'success' => true,
                'data' => [
                    'datosPagosEmpleados' => $datosPagosEmpleados,
                    'montoPagado' => $montoPagado,
                    'totalPrestamos' => $totalPrestamos,
                    'datosCuentasPorPagar' => $datosCuentasPorPagar,
                    'ventasPorMesData' => $ventasPorMesData,
                    'datosIngresosEgresos' => $datosIngresosEgresos,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener informes financieros:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los informes financieros',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getLibroDiario()
    {
        try { // Obtener los asientos contables con las relaciones necesarias
            $asientos = LibroDiario::with(['usuario', 'detalles.cuenta'])
                ->orderBy('id', 'desc') // Ordenar por la columna de fecha en orden descendente
                ->get();

            // Obtener todas las cuentas contables desde la base de datos
            $cuentas = CuentasContables::where('estado', 1)->get();

            $data = [
                'asientos' => $asientos,
                'cuentas' => $cuentas
            ];
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el libro diario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getLibroMayor()
    {
        try {

            // Obtener todos los movimientos del Libro Mayor donde el estado sea 1
            $movimientos = LibroMayor::with('cuenta')
                ->get()
                ->groupBy(function ($item) {
                    return $item->cuenta->nombre; // Agrupar por nombre de cuenta
                });

            // Calcular los totales de débitos, créditos y balance general
            $totalDebitos = LibroMayor::sum('debe');
            $totalCreditos = LibroMayor::sum('haber');
            $balance = $totalDebitos - $totalCreditos;

            // Obtener el último registro de resultados del ejercicio desde la tabla 'registros_ejercicios'
            $registroEjercicio = RegistrosEjercicios::latest('created_at')->first(); // Cambiar a 'created_at'

            // Calcular el resultado del ejercicio (ingresos - gastos)
            $resultado = null;
            if ($registroEjercicio) {
                // Utilizamos los valores de ingresos y gastos registrados
                $resultado = $registroEjercicio->ingresos - $registroEjercicio->gastos;
            }

            $data = [
                'movimientos' => $movimientos,
                'totalDebitos' => $totalDebitos,
                'totalCreditos' => $totalCreditos,
                'balance' => $balance,
                'resultadoEjercicio' => $resultado,
                'registroEjercicio' => $registroEjercicio
            ];
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el libro mayor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // CERRAR VENTAS ANTES DE CARGAR AL LIBRO MAYOR
    public function procesarCierreVentasAnual(Request $request)
    {
        $user = Auth()->user();
        $idEmpresa = $user->idEmpresa;
        // Si quieres procesar un año específico, envíalo, sino usa el actual
        $anio = $request->input('anio', date('Y'));

        DB::beginTransaction();

        try {
            // PASO 1: Obtener ventas agrupadas por día
            // Esto es mucho más eficiente que traerlas todas y sumarlas con PHP
            $ventasPorDia = DB::table('ventas')
                ->where('idEmpresa', $idEmpresa)
                ->whereYear('fechaVenta', $anio) // Filtramos por el año
                ->where('estado', 1) // Solo ventas activas
                ->select(
                    'fechaVenta',
                    DB::raw('SUM(total) as total_dia'),
                    DB::raw('SUM(igv) as igv_dia'),
                    DB::raw('SUM(subtotal) as subtotal_dia'), // O la columna que uses para la base imponible
                    DB::raw('COUNT(id) as cantidad_ventas')
                )
                ->groupBy('fechaVenta')
                ->get();

            if ($ventasPorDia->isEmpty()) {
                return response()->json(['success' => false, 'message' => "No se encontraron ventas en el año $anio."], 404);
            }

            $asientosCreados = 0;

            // PASO 2: Recorrer cada día y crear su asiento contable
            foreach ($ventasPorDia as $dia) {

                // Verificamos si YA existe un cierre para ese día específico para no duplicar
                $existe = LibroDiario::where('idEmpresa', $idEmpresa)
                    ->where('fecha', $dia->fechaVenta)
                    ->where('descripcion', 'LIKE', 'Cierre diario de ventas%')
                    ->exists();

                if ($existe) {
                    continue; // Saltamos este día si ya estaba procesado
                }

                // A. Crear Cabecera del Asiento
                $libro = new LibroDiario();
                $libro->idEmpresa = $idEmpresa;
                $libro->idUsuario = $user->id;
                $libro->fecha = $dia->fechaVenta; // La fecha del asiento es la fecha de la venta
                $libro->estado = 1; // 1 = Confirmado/Activo
                $libro->descripcion = "Cierre diario de ventas (" . $dia->cantidad_ventas . " ops)";
                $libro->save();

                // B. Crear Detalles (Asiento Contable)

                // 1. CUENTA 12 - CLIENTES (Total a Cobrar) - DEBE
                // Usamos ID 4 según tu imagen (image_9e3f64)
                DetalleLibro::create([
                    'idEmpresa'     => $idEmpresa,
                    'idLibroDiario' => $libro->id,
                    'idCuenta'      => 4,
                    'tipo'          => 'debe',
                    'monto'         => $dia->total_dia,
                    'accion'        => 'debe',
                    'estado'        => 1
                ]);

                // 2. CUENTA 40 - IGV (Impuesto) - HABER
                // Usamos ID 11 según tu imagen
                if ($dia->igv_dia > 0) {
                    DetalleLibro::create([
                        'idEmpresa'     => $idEmpresa,
                        'idLibroDiario' => $libro->id,
                        'idCuenta'      => 11,
                        'tipo'          => 'haber',
                        'monto'         => $dia->igv_dia,
                        'accion'        => 'haber',
                        'estado'        => 1
                    ]);
                }

                // 3. CUENTA 70 - VENTAS (INGRESO REAL) - HABER
                // Usamos ID 6 (Venta mercaderia - Grupo 7) según tu imagen
                // ¡ESTO HARÁ QUE TUS INGRESOS DEJEN DE SER CERO!
                DetalleLibro::create([
                    'idEmpresa'     => $idEmpresa,
                    'idLibroDiario' => $libro->id,
                    'idCuenta'      => 6,
                    'tipo'          => 'haber',
                    'monto'         => $dia->subtotal_dia,
                    'accion'        => 'haber',
                    'estado'        => 1
                ]);

                $asientosCreados++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Proceso completado. Se generaron $asientosCreados asientos contables para el año $anio."
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el cierre de ventas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function cargarLibroMayor(Request $request)
    {
        $user = Auth()->user();
        // 1. Validar inputs
        $anio = $request->input('anio', date('Y'));
        $idEmpresa = $request->input('idEmpresa', 2);

        DB::beginTransaction();

        try {
            // --- PASO A: LIMPIEZA PREVIA ---
            LibroMayor::where('idEmpresa', $idEmpresa)
                ->whereYear('fecha', $anio)
                ->delete();

            // --- PASO B: CONSULTA CORREGIDA ---
            // Ya no pedimos 'debe' ni 'haber', pedimos 'monto' y 'tipo'
            $movimientos = DB::table('detalle_libros')
                ->join('libro_diarios', 'detalle_libros.idLibroDiario', '=', 'libro_diarios.id')
                ->where('libro_diarios.idEmpresa', $idEmpresa)
                ->whereYear('libro_diarios.fecha', $anio)
                ->select(
                    'detalle_libros.idCuenta',
                    'detalle_libros.monto',    // <--- CAMBIO IMPORTANTE
                    'detalle_libros.tipo',     // <--- CAMBIO IMPORTANTE
                    // 'detalle_libros.descripcion', // Lo quité porque no parece existir en tu imagen
                    'libro_diarios.descripcion as descripcion_asiento',
                    'libro_diarios.fecha',
                    'libro_diarios.idEmpresa',
                    'libro_diarios.id as idAsiento'
                )
                ->get();

            if ($movimientos->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "No se encontraron movimientos para el año $anio."
                ], 404);
            }

            // --- PASO C: TRANSFORMACIÓN DE DATOS ---
            $dataToInsert = [];
            $now = Carbon::now();

            foreach ($movimientos as $mov) {
                // Lógica para separar Debe y Haber según el 'tipo'
                $debe = 0;
                $haber = 0;

                // Convertimos a minúsculas por seguridad y comparamos
                if (strtolower($mov->tipo) === 'debe') {
                    $debe = $mov->monto;
                } else {
                    $haber = $mov->monto;
                }

                $nombreTransaccion = 'Transacción N° ' . $mov->idAsiento;

                $dataToInsert[] = [
                    'idUsuario'   => $user->id,
                    'idEmpresa'   => $mov->idEmpresa,
                    'idCuentaContable' => $mov->idCuenta, // Asegúrate que en tu BD sea idCuentaContable o idCuenta

                    // --- CORRECCIÓN AQUÍ ---
                    'nombreTransaccion' => $nombreTransaccion, // Corregido el typo y asignado valor

                    'fecha'       => $mov->fecha,
                    'descripcion' => $mov->descripcion_asiento,
                    'debe'        => $debe,
                    'haber'       => $haber,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }

            // --- PASO D: INSERCIÓN MASIVA ---
            $chunks = array_chunk($dataToInsert, 500);
            foreach ($chunks as $chunk) {
                LibroMayor::insert($chunk);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Carga completada. Se procesaron ' . count($dataToInsert) . ' registros.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            // Loguear el error exacto
            \Illuminate\Support\Facades\Log::error("Error en CargarLibroMayor: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno al cargar el libro mayor.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
    public function cierreEjercicio(Request $request)
    {
        // 1. Obtener usuario autenticado
        $user = Auth()->user();

        $idEmpresa = $user->idEmpresa;

        $anio = $request->input('anio', date('Y'));

        if (!$idEmpresa) {
            return response()->json(['success' => false, 'message' => 'El usuario no tiene empresa asignada'], 400);
        }

        DB::beginTransaction();

        try {

            $ingresos = DB::table('libro_mayors')
                ->join('cuentas_contables', 'libro_mayors.idCuentaContable', '=', 'cuentas_contables.id')
                ->where('libro_mayors.idEmpresa', $idEmpresa) // Usa el ID del usuario
                ->whereYear('libro_mayors.fecha', $anio)
                ->where('cuentas_contables.idGrupoCuenta', 7)
                ->sum(DB::raw('libro_mayors.haber - libro_mayors.debe'));

            // 2. Calcular GASTOS (idGrupoCuenta = 6)
            $gastos = DB::table('libro_mayors')
                ->join('cuentas_contables', 'libro_mayors.idCuentaContable', '=', 'cuentas_contables.id')
                ->where('libro_mayors.idEmpresa', $idEmpresa) // Usa el ID del usuario
                ->whereYear('libro_mayors.fecha', $anio)
                ->where('cuentas_contables.idGrupoCuenta', 6)
                ->sum(DB::raw('libro_mayors.debe - libro_mayors.haber'));

            $ingresos = $ingresos ?? 0;
            $gastos = $gastos ?? 0;

            // 3. Guardar registro
            $registro = RegistrosEjercicios::updateOrCreate(
                [
                    'idEmpresa' => $idEmpresa,
                    'temporada' => $anio
                ],
                [
                    'idUsuario'   => $user->id,
                    'fechaInicio' => Carbon::createFromDate($anio, 1, 1)->format('Y-m-d'),
                    'fechaFin'    => Carbon::createFromDate($anio, 12, 31)->format('Y-m-d'),
                    'ingresos'    => $ingresos,
                    'gastos'      => $gastos,
                    'created_at'  => Carbon::now(),
                    'updated_at'  => Carbon::now()
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Cierre del ejercicio $anio calculado correctamente.",
                'data'    => [
                    'ingresos'  => number_format($ingresos, 2),
                    'gastos'    => number_format($gastos, 2),
                    'resultado' => number_format($ingresos - $gastos, 2)
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular el cierre del ejercicio',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getCuentasPorCobrar()
    {
        try {
            $miEmpresa = MiEmpresa::first();
            $clientes = Cliente::with('persona', 'empresa')->where('estado', 1)->get();

            // Carga las cuentas por cobrar con la relación cliente
            $cuentasPorCobrar = CuentasPorCobrar::with('cuotasProgramadas', 'cliente.persona', 'cliente.empresa')->get();

            $cuentas = CuentasContables::all();
            $data = [
                'mi_empresa' => $miEmpresa,
                'clientes' => $clientes,
                'cuentas_por_cobrar' => $cuentasPorCobrar,
                'cuentas' => $cuentas
            ];
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cuentas por cobrar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getCuentasPorPagar()
    {
        try {
            $proveedores = Proveedore::where('estado', 1)->get();
            $cuentasPorPagar = CuentasPorPagar::with('proveedor', 'cuotasPagar')->get();
            $cuentas = CuentasContables::get();
            $data = [
                'proveedores' => $proveedores,
                'cuentas_por_pagar' => $cuentasPorPagar,
                'cuentas' => $cuentas
            ];
            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las cuentas por pagar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // PAGAR LA CUOTA QUE SE DEBE 
    public function pagarCuota($id)
    {
        try {
            // Iniciar una transacción para mantener la consistencia en caso de errores
            DB::beginTransaction();



            // Buscar y actualizar la cuota utilizando Eloquent
            $cuota = CuotasPorPagar::findOrFail($id);
            $cuota->estado = 'pagado';
            $cuota->fecha_pagado = Carbon::now()->toDateString();
            $cuota->save();

            $descripcion = "Pago de cuota a proveedor";

            // Actualizar el monto pagado en cuentas por pagar
            $cuentaPorPagar = CuentasPorPagar::findOrFail($cuota->idCuentaPorPagar);
            $cuentaPorPagar->monto_pagado += $cuota->monto;
            $cuentaPorPagar->cuotas_pagadas += 1;
            $cuentaPorPagar->save();

            // Verificar si todas las cuotas han sido pagadas
            $cuotasPendientes = CuotasPorPagar::where('idCuentaPorPagar', $cuentaPorPagar->id)
                ->where('estado', 'pendiente')
                ->count();

            if ($cuotasPendientes === 0) {
                $cuentaPorPagar->estado = 'pagado';
                $cuentaPorPagar->save();
                $compra = Compra::where('idCuentaPorPagar', $cuentaPorPagar->id)->first();
                if ($compra) {
                    $compra->estado = 1;
                    $compra->save();
                }
            }

            // ======= Registro en el Libro Diario =======

            // Registrar en la tabla `libro_diario`
            $libroDiario = new LibroDiario();
            $libroDiario->idUsuario = auth()->id();
            $libroDiario->fecha = Carbon::now();
            $libroDiario->estado = 0; // Estado pendiente por defecto
            $libroDiario->descripcion = $descripcion;
            $libroDiario->save();

            // Calcular el IGV y el monto neto
            $montoTotal = $cuota->monto;
            $porcentajeIGV = 0.18; // 18% IGV
            $montoIGV = $montoTotal * $porcentajeIGV;
            $montoNeto = $montoTotal - $montoIGV;

            // Registrar en `detalle_libros` usando el método privado
            $this->registrarDetalleLibro($libroDiario->id, [
                ['codigo' => '101', 'accion' => 'debe', 'monto' => $cuota->monto],

                ['codigo' => '1212', 'accion' => 'haber', 'monto' => $cuota->monto]
            ]);

            // Confirmar la transacción
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Cuota pagada y registrada en el libro diario.'], 200);
        } catch (\Exception $e) {
            // Revertir en caso de error
            DB::rollBack();
            Log::error('Error al marcar la cuota como pagada: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al marcar la cuota como pagada'], 500);
        }
    }
    public function getPresupuestacion()
    {
        try {
            // Obtener el año actual
            $anioActual = Carbon::now()->year;
            $mesActual = Carbon::now()->month;

            // Obtener todos los presupuestos del año actual
            $presupuestos = Presupuestacion::get();

            // Obtener los presupuestos del mes actual
            $presupuestosMensual = Presupuestacion::where('anio', $anioActual)
                ->where('mes', $mesActual)
                ->where('tipo_presupuesto', 'ingresos')
                ->sum('monto_presupuestado');

            // Obtener los ingresos (ventas) del mes actual
            $ingresosMensuales = Venta::whereYear('fechaVenta', $anioActual)
                ->whereMonth('fechaVenta', $mesActual)
                ->sum('total');

            // Obtener los datos del gráfico de gastos
            $graficoGastos = $this->obtenerDatosGraficoGastos($anioActual, $mesActual);

            $valorAlmacen = Almacen::sum(DB::raw('precioUnit * cantidad'));
            $valorInventario = Inventario::sum(DB::raw('precio * stock'));
            $valorPagar = CuentasPorPagar::sum(DB::raw('monto - monto_pagado'));
            $cuentaCaja = CuentasContables::where('codigo', '101')->first();

            // CONSULTAS PARA EL FLUJO DE CAJA
            $flujoCaja = $this->obtenerDatosFlujoCaja();
            // Enviar estos valores a la vista para el gráfico
            return response()->json([
                'success' => true,
                'data' => [
                    'presupuestos' => $presupuestos,
                    'presupuestosMensual' => $presupuestosMensual,
                    'ingresosMensuales' => $ingresosMensuales,
                    'graficoGastos' => $graficoGastos,
                    'valorAlmacen' => $valorAlmacen,
                    'valorInventario' => $valorInventario,
                    'valorPagar' => $valorPagar,
                    'cuentaCaja' => $cuentaCaja,
                    'flujoCaja' => $flujoCaja
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la presupuestación',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Método privado para obtener datos acumulados del flujo de caja.
     *
     * @return array
     */
    private function obtenerDatosFlujoCaja()
    {
        // 1. Ventas al contado (total pagado)
        $ventasContado = Venta::where('estado', '1')
            ->sum('total');

        // 2. Ventas al crédito (monto cobrado)
        $ventasCreditoCobradas = Cuota::where('estado', 'pagado')
            ->sum('monto'); // Asumiendo un campo `total_pagado`

        // 3. Compras al contado (total pagado)
        $comprasContado = Compra::where('tipoCompra', 'contado')
            ->sum('totalPagado');

        // 4. Compras al crédito (monto pagado)
        $comprasCreditoPagadas = CuotasPorPagar::where('estado', 'pagado')
            ->sum('monto'); // Asumiendo un campo `monto`

        // 5. Pagos al personal (total acumulado)
        $pagosPersonal = Pago::sum('salario_neto');

        // 6. Inversiones (aún no se tienen tablas de inversión, asignar 0)
        $valorAlmacen = Almacen::sum(DB::raw('precioUnit * cantidad'));
        $valorInventario = Inventario::sum(DB::raw('precio * stock'));
        $inversionesActivos = $valorAlmacen + $valorInventario;

        // 7. Financiamiento (aún no se tienen tablas de financiamiento, asignar 0)
        $prestamosRecibidos = 0;  // No se tiene la tabla de financiamiento, se pone 0 por ahora
        $pagoDeudas = 0;  // No se tiene la tabla de financiamiento, se pone 0 por ahora

        return [
            'ventasContado' => $ventasContado,
            'ventasCreditoCobradas' => $ventasCreditoCobradas,
            'comprasContado' => $comprasContado,
            'comprasCreditoPagadas' => $comprasCreditoPagadas,
            'pagosPersonal' => $pagosPersonal,
            'inversionesActivos' => $inversionesActivos,  // Agregado a los resultados
            'prestamosRecibidos' => $prestamosRecibidos,  // Agregado a los resultados
            'pagoDeudas' => $pagoDeudas,  // Agregado a los resultados
        ];
    }


    private function obtenerDatosGraficoGastos($anioActual, $mesActual)
    {
        // Obtener los gastos de la tabla compras del año actual y meses anteriores
        $gastosComprasMensuales = Compra::whereYear('fecha_compra', $anioActual)
            ->whereMonth('fecha_compra', '<=', $mesActual)
            ->selectRaw('MONTH(fecha_compra) as mes, SUM(totalPagado) as gasto_total')
            ->groupBy('mes')
            ->where('estado', 1)
            ->get();

        // Obtener los gastos del presupuesto (tipo "gastos") del año actual y todos los meses registrados
        $gastosPresupuestacion = Presupuestacion::where('anio', $anioActual)
            ->where('tipo_presupuesto', 'gastos')
            ->whereIn('mes', range(1, $mesActual)) // Consideramos los meses hasta el actual
            ->select('mes', 'monto_presupuestado')
            ->get();

        // Obtener los pagos realizados en cuotas para el año actual y meses anteriores
        $pagosPorCuotas = CuotasPorPagar::whereYear('fecha_pagado', $anioActual)
            ->whereMonth('fecha_pagado', '<=', $mesActual)
            ->where('estado', 'pagado')
            ->selectRaw('MONTH(fecha_pagado) as mes, SUM(monto) as gasto_cuotas')
            ->groupBy('mes')
            ->get();

        // Preparar los datos para el gráfico
        $labels = []; // Meses para el gráfico
        $gastos = []; // Gastos acumulados
        $gastosPresupuestados = []; // Gastos presupuestados

        // Llenar los datos para el gráfico de cada mes
        foreach (range(1, $mesActual) as $mes) {
            $mesNombre = Carbon::createFromFormat('m', $mes)->format('F'); // Nombre del mes

            // Añadir el nombre del mes a las etiquetas
            $labels[] = $mesNombre;

            // Obtener el gasto total de las compras para este mes
            $gastoCompra = $gastosComprasMensuales->firstWhere('mes', $mes);
            $gastoTotalCompra = $gastoCompra ? $gastoCompra->gasto_total : 0;

            // Obtener el gasto total por cuotas para este mes
            $gastoCuota = $pagosPorCuotas->firstWhere('mes', $mes);
            $gastoTotalCuota = $gastoCuota ? $gastoCuota->gasto_cuotas : 0;

            // Sumar ambos gastos
            $gastos[] = $gastoTotalCompra + $gastoTotalCuota;

            // Obtener el monto presupuestado para este mes
            $gastoPresupuesto = $gastosPresupuestacion->firstWhere('mes', $mes);
            $gastosPresupuestados[] = $gastoPresupuesto ? $gastoPresupuesto->monto_presupuestado : 0;
        }

        // Devolver los datos para el gráfico
        return [
            'labels' => $labels,
            'gastos' => $gastos,
            'gastosPresupuestados' => $gastosPresupuestados,
        ];
    }

    public function marcarPagada(Request $request, $id)
    {
        try {
            // Iniciar una transacción para mantener la consistencia en caso de errores
            DB::beginTransaction();



            // Buscar y actualizar la cuota utilizando Eloquent
            $cuota = Cuota::findOrFail($id);
            $cuota->estado = 'pagado';
            $cuota->fecha_pagada = Carbon::now()->toDateString();
            $cuota->save();

            $descripcion = "Pago de cuota por cobrar";

            // Actualizar el monto pagado en cuentas por cobrar
            $cuentaPorCobrar = CuentasPorCobrar::findOrFail($cuota->cuenta_por_cobrar_id);
            $cuentaPorCobrar->monto_pagado += $cuota->monto;
            $cuentaPorCobrar->cuotas_pagadas += 1;
            $cuentaPorCobrar->save();

            // Verificar si todas las cuotas han sido pagadas
            $cuotasPendientes = Cuota::where('cuenta_por_cobrar_id', $cuentaPorCobrar->id)
                ->where('estado', 'pendiente')
                ->count();

            if ($cuotasPendientes === 0) {

                $cuentaPorCobrar->estado = 'pagado';
                $cuentaPorCobrar->save();

                $veneta = Venta::where('id', $cuentaPorCobrar->idVenta)->first();
                if ($veneta) {
                    $veneta->estado = 1;
                    $veneta->save();
                }
            }

            // ======= Registro en el Libro Diario =======

            // Registrar en la tabla `libro_diario`
            $libroDiario = new LibroDiario();
            $libroDiario->idUsuario = auth()->id();
            $libroDiario->idEmpresa = auth()->user()->idEmpresa; // IMPORTANTE
            $libroDiario->fecha = Carbon::now();
            $libroDiario->estado = 1;
            $libroDiario->descripcion = "Cobro de cuota #" . $cuota->id;
            $libroDiario->save();

            // Calcular el IGV y el monto neto
            $montoTotal = $cuota->monto;
            $porcentajeIGV = 0.18; // 18% IGV
            $montoIGV = $montoTotal * $porcentajeIGV;
            $montoNeto = $montoTotal - $montoIGV;

            // Registrar en `detalle_libros` usando el método privado
            $this->registrarDetalleLibro($libroDiario->id, [
                ['codigo' => '101', 'accion' => 'debe', 'monto' => $cuota->monto],

                ['codigo' => '1212', 'accion' => 'haber', 'monto' => $cuota->monto]
            ]);

            // Confirmar la transacción
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Cuota marcada como pagada y registros contables actualizados'], 200);
        } catch (\Exception $e) {
            // Revertir en caso de error
            DB::rollBack();
            Log::error('Error al marcar la cuota como pagada: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al marcar la cuota como pagada'], 500);
        }
    }
    /**
     * Método privado para registrar los detalles en `detalle_libros`
     */
    private function registrarDetalleLibro($idLibroDiario, $registros)
    {
        foreach ($registros as $registro) {
            // Buscar la cuenta contable por su código
            $cuenta = CuentasContables::where('codigo', $registro['codigo'])->firstOrFail();

            // Crear el registro en detalle_libros
            $detalle = new DetalleLibro();
            $detalle->idLibroDiario = $idLibroDiario;
            $detalle->idCuenta = $cuenta->id;
            $detalle->tipo = $registro['accion']; // 'debe' o 'haber'
            $detalle->monto = $registro['monto'];
            $detalle->accion = $registro['accion']; // 'debe' o 'haber'
            $detalle->estado = 1; // Asumimos que es activo por defecto
            $detalle->save();
        }
    }

    // PARA FIRMAR SOLICITUD CONIMAGEN A UN PDF
    public function addImageToPdf(Request $request)
    {
        try {
            // Validar y cargar archivos
            $request->validate([
                'pdf_file' => 'required|mimes:pdf|max:10000', // Tamaño máximo de 10MB
                'image_file' => 'required|image|max:5000', // Tamaño máximo de 5MB
            ]);

            Log::info('Archivos recibidos para firmar PDF', [
                'pdf_file' => $request->file('pdf_file')->getClientOriginalName(),
                'image_file' => $request->file('image_file')->getClientOriginalName()
            ]);

            $pdfFilePath = $request->file('pdf_file')->store('pdfs');
            $imageFilePath = $request->file('image_file')->store('images');

            $pdfFullPath = storage_path('app/' . $pdfFilePath);
            $imageFullPath = storage_path('app/' . $imageFilePath);

            if (!file_exists($pdfFullPath) || !file_exists($imageFullPath)) {
                Log::error('Archivo PDF o imagen no encontrado');
                return response()->json(['success' => false, 'message' => 'Archivo no encontrado.'], 404);
            }

            $fpdi = new Fpdi();

            // Cargar el PDF original
            $pageCount = $fpdi->setSourceFile($pdfFullPath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $fpdi->importPage($pageNo);
                $fpdi->AddPage();
                $fpdi->useTemplate($templateId, 0, 0);

                // Obtener dimensiones de la página
                $pageSize = $fpdi->getTemplateSize($templateId);
                $pageWidth = $pageSize['width'];
                $pageHeight = $pageSize['height'];

                // Agregar la imagen de firma en la parte superior derecha
                $fpdi->Image($imageFullPath, $pageWidth - 70, 250, 50);

                // Agregar texto "Firma de finanzas" debajo de la firma
                $fpdi->SetFont('Arial', 'B', 12);
                $fpdi->SetTextColor(0, 0, 0);
                $fpdi->SetXY($pageWidth - 70, 265, 80);
                $fpdi->Cell(0, 10, 'Firma de finanzas', 0, 1, 'C');
            }

            // Guardar el PDF firmado en storage/app/public/pdfs
            $outputPdfPath = 'pdfs/solicitud_' . time() . '.pdf';
            $outputPdfFullPath = storage_path('app/public/' . $outputPdfPath);
            $fpdi->Output($outputPdfFullPath, 'F');

            // Devolver la URL pública del PDF generado
            $pdfUrl = Storage::url($outputPdfPath);

            $documento_firmado = new DocumentosFirmados();
            $documento_firmado->idUsuario = Auth::id();
            $documento_firmado->nombre_archivo = $request->file('pdf_file')->getClientOriginalName();
            $documento_firmado->ruta_archivo = $pdfUrl;
            $documento_firmado->save();

            return response()->json([
                'success' => true,
                'pdf_url' => $pdfUrl,
                'message' => 'Documento firmado correctamente'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al procesar el PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDocFirmados()
    {
        try {
            $documentosFirmados = DocumentosFirmados::with('usuario.empleado.persona')->where('idUsuario', Auth::id())->get();
            return response()->json(['success' => true, 'data' => $documentosFirmados], 200);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al obtener los datos"
            ], 500);
        }
    }
    public function borrarDocumento($id)
    {
        try {
            // Buscar el documento por ID
            $documento = DocumentosFirmados::find($id);

            if (!$documento) {
                return response()->json([
                    'success' => false,
                    'message' => 'El documento no existe.'
                ], 404);
            }

            // Obtener la ruta del archivo (ejemplo: /storage/pdfs/solicitud_1759878426.pdf)
            $rutaArchivo = $documento->ruta_archivo;

            // Convertir la ruta pública a la ruta interna de storage
            // Quitamos "/storage/" y reemplazamos por "public/"
            $rutaStorage = str_replace('/storage/', 'public/', $rutaArchivo);

            // Eliminar el archivo físico si existe
            if (Storage::exists($rutaStorage)) {
                Storage::delete($rutaStorage);
            }

            // Eliminar el registro de la base de datos
            $documento->delete();

            return response()->json([
                'success' => true,
                'message' => 'Documento eliminado correctamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error en el servidor.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
