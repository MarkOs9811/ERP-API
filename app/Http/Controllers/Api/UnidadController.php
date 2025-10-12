<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\UnidadMedida;
use Illuminate\Http\Request;

class UnidadController extends Controller
{
    public function getUnidadMedida()
    {
        try {
            $unidad = UnidadMedida::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $unidad, 'message' => "Unidades obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }
    public function getUnidadMedidaAll()
    {
        try {
            $unidad = UnidadMedida::get();
            return response()->json(['success' => true, 'data' => $unidad, 'message' => "Unidades obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }

    public function saveUnidadMedida(Request $request)
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:255|unique:unidad_medidas,nombre',
            ]);

            $unidad = new UnidadMedida();
            $unidad->nombre = $request->nombre;
            $unidad->estado = 1; // Asignar estado activo por defecto
            $unidad->save();

            return response()->json(['success' => true, 'data' => $unidad, 'message' => "Unidad de medida creada"], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }
}
