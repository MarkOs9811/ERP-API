<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PeriodoNomina;
use Illuminate\Http\Request;

class PeriodoNominaController extends Controller
{
    public function getPeriodoNomina()
    {
        try {
            $periodos = PeriodoNomina::get();
            return response()->json(['success' => true, "data" => $periodos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, "data" => $e->getMessage()], 500);
        }
    }
}
