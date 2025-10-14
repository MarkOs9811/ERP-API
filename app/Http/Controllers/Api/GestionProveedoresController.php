<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GestionProveedoresController extends Controller
{
    public function getProveedores()
    {
        try {
            $proveedor = Proveedore::orderBy('id', 'Desc')->get();
            return response()->json(['success' => true, 'data' => $proveedor], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function addProveedores(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'contacto' => 'required|string|max:255',
            'direccion' => 'required|string|max:255',
            'tipo_documento' => 'required|string',
            'numero_documento' => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->tipo_documento === 'RUC' && !preg_match('/^\d{11}$/', $value)) {
                        return $fail('El número de documento debe tener 11 dígitos para RUC.');
                    }
                    if ($request->tipo_documento === 'DNI' && !preg_match('/^\d{8}$/', $value)) {
                        return $fail('El número de documento debe tener 8 dígitos para DNI.');
                    }
                },
                'unique:proveedores,numero_documento',
            ],
            'telefono' => 'required|string|max:9|unique:proveedores,telefono',
            'correo' => 'required|string|email|max:255|unique:proveedores,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            Log::info('Validación fallida al crear proveedor: ' . json_encode($validator->errors()));
        }

        try {
            DB::beginTransaction();

            // Crear proveedor
            Proveedore::create([
                'nombre' => $request->nombre,
                'contacto' => $request->contacto,
                'direccion' => $request->direccion,
                'tipo_documento' => $request->tipo_documento,
                'numero_documento' => $request->numero_documento,
                'telefono' => $request->telefono,
                'email' => $request->correo,
            ]);


            DB::commit();

            Log::info('Proveedor creado correctamente.');

            return response()->json(['success' => true, 'message' => 'Proveedor creado correctamente.'], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            if ($e->errorInfo[1] == 1062) {
                // Error de clave duplicada
                return response()->json(['success' => false, 'message' => 'Ya existe un proveedor con el número de documento: ' . $request->numero_documento], 409);
            }

            Log::error('Error al crear proveedor: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'Ha ocurrido un error al crear el proveedor.'], 500);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al crear proveedor: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'Ha ocurrido un error al crear el proveedor.'], 500);
        }
    }

    public function updateProveedores($id, Request $request)
    {
        try {
            $proveedor = Proveedore::find($id);

            if (!$proveedor) {
                return response()->json(['success' => false, "errors" => "Proveedor no encontrado"], 422);
            }

            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:255',
                'contacto' => 'required|string|max:255',
                'direccion' => 'required|string|max:255',
                'tipo_documento' => 'required|string',
                'numero_documento' => [
                    'required',
                    'string',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($request->tipo_documento === 'RUC' && !preg_match('/^\d{11}$/', $value)) {
                            return $fail('El número de documento debe tener 11 dígitos para RUC.');
                        }
                        if ($request->tipo_documento === 'DNI' && !preg_match('/^\d{8}$/', $value)) {
                            return $fail('El número de documento debe tener 8 dígitos para DNI.');
                        }
                    },
                    'unique:proveedores,numero_documento,' . $proveedor->id, // Ignora el proveedor actual en la validación
                ],
                'telefono' => 'required|string|max:9|unique:proveedores,telefono,' . $proveedor->id, // Ignora el proveedor actual en la validación
                'correo' => 'required|string|email|max:255|unique:proveedores,email,' . $proveedor->id, // Ignora el proveedor actual en la validación
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            $proveedor->nombre = $request->nombre;
            $proveedor->contacto = $request->contacto;
            $proveedor->direccion = $request->direccion;
            $proveedor->tipo_documento = $request->tipo_documento;
            $proveedor->numero_documento = $request->numero_documento;
            $proveedor->telefono = $request->telefono;
            $proveedor->email = $request->correo;
            $proveedor->save();

            return response()->json(['success' => true, 'message' => 'Registro Exitoso'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function deleteProveedor($id)
    {
        try {
            // Busca el categroiaPlato por su ID
            $proveedor = Proveedore::find($id);

            // Verifica si el proveedor existe
            if (!$proveedor) {
                return response()->json(['success' => false, 'message' => 'Proveedor no encontrado']);
            }

            // Cambia el estado del proveedor de 1 a 0
            $proveedor->estado = 0; // Cambia el estado
            $proveedor->save(); // Guarda los cambios

            return response()->json(['success' => true, 'message' => 'Proveedor eliminado correctamente'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Ocurrio un error' . $e->getMessage()], 500);
        }
    }

    public function activarProveedor($id)
    {
        try {
            $proveedor = Proveedore::find($id);
            if (!$proveedor) {
                return response()->json(['success' => false, 'message' => 'Proveedor no encontrado']);
            }
            $proveedor->estado = 1;
            $proveedor->save();
            return response()->json(['success' => true, 'message' => 'Proveedor activado'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Ocurrio un error' . $e->getMessage()], 500);
        }
    }
}
