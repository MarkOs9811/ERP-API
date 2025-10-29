<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdelantoSueldo;
use App\Models\Asistencia;
use App\Models\HoraExtras;
use App\Models\PeriodoNomina;
use App\Models\Vacacione;
use App\Traits\EmpresaSedeValidation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodoNominaController extends Controller
{
    use EmpresaSedeValidation;

    public function getPeriodoNomina()
    {
        try {
            $periodos = PeriodoNomina::orderBy('fecha_inicio', 'desc')->get();
            return response()->json(['success' => true, "data" => $periodos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "data" => $e->getMessage()], 500);
        }
    }

    public function savePeriodoNomina(Request $request)
    {

        try {
            DB::beginTransaction();
            $validatedData = $request->validate([
                'periodosPreview' => 'required|array|min:1',
                'periodosPreview.*.nombrePeriodo' => 'required|string|max:100',
                'periodosPreview.*.fecha_inicio' => 'required|date',
                'periodosPreview.*.fecha_fin' => 'required|date|after:periodosPreview.*.fecha_inicio',
                'periodosPreview.*.estado' => 'required|integer|in:0',
            ]);


            $periodosParaGuardar = $validatedData['periodosPreview'];

            // 3. Iterar y guardar
            foreach ($periodosParaGuardar as $periodoData) {
                PeriodoNomina::create([
                    'nombrePeriodo' => $periodoData['nombrePeriodo'],
                    'fecha_inicio'  => $periodoData['fecha_inicio'],
                    'fecha_fin'     => $periodoData['fecha_fin'],
                    'estado'        => $periodoData['estado'],
                ]);
            }

            // 4. Si todo salió bien, confirmamos la transacción
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Periodos generados y guardados correctamente.'
            ], 201); // 201 = Creado

        } catch (\Exception $e) {

            DB::rollBack();

            // Devolvemos un error 500 (Error Interno del Servidor)
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error interno al guardar los periodos.',
                'error'   => $e->getMessage() // Opcional: para depuración
            ], 500);
        }
    }

    public function updatePeriodoNomina(Request $request, $id)
    {
        try {
            $periodo = PeriodoNomina::findOrFail($id);

            if ($periodo->estado >= 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: No se puede modificar un periodo que está en validación o ya fue cerrado.'
                ], 403);
            }

            // 3. VALIDACIÓN COMPLEJA
            $validatedData = $request->validate([
                'nombrePeriodo' => [
                    'required',
                    'string',
                    'max:100',
                    $this->uniqueEmpresaSede('periodo_nominas', 'nombrePeriodo', $id),
                ],

                'fecha_inicio'  => 'required|date',
                'fecha_fin'     => [
                    'required',
                    'date',
                    'after:fecha_inicio',

                    // --- AQUÍ ESTÁ LA VALIDACIÓN COMPLEJA "ANTI-SUPERPOSICIÓN" ---
                    function ($attribute, $value, $fail) use ($request, $id, $periodo) {

                        $nueva_fecha_inicio = $request->input('fecha_inicio');
                        $nueva_fecha_fin = $value; // $value es fecha_fin

                        // Buscar si existe algún periodo (que no sea este)
                        // cuyo rango se cruce con mi nuevo rango.
                        $overlap = PeriodoNomina::where('idEmpresa', $periodo->idEmpresa)
                            ->where('idSede', $periodo->idSede)
                            ->where('id', '!=', $id) // Ignorar este mismo periodo
                            ->where(function ($query) use ($nueva_fecha_inicio, $nueva_fecha_fin) {

                                // La consulta mágica que detecta CUALQUIER superposición:
                                // (Inicio_Otro <= Fin_Nuevo) Y (Fin_Otro >= Inicio_Nuevo)
                                $query->where('fecha_inicio', '<=', $nueva_fecha_fin)
                                    ->where('fecha_fin', '>=', $nueva_fecha_inicio);
                            })
                            ->exists(); // Devuelve true si encuentra un overlap

                        if ($overlap) {
                            $fail('El rango de fechas (Del ' . $nueva_fecha_inicio . ' al ' . $nueva_fecha_fin . ') se superpone con un periodo ya existente.');
                        }
                    }
                ],
            ]);

            // 4. ACTUALIZAR
            $periodo->update($validatedData);

            // 5. RESPUESTA DE ÉXITO
            return response()->json([
                'success' => true,
                'message' => 'Periodo actualizado correctamente.'
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Datos de validación incorrectos.',
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: El periodo que intenta editar no existe.'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error interno en el servidor.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function deletePeriodoNomina($id)
    {
        try {

            $periodo = PeriodoNomina::findOrFail($id);

            if ($periodo->estado !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: Solo se pueden eliminar periodos que están en estado "Pendiente".'
                ], 403); // 403 = Prohibido
            }

            $ultimoPeriodo = PeriodoNomina::orderBy('fecha_inicio', 'DESC')
                ->first();

            if ($ultimoPeriodo && $periodo->id != $ultimoPeriodo->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error: Solo se puede eliminar el último periodo de la lista para evitar huecos en la línea de tiempo.'
                ], 403);
            }
            $periodo->delete();

            return response()->json(['success' => true, 'message' => "Se eliminó correctamente"], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Error: El periodo no existe.'], 404);
        } catch (\Exception $e) {
            // Corrección de tu JSON de error
            return response()->json(['success' => false, 'message' => 'Error interno del servidor.', 'error'   => $e->getMessage()], 500);
        }
    }

   
    public function getDatosParaResolverNomina()
    {
        try {
            // 1. Buscamos el periodo de foco
            $periodoDePago = PeriodoNomina::whereIn('estado', [1, 2])
                ->orderBy('estado', 'DESC')
                ->first();

            if (!$periodoDePago) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un periodo Abierto o En Validación para procesar.'
                ], 404);
            }

            // 2. Traemos TODOS los registros del periodo usando el Scope

            // A. TODAS las Asistencias
            $asistenciasDelPeriodo = Asistencia::delPeriodoDePago()
                ->with('usuario:id,nombres,apellidos')
                // --- CORRECCIÓN AQUÍ ---
                // Usamos 'fechaEntrada' de tu tabla, no 'fecha_asistencia'
                ->orderBy('fechaEntrada', 'asc')
                ->get();

            // B. TODOS los Adelantos de sueldo
            $adelantosDelPeriodo = AdelantoSueldo::delPeriodoDePago()
                ->with('usuario:id,nombres,apellidos')
                ->get();

            // C. TODAS las Horas Extra
            $horasExtraDelPeriodo = HoraExtras::delPeriodoDePago()
                ->with('usuario:id,nombres,apellidos')
                ->get();

            // D. TODAS las Vacaciones
            $vacacionesDelPeriodo = Vacacione::delPeriodoDePago()
                ->with('usuario:id,nombres,apellidos')
                ->get();


            // 3. Preparamos el array de datos
            $dataCompleta = [
                'periodo'     => $periodoDePago,
                'asistencias' => $asistenciasDelPeriodo,
                'adelantos'   => $adelantosDelPeriodo,
                'horasExtra'  => $horasExtraDelPeriodo,
                'vacaciones'  => $vacacionesDelPeriodo,
            ];

            // 4. Devolvemos todo en la clave 'data'
            return response()->json([
                'success' => true,
                'data'    => $dataCompleta
            ]);
        } catch (\Exception $e) {
            // --- Manejo de cualquier error inesperado (500) ---
            return response()->json(
                [
                    'success' => false,
                    'message' => 'Ocurrió un error interno al consultar los datos.',
                    'error'   => $e->getMessage()
                ],
                500
            );
        }
    }
}
