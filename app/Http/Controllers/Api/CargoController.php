<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CargoController extends Controller
{
    public function getCargos()
    {
        try {
            $areas = Cargo::with('empleados')->where('estado', 1)->get();
            return response()->json($areas, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener los Cargos', 'message' => $e->getMessage()], 500);
        }
    }
    
    public function getRolesAll()
    {
        try {
            Log::info('getRolesAll llamado');

            $roles = Role::with([
                'users',
                'cargos',
                'rolUsers.permisos' // accede a permisos a través del modelo RoleUser
            ])->get();




            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function getCargosAll()
    {
        try {
            $areas = Cargo::with(['roles', 'empleados'])->withCount(['empleados', 'roles'])->get();

            return response()->json(['success' => true, "data" => $areas], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Error al obtener los Cargos', 'message' => $e->getMessage()], 500);
        }
    }

    public function saveCargos(Request $request)
    {
        try {
            // Validación
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/',
                'salario' => 'required|numeric|min:0',
                'pagoPorHoras' => 'required|numeric|min:0',
                'rolerCar' => 'required|array',
                'rolerCar.*' => 'integer|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Guardar el cargo
            $cargo = new Cargo();
            $cargo->nombre = $request->nombre;
            $cargo->salario = $request->salario;
            $cargo->pagoPorHoras = $request->pagoPorHoras;
            $cargo->save();

            $cargo->roles()->sync($request->rolerCar);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en el servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateCargos(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'idCargo' => 'required|integer|exists:cargos,id',
                'nombre' => 'required|string|regex:/^[A-Za-zÁÉÍÓÚáéíóúÑñ\s]+$/|unique:cargos,nombre,' . $request->idCargo,
                'salario' => 'required|numeric|min:0',
                'pagoPorHoras' => 'required|numeric|min:0',
                'rolerCar' => 'required|array',
                'rolerCar.*' => 'integer|exists:roles,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar cargo existente
            $cargo = Cargo::findOrFail($request->idCargo);
            $cargo->nombre = $request->nombre;
            $cargo->salario = $request->salario;
            $cargo->pagoPorHoras = $request->pagoPorHoras;
            $cargo->save();

            // Sincronizar roles
            $cargo->roles()->sync($request->rolerCar);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en el servidor: ' . $e->getMessage()
            ], 500);
        }
    }
    public function getSalarioCargo($id)
    {
        $cargo = Cargo::find($id);

        if ($cargo) {
            return response()->json(['salario' => $cargo->salario]);
        } else {
            return response()->json(['error' => 'Cargo no encontrado'], 404);
        }
    }
}
