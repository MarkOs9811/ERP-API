<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Support\Facades\Log;

class AjustesAlmacenController extends Controller
{
    public function updateCategoriaEstado($id)
    {
        try {
            $categoria = Categoria::find($id);
            if (!$categoria) {
                return response()->json(['success' => false, 'message' => 'Categoria no encontrado'], 404);
            }

            // Cambiar el estado: si es 1 pasa a 0, si es 0 pasa a 1
            $nuevoEstado = $categoria->estado == 1 ? 0 : 1;
            $categoria->estado = $nuevoEstado;
            $categoria->save();

            Log::info('Categoria actualizado', ['id' => $id, 'estado' => $categoria->estado]);
            return response()->json(['success' => true, 'data' => $categoria, 'message' => 'Categoria actualizado'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
