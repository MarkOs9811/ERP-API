<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AjustesPlanilla;
use Illuminate\Http\Request;

class AjustesPlanillasController extends Controller
{
    public function getAjustesPlanilla()
    {
        try {
            $ajustePlanilla = AjustesPlanilla::get();
            return response()->json(['success' => true, 'data' => $ajustePlanilla], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
}
