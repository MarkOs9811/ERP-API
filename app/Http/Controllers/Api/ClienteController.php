<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function perfil(Request $request)
    {
        // 1. Obtenemos la Persona autenticada (gracias al token)
        $persona = $request->user();


        $cliente = Cliente::where('idPersona', $persona->id)
            ->with('persona') // <--- IMPORTANTE: Cargar la relación
            ->first();

        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }

        // 3. Devolvemos el objeto Cliente completo
        return response()->json($cliente);
    }

    public function logout(Request $request)
    {
        // Revoca el token actual
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
