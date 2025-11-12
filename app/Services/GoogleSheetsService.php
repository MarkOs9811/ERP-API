<?php

namespace App\Services;


use App\Helpers\ConfiguracionHelper;
// ¡Ya NO importamos App\Models\User!
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

    // 1. CONSTRUCTOR ACTUALIZADO
    // Ya no recibe un User, usa la configuración central
    public function __construct()
    {
        // Obtener credenciales desde la fila "Google Service"
        $clientId     = ConfiguracionHelper::valor1('Google Service');
        $clientSecret = ConfiguracionHelper::valor2('Google Service');
        $redirectUri  = ConfiguracionHelper::valor3('Google Service');
        $refreshToken = ConfiguracionHelper::valor4('Google Service'); // <-- Leemos de valor4

        if (!$refreshToken) {
            Log::error("Google Refresh Token no está configurado en 'valor4' de 'Google Service'");
            throw new \Exception("Google Refresh Token no está configurado.");
        }

        $this->client = new Google_Client();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->setAccessType('offline');
        $this->client->setScopes(config('services.google.scopes'));

        // Refrescar el token de acceso
        try {
            $this->client->fetchAccessTokenWithRefreshToken($refreshToken);

            // (Opcional) Si Google da un *nuevo* refresh token, lo guardamos
            $newToken = $this->client->getAccessToken();
            if (isset($newToken['refresh_token']) && $newToken['refresh_token'] !== $refreshToken) {
                Log::info('Google emitió un nuevo Refresh Token. Guardando en valor4.');
                ConfiguracionHelper::guardarValorColumna('Google Service', 'valor4', $newToken['refresh_token']);
            }
        } catch (\Exception $e) {
            Log::error('Error al renovar el token central de Google: ' . $e->getMessage());
            throw new \Exception('No se pudo autenticar con Google: ' . $e->getMessage());
        }

        $this->sheetsService = new GoogleSheets($this->client);
        $this->driveService = new GoogleDrive($this->client);
    }

    // 2. FUNCIÓN "OBTENER O CREAR" ACTUALIZADA
    // Esta es la lógica que querías, adaptada al sistema central
    public function getOrCreateSystemSpreadsheet(string $defaultTitle = 'Hoja de Reportes del Sistema')
    {
        // 1. Busca el ID en la columna 'clave' de 'Google Service'
        $spreadsheetId = ConfiguracionHelper::clave('Google Service');

        if ($spreadsheetId) {
            Log::info('Usando la hoja de cálculo central existente. ID: ' . $spreadsheetId);
            return $spreadsheetId;
        }

        // 2. Si no existe (es NULL), creamos una nueva hoja.
        try {
            Log::info('Creando una nueva hoja de cálculo central para el sistema.');

            $spreadsheet = new \Google_Service_Sheets_Spreadsheet([
                'properties' => ['title' => $defaultTitle]
            ]);

            $spreadsheet = $this->sheetsService->spreadsheets->create($spreadsheet);
            $spreadsheetId = $spreadsheet->spreadsheetId;

            if (!$spreadsheetId) {
                Log::error('Error al obtener el ID de la hoja de cálculo creada.');
                throw new \Exception('Error al obtener el ID de la hoja de cálculo.');
            }

            // 3. Guardar el ID en la 'clave' de la config
            // (Asegúrate de tener 'guardarValorColumna' en tu Helper)
            ConfiguracionHelper::guardarValorColumna(
                'Google Service', // Fila
                'clave',          // Columna
                $spreadsheetId   // Valor
            );

            Log::info('Hoja de cálculo central creada con éxito. ID: ' . $spreadsheetId);
            return $spreadsheetId;
        } catch (\Exception $e) {
            Log::error('Error al crear la hoja de cálculo central: ' . $e->getMessage());
            throw $e;
        }
    }


    // 3. TU FUNCIÓN 'updateSheet'
    // Esta es tu función original, ¡no necesita cambios!
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
                            'range' => ['sheetId' => $sheetId],
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
            $range = 'A1';
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            $this->sheetsService->spreadsheets_values->update(
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
