<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\Configuraciones;
use App\Models\MiEmpresa;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        // Obtener valores desde la base de datos
        $clientId = ConfiguracionHelper::valor1('Google Service');
        $clientSecret = ConfiguracionHelper::valor2('Google Service');
        $redirectUri = ConfiguracionHelper::valor3('Google Service'); // O como lo tengas almacenado

        // Sobrescribir la configuración de Socialite en tiempo de ejecución
        config([
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => $clientSecret,
            'services.google.redirect' => $redirectUri,
        ]);

        return Socialite::driver('google')
            ->stateless()
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
            ])
            ->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            // Volver a sobrescribir la configuración por si el ciclo de vida de la petición cambió
            $clientId = ConfiguracionHelper::valor1('Google Service');
            $clientSecret = ConfiguracionHelper::valor2('Google Service');
            $redirectUri = ConfiguracionHelper::valor3('Google Service');

            config([
                'services.google.client_id' => $clientId,
                'services.google.client_secret' => $clientSecret,
                'services.google.redirect' => $redirectUri,
            ]);

            $googleUser = Socialite::driver('google')
                ->stateless()
                ->with([
                    'access_type' => 'offline', // Para obtener el refresh_token
                    'prompt' => 'consent'     // Importante para que se soliciten los nuevos scopes
                ])
                ->user();

            Log::debug('===========================================');

            // Buscar usuario existente
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                return redirect("$frontendUrl/login?error=UsuarioNoRegistrado");
            }

            if ($user->auth_type !== 'google Oauth2') {
                return redirect("$frontendUrl/login?error=TipoAutenticacionInvalido");
            }
            // Guardar tokens
            $user->google_token = $googleUser->token;
            $user->google_refresh_token = $googleUser->refreshToken;
            $user->save();

            $token = $user->createToken('auth_token')->plainTextToken;

            // === LOG DEL TOKEN DE TU APP ===
            Log::debug('Token de Laravel (Sanctum/Passport):', ['token' => $token]);

            return redirect("$frontendUrl/google?token=$token");
        } catch (\Exception $e) {
            Log::error('Error en handleGoogleCallback:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect('http://localhost:3000/login?error=GoogleAuthFailed');
        }
    }

    public function datosLoginGoogle(Request $request)
    {
        $googleUser = $request->user(); // Usuario autenticado con Google

        // Buscar usuario por correo
        $user = User::where('email', $googleUser->email)->first();
        Log::debug('Usuario autenticado:', [$user]);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Este correo no está registrado en el sistema. Contacta al administrador.',
            ], 403);
        }

        // Cargar relaciones necesarias
        $user->loadMissing(['empleado.persona', 'roles', 'empleado.cargo', 'sede']);

        // Verificar si tiene las relaciones esperadas
        if (!$user->empleado || !$user->empleado->persona || $user->roles->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario no tiene roles asignados o no está vinculado correctamente a un empleado/persona.',
            ], 403);
        }

        // Obtener datos de empresa y configuración
        $empresa = MiEmpresa::first();

        $configuracion = Configuraciones::where('idEmpresa', $empresa?->id)
            ->get()
            ->map(function ($config) {
                return [
                    'nombre' => $config->nombre,
                    'estado' => $config->estado,
                    'descripcion' => $config->descripcion,
                ];
            });

        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => $user->roles,
            'token' => $request->bearerToken(),
            'miEmpresa' => $empresa,
            'caja' => $user->cajaAbierta(),
            'configuracion' => $configuracion,
        ]);
    }
}
