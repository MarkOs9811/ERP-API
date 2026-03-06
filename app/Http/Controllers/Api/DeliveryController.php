<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromocionesApp;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function getPromociones()
    {
        try {
            $promociones = PromocionesApp::with('plato')->get();
            return response()->json(['success' => true, 'data' => $promociones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
