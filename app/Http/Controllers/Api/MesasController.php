<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mesa;
use App\Traits\EmpresaSedeValidation;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MesasController extends Controller
{

    use EmpresaSedeValidation;
    public function getMesasAll()
    {
        try {
            $mesas = Mesa::get();
            return response()->json(['success' => true, 'data' => $mesas], 200);
        } catch (Exception $e) {
            return response()->json(['success' => false, $e->getMessage()], 500);
        }
    }

    public function deleteMesas($id)
    {
        try {
            $mesas = Mesa::findOrFail($id);
            if (!$mesas) {
                return response()->json(['success' => false, "No se encontró la mesa"], 404);
            }
            $mesas->delete();
            return response()->json(['success' => true, "message" => "Se procedió con la eliminación"], 200);
        } catch (Exception $e) {
            return response()->json(['success' => true, $e->getMessage()], 500);
        }
    }

    public function storeMesa(Request $request)
    {
        try {
            $validatedData = $request->validate([
                // unique:mesas (asume que tu tabla se llama 'mesas')
                'numero' => ([

                    'required',
                    'numeric',
                    'min:1',
                    $this->uniqueEmpresaSede('mesas', 'numero'),
                ]),
                'capacidad' => 'required|numeric|min:1',
                'piso' => 'required|numeric|min:1',
            ]);

            $mesa = new Mesa();
            $mesa->numero = $validatedData['numero'];
            $mesa->capacidad = $validatedData['capacidad'];
            $mesa->piso = $validatedData['piso'];


            $mesa->estado = 1;

            $mesa->save();

            return response()->json([
                'success' => true,
                'message' => 'Mesa registrada'
            ], 201);
        } catch (Exception $e) {
            Log::error('Error al guardar la mesa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateMesa(Request $request, $id)
    {
        try {
            $validatedData = $request->validate([
                'numero' => ([
                    'required',
                    'numeric',
                    'min:1',
                    $this->uniqueEmpresaSede('mesas', 'numero', $id),
                ]),
                'capacidad' => 'required|numeric|min:1',
                'piso' => 'required|numeric|min:1',
            ]);

            $mesa = Mesa::findOrFail($id);

            $mesa->numero = $validatedData['numero'];
            $mesa->capacidad = $validatedData['capacidad'];
            $mesa->piso = $validatedData['piso'];

            $mesa->save();

            return response()->json([
                'success' => true,
                'message' => 'Mesa actualizada'
            ], 200);
        } catch (ModelNotFoundException $e) { // <-- Captura específica
            return response()->json([
                'success' => false,
                'message' => 'Mesa no encontrada'
            ], 404);
        } catch (Exception $e) {
            Log::error('Error al actualizar la mesa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
