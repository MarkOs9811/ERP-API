<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Horario;
use Illuminate\Http\Request;

class HorarioController extends Controller
{
    public function getHorarios()
    {
        try {
            $areas = Horario::where('estado',1)->get();
            return response()->json($areas, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener los Horarios', 'message' => $e->getMessage()], 500);
        }
    }
}
