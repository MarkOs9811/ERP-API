<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionDelivery;
use App\Models\Configuraciones;
use App\Models\MiEmpresa;
use App\Models\SerieCorrelativo;
use App\Models\User;
use App\Traits\SedeValidation;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


use function Laravel\Prompts\error;

class ConfiguracionController extends Controller
{
    use SedeValidation;
    public function actualizarConfiguracion(Request $request)
    {
        try {
            $user = Auth()->user();
            // Validar los datos del formulario
            $validated = $request->validate([
                'nombre' => 'required|string|max:255',
                'ruc' => 'required|string|max:11',
                'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'numero' => 'required|string|max:15',
                'correo' => 'required|string|email|max:255',
                'direccion' => 'required|string|max:255',
            ]);

            // Obtener la primera empresa
            $empresa = MiEmpresa::find($user->idEmpresa);

            if ($empresa) {

                if ($request->hasFile('logo')) {

                    if ($empresa->logo) {
                        Storage::disk('public')->delete($empresa->logo);
                    }

                    $logoPath = $request->file('logo')->store('miEmpresa', 'public');
                } else {

                    $logoPath = $empresa->logo;
                }

                // Actualizar la empresa con la nueva ruta del logo
                $empresa->update(array_merge($validated, ['logo' => $logoPath]));
            } else {
                // Crear una nueva empresa si no existe
                $logoPath = $request->hasFile('logo') ? $request->file('logo')->store('miEmpresa', 'public') : null;
                MiEmpresa::create(array_merge($validated, ['logo' => $logoPath]));
            }

            // Redirigir con un mensaje de éxito usando redirect()->back()
            return response()->json(['success' => true, 'message' => 'Registros actualizados correctamente'], 200);
        } catch (\Exception $e) {
            // Registrar el error
            Log::error('Error al actualizar la configuración de la empresa: ' . $e->getMessage());

            // Redirigir con un mensaje de error usando redirect()->back()
            return response()->json(['success' => false, 'message' => 'Error al registrar los datos: ' . $e->getMessage(),], 500);
        }
    }

    public function getEmpresa()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json(['message' => 'No autenticado'], 401);
            }
            $empresa = MiEmpresa::find($user->idEmpresa);
            if ($empresa) {
                $empresa->logo = $empresa->logo ? Storage::url($empresa->logo) : null;
                return response()->json($empresa);
            }
            return response()->json([
                'success' => true,
                'data' => $empresa
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener la configuración: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getConfiguracion()
    {
        try {
            $configuracion = Configuraciones::all();
            Log::info($configuracion);
            return response()->json([
                'success' => true,
                'data' => $configuracion
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener la configuración: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getMiPerfil()
    {
        try {
            $miId = auth()->user()->id;
            $miPerfil = User::with('empleado.area', 'empleado.horario', 'empleado.cargo', 'empleado.contrato', 'empleado.persona', 'empleado.persona.distrito', 'empleado.persona.distrito.provincia', 'empleado.persona.distrito.provincia.departamento')->where('id', $miId)->first();
            if ($miPerfil) {
                return response()->json([
                    'success' => true,
                    'data' => $miPerfil
                ], 200);
            }
            return response()->json([
                'success' => false,
                'message' => 'No se encontró el perfil'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error al obtener la configuración: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function configurarIntegracion(Request $request, $id)
    {
        try {
            // Validar los datos del formulario
            $validated = $request->validate([
                'clientId' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
                'idSecretaCliente' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
                'redirectUrl' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
            ]);

            // Buscar la configuración por ID
            $configuracion = Configuraciones::find($id);

            if ($configuracion) {
                // Actualizar la configuración con los datos validados
                $configuracion->valor1 = $validated['clientId'];
                $configuracion->valor2 = $validated['idSecretaCliente'];
                $configuracion->valor3 = $validated['redirectUrl'];
                $configuracion->save();
                return response()->json(['success' => true, 'message' => 'Configuración actualizada correctamente'], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'Configuración no encontrada'], 404);
            }
        } catch (\Exception $e) {
            // Registrar el error
            Log::error('Error al actualizar la configuración: ' . $e->getMessage());

            // Retornar un mensaje de error
            return response()->json(['success' => false, 'message' => 'Error al actualizar la configuración: ' . $e->getMessage(),], 500);
        }
    }

    public function configurarOpenAi(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'apiKey' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],

            ]);
            // Buscar la configuración por ID
            $configuracion = Configuraciones::find($id);

            if ($configuracion) {
                // Actualizar la configuración con los datos validados
                $configuracion->clave = $validated['apiKey'];
                $configuracion->save();
                return response()->json(['success' => true, 'message' => 'Configuración actualizada correctamente'], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'Configuración no encontrada'], 404);
            }
        } catch (\Exception $e) {
            // Registrar el error
            Log::error('Error al actualizar la configuración: ' . $e->getMessage());

            // Retornar un mensaje de error
            return response()->json(['success' => false, 'message' => 'Error al actualizar la configuración: ' . $e->getMessage(),], 500);
        }
    }

    public function configurarTwilio(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'SID' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
                'apiKey' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
                'numeroTwilio' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],

            ]);
            // Buscar la configuración por ID
            $configuracion = Configuraciones::find($id);

            if ($configuracion) {
                // Actualizar la configuración con los datos validados
                $configuracion->valor1 = $validated['SID'];
                $configuracion->valor2 = $validated['apiKey'];
                $configuracion->valor3 = $validated['numeroTwilio'];
                $configuracion->save();
                return response()->json(['success' => true, 'message' => 'Configuración actualizada correctamente'], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'Configuración no encontrada'], 404);
            }
        } catch (\Exception $e) {
            // Registrar el error
            Log::error('Error al actualizar la configuración: ' . $e->getMessage());

            // Retornar un mensaje de error
            return response()->json(['success' => false, 'message' => 'Error al actualizar la configuración: ' . $e->getMessage(),], 500);
        }
    }

    public function configurarSunat(Request $request, $id)
    {
        try {
            // Validación de los campos
            $validated = $request->validate([
                'ruc' => ['required', 'string', 'size:11'],
                'usuarioSol' => ['required', 'string', 'max:255'],
                'claveSol' => ['required', 'string', 'max:255'],
                'endpoint' => ['required', 'string', 'url'],
                'certificado' => ['nullable', 'file', 'mimetypes:text/plain', 'mimes:pem'],
            ]);


            // Buscar la configuración
            $configuracion = Configuraciones::find($id);

            if (!$configuracion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración no encontrada'
                ], 404);
            }

            // Manejo del certificado .pem
            if ($request->hasFile('certificado')) {
                $file = $request->file('certificado');
                $filename = 'certificado.pem';

                // Guardar en storage/app/sunat_certificados/
                $path = 'sunat_certificados/' . $filename;

                // Reemplazar si existe
                Storage::put($path, file_get_contents($file));

                // Guardar solo el nombre en BD
                $configuracion->valor1 = $filename;
            }

            // Guardar otros datos en BD
            $configuracion->clave = $validated['ruc'];
            $configuracion->valor3 = $validated['usuarioSol'];
            $configuracion->valor4 = $validated['claveSol'];
            $configuracion->valor2 = $validated['endpoint'];
            $configuracion->save();

            return response()->json([
                'success' => true,
                'message' => 'Configuración de Sunat actualizada correctamente'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar configuración SUNAT: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    public function activarServicio(Request $request, $id)
    {
        try {
            // Validar los datos del formulario
            $validated = $request->validate([
                'estado' => ['required', 'integer', 'in:0,1'],
            ]);


            // Buscar la configuración por ID
            $configuracion = Configuraciones::find($id);

            if ($configuracion) {
                // Actualizar la configuración con los datos validados
                $configuracion->estado = $validated['estado'];
                $configuracion->save();
                return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente'], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'Configuración no encontrada'], 404);
            }
        } catch (\Exception $e) {
            // Registrar el error
            Log::error('Error al actualizar el estado: ' . $e->getMessage());

            // Retornar un mensaje de error
            return response()->json(['success' => false, 'message' => 'Error al actualizar el estado: ' . $e->getMessage(),], 500);
        }
    }


    public function getEstadoConfig($nombreConfig)
    {
        try {
            $estado = Configuraciones::where('nombre', $nombreConfig)->first();

            if (!$estado) {
                return response()->json([
                    "success" => false,
                    "message" => "Configuración no encontrada"
                ], 404);
            }

            return response()->json([
                "success" => true,
                "data" => [
                    "nombre" => $estado->nombre,
                    "estado" => $estado->estado
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al obtener estado",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function getConfiSerieCorrelativo()
    {
        try {
            $confiSerie = SerieCorrelativo::get();
            return response()->json(['success' => true, "data" => $confiSerie], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "message" => "Error al obtener la serie y correlativo" . $e->getMessage()], 500);
        }
    }

    public function saveSerieCorrelativo(Request $request)
    {
        // Validación
        $validator = Validator::make(
            $request->all(),
            [
                'tipo_documento_sunat' => ['required', 'string', 'in:01,03,07,08'],
                'correlativo' => ['required', 'integer', 'min:1'],

                'numeroSerie' => [
                    'required',
                    'string',
                    'size:4',
                    // Formato general (letra inicial F o B, luego 3 caracteres alfanuméricos)
                    'regex:/^[FB][A-Z0-9]{3}$/i',

                    // Validación de unicidad: misma empresa, sede y tipo_documento_sunat
                    Rule::unique('serie_correlativos', 'serie')
                        ->where(function ($query) use ($request) {
                            $user = Auth::user();
                            return $query
                                ->where('idEmpresa', $user->idEmpresa)
                                ->where('tipo_documento_sunat', $request->input('tipo_documento_sunat'));
                        }),

                    // Validación adicional personalizada según tipo de documento
                    function ($attribute, $value, $fail) use ($request) {
                        $serie = strtoupper($value);
                        $tipo = $request->input('tipo_documento_sunat');

                        if ($tipo === '01' && substr($serie, 0, 1) !== 'F') {
                            return $fail('Las Facturas deben empezar con "F".');
                        }
                        if ($tipo === '03' && substr($serie, 0, 1) !== 'B') {
                            return $fail('Las Boletas deben empezar con "B".');
                        }
                        if (in_array($tipo, ['07', '08']) && !in_array(substr($serie, 0, 1), ['F', 'B'])) {
                            return $fail('Las Notas deben empezar con "F" o "B".');
                        }
                    },
                ],
            ],
            [
                'tipo_documento_sunat.required' => 'Debe seleccionar un tipo de documento.',

                'numeroSerie.unique' => 'La serie ingresada ya existe para este tipo de documento en esta sede o entra.',
                'numeroSerie.regex' => 'Formato inválido (Ej: F001 o B001).',
                'numeroSerie.size' => 'La serie debe tener exactamente 4 caracteres.',
                'correlativo.required' => 'El correlativo es obligatorio.',
                'correlativo.integer' => 'El correlativo debe ser un número entero.',
                'correlativo.min' => 'El correlativo debe ser mayor a 0.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();


            $serie = SerieCorrelativo::create([

                'tipo_documento_sunat' => $validated['tipo_documento_sunat'],
                'serie' => strtoupper($validated['numeroSerie']),
                'correlativo_actual' => $validated['correlativo'],
                'is_default' => 0,
                'estado' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Serie registrada con éxito.',
                'data' => $serie,
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function ponerDefaultSerie($id)
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            // 1. Buscar la serie
            $serie = SerieCorrelativo::where('idEmpresa', $user->idEmpresa)
                ->where('idSede', $user->idSede)
                ->find($id);

            if (!$serie) {
                return response()->json(['message' => 'Serie no encontrada.'], 404);
            }

            // 2. Validar que el correlativo no haya llegado al máximo
            if ($serie->correlativo_actual >= 99999999) {
                return response()->json([
                    'message' => 'No se puede establecer como predeterminada. El correlativo ya alcanzó su límite (99999999).'
                ], 422);
            }
            if ($serie->estado != 1) {
                return response()->json([
                    'message' => 'Para poner por defecto esta serie debe estar activada'
                ], 422);
            }

            // 3. Poner todas las series del mismo tipo en 0
            SerieCorrelativo::where('idEmpresa', $user->idEmpresa)
                ->where('idSede', $user->idSede)
                ->where('tipo_documento_sunat', $serie->tipo_documento_sunat)
                ->update(['is_default' => 0]);

            // 4. Activar solo la seleccionada
            $serie->is_default = 1;
            $serie->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Serie establecida como predeterminada correctamente.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al establecer la serie por defecto.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activarSerie($id)
    {
        try {
            $serie = SerieCorrelativo::find($id);
            if (!$serie) {
                return response()->json(['success' => false, "message" => "no se encontró la serie"], 404);
            }
            $serie->estado = 1;
            $serie->save();
            return response()->json(['success' => true, "message" => "Se activó la serie"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "message" => "Error:" . $e->getMessage()], 500);
        }
    }

    public function DesactivarSerie($id)
    {
        try {
            $serie = SerieCorrelativo::find($id);
            if (!$serie) {
                return response()->json(['success' => false, "message" => "no se encontró la serie"], 404);
            }
            if ($serie->is_default == 1) {
                return response()->json(['success' => false, "message" => "no se puesde desactivar por que está puesta por defecto"], 422);
            }
            $serie->estado = 0;
            $serie->save();
            return response()->json(['success' => true, "message" => "Se desactivó la serie"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "message" => "Error:" . $e->getMessage()], 500);
        }
    }

    public function actualizarSerie(Request $request)
    {

        $idParaIgnorar = $request->input('idSerie');

        if (!$idParaIgnorar) {
            return response()->json(['success' => false, "message" => "No se proporcionó el ID del registro a actualizar"], 422);
        }


        $serieCorrelativo = SerieCorrelativo::find($idParaIgnorar);

        if (!$serieCorrelativo) {
            return response()->json(['success' => false, "message" => "Registro de la serie no encontrada"], 404);
        }

        try {


            $validatedData = $request->validate(
                [
                    'tipo_documento_sunat' => ['required', 'string', 'in:01,03,07,08'],
                    'correlativo' => ['required', 'integer', 'min:1'],
                    'numeroSerie' => [
                        'required',
                        'string',
                        'size:4',
                        'regex:/^[FB][A-Z0-9]{3}$/i',
                        $this->uniqueSede(
                            'serie_correlativos',     // 1. La tabla donde buscar
                            'serie',            // 2. La columna que validamos
                            $idParaIgnorar,           // 3. El ID que debe ignorar (para que no falle consigo mismo)
                            [                         // 4. Las columnas extra para la clave compuesta
                                'tipo_documento_sunat' => $request->input('tipo_documento_sunat')
                            ]
                        )
                    ],
                    // ... (valida otros campos que puedas necesitar, como 'id' si es requerido)
                    'idSerie' => ['required', 'integer']
                ],
                ['numeroSerie.unique' => 'No puedes usar esta serie: ya existe en otra sede o ha sido registrada previamente.']

            );



            // 6. Si la validación pasa, actualiza el registro
            $serieCorrelativo->tipo_documento_sunat = $validatedData['tipo_documento_sunat'];
            $serieCorrelativo->serie = $validatedData['numeroSerie'];
            $serieCorrelativo->correlativo_actual = $validatedData['correlativo'];
            $serieCorrelativo->save();


            // 7. Devuelve una respuesta de éxito
            return response()->json(['success' => true, "message" => "La serie se actualizó correctamente"], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                "message" => "Error de validación",
                "errors" => $e->errors()
            ], 422);
        } catch (\Exception $e) {

            return response()->json(['success' => false, "message" => "Error de servidor: " . $e->getMessage()], 500);
        }
    }

    public function actualizarColorTema($colorTema)
    {
        try {
            $user = auth()->user();


            $hexColor = '#' . str_replace('#', '', $colorTema);


            $validator = Validator::make(['color' => $hexColor], [
                'color' => ['required', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/']
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El código de color no es válido.'
                ], 422);
            }

            Configuraciones::updateOrCreate(

                ['idEmpresa' => $user->idEmpresa, 'tipo' => 'estilos'],
                [
                    'nombre' => 'tema',
                    'clave'  => $hexColor
                ]
            );
            return response()->json([
                'success' => true,
                'message' => 'Color del tema actualizado correctamente.',
                'data'    => ['color' => $hexColor]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error actualizando color: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno al guardar la configuración.'
            ], 500);
        }
    }

    public function actualizarIgv($porcentaje)
    {
        try {
            $user = auth()->user();

            $igvValue = floatval($porcentaje);

            if ($igvValue < 0 || $igvValue > 100) {
                return response()->json([
                    'success' => false,
                    'message' => 'El porcentaje de IGV debe estar entre 0 y 100.'
                ], 422);
            }

            Configuraciones::updateOrCreate(

                ['idEmpresa' => $user->idEmpresa, 'tipo' => 'impuestos'],
                [
                    'nombre' => 'igv',
                    'clave'  => $igvValue
                ]
            );
            return response()->json([
                'success' => true,
                'message' => 'Porcentaje de IGV actualizado correctamente.',
                'data'    => ['igv' => $igvValue]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error actualizando IGV: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error interno al guardar la configuración.'
            ], 500);
        }
    }

    public function getConfigDeliveryEmpresa()
    {
        try {
            $idEmpresa = 2;

            $configuracion = ConfiguracionDelivery::where('idEmpresa', $idEmpresa)->first();

            if (!$configuracion) {
                return response()->json(['success' => false, 'message' => 'No hay configuración para esta empresa'], 404);
            }

            return response()->json(['success' => true, 'data' => $configuracion], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
