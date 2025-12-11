<?php

namespace App\Http\Controllers\Api;

use App\Events\PedidoCocinaEvent;
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

    public function cambiarEstadoCocina(Request $request, $idPedido)
    {
        try {
            $estadoPedido = EstadoPedido::find($idPedido);

            if (!$estadoPedido) {
                return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
            }


            $nuevoEstado = $request->input('estado', $estadoPedido->estado == 1 ? 0 : 1);

            $estadoPedido->estado = $nuevoEstado;
            $estadoPedido->save();


            $detallePlatosArray = json_decode($estadoPedido->detalle_platos, true) ?? [];


            event(new PedidoCocinaEvent(
                $estadoPedido->id,          // int $idPedido
                $detallePlatosArray,        // array $detallePlatos
                $estadoPedido->tipo_pedido, // string $tipo_pedido
                (string)$estadoPedido->estado // string $estado (cast a string por si acaso, según tu evento)
            ));

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
