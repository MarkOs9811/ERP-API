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
            ->scopes([
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/userinfo.email', // <-- Sigue pidiendo el email
                'https://www.googleapis.com/auth/userinfo.profile', // <-- Sigue pidiendo el perfil
            ])
            ->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

            // 1. Configuración de Socialite (usando tu Helper)
            $clientId = ConfiguracionHelper::valor1('Google Service');
            $clientSecret = ConfiguracionHelper::valor2('Google Service');
            $redirectUri = ConfiguracionHelper::valor3('Google Service');

            config([
                'services.google.client_id' => $clientId,
                'services.google.client_secret' => $clientSecret,
                'services.google.redirect' => $redirectUri,
            ]);

            $googleUser = Socialite::driver('google')->stateless()->user();
            $refreshToken = $googleUser->refreshToken;

            if (!$refreshToken) {
                Log::error('No se recibió Refresh Token de Google.');
                return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed&error=NoRefreshToken");
            }

            // 2. Lógica de guardado en 'valor 4' (tu código es correcto)
            $config = Configuraciones::where('nombre', 'Google Service')->first();

            if (!$config) {
                Log::error('No se encontró la fila de configuración "Google Service"');
                return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed&error=ConfigNotFound");
            }

            // 3. Guardar el token en la columna 'valor 4'
            $config->valor4 = $refreshToken;
            $config->save();

            Log::info('Google Service autorizado. Refresh token guardado en valor 4.');

            // 4. Redirigimos de vuelta a la página de configuración con "éxito"
            return redirect("$frontendUrl/configuracion/integraciones?google_auth=success");
        } catch (\Exception $e) {
            Log::error('Error en handleGoogleCallback para integración:', [
                'error' => $e->getMessage()
            ]);
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed");
        }
    }
}
