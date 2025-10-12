<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\MetodoPago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AjusteVentasController extends Controller
{
    public function getMetodosPagosAll()
    {
        try {
            $metodosPagos = MetodoPago::all();
            Log::info('Métodos de pago obtenidos', ['count' => $metodosPagos->count()]);
            return response()->json(['success' => true, 'data' => $metodosPagos, 'message' => 'Métodos de pago obtenidos'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function guardarMetodoPago(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|unique:metodo_pagos,nombre|max:255',
        ]);

        try {
            $metodoPago = MetodoPago::create([
                'nombre' => $request->nombre,
                'estado' => 1, // Activo por defecto
            ]);

            Log::info('Nuevo método de pago creado', ['id' => $metodoPago->id]);
            return response()->json(['success' => true, 'data' => $metodoPago, 'message' => 'Método de pago creado'], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear método de pago: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateMetodoPago($id)
    {
        try {
            $metodoPago = MetodoPago::find($id);
            if (!$metodoPago) {
                return response()->json(['success' => false, 'message' => 'Método de pago no encontrado'], 404);
            }

            // Cambiar el estado: si es 1 pasa a 0, si es 0 pasa a 1
            $nuevoEstado = $metodoPago->estado == 1 ? 0 : 1;
            $metodoPago->estado = $nuevoEstado;
            $metodoPago->save();

            Log::info('Método de pago actualizado', ['id' => $id, 'estado' => $metodoPago->estado]);
            return response()->json(['success' => true, 'data' => $metodoPago, 'message' => 'Método de pago actualizado'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
