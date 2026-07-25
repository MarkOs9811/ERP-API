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

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey
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

    // ========================================================================
    // NUEVO MÉTODO: PREDECIR VENTAS MIGRADO A GEMINI
    // ========================================================================
    public function predecirVentas($ventas)
    {
        // 1. Lógica matemática intacta (Promedios y Fechas)
        $ventasArray = is_array($ventas) ? $ventas : $ventas->toArray();
        $totales = array_column($ventasArray, 'total');
        $diasConVenta = array_filter($totales, fn($t) => $t > 0);
        $promedio = count($diasConVenta) > 0 ? array_sum($diasConVenta) / count($diasConVenta) : 0;

        $proximosDias = [];
        for ($i = 0; $i < 7; $i++) {
            $date = now()->addDays($i + 1);
            $proximosDias[] = [
                'fecha' => $date->format('Y-m-d'),
                'dia_semana' => $date->locale('es')->dayName
            ];
        }

        try {
            if (!$this->apiKey) {
                throw new \Exception("La API Key de Gemini no está configurada.");
            }

            // 2. Construcción del System Prompt para Gemini
            $mensajeSistema = "
            Eres una API de análisis financiero predictivo.
            
            CONTEXTO:
            Analiza un historial de 30 días de ventas de un restaurante.
            Promedio de días activos: " . round($promedio, 2) . ".
            
            TAREA:
            Predice las ventas para los próximos 7 días basándote en el promedio activo y patrones semanales (fines de semana venden más).
            
            REGLAS ESTRICTAS:
            1. Devuelve ÚNICAMENTE un JSON válido.
            2. IMPORTANTE: Si el historial tiene muchos ceros (porque el software recién se está usando), IGNORA los ceros. 
            3. NINGÚN DÍA FUTURO DEBE SER 0.0 si el promedio activo es mayor a 0. Basa tus predicciones futuras en el Promedio de días activos, variando de forma realista (+/- 15%).

            ESTRUCTURA EXACTA:
            {
                \"predicciones\": [
                    {\"fecha\": \"YYYY-MM-DD\", \"total\": 120.50}
                ]
            }";

            $mensajeUsuario = "Historial: " . json_encode($ventasArray) . ". \nFuturo a predecir: " . json_encode($proximosDias);

            // 3. Payload nativo de Gemini (Aprovechando responseMimeType)
            $payload = [
                'system_instruction' => [
                    'parts' => [['text' => $mensajeSistema]]
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $mensajeUsuario]]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.5,
                    'responseMimeType' => 'application/json', // Esto nos garantiza que Gemini NO enviará Markdown
                ]
            ];

            // 4. Petición HTTP usando la llave por Header
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey
            ])->post($this->url, $payload);

            if ($response->failed()) {
                throw new \Exception("Error en la API de Gemini: " . $response->body());
            }

            // 5. Extracción y Parsing
            $responseData = $response->json();
            $textoRespuesta = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

            $respuestaJSON = json_decode($textoRespuesta, true);

            // Validación de estructura
            if (json_last_error() !== JSON_ERROR_NONE || !isset($respuestaJSON['predicciones'])) {
                Log::error("Error JSON Decode Gemini Predictivo: " . json_last_error_msg());
                throw new \Exception("La IA devolvió un formato inválido.");
            }

            return $respuestaJSON['predicciones'];
        } catch (\Exception $e) {
            Log::error("Error en predecirVentas con Gemini: " . $e->getMessage());

            // 6. El mismo Fallback confiable de siempre
            return array_map(function ($dia) use ($promedio) {
                return [
                    'fecha' => $dia['fecha'],
                    'total' => round($promedio, 2)
                ];
            }, $proximosDias);
        }
    }
}
