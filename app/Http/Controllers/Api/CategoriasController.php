<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriasController extends Controller
{
    public function getCategorias()
    {
        try {
            $categorias = Categoria::where('estado', 1)->get();

            return response()->json(['success' => true, 'data' => $categorias, 'message' => "categorias obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }

    public function getCategoriasAll()
    {
        try {
            $categorias = Categoria::get();

            return response()->json(['success' => true, 'data' => $categorias, 'message' => "categorias obtenidas"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }

    public function saveCategorias(Request $request)
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:255|unique:categorias,nombre',
            ]);

            $categoria = new Categoria();
            $categoria->nombre = $request->nombre;
            $categoria->estado = 1; // Asignar estado activo por defecto
            $categoria->save();

            return response()->json(['success' => true, 'data' => $categoria, 'message' => "Categoria creada"], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
        }
    }
}
