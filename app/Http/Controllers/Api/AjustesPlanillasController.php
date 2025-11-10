<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AjustesPlanilla;
use App\Models\Bonificacione;
use App\Models\Deduccione;
use App\Models\Horario;
use App\Traits\EmpresaValidation;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AjustesPlanillasController extends Controller
{
    use EmpresaValidation;
    public function getAjustesPlanilla()
    {
        try {
            $ajustePlanilla = AjustesPlanilla::get();
            return response()->json(['success' => true, 'data' => $ajustePlanilla], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
    public function getBonificacionesAll()
    {
        try {
            $bonificacion = Bonificacione::get();
            return response()->json(['success' => true, 'data' => $bonificacion], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
    public function storeBonificaciones(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('bonificaciones', 'nombre'),
                ]),
                'descripcion' => 'required|string|max:1000',
                'monto' => 'required|numeric|min:0.01', // Coincide con tu validación de front
            ]);
            $bonificacion = new Bonificacione();
            $bonificacion->nombre = $validatedData['nombre'];
            $bonificacion->descripcion = $validatedData['descripcion'];
            $bonificacion->monto = $validatedData['monto'];

            // Aquí se ejecutarán los 'observers' o 'mutators' que tengas.
            $bonificacion->save();
            return response()->json([
                'success' => true,
                'message' => 'Bonificación guardada exitosamente.',

            ], 201);
        } catch (Exception $e) {
            Log::error('Error al guardar bonificación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }

    public function updateBonificacion(Request $request, $idBoni)
    {
        try {

            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('bonificaciones', 'nombre', $idBoni),
                ]),
                'descripcion' => 'string|max:1000',
                'monto' => 'required|numeric|min:0.01', // Coincide con tu validación de front
            ]);


            $bonificacion = Bonificacione::findOrFail($idBoni);

            if (!$bonificacion) {
                return response()->json(['success' => false, "No se encontró la bonificación"], 404);
            }

            $bonificacion->nombre = $validatedData['nombre'];
            $bonificacion->descripcion = $validatedData['descripcion'];
            $bonificacion->monto = $validatedData['monto'];

            // Aquí se ejecutarán los 'observers' o 'mutators' que tengas.
            $bonificacion->save();
            return response()->json([
                'success' => true,
                'message' => 'Bonificación actualizada exitosamente.',

            ], 201);
        } catch (Exception $e) {
            Log::error('Error al actualizar bonificación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }

    public function suspendBonificaciones($id)
    {
        try {
            $bonificacion = Bonificacione::findOrFail($id);
            if (!$bonificacion) {
                return response()->json(['success' => false, "message" => "No se encotnró esta bonificación"], 404);
            }
            $bonificacion->estado = 0;
            $bonificacion->save();
            return response()->json(['success' => true, "message" => "Se suspendió la bonificación"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }
    public function activarBonificaciones($id)
    {
        try {
            $bonificacion = Bonificacione::findOrFail($id);
            if (!$bonificacion) {
                return response()->json(['success' => false, "message" => "No se encotnró esta bonificación"], 404);
            }
            $bonificacion->estado = 1;
            $bonificacion->save();
            return response()->json(['success' => true, "message" => "Se activó la bonificación"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }



    // ==============================================================

    public function getDeduccionesAll()
    {
        try {
            $deducciones = Deduccione::get();
            return response()->json(['success' => true, 'data' => $deducciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }


    public function storeDeducciones(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('deducciones', 'nombre'),
                ]),
                'porcentaje' => 'required|numeric|min:0.01|max:100',
            ]);


            $deduccion = new Deduccione();
            $deduccion->nombre = $validatedData['nombre'];
            $deduccion->porcentaje = $validatedData['porcentaje'] / 100;
            $deduccion->save();

            return response()->json([
                'success' => true,
                'message' => 'Deducción guardada exitosamente.',
            ], 201);
        } catch (Exception $e) {
            // <-- CAMBIO: Mensaje de Log
            Log::error('Error al guardar deducción: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }

    public function updateDeducciones(Request $request, $idDeducciones)
    {
        try {
            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('deducciones', 'nombre', $idDeducciones),
                ]),
                'porcentaje' => 'required|numeric|min:0.01|max:100',
            ]);


            $deduccion = Deduccione::findOrFail($idDeducciones);

            if (!$deduccion) {
                return response()->json(['success' => false, "No se encontró la deducción"], 404);
            }

            $deduccion->nombre = $validatedData['nombre'];
            $deduccion->porcentaje = $validatedData['porcentaje'] / 100;
            $deduccion->save();

            return response()->json([
                'success' => true,
                'message' => 'Deducción actualizada exitosamente.',
            ], 201);
        } catch (Exception $e) {
            // <-- CAMBIO: Mensaje de Log
            Log::error('Error al guardar deducción: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }

    public function suspendDeducciones($id)
    {
        try {
            $deduccion = Deduccione::findOrFail($id);
            if (!$deduccion) {
                return response()->json(['success' => false, "message" => "No se encontró esta deducción"], 404);
            }
            $deduccion->estado = 0;
            $deduccion->save();
            return response()->json(['success' => true, "message" => "Se suspendió la deducción"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }
    public function activarDeducciones($id)
    {
        try {
            $deduccion = Deduccione::findOrFail($id);
            if (!$deduccion) {
                return response()->json(['success' => false, "message" => "No se encontró esta deducción"], 404);
            }
            $deduccion->estado = 1;
            $deduccion->save();
            return response()->json(['success' => true, "message" => "Se activó la deducción"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }

    // =======================================================
    // HORARIO

    public function getHorarioAll()
    {
        try {
            $areas = Horario::get();
            return response()->json(['data' => $areas], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener los Horarios', 'message' => $e->getMessage()], 500);
        }
    }
    public function storeHorarios(Request $request)
    {
        try {


            $validatedData = $request->validate([
                'horaEntrada' => 'required|date_format:H:i', // Valida formato "HH:mm"
                'horaSalida'  => 'required|date_format:H:i|after:horaEntrada', // Valida "HH:mm" y que sea después de la entrada
            ]);

            $horario = new Horario();


            $horario->horaEntrada = $validatedData['horaEntrada'];
            $horario->horaSalida  = $validatedData['horaSalida'];

            // 4. Guardamos el nuevo registro
            $horario->save();


            return response()->json([
                'success' => true,
                'message' => 'Horario guardado exitosamente.',
            ], 201); // 201 (Created) es el estándar para 'store'

        } catch (Exception $e) {

            // 6. CAMBIO: Mensaje de Log
            Log::error('Error al guardar horario: ' . $e->getMessage());

            // El 'ModelNotFoundException' no es necesario aquí

            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }
    public function updateHorarios(Request $request, $idHorario)
    {
        try {

            $validatedData = $request->validate([
                'horaEntrada' => 'required|date_format:H:i', // Valida formato "HH:mm"
                'horaSalida'  => 'required|date_format:H:i|after:horaEntrada', // Valida "HH:mm" y que sea después de la entrada
            ]);

            $horario = Horario::findOrFail($idHorario);

            $horario->horaEntrada = $validatedData['horaEntrada'];
            $horario->horaSalida  = $validatedData['horaSalida'];
            $horario->save();

            // <-- 4. CAMBIO: Mensajes de respuesta
            return response()->json([
                'success' => true,
                'message' => 'Horario actualizado exitosamente.',
            ], 200);
        } catch (Exception $e) {
            // <-- 5. CAMBIO: Mensaje de Log
            Log::error('Error al actualizar horario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' =>  $e->getMessage()
            ], 500);
        }
    }
    public function suspendHorarios($id)
    {
        try {
            $deduccion = Horario::findOrFail($id);
            if (!$deduccion) {
                return response()->json(['success' => false, "message" => "No se encontró esta horario"], 404);
            }
            $deduccion->estado = 0;
            $deduccion->save();
            return response()->json(['success' => true, "message" => "Se suspendió la horario"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }
    public function activarHorarios($id)
    {
        try {
            $deduccion = Horario::findOrFail($id);
            if (!$deduccion) {
                return response()->json(['success' => false, "message" => "No se encontró esta horario"], 404);
            }
            $deduccion->estado = 1;
            $deduccion->save();
            return response()->json(['success' => true, "message" => "Se activó la horario"], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, "message" => $e->getMessage()], 500);
        }
    }
}
