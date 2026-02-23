<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuraciones;
use App\Models\MiEmpresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            Log::info('--- INICIO PROCESO DE LOGIN ---', ['email' => $request->email]);

            $credentials = $request->validate([
                'email' => 'required',
                'password' => 'required',
            ]);
            Log::debug('Paso 1: Validación de request superada.');

            // 1. Buscar usuario
            $user = User::where('email', $credentials['email'])->first();

            if (!$user) {
                Log::warning('Fallo en Paso 2: Usuario no encontrado.', ['email' => $credentials['email']]);
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }
            Log::debug('Paso 2: Usuario encontrado en BD.', ['user_id' => $user->id, 'auth_type' => $user->auth_type]);

            // 2. Validar tipo de autenticación
            if ($user->auth_type !== 'manual') {
                Log::warning('Fallo en Paso 3: Tipo de autenticación incorrecto.', ['esperado' => 'manual', 'actual' => $user->auth_type]);
                return response()->json(['success' => false, 'message' => 'Este usuario debe iniciar sesión con Google'], 403);
            }

            // 3. Validar contraseña
            if (!Auth::attempt($credentials)) {
                Log::warning('Fallo en Paso 4: Contraseña incorrecta para el usuario.', ['email' => $credentials['email']]);
                return response()->json(['success' => false, 'message' => 'Credenciales inválidas'], 401);
            }
            Log::debug('Paso 4: Auth::attempt exitoso. Credenciales correctas.');

            // 4. Cargar usuario con relaciones
            $user = User::with('empleado.persona', 'empleado.cargo', 'roles', 'sede')->find(Auth::id());
            Log::debug('Paso 5: Relaciones cargadas correctamente.', ['roles_count' => $user->roles->count()]);

            // 5. Lógica de Empresa y Roles Efectivos
            $empresa = null;
            $rolesEfectivos = collect([]);
            $confiEmpresa = null; // Inicializamos aquí para evitar errores si cae en el 'else'

            if ($user->idEmpresa) {
                Log::debug('Paso 6: El usuario pertenece a una empresa.', ['idEmpresa' => $user->idEmpresa]);

                $empresa = MiEmpresa::find($user->idEmpresa);
                if (!$empresa) {
                    Log::warning('Fallo en Paso 6: idEmpresa no coincide con ninguna empresa válida.', ['idEmpresa' => $user->idEmpresa]);
                    return response()->json(['success' => false, 'message' => 'Empresa no válida o desactivada'], 403);
                }

                if ($empresa->estado == 0) {
                    Log::warning('Fallo en Paso 6: Empresa inactiva.', ['idEmpresa' => $empresa->id]);
                    return response()->json(['success' => false, 'message' => 'Su empresa se encuentra inactiva. Contacte soporte.'], 403);
                }
                Log::debug('Paso 7: Empresa validada correctamente.', ['empresa_nombre' => $empresa->nombre]);

                $confiEmpresa = Configuraciones::where('idEmpresa', $user->idEmpresa)
                    ->where('tipo', 'estilos')
                    ->get();
                Log::debug('Paso 8: Configuraciones de estilos cargadas.', ['estilos_count' => $confiEmpresa->count()]);

                // A. Obtener IDs de roles que la empresa REALMENTE tiene activos
                $rolesEmpresaIds = DB::table('empresa_roles')
                    ->where('idEmpresa', $empresa->id)
                    ->where('estado', 1)
                    ->pluck('idRole')
                    ->toArray();
                Log::debug('Paso 9: Roles activos de la empresa obtenidos.', ['rolesEmpresaIds' => $rolesEmpresaIds]);

                // B. FILTRADO MÁGICO: Cruzar roles del usuario con los de la empresa
                $rolesEfectivos = $user->roles->filter(function ($role) use ($rolesEmpresaIds) {
                    return in_array($role->id, $rolesEmpresaIds);
                })->values(); // values() reordena los índices del array
                Log::debug('Paso 10: Filtrado mágico de roles completado.', ['rolesEfectivos_count' => $rolesEfectivos->count()]);
            } else {
                Log::debug('Paso 6 (Alternativo): El usuario NO tiene idEmpresa.', ['isAdmin' => $user->isAdmin]);
                if ($user->isAdmin == 1) {
                    $rolesEfectivos = $user->roles;
                    Log::debug('Paso 7 (Alternativo): Roles asignados directamente por ser Admin.', ['rolesEfectivos_count' => $rolesEfectivos->count()]);
                }
            }

            // 6. SOBRESCRIBIR LA RELACIÓN EN EL OBJETO USER
            $user->setRelation('roles', $rolesEfectivos);
            Log::debug('Paso 11: Relación de roles sobrescrita en el objeto User.');

            $token = $user->createToken('accessToken')->plainTextToken;
            $caja = $user->cajaAbierta();
            Log::debug('Paso 12: Token generado y caja consultada.', ['tiene_caja' => $caja ? true : false]);

            Log::info('--- LOGIN EXITOSO FIN DEL PROCESO ---', ['id' => $user->id, 'empresa' => $empresa?->id]);

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'user' => $user, // Ahora este user lleva los roles limpios dentro
                'roles' => $rolesEfectivos,
                'token' => $token,
                'caja' => $caja,
                'empresa' => $empresa,
                'estiloEmpresa' => $confiEmpresa ?? [], // Protegido contra null
            ], 200);
        } catch (\Throwable $e) {
            // Log::error completo para capturar exactamente en qué línea explotó
            Log::error('!!! ERROR CRITICO EN LOGIN !!!', [
                'mensaje' => $e->getMessage(),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile()
            ]);
            return response()->json(['success' => false, 'message' => 'Ocurrió un error en el login'], 500);
        }
    }
    public function logout(Request $request)
    {
        $request->user()->tokens->each(function ($token) {
            $token->delete();
        });

        return response()->json(['message' => 'Cierre de sesión exitoso.'], 200);
    }



    public function loginSuperAdmin(Request $request)
    {
        try {
            Log::info('Intento de login recibido', ['email' => $request->email]);

            $credentials = $request->validate([
                'email' => 'required',
                'password' => 'required',
            ]);

            Log::info('Credenciales validadas', $credentials);

            $user = User::where('isAdmin', 1)->where('email', $credentials['email'])->first();

            if (!$user) {
                Log::warning('Usuario no encontrado', ['email' => $credentials['email']]);
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado',
                ], 404);
            }

            Log::info('Usuario encontrado', ['id' => $user->id, 'auth_type' => $user->auth_type]);

            if ($user->auth_type !== 'manual') {
                Log::warning('Intento de login con método incorrecto', [
                    'id' => $user->id,
                    'esperado' => 'manual',
                    'recibido' => $user->auth_type
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Este usuario debe iniciar sesión con Google',
                ], 403);
            }

            if (!Auth::attempt($credentials)) {
                Log::warning('Credenciales inválidas', ['email' => $credentials['email']]);
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales inválidas',
                ], 401);
            }

            Log::info('Auth::attempt correcto', ['id' => Auth::id()]);

            $user = User::with('empleado.persona', 'empleado.cargo', 'sede')->find(Auth::id());

            /** @var \App\Models\User $user */
            $token = $user->createToken('accessToken')->plainTextToken;
            $empresa = MiEmpresa::first();
            $caja = $user->cajaAbierta(); // puede ser null, y está bien

            Log::info('Login exitoso', [
                'id' => $user->id,
                'caja_id' => $caja?->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'user' => $user,
                'roles' => $user->roles,
                'token' => $token,
                'caja' => $caja, // null o caja abierta
                'empresa' => $empresa,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error en login', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error en el login',
            ], 500);
        }
    }
}
