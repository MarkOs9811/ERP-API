<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Http\Request;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GoogleCalendarController extends Controller
{
    public function crearEvento(Request $request)
    {
        try {
            Log::info('📆 [INICIO] Creación de evento', [
                'usuario_id' => Auth::id(),
                'datos_recibidos' => $request->all(),
            ]);

            $user = Auth::user();

            $request->validate([
                'summary' => 'required|string',
                'description' => 'nullable|string',
                'start' => 'required|date',
                'end' => 'required|date|after:start',
                'attendees' => 'nullable|array',
                'attendees.*' => 'email'
            ]);

            Log::info('✅ Validación correcta, creando servicio de GoogleCalendar');

            $calendar = new GoogleCalendarService($user);

            Log::info('🔁 Enviando datos al API de Google Calendar...', [
                'summary' => $request->summary,
                'description' => $request->description,
                'start' => $request->start,
                'end' => $request->end,
                'attendees' => $request->attendees,
            ]);
            $start = Carbon::parse($request->start)->toRfc3339String();
            $end = Carbon::parse($request->end)->toRfc3339String();

            $evento = $calendar->createEvent(
                $request->summary,
                $request->description,
                $start,
                $end,
                $request->attendees ?? []
            );
            Evento::create([
                'idUsuario' => $user->id,
                'google_event_id' => $evento->getId(),
                'summary' => $request->summary,
                'description' => $request->description,
                'start' =>   $start,
                'end' =>   $end,
                'attendees' => json_encode($request->attendees),
                'status' => $evento->getStatus(),
                'html_link' => $evento->htmlLink
            ]);


            Log::info('✅ Evento creado en Google Calendar', [
                'link' => $evento->htmlLink,
                'id_evento' => $evento->getId(),
                'status' => $evento->getStatus()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evento creado exitosamente.',
                'htmlLink' => $evento->htmlLink
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ Error al crear evento en Google Calendar', [
                'success' => false,
                'mensaje' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al crear el evento.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
