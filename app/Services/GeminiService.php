<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $client;
    protected $idEmpresa;
    protected $idSede;
    protected $apiKey;
    protected $url;

    public function __construct()
    {
        $user = auth()->user();
        $this->idEmpresa = $user->idEmpresa ?? null;
        $clave = ConfiguracionHelper::clave('Gemini AI', $this->idEmpresa);
        $this->apiKey = $clave;
        $this->url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent";
    }

    public function generarCombo($datos)
    {
        try {
            if (!$this->apiKey) {
                throw new \Exception("La API Key de Gemini no está configurada en el .env.");
            }

            $procesarCategoria = function ($items) {
                if (empty($items)) return [];
                if (is_array($items)) {
                    return array_map(function ($item) {
                        return ['nombre' => $item['nombre'] ?? '', 'precio' => $item['precio'] ?? 0];
                    }, $items);
                } else {
                    return $items->map(function ($item) {
                        return ['nombre' => $item->nombre, 'precio' => $item->precio];
                    })->toArray();
                }
            };

            $brasas = $procesarCategoria($datos['brasas'] ?? []);
            $hamburguesas = $procesarCategoria($datos['hamburguesas'] ?? []);
            $platos = $procesarCategoria($datos['platos'] ?? []);
            $bebidas = $procesarCategoria($datos['bebidas'] ?? []);

            $formatItems = function ($items) {
                return implode("\n", array_map(
                    fn($item) => "• {$item['nombre']} (S/ {$item['precio']})",
                    $items
                ));
            };

            $mensajeSistema = "
            [CONTEXTO ACTUALIZADO]
            Eres un chef y experto en precios para restaurantes. Tu tarea es:
            1. Crear combos innovadores mezclando categorías.
            2. Calcular un precio final atractivo (Suma de precios - 10/15% de descuento).
            3. Precios psicológicos (ej: S/ 39.90).

            [MENÚ DISPONIBLE]
            1. BRASAS:\n" . $formatItems($brasas) . "
            2. HAMBURGUESAS:\n" . $formatItems($hamburguesas) . "
            3. PLATOS:\n" . $formatItems($platos) . "
            4. BEBIDAS:\n" . ($bebidas ? $formatItems($bebidas) : "NINGUNA - Sugerir bebida") . "

            [FORMATO OBLIGATORIO]
            Devuelve ÚNICAMENTE un JSON válido con esta estructura exacta:
            {
              \"nombre\": \"Nombre del combo\",
              \"descripcion\": \"Descripción atractiva\",
              \"precioCombo\": 39.90,
              \"items\": [
                { \"tipo\": \"categoría\", \"nombre\": \"Item 1\", \"precio\": 15.00 }
              ]
            }";

            $payload = [
                'system_instruction' => [
                    'parts' => [['text' => $mensajeSistema]]
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => 'Genera un combo con precio especial basado en el menú.']]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'responseMimeType' => 'application/json',
                ]
            ];

            // AQUÍ ESTÁ LA SOLUCIÓN AL ERROR 401
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey // Pasamos la llave AQ. por el header oficial
            ])->post($this->url, $payload);

            if ($response->failed()) {
                throw new \Exception("Error en la API de Gemini: " . $response->body());
            }

            $responseData = $response->json();
            $textoRespuesta = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

            $respuestaJSON = json_decode($textoRespuesta, true);

            $requiredFields = ['nombre', 'descripcion', 'precioCombo', 'items'];
            foreach ($requiredFields as $field) {
                if (!isset($respuestaJSON[$field])) {
                    throw new \Exception("Falta el campo requerido en la respuesta de IA: $field");
                }
            }

            return $respuestaJSON;
        } catch (\Exception $e) {
            Log::error("Error al generar combo con Gemini: " . $e->getMessage());
            return [
                'nombre' => 'Combo Especial',
                'descripcion' => 'Nuestro chef está preparando nuevas combinaciones',
                'precioCombo' => 0,
                'items' => []
            ];
        }
    }
}
