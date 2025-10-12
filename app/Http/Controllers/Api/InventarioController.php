<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InventarioController extends Controller
{
    public function getInventario()
    {
        try {
            $inventario = Inventario::with('categoria', 'unidad')->get();
            return response()->json(['success' => true, 'data' => $inventario, 'message' => 'Inventario obtenido'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function updateInventario(Request $request, $id)
    {
        try {
            // Buscar inventario
            $inventario = Inventario::findOrFail($id);

            // Validar datos
            $request->validate([
                'nombre'       => 'required|string|max:255',
                'marca'        => 'nullable|string',
                'descripcion'  => 'nullable|string',
                'precio'       => 'required|numeric',
                'stock'        => 'required|integer',
                'idCategoria'  => 'required|exists:categorias,id',
                'idUnidad'     => 'required|exists:unidad_medidas,id',
                'estado'       => 'required|in:0,1',
                'foto'         => 'nullable|image|max:2048', // máx 2MB
            ]);

            // Actualizar datos básicos
            $inventario->nombre       = $request->nombre;
            $inventario->descripcion  = $request->descripcion;
            $inventario->precio       = $request->precio;
            $inventario->stock        = $request->stock;
            $inventario->idCategoria  = $request->idCategoria;
            $inventario->idUnidad     = $request->idUnidad;
            $inventario->estado       = $request->estado;

            // Manejo de la foto
            if ($request->hasFile('foto')) {
                // Eliminar foto anterior si existe
                if ($inventario->foto && Storage::disk('public')->exists($inventario->foto)) {
                    Storage::disk('public')->delete($inventario->foto);
                }

                // Guardar nueva foto
                $path = $request->file('foto')->store('fotosInventario', 'public');
                $inventario->foto = $path; // ej: fotosInventario/nombre.jpg
            }

            $inventario->save();

            return response()->json([
                'success' => true,
                'message' => 'Inventario actualizado correctamente',
                'data'    => $inventario
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteProduInventario($id)
    {
        try {
            $inventario = Inventario::findOrFail($id);
            $inventario->delete();

            return response()->json(['success' => true, 'message' => 'Eliminado correctamente'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar el producto: ' . $e->getMessage()], 500);
        }
    }

    public function activarInventario($id)
    {
        try {
            $inventario = Inventario::findOrFail($id);
            $inventario->estado = 1;
            $inventario->save();
            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al activar el producto: ' . $e->getMessage()], 500);
        }
    }
    public function desactivarInventario($id)
    {
        try {
            $inventario = Inventario::findOrFail($id);
            $inventario->estado = 0;
            $inventario->save();
            return response()->json(['success' => true], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al desactivar el producto: ' . $e->getMessage()], 500);
        }
    }
}
