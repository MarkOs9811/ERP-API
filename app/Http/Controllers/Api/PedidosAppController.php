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
    protected $idSede = 1;
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

        $menu = Plato::where('idEmpresa', $this->idEmpresa)
            ->where('idSede', $this->idSede)
            ->where('estado', '1')
            ->with('categoria')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $menu
        ]);
    }
}
