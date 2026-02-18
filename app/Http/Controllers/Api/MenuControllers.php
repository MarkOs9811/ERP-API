<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaPlato;
use App\Models\Plato;
use Illuminate\Http\Request;

class MenuControllers extends Controller
{
    public function getMenuCliente()
    {
        try {
            $menu = Plato::where('idEmpresa', '2')
                ->where('estado', '1')
                ->get();
            return response()->json([
                'success' => true,
                'data' => $menu
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el menú del cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getCategoriasPlatos()
    {
        try {
            $categorias = CategoriaPlato::where('idEmpresa', '2')
                ->where('estado', '1')
                ->get();
            return response()->json([
                'success' => true,
                'data' => $categorias
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las categorías de platos: ' . $e->getMessage()
            ], 500);
        }
    }
}
