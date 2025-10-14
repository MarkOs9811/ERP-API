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
            // Obtener el total de pagos de empleados por mes
            $pagosEmpleados = DB::table('pagos')
                ->select(DB::raw('MONTH(fecha_pago) as mes'), DB::raw('SUM(salario_neto) as total'))
                ->whereYear('fecha_pago', now()->year)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Obtener ventas por mes
            $ventasPorMes = DB::table('ventas')
                ->select(DB::raw('MONTH(fechaVenta) as mes'), DB::raw('SUM(total) as total'))
                ->whereYear('fechaVenta', now()->year)
                ->where('estado', 1)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();
            Log::info('Ventas por mes:', ['ventasPorMes' => $ventasPorMes]);
            // Obtener cuentas por cobrar por mes
            $cuentasPorCobrar = DB::table('cuotas')
                ->select(DB::raw('MONTH(cuotas.fecha_pagada) as mes'), DB::raw('SUM(cuotas.monto) as total'))
                ->whereYear('cuotas.fecha_pagada', now()->year)
                ->where('estado', 'pagado')
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Obtener cuotas por pagar por mes 
            $cuotasPorPagar = DB::table('cuotas_por_pagars')
                ->select(DB::raw('MONTH(fecha_pagado) as mes'), DB::raw('SUM(cuotas_por_pagars.monto) as total'))
                ->whereYear('fecha_pagado', now()->year)
                ->where('estado', 'pagado')
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Obtener compras por mes (egresos)
            $comprasPorMes = DB::table('compras')
                ->select(DB::raw('MONTH(fecha_compra) as mes'), DB::raw('SUM(totalPagado) as total'))
                ->whereYear('fecha_compra', now()->year)
                ->groupBy('mes')
                ->pluck('total', 'mes')
                ->toArray();

            // Obtener el monto total de todos los préstamos registrados
            $totalPrestamos = DB::table('cuentas_por_cobrars')
                ->select(DB::raw('SUM(monto) as total'))
                ->pluck('total')
                ->first();

            $montoPagado = DB::table('cuentas_por_cobrars')
                ->select(DB::raw('SUM(monto_pagado) as total'))
                ->pluck('total')
                ->first();

            // Inicializar todos los meses del año con cero
            $meses = array_fill(1, 12, 0);

            // Calcular ingresos por mes
            $ingresos = array_replace($meses, $ventasPorMes);

            foreach ($cuentasPorCobrar as $mes => $total) {
                if (isset($ingresos[$mes])) {
                    $ingresos[$mes] += $total;
                } else {
                    $ingresos[$mes] = $total;
                }
            }

            // Calcular egresos por mes
            $egresos = $meses;
            foreach ($pagosEmpleados as $mes => $total) {
                if (isset($egresos[$mes])) {
                    $egresos[$mes] += $total;
                } else {
                    $egresos[$mes] = $total;
                }
            }

            foreach ($cuotasPorPagar as $mes => $total) {
                if (isset($egresos[$mes])) {
                    $egresos[$mes] += $total;
                } else {
                    $egresos[$mes] = $total;
                }
            }

            foreach ($comprasPorMes as $mes => $total) {
                if (isset($egresos[$mes])) {
                    $egresos[$mes] += $total;
                } else {
                    $egresos[$mes] = $total;
                }
            }

            // Datos para el gráfico de ingresos y egresos
            $totalIngresos = array_sum($ingresos);

            $datosIngresosEgresos = [
                'labels' => array_keys($ingresos),
                'ingresos' => array_values($ingresos),
                'egresos' => array_values($egresos),
                'totalIngresos' => $totalIngresos // Puedes incluirlo en la respuesta si lo necesitas
            ];


            // Datos para otros gráficos
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

            // Devolver todo dentro de 'data'
            return response()->json([
                'success' => true,
                'data' => [
                    'datosPagosEmpleados' => $datosPagosEmpleados,
                    'montoPagado' => $montoPagado,
                    'totalPrestamos' => $totalPrestamos,
                    'datosCuentasPorPagar' => $datosCuentasPorPagar,
                    'ventasPorMesData' => $ventasPorMesData,
                    'datosIngresosEgresos' => $datosIngresosEgresos
                ]
            ]);
        } catch (\Exception $e) {
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
                'resultadoEjercicio' => $resultado
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
                ['codigo' => '101', 'accion' => 'debe', 'monto' => $montoTotal],      // Banco/Caja (DEBE)
                ['codigo' => '4212', 'accion' => 'haber', 'monto' => $montoNeto],     // Cuentas por Pagar a Proveedores (HABER)
                ['codigo' => '4011', 'accion' => 'haber', 'monto' => $montoIGV],      // IGV por Pagar (HABER)
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
                ['codigo' => '101', 'accion' => 'debe', 'monto' => $montoTotal],      // Caja/Banco (DEBE)
                ['codigo' => '1212', 'accion' => 'haber', 'monto' => $montoNeto],    // Cuentas por Cobrar Comerciales (HABER)
                ['codigo' => '4011', 'accion' => 'haber', 'monto' => $montoIGV],     // IGV por Pagar (HABER)
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
