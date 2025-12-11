<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
use App\Models\MiEmpresa;
use Illuminate\Support\Facades\Log;
use OpenAI;
use App\Models\Plato;
use Carbon\Carbon;
use DateTime;

class OpenAIService
{
    protected $client;

    public function __construct()
    {
        $idEmpresa = MiEmpresa::first();
        $clave   = ConfiguracionHelper::clave('Open AI', $idEmpresa->id);

        if ($clave) {
            $this->client = OpenAI::client($clave);
        } else {
            $this->client = null; // No lanzar excepción aquí
        }
    }


    public function extraerPlatosYCantidades($mensaje)
    {
        try {
            if (!$this->client) {
                throw new \Exception("OpenAI no está configurado.");
            }
            $platosMenu = Plato::all()->pluck('nombre')->toArray();
            $ejemploMenu = implode(", ", $platosMenu); // Mostrar TODOS los platos

            $response = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Eres un asistente experto de restaurante. Tu tarea es identificar exactamente qué platos y cantidades pide el cliente, incluso si los escribe con errores ortográficos. 
                    
                    MENÚ COMPLETO: $ejemploMenu
                    
                    REGLAS:
                    1. Analiza cuidadosamente el texto del cliente para identificar nombres de platos y cantidades.
                    2. Si el cliente no especifica cantidad, asume 1.
                    3. Conserva EXACTAMENTE el texto que el cliente usó para cada plato.
                    4. Si un plato no está en el menú, inclúyelo igualmente.
                    5. Devuelve SOLO un JSON válido en este formato exacto: 
                    {\"platos\": [{\"nombre\": \"texto exacto del cliente\", \"cantidad\": número}]}
                    
                    EJEMPLOS:
                    - Cliente dice: 'quiero 2 hamburguesa de pollo y 1 lomo saltado'
                    - Respuesta: {\"platos\": [{\"nombre\": \"hamburguesa de pollo\", \"cantidad\": 2}, {\"nombre\": \"lomo saltado\", \"cantidad\": 1}]}"
                    ],
                    [
                        'role' => 'user',
                        'content' => $mensaje
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 150 // Aumentar tokens para asegurar respuesta completa
            ]);

            $respuesta = json_decode($response->choices[0]->message->content, true);
            return $respuesta['platos'] ?? [];
        } catch (\Exception $e) {
            Log::error("Error OpenAI: " . $e->getMessage());
            return [];
        }
    }
    public function predecirVentas($ventas)
    {
        try {
            if (!$this->client) {
                throw new \Exception("OpenAI no está configurado.");
            }

            $ventasArray = $ventas->toArray();

            // Calculamos promedio de referencia
            $totales = array_column($ventasArray, 'total');
            $diasConVenta = array_filter($totales, fn($t) => $t > 0);
            $promedio = count($diasConVenta) > 0 ? array_sum($diasConVenta) / count($diasConVenta) : 0;

            // Preparamos fechas futuras
            $proximosDias = [];
            for ($i = 0; $i < 7; $i++) {
                $date = now()->addDays($i + 1);
                $proximosDias[] = [
                    'fecha' => $date->format('Y-m-d'),
                    'dia_semana' => $date->locale('es')->dayName
                ];
            }

            $response = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Eres una API que SOLO devuelve JSON.
                    
                    CONTEXTO:
                    Analiza 30 días de ventas de un restaurante.
                    Promedio activo aprox: {$promedio}.
                    
                    TAREA:
                    Predice ventas para 7 días futuros basándote en patrones semanales (fines de semana vs lunes).
                    
                    REGLAS ESTRICTAS:
                    1. Tu respuesta debe contener SOLO el JSON crudo.
                    2. NO incluyas explicaciones, ni saludos, ni texto introductorio.
                    3. NO uses bloques de código Markdown (```json).
                    4. Si el historial tiene ceros, sé conservador pero marca tendencia.

                    ESTRUCTURA EXACTA:
                    {\"predicciones\": [{\"fecha\": \"YYYY-MM-DD\", \"total\": 120.50}]}
                    "
                    ],
                    [
                        'role' => 'user',
                        'content' => "Historial: " . json_encode($ventasArray) .
                            ". Futuro: " . json_encode($proximosDias)
                    ]
                ],
                'temperature' => 0.5,
                'max_tokens' => 600
            ]);

            $content = $response->choices[0]->message->content;

            Log::info("Respuesta cruda OpenAI: " . $content);
            $cleanContent = str_replace(["```json", "```"], "", $content);
            $inicio = strpos($cleanContent, '{');
            $fin = strrpos($cleanContent, '}');

            if ($inicio !== false && $fin !== false) {
                $cleanContent = substr($cleanContent, $inicio, $fin - $inicio + 1);
            }

            $respuesta = json_decode($cleanContent, true);


            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Error JSON Decode: " . json_last_error_msg());
                throw new \Exception("La IA devolvió un formato inválido.");
            }

            return $respuesta['predicciones'] ?? [];
        } catch (\Exception $e) {
            Log::error("Error OpenAI o Parsing: " . $e->getMessage());
            return array_map(function ($dia) use ($promedio) {
                return [
                    'fecha' => $dia['fecha'],
                    'total' => round($promedio, 2)
                ];
            }, $proximosDias);
        }
    }

    public function generarRecomendacionesIA($ventasReales, $ventasIA)
    {
        try {
            if (!$this->client) {
                throw new \Exception("OpenAI no está configurado.");
            }
            // Filtrar y procesar ventas reales de los últimos 15 días
            $ventasUltimos15Dias = collect($ventasReales)
                ->filter(function ($venta) {
                    return \Carbon\Carbon::parse($venta['fechaVenta'])->gte(now()->subDays(15));
                })
                ->groupBy(function ($venta) {
                    return \Carbon\Carbon::parse($venta['fechaVenta'])->format('Y-m-d');
                })
                ->map(function ($ventas, $fecha) {
                    $detallesPedidos = collect($ventas)->flatMap(function ($venta) {
                        return collect($venta['pedido']['detalle_pedido'] ?? [])->map(function ($detalle) {
                            return [
                                'producto' => $detalle['producto']['nombre'] ?? 'Desconocido',
                                'cantidad' => $detalle['cantidad'],
                                'precio_unitario' => $detalle['precio'],
                                'subtotal' => $detalle['cantidad'] * $detalle['precio']
                            ];
                        });
                    });

                    $detallesPedidosWeb = collect($ventas)->flatMap(function ($venta) {
                        return collect($venta['pedido_web']['detalle_pedido'] ?? [])->map(function ($detalle) {
                            return [
                                'producto' => $detalle['plato']['nombre'] ?? 'Desconocido',
                                'cantidad' => $detalle['cantidad'],
                                'precio_unitario' => $detalle['precio'],
                                'subtotal' => $detalle['cantidad'] * $detalle['precio']
                            ];
                        });
                    });

                    $todosDetalles = $detallesPedidos->merge($detallesPedidosWeb);

                    return [
                        'fecha' => $fecha,
                        'total' => collect($ventas)->sum('total'),
                        'detalles' => $todosDetalles->groupBy('producto')
                            ->map(function ($items, $producto) {
                                return [
                                    'producto' => $producto,
                                    'cantidad_total' => $items->sum('cantidad'),
                                    'precio_promedio' => $items->avg('precio_unitario'),
                                    'subtotal_total' => $items->sum('subtotal')
                                ];
                            })->values()->toArray()
                    ];
                })
                ->values()
                ->sortBy('fecha')
                ->toArray();

            $ultimaSemana = array_slice($ventasUltimos15Dias, -7);
            $semanaAnterior = array_slice($ventasUltimos15Dias, 0, 7);

            $totalUltimaSemana = array_sum(array_column($ultimaSemana, 'total'));
            $totalSemanaAnterior = array_sum(array_column($semanaAnterior, 'total'));
            $diferencia = $totalUltimaSemana - $totalSemanaAnterior;
            $porcentajeCambio = $totalSemanaAnterior != 0
                ? ($diferencia / $totalSemanaAnterior) * 100
                : 0;

            $productosUltimaSemana = collect($ultimaSemana)
                ->flatMap(fn($dia) => $dia['detalles'])
                ->groupBy('producto')
                ->map(function ($items, $producto) {
                    return [
                        'producto' => $producto,
                        'cantidad' => $items->sum('cantidad_total'),
                        'precio_promedio' => $items->avg('precio_promedio'),
                        'ventas' => $items->sum('subtotal_total')
                    ];
                })
                ->sortByDesc('ventas')
                ->values()
                ->toArray();

            $productoMasVendido = $productosUltimaSemana[0] ?? null;
            $productoMenosVendido = count($productosUltimaSemana) > 1
                ? $productosUltimaSemana[count($productosUltimaSemana) - 1]
                : null;

            $predicciones = collect($ventasIA)->map(function ($venta) {
                return [
                    'fecha' => $venta['fecha'],
                    'probabilidad' => $venta['probabilidad'],
                    'tendencia' => $venta['tendencia'] ?? 'neutral',
                    'venta_esperada' => $venta['venta_esperada'] ?? null
                ];
            })->toArray();

            // Crear prompt para IA
            $prompt = "
            Eres un analista de ventas para restaurantes. Genera un análisis completo y recomendaciones. Responde únicamente con un JSON VÁLIDO con la siguiente estructura:

            {
            \"resumen_ejecutivo\": \"Texto conciso\",
            \"analisis_comparativo\": \"Texto sobre comparación de semanas\",
            \"tendencias\": [\"Tendencia 1\", \"Tendencia 2\"],
            \"recomendaciones\": [
                {
                \"titulo\": \"Título\",
                \"descripcion\": \"Texto detallado\",
                \"prioridad\": \"alta|media|baja\"
                }
            ],
            \"causas_posibles\": [\"Causa 1\", \"Causa 2\"],
            \"datos_reales\": {
                \"total_ultima_semana\": " . $totalUltimaSemana . ",
                \"total_semana_anterior\": " . $totalSemanaAnterior . ",
                \"diferencia\": " . $diferencia . ",
                \"porcentaje_cambio\": " . round($porcentajeCambio, 2) . ",
                \"producto_mas_vendido\": \"" . ($productoMasVendido['producto'] ?? 'N/A') . "\",
                \"ventas_producto_mas_vendido\": " . ($productoMasVendido['ventas'] ?? 0) . ",
                \"producto_menos_vendido\": \"" . ($productoMenosVendido['producto'] ?? 'N/A') . "\",
                \"ventas_producto_menos_vendido\": " . ($productoMenosVendido['ventas'] ?? 0) . "
            },
            \"predicciones\": " . json_encode($predicciones) . "
            }
            ";

            $response = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => "Solo responde con JSON válido. No agregues explicaciones."],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.4,
                'max_tokens' => 1000
            ]);

            $respuesta = json_decode($response->choices[0]->message->content ?? '', true);

            if (!$respuesta || !is_array($respuesta)) {
                throw new \Exception("La respuesta de la IA no tiene un formato JSON válido.");
            }

            return $respuesta;
        } catch (\Exception $e) {
            Log::error("Error al generar recomendaciones: " . $e->getMessage());
            return [
                'error' => 'Ocurrió un error al generar el análisis. Por favor intenta nuevamente.'
            ];
        }
    }

    public function generarComboConOpenAI($datos)
    {
        try {
            if (!$this->client) {
                throw new \Exception("OpenAI no está configurado.");
            }
            // Procesar cada categoría para extraer nombres y precios
            $procesarCategoria = function ($items) {
                if (empty($items)) return [];
                if (is_array($items)) {
                    return array_map(function ($item) {
                        return [
                            'nombre' => $item['nombre'] ?? '',
                            'precio' => $item['precio'] ?? 0
                        ];
                    }, $items);
                } else {
                    return $items->map(function ($item) {
                        return [
                            'nombre' => $item->nombre,
                            'precio' => $item->precio
                        ];
                    })->toArray();
                }
            };

            // Obtener datos de cada categoría
            $brasas = $procesarCategoria($datos['brasas'] ?? []);
            $hamburguesas = $procesarCategoria($datos['hamburguesas'] ?? []);
            $platos = $procesarCategoria($datos['platos'] ?? []);
            $bebidas = $procesarCategoria($datos['bebidas'] ?? []);

            // Crear listas para el prompt
            $formatItems = function ($items) {
                return implode("\n", array_map(
                    fn($item) => "• {$item['nombre']} (S/ {$item['precio']})",
                    $items
                ));
            };

            $mensajeSistema = "
        [CONTEXTO ACTUALIZADO]
        Eres un chef y experto en precios para restaurantes. Tu tarea es:
        1. Crear combos innovadores mezclando categorías
        2. Calcular un precio final atractivo considerando:
           - Suma de precios individuales
           - Descuento del 10-15% por ser combo
           - Precios psicológicos (ej: S/ 39.90 en lugar de S/ 40)

        [CATEGORÍAS DISPONIBLES CON PRECIOS]
        1. BRASAS:
        " . $formatItems($brasas) . "

        2. HAMBURGUESAS:
        " . $formatItems($hamburguesas) . "

        3. PLATOS:
        " . $formatItems($platos) . "

        4. BEBIDAS:
        " . ($bebidas ? $formatItems($bebidas) : "NINGUNA - Sugerir bebida") . "

        [REGLAS DE PRECIO]
        • Calcular como: (Suma de precios) - (10% a 15% de descuento)
        • Redondear a .90 o .50 (ej: S/ 29.90 en lugar de S/ 30)
        • Mostrar siempre 2 decimales

        [FORMATO REQUERIDO]
        {
          \"nombre\": \"Nombre basado en los items\",
          \"descripcion\": \"Descripción atractiva\",
          \"precioCombo\": 39.90, // Precio calculado con descuento
          \"items\": [
            { \"tipo\": \"categoría\", \"nombre\": \"Item 1\", \"precio\": 15.00 },
            { \"tipo\": \"categoría\", \"nombre\": \"Item 2\", \"precio\": 20.00 },
            { \"tipo\": \"bebida\", \"nombre\": \"Bebida\", \"precio\": 8.50 }
          ]
        }

        [EJEMPLO]
        {
          \"nombre\": \"Pollo & Causa Fusion\",
          \"descripcion\": \"Lo mejor de la parrilla con sabores tradicionales\",
          \"precioCombo\": 42.90,
          \"items\": [
            { \"tipo\": \"brasa\", \"nombre\": \"1/2 Pollo a la Brasa\", \"precio\": 25.00 },
            { \"tipo\": \"plato\", \"nombre\": \"Causa Limeña\", \"precio\": 18.00 },
            { \"tipo\": \"bebida\", \"nombre\": \"Chicha Morada\", \"precio\": 6.50 }
          ]
        }";

            // Resto de la llamada a la API...
            $response = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => $mensajeSistema],
                    ['role' => 'user', 'content' => 'Genera un combo con precio especial.']
                ],
                'temperature' => 0.7,
                'max_tokens' => 400
            ]);

            $respuesta = json_decode($response->choices[0]->message->content, true);

            // Validación mejorada
            $requiredFields = ['nombre', 'descripcion', 'precioCombo', 'items'];
            foreach ($requiredFields as $field) {
                if (!isset($respuesta[$field])) {
                    throw new \Exception("Falta el campo requerido: $field");
                }
            }

            return $respuesta;
        } catch (\Exception $e) {
            Log::error("Error al generar combo: " . $e->getMessage());
            return [
                'nombre' => 'Combo Especial',
                'descripcion' => 'Nuestro chef está preparando nuevas combinaciones',
                'precioCombo' => 0,
                'items' => []
            ];
        }
    }
}
