<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log; // AÑADIDO PARA LOS LOGS

class MiPerfilController extends Controller
{
    public function actualizarPerfil(Request $request)
    {
        try {
            $userId = Auth::id();
            $user = User::find($userId);

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado o no autenticado'], 401);
            }


            // 1. Validación
            $validator = Validator::make($request->all(), [
                'nombre'              => 'required|string|max:255',
                'apellidos'           => 'required|string|max:255',
                'telefono'            => 'nullable|string|max:11',
                'direccion'           => 'nullable|string|max:255',
                'fecha_nacimiento'    => 'nullable|date',
                'tipo_documento'      => 'nullable|string|max:50',
                'documento_identidad' => 'nullable|string|max:15',
                'foto'                => 'nullable|image|mimes:jpeg,png,jpg,webp|max:3072'
            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors'  => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // 2. Actualizar datos de Persona
            $empleado = $user->empleado;

            if ($empleado && $empleado->persona) {
                $empleado->persona->update([
                    'nombre'              => $request->nombre,
                    'apellidos'           => $request->apellidos,
                    'fecha_nacimiento'    => $request->fecha_nacimiento,
                    'tipo_documento'      => $request->tipo_documento,
                    'documento_identidad' => $request->documento_identidad,
                    'telefono'            => $request->telefono,
                    'direccion'           => $request->direccion,
                ]);
            }

            // 3. Manejo de la Foto con S3 / Cloudflare R2
            if ($request->hasFile('foto')) {


                // Borrar foto anterior del bucket si existe
                if ($user->fotoPerfil) {
                    $existeEnS3 = Storage::disk('s3')->exists($user->fotoPerfil);


                    if ($existeEnS3) {
                        Storage::disk('s3')->delete($user->fotoPerfil);
                    }
                }

                // Intentar guardar la nueva foto
                try {
                    $rutaFoto = $request->file('foto')->store('perfiles', 's3');


                    $user->fotoPerfil = $rutaFoto;
                    $user->save();
                } catch (\Exception $eStorage) {

                    throw $eStorage; // Lanzar de nuevo para que haga Rollback
                }
            }

            DB::commit();
            Log::info("Perfil del usuario {$userId} actualizado con éxito.");

            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado correctamente',
                'data'    => $user->load('empleado.persona')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error crítico al actualizar perfil: " . $e->getMessage(), [
                'linea' => $e->getLine(),
                'archivo' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar perfil: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cambiarPassword(Request $request)
    {
        $user = Auth::user();

        // Validación de la contraseña actual y la nueva
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed', // confirmed espera un campo new_password_confirmation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors()
            ], 422);
        }

        // Verificar que la contraseña actual sea correcta
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta'
            ], 422);
        }

        // Actualizar la contraseña
        $user->password = Hash::make($request->new_password);
        $user->save();

        Log::info("Usuario {$user->id} cambió su contraseña con éxito.");

        return response()->json([
            'success' => true,
            'message' => 'Contraseña cambiada correctamente'
        ], 200);
    }
}
