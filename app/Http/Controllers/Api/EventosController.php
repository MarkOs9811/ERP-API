<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Http\Request;

class EventosController extends Controller
{
    public function getEventos()
    {
        try {
            $eventos = Evento::all()->map(function ($evento) {
                return [
                    'id' => $evento->id,
                    'summary' => $evento->summary,
                    'description' => $evento->description,
                    'start' => $evento->start,
                    'end' => $evento->end,
                    'attendees' => json_decode($evento->attendees, true) ?: [],
                    'status' => $evento->status,
                    'html_link' => $evento->html_link,
                    'goog_event_id' => $evento->goog_event_id
                ];
            });

            return response()->json(['success' => true, 'data' => $eventos], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error ' . $e->getMessage()
            ], 500);
        }
    }
}
