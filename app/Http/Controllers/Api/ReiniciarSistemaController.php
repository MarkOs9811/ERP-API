<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ReiniciarSistemaController extends Controller
{
    public function reiniciarSistema(Request $request)
    {
        $user = $request->user();
        $correoMaestro = env('SUPERADMIN_EMAIL', 'marcosparitorres@gmail.com');

        // 1. Validar que sea el SuperAdmin usando el .env
        if ($user->email !== $correoMaestro) {
            Log::warning("Intento fallido de reinicio de sistema por usuario no autorizado: {$user->email}");
            // Usar 403 Forbidden es correcto aquí: el token es válido, pero no tiene permisos para esta acción
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 2. Validar la contraseña enviada desde el modal de React
        if (!Hash::check($request->password, $user->password)) {
            Log::warning("Intento de reinicio con contraseña incorrecta para el usuario: {$user->email}");
            // CAMBIO CLAVE: Usar 422 en lugar de 401 para evitar que el interceptor global de Axios cierre la sesión
            return response()->json(['message' => 'Contraseña incorrecta'], 422);
        }

        try {
            Artisan::call('sistema:reinicio-limpio', ['--force' => true]);

            Log::info("El sistema fue reiniciado exitosamente por el SuperAdmin: {$user->email}");

            return response()->json([
                'success' => true,
                'message' => 'Sistema reiniciado exitosamente de fábrica.'
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error crítico al ejecutar el reinicio de sistema: " . $e->getMessage(), [
                'exception' => $e
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al reiniciar el sistema',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
