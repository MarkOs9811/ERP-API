<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\Permiso;
use App\Models\Persona;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UsuarioController extends Controller
{

    public function showUser()
    {
        try {
            $usuarios = User::with('empleado.persona',  'empleado.cargo', 'empleado.area', 'empleado.horario', 'sede')->orderBy('id', 'desc')->get();
            return response()->json(['success' => true, 'data' => $usuarios], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener los usuarios: ' . $e->getMessage()], 500);
        }
    }

    public function guardarUsuario(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombres' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'cargo' => 'required|exists:cargos,id',
            'correo' => 'required|email|unique:users,correo',
            'area' => 'required|exists:areas,id',
            'tipo_documento' => 'required|string',
            'tipoAuth' => 'required|string',
            'numero_documento' => [
                'required',
                'string',
                'max:20',
                'unique:personas,documento_identidad',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->tipo_documento === 'DNI' && !preg_match('/^\d{8}$/', $value)) {
                        return $fail('El número de documento debe tener 8 dígitos para DNI.');
                    }
                    if ($request->tipo_documento === 'Carnet De Extranjeria' && !preg_match('/^\d{10}$/', $value)) {
                        return $fail('El número de documento debe tener 10 dígitos para Carnet de Extranjeria.');
                    }
                },
            ],
            'salario' => 'required|numeric',
            'horario' => 'required',
            'fotoPerfil' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $path = $request->hasFile('fotoPerfil')
                ? $request->file('fotoPerfil')->store('fotos', 'public')
                : null;

            // Crear persona
            $persona = new Persona();
            $persona->nombre = $request->nombres;
            $persona->apellidos = $request->apellidos;
            $persona->tipo_documento = $request->tipo_documento;
            $persona->correo = $request->correo;
            $persona->documento_identidad = $request->numero_documento;
            $persona->save();

            // Crear empleado
            $empleado = new Empleado();
            $empleado->idpersona = $persona->id;
            $empleado->idArea = $request->area;
            $empleado->idCargo = $request->cargo;
            $empleado->salario = $request->salario;
            $empleado->idHorario = $request->horario;
            $empleado->save();

            // Roles
            $rolesSeleccionados = DB::table('cargo_roles')
                ->where('idCargo', $request->cargo)
                ->pluck('idRole')
                ->toArray();

            $rolVenderId = Role::where('nombre', 'vender')->value('id');
            $cargo = Cargo::find($request->cargo);

            if ($rolVenderId && in_array($rolVenderId, $rolesSeleccionados) && $cargo->nombre !== 'atención al cliente') {
                $rolesSeleccionados = array_diff($rolesSeleccionados, [$rolVenderId]);
            }

            // Crear usuario
            $user = new User();
            $user->idEmpleado = $empleado->id;
            $user->email = $request->correo;
            $user->correo = $request->correo;
            $user->password = Hash::make('123');
            $user->auth_type = $request->tipoAuth;
            $user->fotoPerfil = $path;
            $user->save();

            $user->roles()->attach($rolesSeleccionados);

            // Asignar permisos
            $permisos = Permiso::whereIn('nombre', ['crear', 'ver', 'eliminar', 'actualizar'])->pluck('id');
            $userRoleIds = DB::table('role_users')
                ->whereIn('idRole', $rolesSeleccionados)
                ->where('idUsuarios', $user->id)
                ->pluck('id');

            foreach ($userRoleIds as $userRoleId) {
                foreach ($permisos as $permisoId) {
                    DB::table('user_rol_permisos')->insert([
                        'idRolUser' => $userRoleId,
                        'idPermiso' => $permisoId,
                    ]);
                }
            }

            DB::commit();
            return response()->json(['success' => 'Usuario creado correctamente con permisos asignados.']);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            if ($e->errorInfo[1] == 1062) {
                if (strpos($e->getMessage(), 'correo') !== false) {
                    return response()->json(['error' => 'Ya existe un usuario con el correo: ' . $request->correo], 409);
                }
                if (strpos($e->getMessage(), 'documento_identidad') !== false) {
                    return response()->json(['error' => 'Ya existe un usuario con el documento: ' . $request->numero_documento], 409);
                }
            }

            Log::error('Error SQL al crear usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Error de base de datos al crear el usuario.'], 500);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error general al crear usuario: ' . $e->getMessage());
            return response()->json(['error' => 'Error inesperado al crear el usuario.'], 500);
        }
    }


    public function getUsuarioById($id)
    {
        try {
            $usuario = User::with('empleado.persona')->where('id', $id)->first();
            return response()->json($usuario, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener el usuario', 'message' => $e->getMessage()], 500);
        }
    }

    public function updateUsuario(Request $request, $id)
    {
        Log::info('Iniciando actualización del usuario con ID: ' . $request);
        $user = User::where('id', $id)->first();
        $idPersona = $user->empleado->persona->id;
        // Validación de datos
        $validatedData = $request->validate([
            'tipo_documento' => 'required|string',
            'numero_documento' => 'required|numeric|unique:personas,documento_identidad,' . $idPersona,
            'nombres' => 'required|string',
            'apellidos' => 'required|string',
            'correo_electronico' => 'required|email|unique:users,correo,' . $id,
            'area' => 'required|exists:areas,id',
            'sede' => 'required|exists:sedes,id',
            'cargo' => 'required|exists:cargos,id',
            'salario' => 'nullable|numeric',
            'horario' => 'required|exists:horarios,id',
        ]);

        try {
            // Verificar si el DNI ya está registrado
            $dniExistente = Persona::where('documento_identidad', $validatedData['numero_documento'])
                ->where('id', '!=', $idPersona) // Asegurarse de que no sea el propio usuario
                ->exists();

            if ($dniExistente) {
                return response()->json(['success' => false, 'message' => 'El DNI ya está registrado para otro usuario.'], 400);
            }

            // Verificar si el correo ya está registrado
            $correoExistente = User::where('correo', $validatedData['correo_electronico'])
                ->where('id', '!=', $id) // Asegurarse de que no sea el propio usuario
                ->exists();

            if ($correoExistente) {
                return response()->json(['success' => false, 'message' => 'El correo electrónico ya está registrado para otro usuario.'], 400);
            }

            // Actualizar los datos del usuario
            $usuario = User::findOrFail($id);
            $usuario->update([
                'correo' => $validatedData['correo_electronico'],
                'idSede' => $validatedData['sede'],
            ]);

            // Actualizar los datos del empleado
            $empleado = $usuario->empleado;
            $empleado->update([
                'idArea' => $validatedData['area'],
                'idCargo' => $validatedData['cargo'],
                'salario' => $validatedData['salario'],
                'idHorario' => $validatedData['horario'],
            ]);

            $persona = $usuario->empleado->persona;
            $persona->update([
                'tipo_documento' => $validatedData['tipo_documento'],
                'documento_identidad' => $validatedData['numero_documento'],
                'nombre' => $validatedData['nombres'],
                'apellidos' => $validatedData['apellidos'],
                'correo' => $validatedData['correo_electronico'],
            ]);

            return response()->json(['success' => true, 'message' => 'Usuario actualizado correctamente.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Hubo un error al actualizar el usuario.'], 500);
        }
    }

    public function eliminarUsuario($id)
    {
        // Busca el usuario por su ID
        $usuario = User::find($id);

        // Verifica si el usuario existe
        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado']);
        }

        // Cambia el estado del usuario de 1 a 0
        $usuario->estado = 0; // Cambia el estado
        $usuario->save(); // Guarda los cambios

        return response()->json(['success' => true, 'message' => 'Usuario eliminado correctamente']);
    }

    public function activarUsuario($id)
    {
        // Busca el usuario por su ID
        $usuario = User::find($id);

        // Verifica si el usuario existe
        if (!$usuario) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado']);
        }

        // Cambia el estado del usuario de 1 a 0
        $usuario->estado = 1; // Cambia el estado
        $usuario->save(); // Guarda los cambios

        return response()->json(['success' => true, 'message' => 'Usuario eliminado correctamente']);
    }

    public function estadisticas()
    {
        return response()->json([
            'totalUsuarios' => User::count(),
            'usuariosActivos' => User::where('estado', 1)->count(),
            'usuariosAlmacen' => Empleado::whereHas('cargo', function ($query) {
                $query->where('nombre', 'almacen');
            })->count(),
        ]);
    }
}
