<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Notificaciones;
use App\Models\Scopes\UsuarioScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificacionesController extends Controller
{
    public function  getNotificaciones()
    {
        try {
            $notificaciones = Notificaciones::get();
            return response()->json(['success' => true, 'data' => $notificaciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'Error', $e->getMessage()], 500);
        }
    }

    public function getNotificacionesPrivadas(Request $request)
    {
        try {
            $usuario = $request->user();
            $notificaciones = Notificaciones::withoutGlobalScope(UsuarioScope::class)
                ->where('idUsuario', $usuario->id)
                ->get();

            return response()->json(['success' => true, 'data' => $notificaciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    public function cambiarEstadoMisNotificaciones(Request $request)
    {
        try {
            $usuario = $request->user();
            Notificaciones::withoutGlobalScope(UsuarioScope::class)
                ->where('idUsuario', $usuario->id)
                ->where('estado', 0)
                ->update(['estado' => 1]);

            return response()->json(['success' => true, 'message' => 'Estado de notificaciones actualizado'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    public function getNotificacionesCliente(Request $request)
    {
        try {
            // 1. Validamos que el usuario realmente venga en la petición (que esté autenticado)
            $persona = $request->user();
            if (!$persona) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado (Falta Token)'
                ], 401);
            }

            // 2. Buscamos al cliente
            $cliente = Cliente::where('idPersona', $persona->id)->first();
            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            // 3. Obtenemos las notificaciones
            // OJO: Verifica si tu modelo se llama 'Notificaciones' o 'Notificacion' (singular)
            $notificaciones = Notificaciones::withoutGlobalScope(UsuarioScope::class)
                ->where('idCliente', $cliente->id)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $notificaciones
            ], 200);
        } catch (\Exception $e) {
            // 4. Corregimos el catch para que sea FALSE y devuelva el texto exacto del error a React
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error_log' => $e->getMessage(),
                'linea' => $e->getLine() // Esto te dirá exactamente en qué línea falló
            ], 500);
        }
    }

    public function cambiarEstadoNotificacion(Request $request)
    {
        try {

            $persona = $request->user();
            $cliente = Cliente::where('idPersona', $persona->id)->first();
            Notificaciones::withoutGlobalScope(UsuarioScope::class)
                ->where('idCliente', $cliente->id)
                ->where('estado', 0)
                ->update(['estado' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'Estado de notificaciones actualizado',
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }
}
