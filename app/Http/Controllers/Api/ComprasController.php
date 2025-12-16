<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Compra;
use App\Models\CuentasContables;
use App\Models\CuentasPorPagar;
use App\Models\CuotasPorPagar;
use App\Models\DetalleLibro;
use App\Models\LibroDiario;
use App\Models\Proveedore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ComprasController extends Controller
{
    public function getCompras()
    {
        try {
            $proveedores = Proveedore::where('estado', 1)->get();
            $totalCompras = Compra::count();
            $comprasMes = Compra::whereMonth('fecha_compra', now()->month)->count();
            $egresoMes = Compra::whereMonth('fecha_compra', now()->month)->sum('totalPagado');
            $proveedorTop = Compra::select('idProveedor', DB::raw('count(*) as total'))
                ->groupBy('idProveedor')
                ->orderBy('total', 'desc')
                ->first();
            $compras = Compra::with('proveedor', 'usuario.empleado.persona')->orderBy('id', 'Desc')->get();

            $data = [
                'totalCompras' => $totalCompras,
                'comprasMes' => $comprasMes,
                'egresoMes' => $egresoMes,
                'proveedorTop' => $proveedorTop,
                'compras' => $compras,
                'proveedores' => $proveedores
            ];

            return response()->json(['success' => true, 'data' => $data, 'message' => 'Compras obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function storeCompra(Request $request)
    {
        // Log de entrada
        Log::info('Datos recibidos en storeCompra:', $request->all());

        // Eliminar o establecer en null el campo 'numeroCuotas' si el método de pago es 'contado'
        if ($request->metodoPago === 'contado') {
            $request->merge(['numeroCuotas' => null]);
        }

        try {
            $request->validate([
                'totalPagado' => 'required|numeric',
                'fecha_compra' => 'required|date',
                'idProveedor' => 'required|exists:proveedores,id',
                'metodoPago' => 'required|in:credito,contado',
                'numeroCuotas' => 'nullable|required_if:metodoPago,credito|integer|min:1',
                'documento' => 'nullable|file|mimes:pdf,jpg,jpeg,png'
            ]);
            Log::info('Validación pasada correctamente.');

            DB::beginTransaction();

            // Calcular el IGV (18%)
            $montoBase = $request->totalPagado / 1.18;
            $igv = $request->totalPagado - $montoBase;
            Log::debug('Cálculo de montos:', [
                'totalPagado' => $request->totalPagado,
                'montoBase' => $montoBase,
                'igv' => $igv
            ]);

            // Obtener el último número de compra
            $lastCompra = Compra::orderBy('id', 'desc')->first();
            $newNumeroCompra = $lastCompra
                ? str_pad(intval($lastCompra->numero_compra) + 1, 5, '0', STR_PAD_LEFT)
                : '00001';
            Log::debug('Nuevo número de compra:', ['numero' => $newNumeroCompra]);

            $idCuentaPorPagar = null;

            // Si el método de pago es crédito
            if ($request->metodoPago === 'credito') {
                Log::info('Registrando compra a crédito.');
                $cuentaPorPagar = new CuentasPorPagar();
                $cuentaPorPagar->idUsuario = auth()->id();
                $cuentaPorPagar->idProveedor = $request->idProveedor;
                $cuentaPorPagar->nombreTransaccion = 'Cuentas por Pagar';
                $cuentaPorPagar->fecha_pago = now()->addMonth();
                $cuentaPorPagar->cuotas = $request->numeroCuotas;
                $cuentaPorPagar->monto = $request->totalPagado;
                $cuentaPorPagar->descripcion = "Credito de compra";
                $cuentaPorPagar->estado = 'pendiente';
                $cuentaPorPagar->save();

                $idCuentaPorPagar = $cuentaPorPagar->id;
                Log::debug('Cuenta por pagar registrada:', ['id' => $idCuentaPorPagar]);

                $montoPorCuota = $request->totalPagado / $request->numeroCuotas;
                for ($i = 1; $i <= $request->numeroCuotas; $i++) {
                    $cuota = new CuotasPorPagar();
                    $cuota->idCuentaPorPagar = $cuentaPorPagar->id;
                    $cuota->cuotas = $i;
                    $cuota->fecha_pago = now()->addMonths($i);
                    $cuota->monto = round($montoPorCuota, 2);
                    $cuota->monto_pagado = 0;
                    $cuota->estado = 'pendiente';
                    $cuota->save();
                }
                Log::info('Cuotas registradas correctamente.');
            } else {
                Log::info('Registrando compra al contado.');
                $libroDiario = new LibroDiario();
                $libroDiario->idUsuario = auth()->id();
                $libroDiario->fecha = $request->fecha_compra;
                $libroDiario->descripcion = "Compra al contado a proveedor";
                $libroDiario->estado = 0;
                $libroDiario->save();

                // --- CORRECCIÓN AQUÍ ---
                $this->registrarDetalleLibro($libroDiario->id, [
                    // 1. El Gasto (Mercadería/Compra) va al DEBE (Aumenta el gasto)
                    ['codigo' => '601', 'accion' => 'debe', 'monto' => $montoBase],

                    // 2. El IGV va al DEBE (Es un crédito fiscal a tu favor)
                    ['codigo' => '4011', 'accion' => 'debe', 'monto' => $igv],

                    // 3. La Caja va al HABER (El dinero sale de tu bolsillo)
                    ['codigo' => '101', 'accion' => 'haber', 'monto' => $request->totalPagado],
                ]);


                Log::info('Libro diario registrado.');
            }

            // Registrar la compra
            $compra = new Compra();
            $compra->idUsuario = auth()->id();
            $compra->idProveedor = $request->idProveedor;
            $compra->idCuentaPorPagar = $idCuentaPorPagar;
            $compra->fecha_compra = $request->fecha_compra;
            $compra->totalPagado = $request->totalPagado;
            $compra->numero_compra = $newNumeroCompra;
            $compra->observaciones = $request->observaciones;
            $compra->tipoCompra = $request->metodoPago;
            $compra->estado = $request->metodoPago === 'credito' ? 0 : 1;

            if ($request->hasFile('documento')) {
                $filename = 'facturacion' . time() . '.' . $request->file('documento')->getClientOriginalExtension();
                $path = $request->file('documento')->storeAs('public/documentosCompras', $filename);
                $compra->document_path = Storage::url($path);
                Log::info('Documento cargado:', ['path' => $compra->document_path]);
            }

            $compra->save();
            Log::info('Compra registrada con éxito.', ['id' => $compra->id]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Compra registrada exitosamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en storeCompra: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error al registrar la compra: ' . $e->getMessage()]);
        }
    }

    private function registrarDetalleLibro($idLibroDiario, $registros)
    {
        foreach ($registros as $registro) {
            // Buscar la cuenta contable por su código
            $cuenta = CuentasContables::where('codigo', $registro['codigo'])->firstOrFail();

            // Crear el registro en detalle_libros
            $detalle = new DetalleLibro();
            $detalle->idLibroDiario = $idLibroDiario;
            $detalle->idCuenta = $cuenta->id;
            $detalle->tipo = $registro['accion'];
            $detalle->monto = $registro['monto'];
            $detalle->accion = $registro['accion'];
            $detalle->estado = 1;
            $detalle->save();
        }
    }

    public function eliminarCompra($idCompra)
    {
        try {
            // Encuentra la compra a eliminar
            $compra = Compra::find($idCompra);

            if ($compra) {
                // Verifica si la compra es al contado
                if ($compra->tipoCompra === 'contado') {
                    // Obtén la ruta del archivo desde el campo `document_path`
                    $documentPath = $compra->document_path;

                    // Asegúrate de que el campo `document_path` no esté vacío
                    if ($documentPath) {
                        // Construye la ruta absoluta al archivo en el almacenamiento
                        $absolutePath = public_path('storage/documentosCompras/' . basename($documentPath));

                        // Verifica si el archivo existe antes de intentar eliminarlo
                        if (file_exists($absolutePath)) {
                            unlink($absolutePath);
                        }
                    }

                    // Elimina la compra del registro
                    $compra->delete();

                    return response()->json(['success' => true, 'message' => 'La compra y su documento han sido eliminados exitosamente.'], 200);
                } else {
                    // No se permite eliminar si la compra es al crédito
                    return response()->json(['success' => false, 'message' => 'No se puede eliminar una compra al crédito.'], 403);
                }
            } else {
                return response()->json(['success' => false, 'message' => 'Compra no encontrada.'], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error al eliminar Compra: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Ocurrió un error al eliminar la compra.'], 500);
        }
    }
}
