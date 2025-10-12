<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuraciones;
use App\Models\MiEmpresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PSpell\Config;

use function Laravel\Prompts\error;

class ConfiguracionController extends Controller
{
    public function actualizarConfiguracion(Request $request)
    {
        try {
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
            $empresa = MiEmpresa::first();

            if ($empresa) {
                // Verificar si se ha cargado un nuevo logo
                if ($request->hasFile('logo')) {
                    // Eliminar el logo anterior si existe
                    if ($empresa->logo) {
                        Storage::disk('public')->delete($empresa->logo);
                    }
                    // Guardar el nuevo logo en la carpeta 'miEmpresa' dentro del almacenamiento público
                    $logoPath = $request->file('logo')->store('miEmpresa', 'public');
                } else {
                    // Mantener el logo anterior si no se ha cargado uno nuevo
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
            $empresa = MiEmpresa::first();
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
                'success' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getConfiguracion()
    {
        try {
            $configuracion = Configuraciones::all();
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
}
