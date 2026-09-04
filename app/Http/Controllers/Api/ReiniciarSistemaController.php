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
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // 2. Validar la contraseña enviada desde el modal de React
        if (!Hash::check($request->password, $user->password)) {
            Log::warning("Intento de reinicio con contraseña incorrecta para el usuario: {$user->email}");
            return response()->json(['message' => 'Contraseña incorrecta'], 401);
        }

        try {
            // Ejecutamos tu comando Artisan pasando --force para evitar bloqueos por consola
            Artisan::call('sistema:reinicio-limpio', ['--force' => true]);

            Log::info("El sistema fue reiniciado exitosamente por el SuperAdmin: {$user->email}");

            return response()->json([
                'success' => true,
                'message' => 'Sistema reiniciado exitosamente de fábrica.'
            ], 200);
        } catch (\Exception $e) {
            // Registramos el error completo en el archivo laravel.log con su traza (stack trace)
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
