<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Kardex;
use Illuminate\Http\Request;

class KardexController extends Controller
{
    public function getKardex()
    {
        try {
            $kardex = Kardex::with('producto')->orderBy('id', 'desc')->get();
            return response()->json(['success' => true, 'data' => $kardex], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 200);
        }
    }
}
