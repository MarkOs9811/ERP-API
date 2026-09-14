<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ConfiguracionHelper;
use App\Http\Controllers\Controller;
use App\Models\CategoriaPlato;
use App\Models\Plato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AgenteController extends Controller
{
    // --------------------------------------------------------
    // HERRAMIENTAS REALES DEL ERP (CRUD)
    // --------------------------------------------------------

    private function buscarPlato($nombrePlato)
    {
        $terminoBusqueda = trim(mb_strtolower((string) $nombrePlato));

        if ($terminoBusqueda === '') {
            return null;
        }

        $plato = Plato::whereRaw('LOWER(nombre) = ?', [$terminoBusqueda])->first();

        if ($plato) {
            return $plato;
        }

        $coincidencias = Plato::where('nombre', 'like', $terminoBusqueda . '%')
            ->limit(2)
            ->get();

        if ($coincidencias->count() === 1) {
            return $coincidencias->first();
        }

        return Plato::where('nombre', 'like', '%' . $terminoBusqueda . '%')
            ->orWhere('descripcion', 'like', '%' . $terminoBusqueda . '%')
            ->first();
    }

    private function actualizarPrecioPlato($nombrePlato, $nuevoPrecio)
    {
        $plato = $this->buscarPlato($nombrePlato);

        if (!$plato) {
            $platosDisponibles = Plato::orderBy('nombre')->limit(10)->pluck('nombre')->implode(', ');
            return "ERROR_NO_ENCONTRADO: No existe '$nombrePlato'. Los platos disponibles son: [$platosDisponibles]. Dile al usuario que no lo encontraste y dale opciones.";
        }

        if (!is_numeric($nuevoPrecio) || $nuevoPrecio < 0) {
            return 'ERROR_VALIDACION: El precio debe ser un número mayor o igual a cero.';
        }

        $precioAnterior = $plato->precio;
        $plato->precio = round((float) $nuevoPrecio, 2);
        $plato->save();

        return "ÉXITO: Se actualizó correctamente. El plato '{$plato->nombre}' pasó de S/ {$precioAnterior} a S/ {$nuevoPrecio}. Informa esto al usuario de manera amigable.";
    }

    private function cambiarEstadoWebPlato($nombrePlato, $estadoWeb)
    {
        $plato = $this->buscarPlato($nombrePlato);

        if (!$plato) {
            return "No se encontró el plato '$nombrePlato' en el sistema.";
        }

        $plato->estado = (int) (bool) $estadoWeb;
        $plato->save();

        $textoEstado = $estadoWeb == 1 ? 'activo y visible' : 'inactivo y oculto';
        return "ÉXITO: El plato '{$plato->nombre}' ahora está $textoEstado.";
    }

    private function listarPlatos($filtro = null)
    {
        $consulta = Plato::with('categoria')->orderBy('nombre')->limit(20);

        if ($filtro) {
            $termino = trim((string) $filtro);
            $consulta->where(function ($query) use ($termino) {
                $query->where('nombre', 'like', '%' . $termino . '%')
                    ->orWhere('descripcion', 'like', '%' . $termino . '%');
            });
        }

        $platos = $consulta->get()->map(function ($plato) {
            return [
                'id' => $plato->id,
                'nombre' => $plato->nombre,
                'precio' => $plato->precio,
                'descripcion' => $plato->descripcion,
                'categoria' => $plato->categoria?->nombre,
                'estado' => (bool) $plato->estado,
            ];
        });

        return $platos->isEmpty()
            ? 'No se encontraron platos con ese criterio.'
            : 'PLATOS: ' . $platos->toJson(JSON_UNESCAPED_UNICODE);
    }

    private function crearPlato(array $argumentos)
    {
        $datos = Validator::make($argumentos, [
            'nombrePlato' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'precio' => 'required|numeric|min:0',
            'idCategoria' => 'required|integer',
        ])->validate();

        if (!CategoriaPlato::whereKey($datos['idCategoria'])->exists()) {
            return 'ERROR_VALIDACION: La categoría indicada no existe en tu empresa.';
        }

        $nombre = trim($datos['nombrePlato']);
        if (Plato::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
            return "ERROR_DUPLICADO: Ya existe un plato llamado '$nombre'.";
        }

        $plato = Plato::create([
            'nombre' => mb_strtolower($nombre),
            'descripcion' => $datos['descripcion'] ?? null,
            'precio' => round((float) $datos['precio'], 2),
            'idCategoria' => $datos['idCategoria'],
            'estado' => 1,
        ]);

        return "ÉXITO: Se creó el plato '{$plato->nombre}' con precio S/ {$plato->precio}.";
    }

    private function editarPlato(array $argumentos)
    {
        $plato = $this->buscarPlato($argumentos['nombreActual'] ?? '');
        if (!$plato) {
            return 'ERROR_NO_ENCONTRADO: No encontré el plato que deseas editar.';
        }

        $datos = Validator::make($argumentos, [
            'nombreActual' => 'required|string',
            'nuevoNombre' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'nuevoPrecio' => 'nullable|numeric|min:0',
            'idCategoria' => 'nullable|integer',
        ])->validate();

        if (isset($datos['idCategoria']) && !CategoriaPlato::whereKey($datos['idCategoria'])->exists()) {
            return 'ERROR_VALIDACION: La categoría indicada no existe en tu empresa.';
        }

        if (isset($datos['nuevoNombre'])) {
            $plato->nombre = mb_strtolower(trim($datos['nuevoNombre']));
        }
        foreach (['descripcion', 'idCategoria'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $plato->{$campo} = $datos[$campo];
            }
        }
        if (isset($datos['nuevoPrecio'])) {
            $plato->precio = round((float) $datos['nuevoPrecio'], 2);
        }
        $plato->save();

        return "ÉXITO: El plato '{$plato->nombre}' fue actualizado correctamente.";
    }

    private function eliminarPlato($nombrePlato)
    {
        $plato = $this->buscarPlato($nombrePlato);
        if (!$plato) {
            return "ERROR_NO_ENCONTRADO: No encontré el plato '$nombrePlato'.";
        }

        $plato->estado = 0;
        $plato->save();

        return "ÉXITO: El plato '{$plato->nombre}' fue desactivado. Se conservó su historial.";
    }

    private function diferenciarPlatos(array $argumentos)
    {
        $familia = trim((string) ($argumentos['familia'] ?? ''));
        $nombresFinales = array_values(array_unique(array_filter(array_map(
            fn($nombre) => trim(mb_strtolower((string) $nombre)),
            $argumentos['nombresFinales'] ?? []
        ))));

        if ($familia === '' || count($nombresFinales) < 2) {
            return 'ERROR_VALIDACION: Indica la familia del plato y al menos dos nombres finales distintos.';
        }

        $platos = Plato::where(function ($query) use ($familia) {
            $query->where('nombre', 'like', '%' . $familia . '%')
                ->orWhere('descripcion', 'like', '%' . $familia . '%');
        })->get();

        if ($platos->count() !== count($nombresFinales)) {
            return "ERROR_AMBIGUO: Encontré {$platos->count()} registros relacionados con '$familia' y "
                . count($nombresFinales) . " nombres finales. No modificaré datos hasta que coincidan las cantidades.";
        }

        $ocupados = [];
        $cambios = [];

        // Conserva primero los nombres finales que ya existen y asigna los restantes
        // a los registros genéricos, evitando que un registro absorba otro por LIKE.
        foreach ($platos as $plato) {
            $nombreActual = mb_strtolower(trim($plato->nombre));
            $coincide = array_search($nombreActual, $nombresFinales, true);
            if ($coincide !== false) {
                $ocupados[$coincide] = true;
            }
        }

        $pendientes = array_values(array_diff_key($nombresFinales, $ocupados));
        $indicePendiente = 0;

        foreach ($platos as $plato) {
            $nombreActual = mb_strtolower(trim($plato->nombre));
            if (in_array($nombreActual, $nombresFinales, true)) {
                continue;
            }

            $nuevoNombre = $pendientes[$indicePendiente++] ?? null;
            if (!$nuevoNombre) {
                continue;
            }

            $cambios[] = "{$plato->nombre} -> {$nuevoNombre}";
            $plato->nombre = $nuevoNombre;
            $plato->save();
        }

        return $cambios
            ? 'ÉXITO: Diferencié los platos: ' . implode(', ', $cambios) . '.'
            : 'ÉXITO: Los platos ya tenían nombres diferenciados.';
    }

    private function ejecutarHerramienta($nombreFuncion, array $argumentos)
    {
        return match ($nombreFuncion) {
            'actualizarPrecioPlato' => $this->actualizarPrecioPlato($argumentos['nombrePlato'] ?? '', $argumentos['nuevoPrecio'] ?? null),
            'cambiarEstadoWebPlato' => $this->cambiarEstadoWebPlato($argumentos['nombrePlato'] ?? '', $argumentos['estadoWeb'] ?? 0),
            'listarPlatos' => $this->listarPlatos($argumentos['filtro'] ?? null),
            'crearPlato' => $this->crearPlato($argumentos),
            'editarPlato' => $this->editarPlato($argumentos),
            'eliminarPlato' => $this->eliminarPlato($argumentos['nombrePlato'] ?? ''),
            'diferenciarPlatos' => $this->diferenciarPlatos($argumentos),
            default => 'ERROR_HERRAMIENTA: Operación no reconocida.',
        };
    }

    // --------------------------------------------------------
    // EL ORQUESTADOR PRINCIPAL (INTENTA GEMINI PRIMERO)
    // --------------------------------------------------------

    public function chatear(Request $request, $agente)
    {
        set_time_limit(180);
        $pregunta = $request->input('pregunta');
        $systemPrompt = config("agent.prompts.$agente");

        if (!$systemPrompt) {
            return response()->json([
                'respuesta' => 'El agente seleccionado aún no está implementado.'
            ], 422);
        }

        try {
            $user = auth()->user();
            $idEmpresa = $user->idEmpresa ?? null;
            $apiKey = ConfiguracionHelper::clave('Gemini AI', $idEmpresa);

            if (!$apiKey) {
                throw new \Exception("La API Key de Gemini no está configurada.");
            }

            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent";

            if ($agente === 'platos') {
                // Herramientas formato Gemini
                $tools = [
                    [
                        'functionDeclarations' => [
                            [
                                'name' => 'actualizarPrecioPlato',
                                'description' => 'Actualiza el precio de un plato en el ERP.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'nombrePlato' => ['type' => 'STRING', 'description' => 'Nombre del plato a modificar'],
                                        'nuevoPrecio' => ['type' => 'NUMBER', 'description' => 'El nuevo valor numérico del precio']
                                    ],
                                    'required' => ['nombrePlato', 'nuevoPrecio']
                                ]
                            ],
                            [
                                'name' => 'cambiarEstadoWebPlato',
                                'description' => 'Activa o desactiva la visibilidad de un plato en el menú digital.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'nombrePlato' => ['type' => 'STRING', 'description' => 'Nombre del plato a modificar'],
                                        'estadoWeb' => ['type' => 'INTEGER', 'description' => '1 para activar, 0 para desactivar']
                                    ],
                                    'required' => ['nombrePlato', 'estadoWeb']
                                ]
                            ],
                            [
                                'name' => 'listarPlatos',
                                'description' => 'Consulta los platos del menú, opcionalmente filtrados por nombre o descripción.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'filtro' => ['type' => 'STRING', 'description' => 'Texto opcional para buscar platos']
                                    ]
                                ]
                            ],
                            [
                                'name' => 'crearPlato',
                                'description' => 'Crea un plato nuevo en el menú.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'nombrePlato' => ['type' => 'STRING'],
                                        'descripcion' => ['type' => 'STRING'],
                                        'precio' => ['type' => 'NUMBER'],
                                        'idCategoria' => ['type' => 'INTEGER', 'description' => 'ID de la categoría existente']
                                    ],
                                    'required' => ['nombrePlato', 'precio', 'idCategoria']
                                ]
                            ],
                            [
                                'name' => 'editarPlato',
                                'description' => 'Edita uno o más datos de un plato existente.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'nombreActual' => ['type' => 'STRING'],
                                        'nuevoNombre' => ['type' => 'STRING'],
                                        'descripcion' => ['type' => 'STRING'],
                                        'nuevoPrecio' => ['type' => 'NUMBER'],
                                        'idCategoria' => ['type' => 'INTEGER']
                                    ],
                                    'required' => ['nombreActual']
                                ]
                            ],
                            [
                                'name' => 'eliminarPlato',
                                'description' => 'Desactiva un plato sin borrar su historial.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'nombrePlato' => ['type' => 'STRING']
                                    ],
                                    'required' => ['nombrePlato']
                                ]
                            ],
                            [
                                'name' => 'diferenciarPlatos',
                                'description' => 'Renombra una familia de platos para que cada registro tenga un nombre final claro y único. Úsala cuando el usuario diga diferenciar, ordenar o corregir nombres ambiguos.',
                                'parameters' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'familia' => ['type' => 'STRING', 'description' => 'Familia común, por ejemplo lomo o lomo saltado'],
                                        'nombresFinales' => [
                                            'type' => 'ARRAY',
                                            'items' => ['type' => 'STRING'],
                                            'description' => 'Nombres finales exactos, por ejemplo lomo saltado de pollo y lomo saltado de res'
                                        ]
                                    ],
                                    'required' => ['familia', 'nombresFinales']
                                ]
                            ]
                        ]
                    ]
                ];
            } else {
                return response()->json(['respuesta' => 'El agente seleccionado aún no está implementado.']);
            }

            $contents = [['role' => 'user', 'parts' => [['text' => $pregunta]]]];

            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $contents,
                'tools' => $tools,
                'generationConfig' => ['temperature' => 0.2]
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $apiKey
            ])->timeout(180)->post($url, $payload);

            if ($response->failed()) {
                throw new \Exception("Error en Gemini: " . $response->body());
            }

            $responseData = $response->json();

            if (isset($responseData['candidates'][0]['content']['parts'][0]['functionCall'])) {
                $functionCall = $responseData['candidates'][0]['content']['parts'][0]['functionCall'];
                $nombreFuncion = $functionCall['name'];
                $argumentos = $functionCall['args'] ?? [];

                Log::info("[GEMINI] Decidió usar: " . $nombreFuncion, $argumentos);

                $resultadoBackend = $this->ejecutarHerramienta($nombreFuncion, $argumentos);

                $contents[] = $responseData['candidates'][0]['content'];
                $contents[] = [
                    'role' => 'user',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $nombreFuncion,
                                'response' => ['resultado' => $resultadoBackend]
                            ]
                        ]
                    ]
                ];

                $respuestaFinal = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Goog-Api-Key' => $apiKey
                ])->timeout(180)->post($url, [
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'contents' => $contents
                ]);

                $dataFinal = $respuestaFinal->json();
                $textoFinal = $dataFinal['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (!$textoFinal) {
                    $textoFinal = "✅ " . $resultadoBackend;
                }

                return response()->json(['respuesta' => $textoFinal]);
            }

            $textoDirecto = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? "No pude interpretar la solicitud.";
            return response()->json(['respuesta' => $textoDirecto]);
        } catch (\Exception $e) {
            Log::warning("Gemini no está disponible. Iniciando Fallback a Ollama. Motivo: " . $e->getMessage());
            return $this->fallbackOllama($pregunta, $agente, $systemPrompt);
        }
    }

    // --------------------------------------------------------
    // SISTEMA DE RESPALDO (OLLAMA LOCAL)
    // --------------------------------------------------------

    private function fallbackOllama($pregunta, $agente, $systemPrompt)
    {
        try {
            $urlOllama = 'http://localhost:11434/api/chat';

            if ($agente === 'platos') {
                // Herramientas formato OpenAI (soportado por Ollama)
                $toolsOllama = [
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'actualizarPrecioPlato',
                            'description' => 'Actualiza el precio de un plato en el ERP. Extrae el nombre y el nuevo precio.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombrePlato' => ['type' => 'string', 'description' => 'Nombre del plato a modificar'],
                                    'nuevoPrecio' => ['type' => 'number', 'description' => 'El nuevo valor numérico del precio']
                                ],
                                'required' => ['nombrePlato', 'nuevoPrecio']
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'cambiarEstadoWebPlato',
                            'description' => 'Activa o desactiva la visibilidad de un plato.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombrePlato' => ['type' => 'string'],
                                    'estadoWeb' => ['type' => 'integer', 'description' => '1 para mostrar, 0 para ocultar']
                                ],
                                'required' => ['nombrePlato', 'estadoWeb']
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'listarPlatos',
                            'description' => 'Consulta los platos del menú, opcionalmente filtrados.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'filtro' => ['type' => 'string']
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'crearPlato',
                            'description' => 'Crea un plato nuevo en el menú.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombrePlato' => ['type' => 'string'],
                                    'descripcion' => ['type' => 'string'],
                                    'precio' => ['type' => 'number'],
                                    'idCategoria' => ['type' => 'integer']
                                ],
                                'required' => ['nombrePlato', 'precio', 'idCategoria']
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'editarPlato',
                            'description' => 'Edita uno o más datos de un plato existente.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombreActual' => ['type' => 'string'],
                                    'nuevoNombre' => ['type' => 'string'],
                                    'descripcion' => ['type' => 'string'],
                                    'nuevoPrecio' => ['type' => 'number'],
                                    'idCategoria' => ['type' => 'integer']
                                ],
                                'required' => ['nombreActual']
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'eliminarPlato',
                            'description' => 'Desactiva un plato sin borrar su historial.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombrePlato' => ['type' => 'string']
                                ],
                                'required' => ['nombrePlato']
                            ]
                        ]
                    ],
                    [
                        'type' => 'function',
                        'function' => [
                            'name' => 'diferenciarPlatos',
                            'description' => 'Renombra una familia de platos para que cada registro tenga un nombre final claro y único.',
                            'parameters' => [
                                'type' => 'object',
                                'properties' => [
                                    'familia' => ['type' => 'string'],
                                    'nombresFinales' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string']
                                    ]
                                ],
                                'required' => ['familia', 'nombresFinales']
                            ]
                        ]
                    ]
                ];
            } else {
                return response()->json(['respuesta' => 'El agente seleccionado aún no está implementado.'], 422);
            }

            $mensajes = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $pregunta]
            ];

            $respuesta = Http::timeout(180)->post($urlOllama, [
                'model' => 'llama3.1', // Tu modelo local
                'messages' => $mensajes,
                'tools' => $toolsOllama,
                'stream' => false
            ])->json();

            $mensajeIA = $respuesta['message'] ?? null;
            $contenidoTexto = $mensajeIA['content'] ?? '';

            // TRAMPA PARA OLLAMA: Verificamos si escupió el JSON como texto plano
            $jsonDecodificado = json_decode(trim($contenidoTexto), true);
            $esJsonEnTexto = (json_last_error() === JSON_ERROR_NONE && isset($jsonDecodificado['name']));

            // Verificamos si usó el canal oficial (tool_calls) o si cayó en nuestra trampa de texto
            if (isset($mensajeIA['tool_calls']) || $esJsonEnTexto) {

                if (isset($mensajeIA['tool_calls'])) {
                    $toolCall = $mensajeIA['tool_calls'][0];
                    $nombreFuncion = $toolCall['function']['name'];
                    $argumentos = $toolCall['function']['arguments'];
                } else {
                    $nombreFuncion = $jsonDecodificado['name'];
                    // A veces Ollama llama a la llave "parameters" y otras "arguments"
                    $argumentos = $jsonDecodificado['parameters'] ?? $jsonDecodificado['arguments'] ?? [];
                }

                Log::info("[OLLAMA FALLBACK] Decidió usar: " . $nombreFuncion, $argumentos);

                // Ejecutamos tu súper orquestador
                $resultadoBackend = $this->ejecutarHerramienta($nombreFuncion, $argumentos);

                // 2do Payload a Ollama para que nos dé un mensaje amigable y no solo el JSON
                $mensajes[] = $mensajeIA;
                $mensajes[] = [
                    'role' => 'tool',
                    'content' => $resultadoBackend,
                    'name' => $nombreFuncion
                ];

                $respuestaFinal = Http::timeout(180)->post($urlOllama, [
                    'model' => 'llama3.1',
                    'messages' => $mensajes,
                    'stream' => false
                ])->json();

                $textoFinal = $respuestaFinal['message']['content'] ?? null;

                if (!$textoFinal || str_starts_with(trim($textoFinal), '{')) {
                    $textoFinal = "✅ " . $resultadoBackend;
                }

                return response()->json(['respuesta' => "*(Vía Servidor Local)*\n\n" . $textoFinal]);
            }

            // Si realmente era un mensaje de texto normal
            $textoDirecto = $mensajeIA['content'] ?? "No pude interpretar la solicitud.";
            return response()->json(['respuesta' => "*(Vía Servidor Local)*\n\n" . $textoDirecto]);
        } catch (\Exception $e) {
            Log::error("Error Crítico: Falló Gemini y también falló Ollama local. " . $e->getMessage());
            return response()->json(['respuesta' => 'Todos los sistemas de IA están fuera de servicio temporalmente.'], 500);
        }
    }
}
