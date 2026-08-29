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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;


class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            // RASTREADOR 1: Inicio
            Log::info('--- 1. INICIO DE PETICION LOGIN ---', ['email' => $request->email]);

            $credentials = $request->validate([
                'email' => 'required',
                'password' => 'required',
            ]);

            $throttleKey = Str::lower($request->email) . '|' . $request->ip();

            if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
                $seconds = RateLimiter::availableIn($throttleKey);
                Log::warning('--- ALERTA: Bloqueo por intentos ---', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => "Demasiados intentos fallidos. Tu cuenta ha sido bloqueada temporalmente. Intenta de nuevo en {$seconds} segundos."
                ], 429);
            }

            $user = User::where('email', $credentials['email'])->first();

            if (!$user) {
                Log::info('--- 2. FALLO: Usuario no encontrado ---');
                RateLimiter::hit($throttleKey, 60);
                return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
            }
            
            Log::info('--- 2. EXITO: Usuario encontrado ---', ['id' => $user->id]);

            if ($user->auth_type !== 'manual') {
                Log::info('--- 3. FALLO: auth_type no es manual ---', ['auth_type' => $user->auth_type]);
                RateLimiter::hit($throttleKey, 60);
                return response()->json(['success' => false, 'message' => 'Este usuario debe iniciar sesión con Google'], 403);
            }

            Log::info('--- 4. Comprobando Hash de la contraseña... ---');
            
            if (!Hash::check($credentials['password'], $user->password)) {
                Log::info('--- 4. FALLO: La contraseña NO coincide con el Hash de la DB ---');
                RateLimiter::hit($throttleKey, 60);
                $intentosRestantes = RateLimiter::retriesLeft($throttleKey, 3);
                return response()->json([
                    'success' => false,
                    'message' => "Credenciales inválidas. Te quedan {$intentosRestantes} intento(s)."
                ], 401);
            }
            
            Log::info('--- 4. EXITO: Hash::check aprobo la contraseña ---');
            
            RateLimiter::clear($throttleKey);

            $user->load('empleado.persona', 'empleado.cargo', 'roles', 'sede');
            Log::info('--- 5. Relaciones del usuario cargadas ---');

            $empresa = null;
            $rolesEfectivos = collect([]);
            $confiEmpresa = null;

            if ($user->idEmpresa) {
                $empresa = MiEmpresa::find($user->idEmpresa);
                if (!$empresa) {
                    Log::info('--- 6. FALLO: Empresa no encontrada ---');
                    return response()->json(['success' => false, 'message' => 'Empresa no válida o desactivada'], 403);
                }

                if ($empresa->estado == 0) {
                    Log::info('--- 6. FALLO: Empresa inactiva ---');
                    return response()->json(['success' => false, 'message' => 'Su empresa se encuentra inactiva. Contacte soporte.'], 403);
                }

                $confiEmpresa = Configuraciones::where('idEmpresa', $user->idEmpresa)
                    ->where('tipo', 'estilos')
                    ->get();

                $rolesEmpresaIds = DB::table('empresa_roles')
                    ->where('idEmpresa', $empresa->id)
                    ->where('estado', 1)
                    ->pluck('idRole')
                    ->toArray();

                $rolesEfectivos = $user->roles->filter(function ($role) use ($rolesEmpresaIds) {
                    return in_array($role->id, $rolesEmpresaIds);
                })->values();
                Log::info('--- 6. EXITO: Empresa validada y roles cruzados ---');
            } else {
                if ($user->isAdmin == 1) {
                    $rolesEfectivos = $user->roles;
                    Log::info('--- 6. EXITO: Es SuperAdmin (isAdmin = 1) ---');
                }
            }

            $user->setRelation('roles', $rolesEfectivos);

            Log::info('--- 7. Generando Token... ---');
            $token = $user->createToken('accessToken')->plainTextToken;
            $caja = $user->cajaAbierta();

            Log::info('--- 8. LOGIN COMPLETADO. Enviando 200 OK ---');
            return response()->json([
                'success' => true,
                'message' => 'Login exitoso',
                'user' => $user,
                'roles' => $rolesEfectivos,
                'token' => $token,
                'caja' => $caja,
                'empresa' => $empresa,
                'estiloEmpresa' => $confiEmpresa ?? [],
            ], 200);
            
        } catch (\Throwable $e) {
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
