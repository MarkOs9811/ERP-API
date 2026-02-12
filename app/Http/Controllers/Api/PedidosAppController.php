<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaPlato;
use App\Models\Plato;
use Illuminate\Http\Request;

class PedidosAppController extends Controller
{
    // ID de la empresa por defecto (según tu requerimiento)
    protected $idEmpresa = 2;

    /**
     * Obtener todas las categorías de la empresa 2
     */
    public function getCategorias()
    {
        // Asumiendo que tu tabla de categorías tiene 'idEmpresa'
        $categorias = CategoriaPlato::where('idEmpresa', $this->idEmpresa)
            ->where('estado', '1') // Solo activas
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categorias
        ]);
    }

    /**
     * Obtener el menú completo (Platos) de la empresa 2
     */
    public function getMenu()
    {
        // Traemos los platos con su categoría (si tienes la relación definida)
        // Ajusta 'categoria' al nombre real de tu relación en el modelo Plato
        $menu = Plato::where('idEmpresa', $this->idEmpresa)
            ->where('estado', '1') // Solo platos activos
            ->with('categoria')    // Para poder filtrar/ordenar en el front si quieres
            ->get();

        return response()->json([
            'success' => true,
            'data' => $menu
        ]);
    }
}
