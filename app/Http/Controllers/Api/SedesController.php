<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use Illuminate\Http\Request;

class SedesController extends Controller
{
    public function getSedes()
    {
        try {
            $sedes = Sede::get();
            return response()->json(['success' => true, 'data' => $sedes], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
