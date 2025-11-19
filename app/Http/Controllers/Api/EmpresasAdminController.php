<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MiEmpresa;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class EmpresasAdminController extends Controller
{
    public function getEmpresas()
    {
        try {
            $empresas = MiEmpresa::with('usuarios.empleado.persona', 'sedes', 'configuraciones')->get();
            return response()->json(['success' => true, 'data' => $empresas], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener las empresas', 'error' => $e->getMessage()], 500);
        }
    }


    public function storeEmpresa(Request $request)
    {
        try {
            // Validación (soporta multipart/form-data o JSON)
            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:255',
                'direccion' => 'nullable|string|max:255',
                'telefono' => 'nullable|string|max:20',
                'numero' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'correo' => 'nullable|email|max:255',
                'ruc' => 'nullable|string|max:50',
                'pagina' => 'nullable|url|max:255',
                'logo' => 'nullable|file|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Normalizar campos a los que usa el modelo
            $data = [
                'nombre' => $request->input('nombre'),
                'direccion' => $request->input('direccion'),
                'ruc' => $request->input('ruc'),
                'numero' => $request->input('numero') ?? $request->input('telefono'),
                'correo' => $request->input('correo') ?? $request->input('email'),
            ];

            // Si viene archivo lo guardamos en storage/app/public/miEmpresa (disk 'public')
            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                // usar Storage::disk('public')->putFile para mayor control
                $path = Storage::disk('public')->putFile('miEmpresa', $file); // devuelve 'miEmpresa/archivo.ext'
                if (!$path) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se pudo guardar el archivo de logo'
                    ], 500);
                }
                $data['logo'] = $path;
            }

            $empresa = MiEmpresa::create($data);

            return response()->json(['success' => true, 'data' => $empresa], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la empresa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Nuevo método: actualizar solo el logo de una empresa existente
    public function uploadLogo(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'logo' => 'required|file|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $empresa = MiEmpresa::find($id);
            if (!$empresa) {
                return response()->json(['success' => false, 'message' => 'Empresa no encontrada'], 404);
            }

            $file = $request->file('logo');
            $path = Storage::disk('public')->putFile('miEmpresa', $file);
            if (!$path) {
                return response()->json(['success' => false, 'message' => 'No se pudo guardar el logo'], 500);
            }

            // Eliminar logo anterior (si existe) para no dejar archivos huérfanos
            if ($empresa->logo) {
                Storage::disk('public')->delete($empresa->logo);
            }

            $empresa->logo = $path;
            $empresa->save();

            return response()->json(['success' => true, 'data' => $empresa], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al subir logo', 'error' => $e->getMessage()], 500);
        }
    }
}
