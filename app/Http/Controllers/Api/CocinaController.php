<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EstadoPedido;
use Illuminate\Http\Request;

class CocinaController extends Controller
{
    public function getPedidoCocina()
    {
        try {
            $hoy = now()->toDateString();

            $pedidos = EstadoPedido::with('preventaMesa.preVentas.mesa', 'caja')
                ->whereHas('caja', function ($query) {
                    $query->where('estadoCaja', 1);
                })
                ->where('estado', 0)
                ->whereNotNull('detalle_platos') // ✅ no sea null
                ->where('detalle_platos', '!=', '') // ✅ no esté vacío
                ->where('detalle_platos', '!=', '[]') // ✅ no sea un array vacío
                ->whereDate('created_at', $hoy)
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->get();

            return response()->json(['success' => true, 'data' => $pedidos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
