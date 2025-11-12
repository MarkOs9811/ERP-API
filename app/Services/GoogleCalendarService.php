<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
// ¡YA NO USAMOS App\Models\User!
use Google_Client;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected $client;
    protected $service;

    // --- ¡CAMBIO #1: Constructor sin Usuario! ---
    public function __construct()
    {
        // 1. Obtener todas las credenciales desde la fila "Google Service"
        // (Corregido 'google' a 'Google Service')
        $clientId     = ConfiguracionHelper::valor1('Google Service');
        $clientSecret = ConfiguracionHelper::valor2('Google Service');
        $redirectUri  = ConfiguracionHelper::valor3('Google Service');
        $refreshToken = ConfiguracionHelper::valor4('Google Service'); // <-- Leemos de valor4

        if (!$clientId || !$clientSecret || !$redirectUri) {
            Log::error('Faltan credenciales de Google (ID, Secreto o Redirect URI) en la configuración.');
            throw new \Exception('Credenciales de Google no configuradas.');
        }

        if (!$refreshToken) {
            Log::error("Google Refresh Token no está configurado en 'valor4' de 'Google Service'");
            throw new \Exception("Google Refresh Token no está configurado.");
        }

        // 2. Configurar el Google Client
        $this->client = new Google_Client();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->setAccessType('offline');
        $this->client->setScopes([ // Tus scopes están bien
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.events',
            'email',
            'profile'
        ]);

        // --- ¡CAMBIO #2: Autenticación Centralizada ---
        // Ya no leemos el token del usuario.
        // Siempre refrescamos usando el token central.
        try {
            $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

            // (Opcional) Si Google da un *nuevo* refresh token, lo guardamos
            $newToken = $this->client->getAccessToken();
            if (isset($newToken['refresh_token']) && $newToken['refresh_token'] !== $refreshToken) {
                Log::info('Google emitió un nuevo Refresh Token (Calendar). Guardando en valor4.');
                // Asumimos que tienes 'guardarValorColumna' en tu helper
                ConfiguracionHelper::guardarValorColumna('Google Service', 'valor4', $newToken['refresh_token']);
            }
        } catch (\Exception $e) {
            Log::error('Error al renovar el token central de Google (Calendar): ' . $e->getMessage());
            throw new \Exception('No se pudo autenticar con Google: ' . $e->getMessage());
        }

        // --- ¡CAMBIO #3: Eliminar lógica de $user->update() ---
        // Ya no es necesario.

        $this->service = new GoogleCalendar($this->client);
    }

    // --- ¡SIN CAMBIOS AQUÍ! ---
    // Esta función ya estaba perfecta.
    public function createEvent($summary, $description, $start, $end, $attendees = [], $calendarId = 'primary')
    {
        $event = new GoogleCalendarEvent([
            'summary' => $summary,
            'description' => $description,
            'start' => ['dateTime' => $start, 'timeZone' => 'America/Lima'],
            'end' => ['dateTime' => $end, 'timeZone' => 'America/Lima'],
            'attendees' => array_map(fn($email) => ['email' => $email], $attendees),
        ]);

        return $this->service->events->insert($calendarId, $event, ['sendUpdates' => 'all']);
    }


    public function listEvents($calendarId = 'primary')
    {
        $events = $this->service->events->listEvents($calendarId);
        return $events->getItems();
    }
}
