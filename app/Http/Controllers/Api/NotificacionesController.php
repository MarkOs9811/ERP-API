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
    public function  getNotificacionesCliente(Request $request)
    {
        try {
            $persona = $request->user();
            $cliente = Cliente::where('idPersona', $persona->id)->first();
            if (!$cliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
            }
            $idCliente = $cliente->id;
            $notificaciones = Notificaciones::withoutGlobalScope(UsuarioScope::class)
                ->where('idCliente', $idCliente)
                ->get();
            Log::info("Notificaciones para cliente ID: $idCliente", ['notificaciones' => $notificaciones]);

            return response()->json(['success' => true, 'data' => $notificaciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'Error', $e->getMessage()], 500);
        }
    }
}
