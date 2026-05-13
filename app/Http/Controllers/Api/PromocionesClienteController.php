<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PromocionesApp;
use App\Models\PromotionalBanner;

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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getBannerPromo($idSede)
    {
        try {
            $promociones = PromotionalBanner::where('idSede', $idSede)->where('is_active', 1)->get();
            return response()->json([
                'success' => true,
                'data' => $promociones
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
