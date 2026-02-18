<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Direccione;
use App\Models\MetodosPagoCliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClienteController extends Controller
{
    public function perfil(Request $request)
    {
        $persona = $request->user();

        $cliente = Cliente::where('idPersona', $persona->id)
            ->with('persona')
            ->with(['direcciones' => function ($query) {
                $query->where('estado', 1);
            }])
            ->with(['metodosPago' => function ($query) {
                $query->where('estado', 1);
            }])
            ->first();

        if (!$cliente) {
            return response()->json([
                'success' => false, // Agregado
                'message' => 'Cliente no encontrado',
                'data' => null // Agregado
            ], 404);
        }

        // ENVOLTURA PARA CUMPLIR CON ApiResponse<T>
        return response()->json([
            'success' => true,
            'data' => $cliente, // Aquí va el objeto cliente
            'message' => 'Perfil recuperado con éxito'
        ]);
    }

    public function logout(Request $request)
    {
        // Revoca el token actual
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }


    public function getMetodosPagos(Request $request)
    {
        try {
            $persona = $request->user();

            $cliente = Cliente::where('idPersona', $persona->id)->first();

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un perfil de Cliente asociado a esta Persona.'
                ], 404);
            }

            // Ahora sí, $cliente es un objeto único y seguro tiene ID
            $metodos = MetodosPagoCliente::where('idCliente', $cliente->id)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $metodos,
            ]);
        } catch (\Exception $e) {
            Log::error('Error crítico en getMetodosPagos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error del servidor: " . $e->getMessage(),
            ], 500);
        }
    }

    public function getDirecciones(Request $request)
    {
        try {
            $persona = $request->user();

            $cliente = Cliente::where('idPersona', $persona->id)->first();

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró un perfil de Cliente asociado a esta Persona.'
                ], 404);
            }

            // Ahora sí, $cliente es un objeto único y seguro tiene ID
            $direcciones = Direccione::where('idCliente', $cliente->id)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $direcciones,
            ]);
        } catch (\Exception $e) {
            Log::error('Error crítico en getdirecciones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => "Error del servidor: " . $e->getMessage(),
            ], 500);
        }
    }
}
