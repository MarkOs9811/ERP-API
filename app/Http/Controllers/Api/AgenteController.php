<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AgenteController extends Controller
{
    public function chatear(Request $request, $agente)
    {
        return match (strtolower(trim((string) $agente))) {
            'platos' => app(AgentPlatosController::class)->chatear($request, 'platos'),
            'pos' => app(AgentePosController::class)->chatear($request),
            default => response()->json(['respuesta' => 'Agente no implementado.'], 422),
        };
    }
}
