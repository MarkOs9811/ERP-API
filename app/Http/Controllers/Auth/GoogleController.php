<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\Configuraciones;
use App\Models\Scopes\EmpresaScope;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleController extends Controller
{
    public function redirectToGoogle(Request $request)
    {
        // 1. CAPTURAR EL ID DE LA URL (?target_company=19)
        $targetCompanyId = $request->query('target_company');

        if (!$targetCompanyId) {
            return response()->json(['error' => 'Es obligatorio enviar el parámetro target_company'], 400);
        }

        // Configuración de credenciales (Client ID / Secret)
        $clientId = ConfiguracionHelper::valor1('Google Service');
        $clientSecret = ConfiguracionHelper::valor2('Google Service');
        $redirectUri = ConfiguracionHelper::valor3('Google Service');

        config([
            'services.google.client_id' => $clientId,
            'services.google.client_secret' => $clientSecret,
            'services.google.redirect' => $redirectUri,
        ]);

        // 2. ENVIAR EL ID A GOOGLE DENTRO DEL PARAMETRO 'STATE'
        // Esto hace que el ID viaje a Google y regrese intacto.
        // Formato: "target_company=19"
        $customState = "target_company=" . $targetCompanyId;

        return Socialite::driver('google')
            ->stateless()
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $customState // <--- AQUÍ GUARDAMOS EL DATO
            ])
            ->scopes([
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        try {
            // 1. CONFIGURACIÓN SOCIALITE
            $clientId = ConfiguracionHelper::valor1('Google Service');
            $clientSecret = ConfiguracionHelper::valor2('Google Service');
            $redirectUri = ConfiguracionHelper::valor3('Google Service');

            config([
                'services.google.client_id' => $clientId,
                'services.google.client_secret' => $clientSecret,
                'services.google.redirect' => $redirectUri,
            ]);

            // 2. RECUPERAR EL ID DEL PARAMETRO 'STATE' QUE GOOGLE NOS DEVOLVIÓ
            // Google nos devuelve algo como: .../callback?code=xxx&state=target_company=19
            $stateContent = $request->input('state');

            // Convertimos el string "target_company=19" en un array
            parse_str($stateContent, $stateData);

            $targetCompanyId = isset($stateData['target_company']) ? $stateData['target_company'] : null;

            Log::info('Codigo de la empresa recuperado del State:', ['id' => $targetCompanyId]);

            if (!$targetCompanyId) {
                Log::error('Se perdió el ID de la empresa (State vacío o inválido).');
                return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed&error=StateLost");
            }

            // 3. OBTENER TOKEN DE GOOGLE
            $googleUser = Socialite::driver('google')->stateless()->user();
            $refreshToken = $googleUser->refreshToken;

            if (!$refreshToken) {
                Log::error('No se recibió Refresh Token de Google.');
                return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed&error=NoRefreshToken");
            }

            // 4. BUSCAR Y ACTUALIZAR LA CONFIGURACIÓN CORRECTA
            $config = Configuraciones::withoutGlobalScope(EmpresaScope::class)
                ->where('nombre', 'Google Service')
                ->where('idEmpresa', $targetCompanyId)
                ->first();

            if (!$config) {
                Log::error("No se encontró configuración para la empresa ID: $targetCompanyId");
                return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed&error=ConfigNotFound");
            }

            // 5. GUARDAR TOKEN
            $config->valor4 = $refreshToken;
            $config->save();

            Log::info("Google Service autorizado. Token guardado para Empresa ID: $targetCompanyId");

            return redirect("$frontendUrl/configuracion/integraciones?google_auth=success");
        } catch (\Exception $e) {
            Log::error('Error en handleGoogleCallback:', ['error' => $e->getMessage()]);
            return redirect("$frontendUrl/configuracion/integraciones?google_auth=failed");
        }
    }
}
