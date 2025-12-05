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
            Log::info('Intento de login recibido', ['email' => $request->email]);

            $credentials = $request->validate([
                'email' => 'required',
                'password' => 'required',
            ]);

            // 1. Buscar usuario
            $user = User::where('email', $credentials['email'])->first();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }

            // 2. Validar tipo de autenticación
            if ($user->auth_type !== 'manual') {
                return response()->json(['success' => false, 'message' => 'Este usuario debe iniciar sesión con Google'], 403);
            }

            // 3. Validar contraseña
            if (!Auth::attempt($credentials)) {
                return response()->json(['success' => false, 'message' => 'Credenciales inválidas'], 401);
            }

            // 4. Cargar usuario con relaciones
            $user = User::with('empleado.persona', 'empleado.cargo', 'roles')->find(Auth::id());

            // 5. Lógica de Empresa y Roles Efectivos
            $empresa = null;
            $rolesEfectivos = collect([]);

            if ($user->idEmpresa) {
                $empresa = MiEmpresa::find($user->idEmpresa);
                $confiEmpresa = Configuraciones::where('idEmpresa', $user->idEmpresa)
                    ->where('tipo', 'estilos')
                    ->get();
                if (!$empresa) {
                    return response()->json(['success' => false, 'message' => 'Empresa no válida o desactivada'], 403);
                }

                if ($empresa->estado == 0) {
                    return response()->json(['success' => false, 'message' => 'Su empresa se encuentra inactiva. Contacte soporte.'], 403);
                }

                // A. Obtener IDs de roles que la empresa REALMENTE tiene activos
                $rolesEmpresaIds = DB::table('empresa_roles')
                    ->where('idEmpresa', $empresa->id)
                    ->where('estado', 1)
                    ->pluck('idRole')
                    ->toArray();

                // B. FILTRADO MÁGICO: Cruzar roles del usuario con los de la empresa
                $rolesEfectivos = $user->roles->filter(function ($role) use ($rolesEmpresaIds) {
                    return in_array($role->id, $rolesEmpresaIds);
                })->values(); // values() reordena los índices del array

            } else {
                if ($user->isAdmin == 1) {
                    $rolesEfectivos = $user->roles;
                }
            }

            // 6. SOBRESCRIBIR LA RELACIÓN EN EL OBJETO USER
            // Esto asegura que si el frontend lee user.roles, vea los filtrados.
            $user->setRelation('roles', $rolesEfectivos);


            $token = $user->createToken('accessToken')->plainTextToken;
            $caja = $user->cajaAbierta();

            Log::info('Login exitoso', ['id' => $user->id, 'empresa' => $empresa?->id]);

            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'user' => $user, // Ahora este user lleva los roles limpios dentro
                'roles' => $rolesEfectivos,
                'token' => $token,
                'caja' => $caja,
                'empresa' => $empresa,
                'estiloEmpresa' => $confiEmpresa,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error en login', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
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
