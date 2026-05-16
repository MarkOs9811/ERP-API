<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\campanaPromo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CampañasController extends Controller
{
    public function saveCampanasPromo(Request $request)
    {
        // 1. Validación de entrada
        $validatedData = $request->validate([

            'nombre'              => 'required|string|max:255',
            'tipo'                => 'required|in:cupon,puntos,recompensa',
            'fecha_inicio'        => 'required|date',
            'fecha_fin'           => 'required|date|after_or_equal:fecha_inicio',
            'estado'              => 'boolean',

            // Validaciones condicionales para cupones
            'codigo_cupon'        => 'required_if:tipo,cupon|nullable|string|unique:campana_promos,codigo_cupon',
            'tipo_descuento'      => 'required_if:tipo,cupon|nullable|in:porcentaje,monto_fijo',
            'valor_descuento'     => 'required_if:tipo,cupon|nullable|numeric|min:0',
            'monto_minimo_compra' => 'nullable|numeric|min:0',
            'limite_uso'          => 'nullable|integer|min:1',
        ]);

        try {
            // 2. Iniciamos una transacción por seguridad
            return DB::transaction(function () use ($validatedData) {

                // Creamos el registro mapeando los nombres del Request a los de la BD
                $campana = campanaPromo::create([

                    'nombre'              => $validatedData['nombre'],
                    'tipo'                => $validatedData['tipo'],
                    'codigo_cupon'        => isset($validatedData['codigo_cupon']) ? strtoupper($validatedData['codigo_cupon']) : null,
                    'tipo_descuento'      => $validatedData['tipo_descuento'] ?? null,
                    'valor_descuento'     => $validatedData['valor_descuento'] ?? 0,
                    'monto_minimo_compra' => $validatedData['monto_minimo_compra'] ?? 0,
                    'limite_uso'          => $validatedData['limite_uso'] ?? null,
                    'fecha_inicio'        => $validatedData['fecha_inicio'],
                    'usados'              => 0, // Inicializamos el contador de usos en 0
                    'fecha_fin'           => $validatedData['fecha_fin'],
                    'estado'              => $validatedData['estado'] ?? true,
                ]);

                return response()->json([
                    'status'  => 'success',
                    'message' => '¡Campaña de fidelización creada exitosamente!',
                    'data'    => $campana
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error("Error al guardar campaña: " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => 'No se pudo guardar la campaña.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getCampanasPromo(Request $request)
    {
        // Ejemplo rápido para listar las campañas de una empresa
        $campana = campanaPromo::orderBy('created_at', 'desc')
            ->get();
        return response()->json([
            'status'  => 'success',
            'message' => '¡Campaña de fidelización creada exitosamente!',
            'data'    => $campana
        ], 200);
    }

    public function deleteCampanasPromo($id)
    {

        try {
            $campana = CampanaPromo::findOrFail($id);
            $campana->delete();

            return response()->json([
                'success'  => true,
                'message' => '¡Campaña de fidelización eliminada exitosamente!',
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error al eliminar campaña: " . $e->getMessage());

            return response()->json([
                'success'  => false,
                'message' => 'No se pudo eliminar la campaña.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function updateCampanasPromo(Request $request, $id)
    {
        // 1. Validación de entrada (Tus reglas están perfectas)
        $validatedData = $request->validate([
            'nombre'              => 'sometimes|required|string|max:255',
            'tipo'                => 'sometimes|required|in:cupon,puntos,recompensa',
            'fecha_inicio'        => 'sometimes|required|date',
            'fecha_fin'           => 'sometimes|required|date|after_or_equal:fecha_inicio',
            'estado'              => 'sometimes|boolean',
            'codigo_cupon'        => 'sometimes|required_if:tipo,cupon|nullable|string|unique:campana_promos,codigo_cupon,' . $id,
            'tipo_descuento'      => 'sometimes|required_if:tipo,cupon|nullable|in:porcentaje,monto_fijo',
            'valor_descuento'     => 'sometimes|required_if:tipo,cupon|nullable|numeric|min:0',
            'monto_minimo_compra' => 'sometimes|nullable|numeric|min:0',
            'limite_uso'          => 'sometimes|nullable|integer|min:1',
        ]);

        try {

            if (isset($validatedData['codigo_cupon'])) {
                $validatedData['codigo_cupon'] = strtoupper($validatedData['codigo_cupon']);
            }


            $campana = CampanaPromo::findOrFail($id);
            $campana->update($validatedData);

            // 4. Respuesta de éxito
            return response()->json([
                'success' => true,
                'message' => 'Campaña actualizada exitosamente.',
                'data'    => $campana
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'La campaña no existe.'
            ], 404);
        } catch (\Exception $e) {
            Log::error("Error al actualizar campaña: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar la campaña.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
