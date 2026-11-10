<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Almacen;
use App\Models\Inventario;
use Illuminate\Http\Request;
use App\Traits\EmpresaSedeValidation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Illuminate\Validation\ValidationException;

class InventarioController extends Controller
{
    use EmpresaSedeValidation;
    public function getInventario()
    {
        try {
            $inventario = Inventario::orderBy('id', 'desc')->with('categoria', 'unidad')->get();
            return response()->json(['success' => true, 'data' => $inventario, 'message' => 'Inventario obtenido'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function updateInventario(Request $request, $id)
    {
        // 1. Buscar inventario primero para tener el codigoProd a la mano
        $inventario = Inventario::findOrFail($id);
        Log::info("DATOS inventario", $request->all());

        try {
            // 2. Validar datos (incluyendo fecha de vencimiento y campos de farmacia)
            $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:100',
                    $this->uniqueEmpresaSede('inventarios', 'nombre', $id),
                ],
                'marca' => [
                    'nullable',
                    'string',
                    'max:100',
                    $this->uniqueEmpresaSede('inventarios', 'marca', $id),
                ],
                'descripcion'        => 'nullable|string|max:255',
                'presentacion'       => 'required|string|max:100',
                'laboratorio'        => 'required|string|max:100',
                'lote'               => 'required|string|max:100',
                'registro_sanitario' => 'required|string|max:100',
                'fecha_vencimiento'  => 'required|date|after:today', // <--- Validación añadida
                'precio'             => 'required|numeric|min:0',
                'stock'              => 'required|integer|min:0', // El stock de inventario SÍ se actualiza aquí
                'idCategoria'        => 'required|exists:categorias,id',
                'idUnidad'           => 'required|exists:unidad_medidas,id',
                'estado'             => 'required|in:0,1',
                'foto'               => 'nullable|image|max:2048', // máx 2MB
            ], [
                'fecha_vencimiento.after' => 'La fecha de vencimiento debe ser posterior a hoy'
            ]);

            DB::beginTransaction();

            // 3. Actualizar datos en la tabla `inventarios`
            $inventario->nombre             = $request->nombre;
            $inventario->descripcion        = $request->descripcion;
            $inventario->presentacion       = $request->presentacion;
            $inventario->marca              = $request->marca;
            $inventario->laboratorio        = $request->laboratorio;
            $inventario->lote               = $request->lote;
            $inventario->registro_sanitario = $request->registro_sanitario;
            $inventario->fecha_vencimiento  = $request->fecha_vencimiento; // <--- Asignado aquí
            $inventario->precio             = $request->precio;
            $inventario->stock              = $request->stock; // Stock de ventas/inventario
            $inventario->idCategoria        = $request->idCategoria;
            $inventario->idUnidad           = $request->idUnidad;
            $inventario->estado             = $request->estado;

            // Manejo de la foto en Inventario
            if ($request->hasFile('foto')) {
                if ($inventario->foto && Storage::disk('s3')->exists($inventario->foto)) {
                    Storage::disk('s3')->delete($inventario->foto);
                }
                $path = $request->file('foto')->store('fotosInventario', 's3');
                $inventario->foto = $path;
            }

            $inventario->save();

            // 4. SINCRONIZAR CON ALMACÉN (Buscando por codigoProd)
            $productoAlmacen = Almacen::where('codigoProd', $inventario->codigoProd)->first();

            if ($productoAlmacen) {
                $productoAlmacen->nombre             = $request->nombre;
                $productoAlmacen->descripcion        = $request->descripcion;
                $productoAlmacen->presentacion       = $request->presentacion;
                $productoAlmacen->marca              = $request->marca;
                $productoAlmacen->laboratorio        = $request->laboratorio;
                $productoAlmacen->lote               = $request->lote;
                $productoAlmacen->registro_sanitario = $request->registro_sanitario;
                $productoAlmacen->fecha_vencimiento  = $request->fecha_vencimiento; // <--- Sincronizado a almacén
                $productoAlmacen->precioUnit         = $request->precio;
                $productoAlmacen->idCategoria        = $request->idCategoria;
                $productoAlmacen->idUnidadMedida     = $request->idUnidad;
                $productoAlmacen->estado             = $request->estado;

                if ($request->hasFile('foto')) {
                    $productoAlmacen->foto = $inventario->foto;
                }

                // ⚠️ IMPORTANTE: NO TOCAMOS `$productoAlmacen->cantidad` (el stock de almacén queda intacto)

                $productoAlmacen->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inventario y Almacén sincronizados y actualizados correctamente',
                'data'    => $inventario
            ], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
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
