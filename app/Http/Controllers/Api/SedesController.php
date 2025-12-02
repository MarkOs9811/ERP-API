<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Traits\EmpresaValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SedesController extends Controller
{
    use EmpresaValidation;
    public function getSedes()
    {
        try {
            $sedes = Sede::get();
            return response()->json(['success' => true, 'data' => $sedes], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    public function saveSedes(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('sedes', 'nombre'),
                ]),
                'direccion' => 'nullable|string|max:255',
                'telefono' => 'nullable|string|max:9',
            ]);

            // Generar un código único usando la regla uniqueEmpresa para 'codigo'
            $maxAttempts = 10;
            $attempt = 0;
            do {
                $codigo = $this->codigoSedeRandom();
                $v = Validator::make(['codigo' => $codigo], [
                    'codigo' => [$this->uniqueEmpresa('sedes', 'codigo')],
                ]);
                $attempt++;
            } while ($v->fails() && $attempt < $maxAttempts);

            if ($v->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo generar un código único para la sede. Intente nuevamente.'
                ], 500);
            }

            $validatedData['codigo'] = $codigo;

            $sede = Sede::create($validatedData);
            return response()->json(['success' => true, 'data' => $sede], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function upDateSedes(Request $request, $id)
    {
        try {
            $sede = Sede::findOrFail($id);

            $validatedData = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresa('sedes', 'nombre', $id),
                ]),
                'direccion' => 'nullable|string|max:255',
                'telefono' => 'nullable|string|max:9',
            ]);

            $sede->update($validatedData);
            return response()->json(['success' => true, 'data' => $sede], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function codigoSedeRandom()
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $codigo = '';
        for ($i = 0; $i < 6; $i++) {
            $codigo .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $codigo;
    }

    public function desactivarSede($id)
    {
        try {
            $sede = Sede::findOrFail($id);
            $sede->estado = 0;
            $sede->save();
            return response()->json(['success' => true, 'data' => $sede], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function activarSede($id)
    {
        try {
            $sede = Sede::findOrFail($id);
            $sede->estado = 1;
            $sede->save();
            return response()->json(['success' => true, 'data' => $sede], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
