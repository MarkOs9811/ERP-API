<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\registrosCajas;
use Illuminate\Http\Request;

class RegistroCajasController extends Controller
{
    public function getCajasAll()
    {
        try {
            $cajas = Caja::get();
            return response()->json(['success' => true, 'data' => $cajas, 'message' => 'Cajas Obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false,  'message' => 'Error' . $e->getMessage()], 500);
        }
    }

    public function getRegistrosCajas()
    {
        try {
            $registroCajas = registrosCajas::with('usuario.empleado.persona', 'caja')->orderBy('id', 'desc')->get();
            return response()->json(['success' => true, 'data' => $registroCajas, 'message' => 'registros caja Obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false,  'message' => 'Error' . $e->getMessage()], 500);
        }
    }
}
