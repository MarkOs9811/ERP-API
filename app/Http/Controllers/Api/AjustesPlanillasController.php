<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AjustesPlanilla;
use App\Models\Bonificacione;
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
}
