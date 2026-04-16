<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuraciones;
use App\Models\MiEmpresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;


class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required',
                'password' => 'required',
            ]);
            // NUEVO: 1. Crear una llave única basada en el email y la IP del usuario
            $throttleKey = Str::lower($request->email) . '|' . $request->ip();

            // NUEVO: 2. Verificar si ya excedió los 3 intentos permitidos
            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning('Bloqueo temporal por múltiples intentos fallidos.', ['email' => $request->email]);

                return response()->json([
                    'success' => false,
                    'message' => "Demasiados intentos fallidos. Tu cuenta ha sido bloqueada temporalmente. Intenta de nuevo en {$seconds} segundos."
                ], 429); // Código HTTP 429: Too Many Requests
            }

            // 1. Buscar usuario
            $user = User::where('email', $credentials['email'])->first();

            if (!$user) {
                // NUEVO: Sumar un intento fallido si el correo no existe
                RateLimiter::hit($throttleKey, 60); // 60 segundos de bloqueo cuando llegue a 3


                return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }


            // 2. Validar tipo de autenticación
            if ($user->auth_type !== 'manual') {
                RateLimiter::hit($throttleKey, 60); // Sumar intento
                return response()->json(['success' => false, 'message' => 'Este usuario debe iniciar sesión con Google'], 403);
            }

            // 3. Validar contraseña
            if (!Auth::attempt($credentials)) {
                // NUEVO: Sumar un intento fallido si la contraseña es incorrecta
                RateLimiter::hit($throttleKey, 60);
                $intentosRestantes = RateLimiter::retriesLeft($throttleKey, 3);

                return response()->json([
                    'success' => false,
                    'message' => "Credenciales inválidas. Te quedan {$intentosRestantes} intento(s)."
                ], 401);
            }
            // NUEVO: 3. Limpiar el contador si el inicio de sesión es exitoso
            RateLimiter::clear($throttleKey);


            // 4. Cargar usuario con relaciones
            $user = User::with('empleado.persona', 'empleado.cargo', 'roles', 'sede')->find(Auth::id());

            // 5. Lógica de Empresa y Roles Efectivos
            $empresa = null;
            $rolesEfectivos = collect([]);
            $confiEmpresa = null; // Inicializamos aquí para evitar errores si cae en el 'else'

            if ($user->idEmpresa) {

                $empresa = MiEmpresa::find($user->idEmpresa);
                if (!$empresa) {
                    return response()->json(['success' => false, 'message' => 'Empresa no válida o desactivada'], 403);
                }

                if ($empresa->estado == 0) {
                    return response()->json(['success' => false, 'message' => 'Su empresa se encuentra inactiva. Contacte soporte.'], 403);
                }

                $confiEmpresa = Configuraciones::where('idEmpresa', $user->idEmpresa)
                    ->where('tipo', 'estilos')
                    ->get();

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
            $user->setRelation('roles', $rolesEfectivos);

            $token = $user->createToken('accessToken')->plainTextToken;
            $caja = $user->cajaAbierta();


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
