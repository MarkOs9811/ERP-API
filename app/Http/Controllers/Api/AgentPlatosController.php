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

class AgentPlatosController extends Controller
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

    // --- NUEVO: GESTIÓN INTELIGENTE DE CATEGORÍAS (CON DETECCIÓN DE PLURALES) ---
    private function buscarOCrearCategoria($nombreCategoria)
    {
        $catNombre = trim((string) $nombreCategoria);
        if ($catNombre === '') {
            $catNombre = 'Platos';
        }

        $catLower = mb_strtolower($catNombre);

        // 1. Búsqueda exacta primero (ej: "hamburguesa" == "hamburguesa")
        $categoria = CategoriaPlato::whereRaw('LOWER(nombre) = ?', [$catLower])->first();
        if ($categoria) {
            return $categoria->id;
        }

        // 2. Inteligencia para plurales/singulares: Le quitamos la "s" o "es" final al texto
        // Ej: "Hamburguesas" -> "Hamburguesa" | "Especiales" -> "Especial"
        $raiz = preg_replace('/(es|s)$/i', '', $catLower);

        // Solo aplicamos la búsqueda por raíz si la palabra tiene un tamaño decente
        if (strlen($raiz) >= 3) {
            $categoriaPlural = CategoriaPlato::whereRaw('LOWER(nombre) LIKE ?', ["%{$raiz}%"])->first();
            if ($categoriaPlural) {
                return $categoriaPlural->id;
            }
        }

        // 3. Si definitivamente no existe, la creamos bonita (Primera Letra Mayúscula)
        $nuevaCat = CategoriaPlato::create([
            'nombre' => mb_convert_case($catLower, MB_CASE_TITLE, "UTF-8")
        ]);

        return $nuevaCat->id;
    }

    private function crearPlato(array $argumentos)
    {
        $datos = Validator::make($argumentos, [
            'nombrePlato' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
            'precio' => 'required|numeric|min:0',
            'nombreCategoria' => 'nullable|string', // AHORA ES OPCIONAL
        ])->validate();

        $nombre = trim($datos['nombrePlato']);
        if (Plato::whereRaw('LOWER(nombre) = ?', [mb_strtolower($nombre)])->exists()) {
            return "ERROR_DUPLICADO: Ya existe un plato llamado '$nombre'.";
        }

        // Si no envía categoría, usamos "Platos" por defecto
        $nombreCat = !empty($datos['nombreCategoria']) ? $datos['nombreCategoria'] : 'Platos';
        $idCategoria = $this->buscarOCrearCategoria($nombreCat);

        $plato = Plato::create([
            'nombre' => mb_strtolower($nombre),
            'descripcion' => $datos['descripcion'] ?? null,
            'precio' => round((float) $datos['precio'], 2),
            'idCategoria' => $idCategoria,
            'estado' => 1,
        ]);

        return "ÉXITO: Se creó el plato '{$plato->nombre}' con precio S/ {$plato->precio} en la categoría '{$nombreCat}'.";
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
            'nombreCategoria' => 'nullable|string', // OPCIONAL
        ])->validate();

        if (!empty($datos['nombreCategoria'])) {
            $plato->idCategoria = $this->buscarOCrearCategoria($datos['nombreCategoria']);
        }
        if (isset($datos['nuevoNombre'])) {
            $plato->nombre = mb_strtolower(trim($datos['nuevoNombre']));
        }
        if (array_key_exists('descripcion', $datos)) {
            $plato->descripcion = $datos['descripcion'];
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
            'listarPlatos'          => $this->listarPlatos($argumentos['filtro'] ?? null),
            'crearPlato'            => $this->crearPlato($argumentos),
            'editarPlato'           => $this->editarPlato($argumentos),
            'eliminarPlato'         => $this->eliminarPlato($argumentos['nombrePlato'] ?? ''),
            'diferenciarPlatos'     => $this->diferenciarPlatos($argumentos),
            default                 => 'ERROR_HERRAMIENTA: Operación no reconocida.',
        };
    }

    // --------------------------------------------------------
    // EL ORQUESTADOR PRINCIPAL (MULTI-STEP REASONING)
    // --------------------------------------------------------

    public function chatear(Request $request, $agente)
    {
        set_time_limit(180);
        $pregunta = $request->input('pregunta');
        $systemPrompt = config("agent.prompts.$agente");

        if (!$systemPrompt) {
            return response()->json(['respuesta' => 'El agente seleccionado aún no está implementado.'], 422);
        }

        try {
            $user = auth()->user();
            $idEmpresa = $user->idEmpresa ?? null;
            $apiKey = ConfiguracionHelper::clave('Groq API', $idEmpresa) ?? env('API_GROQ');
            $url = "https://api.groq.com/openai/v1/chat/completions";

            if ($agente === 'platos') {
                $tools = $this->obtenerHerramientasPlatos();
            } else {
                return response()->json(['respuesta' => 'Agente no implementado.'], 422);
            }

            $mensajes = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $pregunta]
            ];

            $modelos_a_intentar = [
                'openai/gpt-oss-120b',
                'openai/gpt-oss-20b',
                'minimaxai/minimax-m2.7',
                'llama-3.3-70b-versatile'
            ];

            $mensajeIA = null;
            $modeloExitoso = null;

            // 1. PRIMER LLAMADO A LA IA
            foreach ($modelos_a_intentar as $modelo) {
                $payload = [
                    'model' => $modelo,
                    'messages' => $mensajes,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                    'temperature' => 0.2,
                    'max_tokens' => 2000
                ];

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(60)->post($url, $payload);

                $tempData = $response->json();

                if (!$response->failed() && !isset($tempData['error'])) {
                    $mensajeIA = $tempData['choices'][0]['message'] ?? null;
                    $modeloExitoso = $modelo;
                    break;
                }
            }

            if (!$mensajeIA) {
                throw new \Exception("Todos los modelos de IA fallaron al procesar la solicitud.");
            }

            // 2. BUCLE AUTÓNOMO DE HERRAMIENTAS (¡LA MAGIA DEL MULTI-STEP!)
            $iteraciones = 0;
            $maxIteraciones = 3; // Le damos hasta 3 turnos para investigar y actuar

            while (!empty($mensajeIA['tool_calls']) && $iteraciones < $maxIteraciones) {

                $mensajes[] = $mensajeIA; // Guardamos su intento de usar herramientas

                foreach ($mensajeIA['tool_calls'] as $toolCall) {
                    $nombreFuncion = $toolCall['function']['name'];
                    $argumentos = json_decode($toolCall['function']['arguments'], true) ?? [];

                    Log::info("[GROQ] Iteración {$iteraciones} - Ejecutando: {$nombreFuncion}");

                    // Ejecutar nuestro backend
                    $resultadoBackend = $this->ejecutarHerramienta($nombreFuncion, $argumentos);

                    // Devolver el resultado de esa herramienta específica
                    $mensajes[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $nombreFuncion,
                        'content' => (string) $resultadoBackend
                    ];
                }

                // Volvemos a preguntar a la IA qué quiere hacer ahora con esos datos
                $payloadFinal = [
                    'model' => $modeloExitoso,
                    'messages' => $mensajes,
                    'tools' => $tools, // IMPORTANTE: Mantenemos las tools activas
                    'temperature' => 0.2
                ];

                $respuestaFinal = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(60)->post($url, $payloadFinal);

                $dataFinal = $respuestaFinal->json();

                // Actualizamos $mensajeIA para ver si quiere seguir usando herramientas o ya terminó
                $mensajeIA = $dataFinal['choices'][0]['message'] ?? null;
                $iteraciones++;
            }

            // 3. RESPUESTA FINAL (Ya analizó todo y construyó su respuesta en texto)
            $textoDirecto = $mensajeIA['content'] ?? null;

            // Si por alguna razón consumió sus 3 turnos pero no generó texto
            if (!$textoDirecto) {
                $textoDirecto = "✅ Operación completada exitosamente en el sistema.";
            }

            return response()->json(['respuesta' => $textoDirecto]);
        } catch (\Exception $e) {
            Log::error("Error Crítico en Agente: " . $e->getMessage());
            return response()->json(['respuesta' => 'El sistema está experimentando intermitencias. Revisa los logs.'], 500);
        }
    }

    // --------------------------------------------------------
    // DEFINICIÓN DE HERRAMIENTAS (FORMATO ESTÁNDAR OPENAI/GROQ)
    // --------------------------------------------------------

    private function obtenerHerramientasPlatos()
    {
        return [
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
                    'description' => 'Crea un plato nuevo en el menú. Si el usuario pide una descripción, puedes inventarla tú mismo si es necesario.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'nombrePlato' => ['type' => 'string'],
                            'descripcion' => ['type' => 'string', 'description' => 'Descripción corta e inventada si el usuario no da una exacta.'],
                            'precio' => ['type' => 'number'],
                            'nombreCategoria' => ['type' => 'string', 'description' => 'Nombre de categoría. Opcional.']
                        ],
                        'required' => ['nombrePlato', 'precio']
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
                            'descripcion' => ['type' => 'string', 'description' => 'Nueva descripción (puedes inventarla si el usuario lo pide).'],
                            'nuevoPrecio' => ['type' => 'number'],
                            'nombreCategoria' => ['type' => 'string']
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
    }
}
