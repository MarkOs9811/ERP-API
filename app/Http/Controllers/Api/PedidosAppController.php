<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CategoriaPlato;
use App\Models\Plato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        // PONER LOG D ELO QUE SUCEDE AL CONSULTAR
        Log::info("Consultando menú para empresa ID: {$this->idEmpresa}, sede ID: {$this->idSede}");
        $menu = Plato::where('idEmpresa', $this->idEmpresa)
            ->where('idSede', $this->idSede)
            ->where('estado', '1')
            ->with('categoria')
            ->get();

        Log::info("Menú obtenido: " . $menu->count() . " platos encontrados para empresa ID: {$this->idEmpresa}, sede ID: {$this->idSede}");
        return response()->json([
            'success' => true,
            'data' => $menu
        ]);
    }
}
