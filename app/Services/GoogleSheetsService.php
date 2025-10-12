<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
use App\Models\User;
use Google_Client;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
    protected $client;
    protected $sheetsService;
    protected $driveService;

    public function __construct(User $user)
    {

        config([
            'services.google.client_id' => ConfiguracionHelper::valor1('Google Service'),
            'services.google.client_secret' => ConfiguracionHelper::valor2('Google Service'),
            'services.google.redirect' => ConfiguracionHelper::valor3('Google Service'),
        ]);
        $this->client = new Google_Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setRedirectUri(config('services.google.redirect'));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent select_account');

        // Esto es CLAVE: debe coincidir con los scopes de services.php
        $this->client->setScopes(config('services.google.scopes'));

        // Set token
        $this->client->setAccessToken([
            'access_token' => $user->google_token,
            'refresh_token' => $user->google_refresh_token,
            'expires_in' => 3600,
            'created' => time(),
        ]);

        // Refresh token if expired
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

        $this->sheetsService = new GoogleSheets($this->client);
        $this->driveService = new GoogleDrive($this->client);
    }

    /**
     * Crea una hoja de cálculo y escribe los datos proporcionados.
     */
    public function getOrCreateUserSpreadsheet(User $user, string $defaultTitle = 'Hoja de Reporte')
    {
        // Si el usuario ya tiene una hoja, se usa su ID.
        if ($user->google_spreadsheet_id) {
            // El usuario ya tiene una hoja, asegurarse de devolver el ID correcto.
            Log::info('El usuario ya tiene una hoja de cálculo. ID: ' . $user->google_spreadsheet_id);
            return $user->google_spreadsheet_id;
        }

        // Si no tiene, se crea una nueva hoja.
        try {
            Log::info('Creando una nueva hoja de cálculo para el usuario.');

            $spreadsheet = new \Google_Service_Sheets_Spreadsheet([
                'properties' => ['title' => $defaultTitle]
            ]);

            $spreadsheet = $this->sheetsService->spreadsheets->create($spreadsheet);
            $spreadsheetId = $spreadsheet->spreadsheetId;

            // Verifica que el ID de la hoja sea correcto antes de guardarlo
            if (!$spreadsheetId) {
                Log::error('Error al obtener el ID de la hoja de cálculo.');
                throw new \Exception('Error al obtener el ID de la hoja de cálculo.');
            }

            // Guardar el ID en la base de datos del usuario
            $user->update([
                'google_spreadsheet_id' => $spreadsheetId
            ]);

            Log::info('Hoja de cálculo creada con éxito. ID: ' . $spreadsheetId);

            return $spreadsheetId;
        } catch (\Exception $e) {
            // Manejo de errores si algo falla al crear la hoja
            Log::error('Error al crear la hoja de cálculo para el usuario: ' . $e->getMessage());
            throw $e; // Lanzar el error para que el sistema lo maneje
        }
    }



    public function updateSheet(string $spreadsheetId, array $values)
    {
        Log::info('Actualizando la hoja de cálculo con ID: ' . $spreadsheetId);

        // 1. Obtener el ID de la primera hoja
        $spreadsheet = $this->sheetsService->spreadsheets->get($spreadsheetId);
        $sheetId = $spreadsheet->getSheets()[0]->getProperties()->getSheetId();

        // 2. Limpiar completamente todo el contenido de la hoja
        try {
            Log::info("Limpiando completamente la hoja de cálculo (ID hoja: $sheetId)");

            $batchUpdateRequest = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [
                    [
                        'updateCells' => [
                            'range' => [
                                'sheetId' => $sheetId,
                            ],
                            'fields' => '*'
                        ]
                    ]
                ]
            ]);

            $this->sheetsService->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);
            Log::info('Contenido anterior eliminado correctamente.');
        } catch (\Exception $e) {
            Log::error('Error al limpiar completamente la hoja: ' . $e->getMessage());
        }

        // 3. Cargar los nuevos encabezados y filas
        try {
            $range = 'A1'; // Siempre se escribirá desde A1
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);

            $response = $this->sheetsService->spreadsheets_values->update(
                $spreadsheetId,
                $range,
                $body,
                ['valueInputOption' => 'RAW']
            );

            Log::info('Datos nuevos escritos correctamente.');
        } catch (\Exception $e) {
            Log::error('Error al escribir nuevos datos en la hoja: ' . $e->getMessage());
        }

        return 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId;
    }
}
