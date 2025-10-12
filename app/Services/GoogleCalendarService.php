<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
use App\Models\User;
use Google_Client;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected $client;
    protected $service;

    public function __construct(User $user)
    {

        config([
            'services.google.client_id' => ConfiguracionHelper::valor1('google'),
            'services.google.client_secret' => ConfiguracionHelper::valor2('google'),
            'services.google.redirect' => ConfiguracionHelper::valor3('google'),
        ]);
        $this->client = new Google_Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent select_account');
        $this->client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.events',
            'email',
            'profile'
        ]);

        // Establecer el token actual
        $this->client->setAccessToken([
            'access_token' => $user->google_token,
            'refresh_token' => $user->google_refresh_token,
            'expires_in' => 3600,
            'created' => time(),
        ]);

        // Si el token ha expirado, obtener uno nuevo
        if ($this->client->isAccessTokenExpired()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);

            if (isset($newToken['error'])) {
                Log::error('Error al renovar el token', ['error' => $newToken['error_description']]);
                throw new \Exception('No se pudo obtener un nuevo access token.');
            }

            $user->update([
                'google_token' => $newToken['access_token'],
                'token_created_at' => now(),
                'google_refresh_token' => $newToken['refresh_token'] ?? $user->google_refresh_token,
            ]);
            $this->client->setAccessToken($newToken);
        }


        $this->service = new GoogleCalendar($this->client);
    }

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
