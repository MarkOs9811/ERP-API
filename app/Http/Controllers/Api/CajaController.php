<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Mesa;
use App\Models\registrosCajas;
use App\Models\Venta;
use App\Traits\EmpresaSedeValidation;
use Carbon\Carbon;
use Google\Service\Compute\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CajaController extends Controller
{
    use EmpresaSedeValidation;
    // FUNCION PARA OBTENER LAS CAJAS POR SEDE
    public function getCajas()
    {
        try {
            $user = auth()->user();
            $cajas = Caja::where('estado', 1)->where('idSede', $user->idSede)->get();
            return response()->json(['success' => true, 'cajas' => $cajas, 'message' => 'Mostrando Cajas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function saveCaja(Request $request)
    {
        try {
            Log::info($request);
            // Validación
            $validator = Validator::make($request->all(), [
                'nombreCaja' => [
                    'required',
                    'string',
                    'max:50',
                    'regex:/^[A-Za-z0-9\s]+$/',
                    $this->uniqueEmpresaSede('cajas', 'nombreCaja'),
                ],

            ]);
            Log::info('📦 Datos recibidos:', $request->all());
            Log::info('🔍 Resultado validación:', [
                'fails' => $validator->fails(),
                'errors' => $validator->errors()->toArray(),
            ]);
            // Si falla la validación
            if ($validator->fails()) {
                Log::info('❌ Errores de validación:', $validator->errors()->first());
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            // Crear la caja
            $caja = new Caja();
            $caja->nombreCaja = $request->nombreCaja;
            $caja->estadoCaja = 0;
            $caja->estado = 1; // por defecto activa
            $caja->save();

            return response()->json([
                'success' => true,
                'message' => 'Caja creada correctamente.'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }


    public function updateCaja(Request $request, $id)
    {
        try {
            Log::info($id);
            // Buscar la caja por ID
            $caja = Caja::findOrFail($id);
            Log::info($request->all);
            // Validación
            $validator = Validator::make($request->all(), [
                'nombreCaja' => [
                    'required',
                    'string',
                    'max:50',
                    'regex:/^[A-Za-z0-9\s]+$/',
                    $this->uniqueEmpresaSede('cajas', 'nombreCaja', $caja->id),
                ],

            ]);

            // Si falla la validación
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first()
                ], 422);
            }

            // Actualizar campos
            $caja->nombreCaja = $request->nombreCaja;

            $caja->save();

            return response()->json([
                'success' => true,
                'message' => 'Caja actualizada correctamente.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }


    // FUNCION PARA OBTENER LAS CAJAS PARA ADMINSITRADOR
    public function getCajasAll()
    {
        try {
            $cajas = Caja::with('sedes')->get();
            return response()->json(['success' => true, 'data' => $cajas, 'message' => 'Mostrando Cajas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function storeCajaApertura(Request $request)
    {
        try {
            $user = auth()->user();
            $caja = Caja::find($request->input('caja'));

            if (!$caja) {
                return response()->json(['message' => 'La caja no existe.'], 400);
            }

            if ($caja->estadoCaja == 1) {
                return response()->json(['message' => 'La caja ya está abierta.'], 400);
            }

            DB::beginTransaction();

            // Cambiar el estado de la caja
            $caja->estadoCaja = 1;
            $caja->save();

            // Crear un nuevo registro de caja
            registrosCajas::create([
                'idUsuario' => $user->id,
                'idCaja' => $caja->id,
                'montoInicial' => $request->input('montoApertura'),
                'montoFinal' => null,
                'montoDejado' => null,
                'fechaApertura' => now()->toDateString(),
                'horaApertura' => now()->toTimeString(),
                'fechaCierre' => null,
                'horaCierre' => null,
                'estado' => 1,
            ]);

            DB::commit();

            return response()->json(['success' => true, 'caja' => $caja, 'message' => 'Caja abierta exitosamente.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Hubo un error al abrir la caja: ' . $e->getMessage()], 500);
        }
    }

    public function getCajaClose(Request $request, $id)
    {
        try {

            // Buscar el registro de caja
            $registroCaja = registrosCajas::with('usuario.empleado.persona')->where('idCaja', $id)
                ->whereNull('fechaCierre')
                ->whereNull('horaCierre')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$registroCaja) {

                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un registro de caja abierto para este ID.'
                ]);
            }
            // Obtener usuario y fechas
            $idUsuario = Auth::user();
            $fechaHoraApertura = Carbon::createFromFormat(
                'Y-m-d H:i:s',
                $registroCaja->fechaApertura . ' ' . $registroCaja->horaApertura,
                'America/Lima'
            );

            Log::info('Buscando ventas para usuario: ' . $idUsuario->id . ' después de: ' . $fechaHoraApertura);

            // Obtener ventas
            $ventas = Venta::with('metodoPago', 'pedido', 'pedidoWeb')
                ->where('idUsuario', $idUsuario->id)
                ->where('created_at', '>', $fechaHoraApertura)
                ->get();

            // Log de ventas encontradas
            Log::info('Total de ventas encontradas: ' . $ventas->count());
            if ($ventas->count() > 0) {
                Log::debug('Primeras 5 ventas:', $ventas->take(5)->toArray());
            }

            // Procesar datos de respuesta
            $detallesVenta = $ventas->map(function ($venta) {
                $pedido = '';
                if ($venta->idPedido) {
                    $pedido .= $venta->idPedido;
                }
                if ($venta->idPedidoWeb) {
                    $pedido .=  $venta->idPedidoWeb;
                }
                return [
                    'pedido' => $pedido ?: 'N/A',
                    'total' => $venta->total,
                    'metodoPago' => optional($venta->metodoPago)->nombre ?? 'Desconocido',
                    'documento' => $venta->documento,
                    'fechaVenta' => optional($venta->created_at)->format('d-m-Y H:i:s') ?? 'N/A',
                ];
            });



            $montoInicial = $registroCaja->montoInicial;
            $totalVentas = $ventas->sum('total');

            // Log de datos a devolver
            Log::info('Datos preparados para respuesta:', [
                'total_ventas' => $totalVentas,
                'monto_inicial' => $montoInicial,
                'cantidad_detalles' => count($detallesVenta)
            ]);

            // Respuesta
            $response = [
                'success' => true,
                'detallesVenta' => $detallesVenta,
                'totalVenta' => $totalVentas,
                'montoInicial' => $montoInicial,
                'datosRegistroCaja' => $registroCaja,
                'message' => 'Datos obtenidos correctamente'
            ];

            Log::info('=== FIN LLAMADA A getCajaClose ===');
            Log::info('Respuesta enviada:', $response);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error en getCajaClose: ' . $e->getMessage());
            Log::error('Stack trace:', ['exception' => $e]);

            return response()->json([
                'success' => false,
                'message' => 'Error en el servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    public function closeCaja($id, Request $request)
    {
        try {
            Log::info("Intentando cerrar caja", [
                'idCaja' => $id,
                'request' => $request->all()
            ]);

            $request->validate([
                'sumaTotalFormatted' => 'required|numeric|min:0',
                'montoDejarFormatted' => 'required|numeric|min:0',
            ]);

            $idCaja = $id;
            $montoFinal = $request->input('sumaTotalFormatted');
            $montoDejado = $request->input('montoDejarFormatted');

            // 1. Revisar mesas
            $mesasAbiertas = Mesa::where('estado', 0)->count(); // <-- revisa si el valor "1" es abierto
            Log::info("Mesas abiertas detectadas", ['count' => $mesasAbiertas]);

            if ($mesasAbiertas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede cerrar la caja porque hay mesas abiertas.'
                ]);
            }

            // 2. Cerrar la caja
            $caja = Caja::findOrFail($idCaja);
            $caja->estadoCaja = 0;
            $caja->montoVendido = 0;
            $caja->save();
            Log::info("Caja cerrada en tabla Caja", $caja->toArray());

            // 3. Buscar último registro de caja abierto
            $registroCaja = registrosCajas::where('idCaja', $idCaja)
                ->whereNull('fechaCierre')
                ->whereNull('horaCierre')
                ->orderBy('created_at', 'desc')
                ->first();

            Log::info("Registro de caja encontrado", [$registroCaja]);

            if (!$registroCaja) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un registro de caja abierto para este ID.'
                ]);
            }

            // 4. Actualizar registro
            $registroCaja->montoFinal = $montoFinal;
            $registroCaja->montoDejado = $montoDejado;
            $registroCaja->fechaCierre = now()->format('Y-m-d');
            $registroCaja->horaCierre = now()->format('H:i:s');
            $registroCaja->save();

            Log::info("Registro de caja actualizado", $registroCaja->toArray());

            return response()->json([
                'success' => true,
                'message' => 'Caja cerrada exitosamente.'
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error al cerrar caja", ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function suspenderCaja($id, Request $request)
    {
        try {
            $idCaja = $id;
            $caja = Caja::find($idCaja);
            if (!$caja) {
                return response()->json(['success' => false, 'message' => 'Caja no encontrada'], 400);
            }
            $caja->estado = 0;
            $caja->save();
            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function activarCaja($id, Request $request)
    {
        try {
            $idCaja = $id;
            $caja = Caja::find($idCaja);
            if (!$caja) {
                return response()->json(['success' => false, 'message' => 'Caja no encontrada'], 400);
            }
            $caja->estado = 1;
            $caja->save();
            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}
