<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AreaController extends Controller
{
    public function getAreas()
    {
        try {
            $areas = Area::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $areas, 'message' => "Areas obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error en el back" . $e->getMessage()], 500);
        }
    }

    public function getAreasAll()
    {
        try {
            $areas = Area::withCount('empleados')->get();

            // Preparar datos para el gráfico
            $areaNames = $areas->pluck('nombre');
            $empleadosCount = $areas->pluck('empleados_count');

            $data = [
                'areas' => $areas,
                'areasName' => $areaNames,
                'empleadosCount' => $empleadosCount,
            ];

            return response()->json(['success' => true, 'data' => $data, 'message' => "Areas obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error al obtener datos" . $e->getMessage()], 500);
        }
    }
    public function saveArea(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Guardar el cargo
            $cargo = new Area();
            $cargo->nombre = $request->nombre;
            $cargo->estado = 1;
            $cargo->save();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en el servidor: ' . $e->getMessage()
            ], 500);
        }
    }
}
