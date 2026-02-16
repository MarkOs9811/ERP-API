<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function perfil(Request $request)
    {
        // 1. Obtenemos la Persona autenticada (token Sanctum)
        $persona = $request->user();

        // 2. Buscamos al Cliente con TODAS sus relaciones activas
        $cliente = Cliente::where('idPersona', $persona->id)
            ->with('persona') // Tu relación base
            ->with(['direcciones' => function ($query) {
                $query->where('estado', 1); // Solo direcciones activas
            }])
            ->with(['metodosPago' => function ($query) { // Asegúrate que la relación se llame así en el Modelo
                $query->where('estado', 1); // Solo tarjetas activas
            }])
            ->first();

        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }

        // 3. Devolvemos el objeto Cliente completo (con direcciones y tarjetas dentro)
        return response()->json($cliente);
    }

    public function logout(Request $request)
    {
        // Revoca el token actual
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
