<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventario;
use App\Models\Plato;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CombosController extends Controller
{
    public function getCombos()
    {
        try {
            $user = auth()->user();
            $combos = Plato::with('categoria')->where('idCategoria', 2)->orderBy('id', 'Desc')->get();
            return response()->json(['success' => true, 'data' => $combos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener combos:' . $e->getMessage(),], 500);
        }
    }
    public function updateCombo(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'nombre' => 'required|string|max:255|unique:platos,nombre,' . $id,
                'precio' => 'required|numeric|min:0',
                'descripcion' => 'required|string',
            ]);
            $combo = Plato::find($id);
            if (!$combo) {
                return response()->json(['success' => false, 'message' => 'Combo no encontrado'], 404);
            }
            $combo->nombre = $validatedData['nombre'];
            $combo->precio = $validatedData['precio'];
            $combo->descripcion = $validatedData['descripcion'];
            $combo->save();

            return response()->json(['success' => true, 'data' => $combo, 'message' => 'Combo actualizado con éxito'], 200);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar combo: ' . $e->getMessage()], 500);
        }
    }
    public function registerCombo(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'nombreCombo' => 'required|string|max:255',
                'precioCombo' => 'required|numeric|min:0',
                'items' => 'required|array|min:1',
                'items.*.nombre' => 'required|string'
            ]);



            // 3. Procesamiento con log
            $descripcion = collect($validatedData['items'])
                ->map(fn($item) => $item['nombre'])
                ->implode(' + ');


            $combo = new Plato();
            $combo->nombre = $validatedData['nombreCombo'];
            $combo->precio = $validatedData['precioCombo'];
            $combo->idCategoria = 2;
            $combo->descripcion = $descripcion;
            $combo->save();


            return response()->json([
                'success' => true,
                'message' => 'Combo registrado con éxito',
                'data' => [
                    'id' => $combo->id,
                    'nombre' => $combo->nombre,
                    'precio' => $combo->precio,
                    'items' => explode(' + ', $combo->descripcion)
                ]
            ], 200);
        } catch (ValidationException $e) {
            // Log de error de validación
            Log::channel('combos')->warning('Error de validación', [
                'errors' => $e->errors(),
                'input' => $request->except(['password', '_token'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    public function desactivarCombo(Request $request, $id)
    {
        try {

            $combo = Plato::find($id);
            Log::info(['id combo' => $id]);
            if (!$combo) {
                return response()->json(['success' => false, 'message' => 'Combo no encontrado'], 404);
            }

            $combo->estado = 0;
            $combo->save();

            return response()->json(['success' => true, 'message' => 'Combo desactivado correctamente'], 200);
        } catch (\Exception $e) {
            // Registrar en el log de Laravel
            Log::error('Error al desactivar combo: ' . $e->getMessage(), [
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al desactivar',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function activarCombo(Request $request, $id)
    {
        try {

            $combo = Plato::find($id);
            Log::info(['id combo' => $id]);
            if (!$combo) {
                return response()->json(['success' => false, 'message' => 'Combo no encontrado'], 404);
            }
            $combo->estado = 1;
            $combo->save();

            return response()->json(['success' => true, 'message' => 'Combo activado correctamente'], 200);
        } catch (\Exception $e) {
            // Registrar en el log de Laravel
            Log::error('Error al desactivar combo: ' . $e->getMessage(), [
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al desactivar',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function generarComboIA(OpenAIService $openAiService)
    {
        try {
            $platos = Plato::where('estado', 1)->get();

            // Solo bebidas desde el inventario con categoría "bebidas"
            $bebidas = Inventario::with('categoria')
                ->whereHas('categoria', function ($q) {
                    $q->where('nombre', 'bebidas');
                })
                ->where('estado', 1)
                ->get();

            $datosParaAI = [
                'platos' => $platos,
                'bebidas' => $bebidas,
            ];
            Log::info('Datos para IA', [
                'platos' => $platos,
                'bebidas' => $bebidas,
            ]);

            // Llama al método del servicio OpenAiService
            $comboArmadoAi = $openAiService->generarComboConOpenAI($datosParaAI);

            Log::info('Combo generado por IA', [
                'combo' => $comboArmadoAi,
            ]);

            // Guardar el combo generado en la base de datos
            return response()->json([
                'success' => true,
                'message' => 'Combo generado con éxito',
                'data' => $comboArmadoAi
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el combo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
