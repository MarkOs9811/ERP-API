<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MiPerfilController extends Controller
{
    public function actualizarPerfil(Request $request)
    {
        try {
            // CORRECCIÓN 1: Obtenemos el ID y buscamos el MODELO explícitamente
            $userId = Auth::id();
            $user = User::find($userId);

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado o no autenticado'], 401);
            }

            // 2. Validación
            $validator = Validator::make($request->all(), [
                'nombre'              => 'required|string|max:255',
                'apellidos'           => 'required|string|max:255',
                'telefono'            => 'nullable|string|max:11',
                'direccion'           => 'nullable|string|max:255',
                'fecha_nacimiento'    => 'nullable|date',
                'tipo_documento'      => 'nullable|string|max:50',
                'documento_identidad' => 'nullable|string|max:15',
                'foto'                => 'nullable|image|mimes:jpeg,png,jpg|max:3072'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors'  => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // 3. Actualizar datos de Persona
            // Al usar User::find(), $user ya es un objeto Eloquent completo
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

            // 4. Manejo de la Foto
            if ($request->hasFile('foto')) {

                // Borrar anterior
                if ($user->fotoPerfil) {
                    $rutaAnterior = 'public/' . $user->fotoPerfil;
                    if (Storage::exists($rutaAnterior)) {
                        Storage::delete($rutaAnterior);
                    }
                }

                $file = $request->file('foto');
                $filename = time() . '_' . preg_replace('/\s+/', '_', $file->getClientOriginalName());

                // Guardar nuevo
                $file->storeAs('public/fotos', $filename);

                // Asignar al modelo
                $user->fotoPerfil = 'fotos/' . $filename;

                // AHORA SI: save() funcionará porque $user es una instancia de User::class
                $user->save();
            }

            DB::commit();

            // AHORA SI: load() funcionará correctamente
            return response()->json([
                'success' => true,
                'message' => 'Perfil actualizado correctamente',
                'data'    => $user->load('empleado.persona')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar perfil: ' . $e->getMessage()
            ], 500);
        }
    }
}
