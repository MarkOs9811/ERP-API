<?php

namespace App\Services;

use App\Helpers\ConfiguracionHelper;
use App\Models\MiEmpresa;
use Illuminate\Support\Facades\Log;
use OpenAI;
use App\Models\Plato;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\Http;

class OpenAIService
{
    protected $client;
    protected $idEmpresa;
    protected $idSede;

    public function __construct()
    {
        $miEmpresa = MiEmpresa::first();
        $this->idEmpresa = $miEmpresa->id;
        $this->idSede = $miEmpresa->idSede ?? null; // Obtener sede si está disponible

        $clave = ConfiguracionHelper::clave('Open AI', $this->idEmpresa);

        if ($clave) {
            $this->client = OpenAI::client($clave);
        } else {
            $this->client = null; // No lanzar excepción aquí
        }
    }


    public function extraerPlatosYCantidades($mensaje, $idEmpresa = null, $idSede = null)
    {
        try {
            if (!$this->client) {
                throw new \Exception("OpenAI no está configurado.");
            }

            // Usar los parámetros pasados o los valores por defecto de la instancia
            $empresa = $idEmpresa ?? $this->idEmpresa;
            $sede = $idSede ?? $this->idSede;

            // Filtrar platos por empresa y sede
            $query = Plato::where('idEmpresa', $empresa);

            if ($sede) {
                $query->where('idSede', $sede);
            }

            $platosMenu = $query->pluck('nombre')->toArray();
            $listaMenu = implode(", ", $platosMenu);
            $listaMenuJSON = json_encode($platosMenu, JSON_UNESCAPED_UNICODE);

            $response = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "Eres un extractor de pedidos. Tu misión es identificar qué platos y qué cantidades está pidiendo el cliente.
                
                MENÚ DISPONIBLE:
                $listaMenuJSON

                REGLAS:
                1. Extrae TODOS los platos que el cliente mencione, incluso si NO están en el menú proporcionado.
                2. Si el plato está en el menú, usa el nombre exacto o si una palabra esta en el plato lo mostramos en el menú.
                3. Si el plato NO está en el menú, extrae el nombre tal cual lo dijo el cliente.
                4. Devuelve SIEMPRE un JSON con este formato:
                {\"platos\": [{\"nombre\": \"Nombre del plato\", \"cantidad\": 2, \"existe_en_menu\": true/false}]}"
                    ],
                    [
                        'role' => 'user',
                        'content' => $mensaje
                    ]
                ],
                'temperature' => 0,
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

    public function consultarAgenteMoodle($mensajeUsuario, $herramientasMCP, $moodleMcpUrl, $token)
    {
        try {
            // Inicializamos la memoria de la conversación
            $mensajes = [
                ['role' => 'system', 'content' => 'Eres un asistente experto de Moodle. Tienes permiso de usar múltiples herramientas en secuencia. Por ejemplo: si te piden datos de un curso de un usuario, primero busca el ID del usuario, luego busca sus cursos. No te detengas a explicar lo que vas a hacer, simplemente haz los pasos hasta que tengas la información completa y ahí recién devuelve tu respuesta final en texto.'],
                ['role' => 'user', 'content' => $mensajeUsuario]
            ];

            $maxPasos = 5; // Le damos hasta 5 turnos para que haga su trabajo

            for ($i = 0; $i < $maxPasos; $i++) {
                Log::info("--- [Agente] Turno " . ($i + 1) . " ---");

                $response = $this->client->chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => $mensajes,
                    'tools' => $herramientasMCP,
                    'tool_choice' => 'auto'
                ]);

                $mensajeIA = $response->choices[0]->message->toArray();

                // Limpiamos nulos para que el SDK de OpenAI no se queje y lo guardamos en memoria
                $mensajes[] = array_filter($mensajeIA, fn($valor) => $valor !== null);

                // CONDICIÓN DE PARADA: Si la IA responde con texto y ya no pide herramientas, terminamos.
                if (!isset($mensajeIA['tool_calls']) || empty($mensajeIA['tool_calls'])) {
                    Log::info("[Agente] ¡Respuesta final lista!");
                    return $mensajeIA['content'];
                }

                // SI PIDIÓ HERRAMIENTAS: Las ejecutamos
                foreach ($mensajeIA['tool_calls'] as $toolCall) {
                    $nombreFuncion = $toolCall['function']['name'];
                    $argumentos = json_decode($toolCall['function']['arguments'], true);

                    Log::info("[Agente] Ejecutando herramienta en Moodle: {$nombreFuncion}", $argumentos);

                    $responseMcp = Http::withToken($token)->post($moodleMcpUrl, [
                        'jsonrpc' => '2.0',
                        'id' => uniqid(),
                        'method' => 'tools/call',
                        'params' => [
                            'name' => $nombreFuncion,
                            'arguments' => $argumentos
                        ]
                    ]);

                    $resultadoMcp = $responseMcp->json();

                    // Extraemos el contenido bruto primero
                    $contenidoBruto = $resultadoMcp['result']['content'] ?? null;

                    // ==========================================================
                    // 🛡️ EL ESCUDO ANTI-EXPLOSIÓN 3.0 (Inteligente)
                    // ==========================================================
                    if ($nombreFuncion === 'core_enrol_get_enrolled_users' && !empty($contenidoBruto)) {
                        Log::info("[Filtro] Analizando estructura para reducir usuarios...");

                        if (isset($contenidoBruto[0]['text'])) {
                            $datosMoodle = json_decode($contenidoBruto[0]['text'], true);

                            // 1. Verificamos si Moodle mandó una lista vacía
                            if (empty($datosMoodle)) {
                                $contenidoBruto[0]['text'] = json_encode(["mensaje" => "El curso no tiene alumnos matriculados."]);
                            }
                            // 2. Verificamos si Moodle nos tiró un error de permisos (Exception)
                            elseif (isset($datosMoodle['exception'])) {
                                Log::warning("[Filtro] Moodle bloqueó la petición: " . $datosMoodle['message']);
                                // No lo tocamos. Dejamos que la IA lea el error original.
                            }
                            // 3. Es una lista real de alumnos. ¡A filtrar!
                            elseif (is_array($datosMoodle)) {
                                $usuariosReducidos = array_map(function ($user) {
                                    return [
                                        'id' => $user['id'] ?? null,
                                        // Moodle a veces manda fullname, a veces firstname + lastname
                                        'nombre' => $user['fullname'] ?? trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: 'Sin Nombre',
                                    ];
                                }, array_slice($datosMoodle, 0, 3));

                                $contenidoBruto[0]['text'] = json_encode($usuariosReducidos);
                                Log::info("[Filtro] Exito: Alumnos reducidos a " . count($usuariosReducidos));
                            }
                        }
                    }
                    // ==========================================================
                    // ==========================================================

                    $contenidoHerramienta = $contenidoBruto
                        ? json_encode($contenidoBruto)
                        : json_encode(["error" => "No se encontraron datos"]);

                    // Le devolvemos el resultado a la memoria de la IA
                    $mensajes[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => $contenidoHerramienta
                    ];
                }

                // El bucle 'for' se repite. La IA leerá los datos y decidirá el siguiente paso.
            }

            return "Hice mi mejor esfuerzo, pero la tarea requería demasiados pasos. Hasta ahora sé esto: " . $mensajeIA['content'];
        } catch (\Exception $e) {
            Log::error("[Agente CRÍTICO] Error: " . $e->getMessage());
            throw $e;
        }
    }
    public function ejecutarHerramientaMCP($toolCalls, $mensajeOriginalUsuario, $moodleMcpUrl, $token)
    {
        try {
            $mensajes = [
                ['role' => 'system', 'content' => 'Eres un administrador de Moodle inteligente.'],
                ['role' => 'user', 'content' => $mensajeOriginalUsuario],
                ['role' => 'assistant', 'tool_calls' => $toolCalls]
            ];

            // 2. LAS MANOS EJECUTAN
            foreach ($toolCalls as $toolCall) {
                // ¡CORRECCIÓN AQUÍ! Ahora se leen como arrays, no como objetos
                $nombreFuncion = $toolCall['function']['name'];
                $argumentos = json_decode($toolCall['function']['arguments'], true);
                $toolCallId = $toolCall['id'];

                Log::info("5. [Manos] Ejecutando en Moodle la herramienta: {$nombreFuncion}", ['args' => $argumentos]);

                // Hablamos con el servidor MCP para que haga la tarea en Moodle
                $responseMcp = Http::withToken($token)->post($moodleMcpUrl, [
                    'jsonrpc' => '2.0',
                    'id' => uniqid(),
                    'method' => 'tools/call',
                    'params' => [
                        'name' => $nombreFuncion,
                        'arguments' => $argumentos
                    ]
                ]);

                $resultadoMcp = $responseMcp->json();
                Log::info("6. [Manos] Respuesta cruda devuelta por Moodle MCP: ", $resultadoMcp ?? ['Fallo' => 'Respuesta no es JSON']);

                // Atrapamos la respuesta que devolvió Moodle
                $contenidoHerramienta = isset($resultadoMcp['result']['content'])
                    ? json_encode($resultadoMcp['result']['content'])
                    : json_encode(["error" => "La herramienta se ejecutó pero no devolvió datos útiles.", "raw" => $resultadoMcp]);

                // Le entregamos los datos masticados de Moodle a OpenAI
                $mensajes[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => $contenidoHerramienta
                ];
            }

            Log::info("7. [Manos] Devolviendo datos de Moodle a OpenAI para que redacte el texto final...");

            // 3. LA IA REDACTA EL RESULTADO FINAL
            $respuestaFinal = $this->client->chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => $mensajes
            ]);

            Log::info("8. [Manos] Respuesta final generada correctamente.");
            return $respuestaFinal->choices[0]->message->content;
        } catch (\Exception $e) {
            Log::error("[Manos CRÍTICO] Error en ejecutarHerramientaMCP: " . $e->getMessage());
            throw $e;
        }
    }
}
