<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PromocionesApp;

class PromocionesClienteController extends Controller
{
    public function getPromociones($idSede)
    {
        try {
            $promociones = PromocionesApp::where('idSede', $idSede)->where('estado', 1)->get();
            return response()->json([
                'success' => true,
                'data' => $promociones
            ], 200);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
