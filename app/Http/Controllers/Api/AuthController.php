<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MiEmpresa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

            Log::info('Credenciales validadas', $credentials);

            $user = User::where('email', $credentials['email'])->first();

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



    public function logout(Request $request)
    {
        $request->user()->tokens->each(function ($token) {
            $token->delete();
        });

        return response()->json(['message' => 'Cierre de sesión exitoso.'], 200);
    }
}
