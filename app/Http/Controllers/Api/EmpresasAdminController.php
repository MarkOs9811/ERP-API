<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\Horario;
use App\Models\MiEmpresa;
use App\Models\Persona;
use App\Models\User;
use Exception;
use Google\Service\ServiceControl\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmpresasAdminController extends Controller
{
    public function getEmpresas()
    {
        try {
            $empresas = MiEmpresa::with('usuarios.empleado.persona', 'sedes', 'configuraciones', 'roles')->get();
            return response()->json(['success' => true, 'data' => $empresas], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener las empresas', 'error' => $e->getMessage()], 500);
        }
    }
    public function getEmpresasId($id)
    {
        try {
            $empresas = MiEmpresa::with('usuarios.empleado.persona', 'sedes', 'configuraciones', 'roles')->where('id', $id)->first();
            return response()->json(['success' => true, 'data' => $empresas], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener las empresas', 'error' => $e->getMessage()], 500);
        }
    }


    public function storeEmpresa(Request $request)
    {
        // Iniciamos transacción para asegurar integridad (Todo o nada)
        DB::beginTransaction();

        try {
            // 1. Validación
            $validator = Validator::make($request->all(), [
                'nombre'    => 'required|string|max:255',
                'ruc'       => 'nullable|string|max:50',
                'direccion' => 'nullable|string|max:255',
                'telefono'  => 'nullable|string|max:20',
                // CAMBIO IMPORTANTE AQUI:
                'email'     => 'required|email|max:255|unique:users,email|unique:personas,correo',
                'logo'      => 'nullable|image|mimes:jpeg,png,jpg,svg|max:4096',
            ], [
                // Mensajes personalizados para el error de duplicidad
                'email.unique' => 'El correo electrónico ya está registrado en el sistema.',
                'email.required' => 'El correo electrónico es obligatorio para el administrador.',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }

            // 2. Datos básicos para la empresa
            $dataEmpresa = [
                'nombre'    => $request->nombre,
                'ruc'       => $request->ruc,
                'direccion' => $request->direccion,
                'numero'    => $request->telefono,
                'correo'    => $request->email,
                // 'userAdmin' se actualizará al final
            ];

            // Guardar Logo
            if ($request->hasFile('logo')) {
                $dataEmpresa['logo'] = $request->file('logo')->store('miEmpresa', 'public');
            }

            // 3. Crear Registro de Empresa
            $empresa = MiEmpresa::create($dataEmpresa);

            // 4. Crear dependencias básicas (Área, Horario, Cargo)
            $area = Area::create([
                'nombre' => 'Administración',
                'idEmpresa' => $empresa->id
            ]);

            $horario = Horario::create([
                'nombre' => 'Horario General',
                'horaEntrada' => '08:00:00',
                'horaSalida' => '17:00:00',
                'idEmpresa' => $empresa->id
            ]);

            $cargo = Cargo::create([
                'nombre' => 'administrador',
                'salario' => 3000.00,
                'pagoPorHoras' => (3000 / 30 / 8),
                'estado' => 1,
                'idEmpresa' => $empresa->id
            ]);

            // 5. Crear Persona
            $partesNombre = explode(' ', $request->nombre, 2);
            $nombrePersona = $partesNombre[0];
            $apellidoPersona = isset($partesNombre[1]) ? $partesNombre[1] : 'Empresa';

            $persona = new Persona();
            $persona->nombre = $nombrePersona;
            $persona->apellidos = $apellidoPersona;
            $persona->tipo_documento = 'RUC';
            // OJO: Asegúrate que tu BD permita 'documento_identidad' NULL. 
            // Si es unique en la BD, varios NULL podrían dar error dependiendo del motor SQL.
            // Sugerencia: Usar el RUC de la empresa si está disponible.
            $persona->documento_identidad = null;
            $persona->correo = $request->email;

            if (in_array('idEmpresa', $persona->getFillable())) {
                $persona->idEmpresa = $empresa->id;
            }
            $persona->save();

            // 6. Crear Empleado
            $empleado = new Empleado();
            $empleado->idpersona = $persona->id;
            $empleado->idArea = $area->id;
            $empleado->idCargo = $cargo->id;
            $empleado->idHorario = $horario->id;
            $empleado->salario = 3000.00;

            if (in_array('idEmpresa', $empleado->getFillable())) {
                $empleado->idEmpresa = $empresa->id;
            }
            $empleado->save();

            // 7. Crear Usuario de Sistema
            $user = new User();
            $user->idEmpleado = $empleado->id;
            $user->idEmpresa = $empresa->id;
            $user->email = $request->email;
            $user->correo = $request->email;
            $user->password = Hash::make('123');
            $user->auth_type = 'manual';
            $user->isAdmin = 0;

            if (isset($dataEmpresa['logo'])) {
                $user->fotoPerfil = $dataEmpresa['logo'];
            }

            $user->save();

            // --- PASO FINAL: Actualizar el campo userAdmin en la empresa ---
            $empresa->userAdmin = $user->email;
            $empresa->save();

            // Finalizamos transacción
            DB::commit();

            return response()->json([
                'message' => 'Empresa y Usuario Administrador creados correctamente',
                'data' => $empresa,
                'admin_user' => $user->email
            ], 201);
        } catch (Exception $e) {
            DB::rollBack(); // Si algo falla, deshace todo

            Log::error('EXCEPTION EN STORE EMPRESA:', ['msg' => $e->getMessage()]);
            return response()->json(['message' => 'Error interno al crear empresa', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateEmpresa(Request $request, $id)
    {
        try {
            $empresa = MiEmpresa::findOrFail($id);

            // 1. Validación
            $validator = Validator::make($request->all(), [
                'nombre'    => 'required|string|max:255',
                'ruc'       => 'nullable|string|max:50',
                'direccion' => 'nullable|string|max:255',
                'telefono'  => 'nullable|string|max:20',
                'email'     => 'nullable|email|max:255',
                'logo'      => 'nullable|image|mimes:jpeg,png,jpg,svg|max:4096',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Error de validación', 'errors' => $validator->errors()], 422);
            }

            // 2. MAPEO MANUAL PARA UPDATE
            $data = [
                'nombre'    => $request->nombre,
                'ruc'       => $request->ruc,
                'direccion' => $request->direccion,
                'numero'    => $request->telefono, // Mapeo telefono -> numero
                'correo'    => $request->email,    // Mapeo email -> correo
            ];

            // 3. Lógica de reemplazo de imagen
            if ($request->hasFile('logo')) {
                if ($empresa->logo && Storage::disk('public')->exists($empresa->logo)) {
                    Storage::disk('public')->delete($empresa->logo);
                }
                $data['logo'] = $request->file('logo')->store('miEmpresa', 'public');
            }

            // 4. Actualizar
            $empresa->update($data);

            return response()->json(['message' => 'Empresa actualizada correctamente', 'data' => $empresa], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error interno', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateEmpresaModulos(Request $request, $id)
    {
        try {
            $empresa = MiEmpresa::findOrFail($id);
            $roleId = $request->input('role_id');
            $action = $request->input('action');

            $pivotData = [];
            if ($request->has('estado')) $pivotData['estado'] = $request->input('estado');
            if ($request->has('fecha_expiracion')) $pivotData['fecha_expiracion'] = $request->input('fecha_expiracion');

            // 1. Buscar Cargo Administrador
            $cargoAdmin = Cargo::where('idEmpresa', $id)
                ->where('nombre', 'Administrador')
                ->first();

            // 2. NUEVO: Buscar Usuario Administrador (Dueño de la empresa)
            // Usamos withoutGlobalScopes por si quien ejecuta esto es un SuperAdmin externo
            // Asumimos que $empresa->email es el campo de correo. Si es 'correo', cámbialo.
            $usuarioAdmin = User::withoutGlobalScopes()
                ->where('email', $empresa->userAdmin)
                ->first();

            switch ($action) {
                case 'attach':
                    // A. Asignar a la EMPRESA
                    if (!$empresa->roles()->where('idRole', $roleId)->exists()) {
                        $empresa->roles()->attach($roleId, ['estado' => 1]);
                    }

                    // B. Asignar al CARGO
                    if ($cargoAdmin) {
                        $cargoAdmin->roles()->syncWithoutDetaching([$roleId]);
                    }

                    // C. NUEVO: Asignar al USUARIO
                    if ($usuarioAdmin) {
                        $usuarioAdmin->roles()->syncWithoutDetaching([$roleId]);
                    }
                    break;

                case 'detach':
                    // A. Quitar de la EMPRESA
                    $empresa->roles()->detach($roleId);

                    // B. Quitar del CARGO
                    if ($cargoAdmin) {
                        $cargoAdmin->roles()->detach($roleId);
                    }

                    // C. NUEVO: Quitar del USUARIO
                    if ($usuarioAdmin) {
                        $usuarioAdmin->roles()->detach($roleId);
                    }
                    break;

                case 'update_pivot':
                    // Solo actualiza datos de la relación empresa-rol
                    $empresa->roles()->updateExistingPivot($roleId, $pivotData);
                    break;

                case 'sync_all':
                    $rolesIds = $request->input('roles_ids', []);

                    // A. Empresa
                    $empresa->roles()->syncWithoutDetaching($rolesIds);

                    // B. Cargo
                    if ($cargoAdmin) {
                        $cargoAdmin->roles()->syncWithoutDetaching($rolesIds);
                    }

                    // C. NUEVO: Usuario
                    if ($usuarioAdmin) {
                        $usuarioAdmin->roles()->syncWithoutDetaching($rolesIds);
                    }
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Módulos actualizados en Empresa, Cargo y Usuario Admin.',
                'data' => ['id' => $empresa->id]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function completeSetup()
    {
        // 1. Log de entrada
        Log::info('completeSetup: Inicio de petición recibida.');

        try {
            $user = auth()->user();

            // 2. Verificar usuario
            if (!$user) {
                Log::warning('completeSetup: Intento de acceso sin usuario autenticado.');
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }

            Log::info('completeSetup: Usuario identificado.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'idEmpresa_en_user' => $user->idEmpresa
            ]);

            $empresaId = $user->idEmpresa;

            // 3. Buscar empresa
            // Usamos findOrFail, si falla saltará al catch
            $empresa = MiEmpresa::findOrFail($empresaId);

            Log::info('completeSetup: Empresa encontrada en BD.', [
                'empresa_id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'setup_steps_anterior' => $empresa->setup_steps
            ]);

            // 4. Actualizar
            $empresa->setup_steps = 5;
            $empresa->save();

            Log::info('completeSetup: Guardado exitoso. setup_steps ahora es 5.');

            return response()->json([
                'success' => true,
                'message' => 'Configuración inicial de la empresa completada.',
                'data' => ['id' => $empresa->id]
            ], 200);
        } catch (\Exception $e) {
            // 5. Log de Error (Captura todo)
            Log::error('completeSetup: Ocurrió una excepción.', [
                'mensaje' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine(),
                'trace' => $e->getTraceAsString() // Opcional si quieres mucho detalle
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
