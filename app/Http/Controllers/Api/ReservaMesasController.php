<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MesaReserva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReservaMesasController extends Controller
{
    public function index()
    {
        try {
            // Traemos las reservas Pendientes (estado 1) desde hoy en adelante
            $reservas = MesaReserva::with('mesa')
                ->where('estado', 1)
                ->where('fecha_reserva', '>=', now()->toDateString())
                ->orderBy('fecha_reserva', 'asc')
                ->orderBy('hora_reserva', 'asc')
                ->get();

            return response()->json(['success' => true, 'data' => $reservas], 200);
        } catch (\Exception $e) {
            Log::error('Error GET Reservas: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al listar reservas'], 500);
        }
    }

    // POST: Crear reserva
    public function store(Request $request)
    {
        $request->validate([
            'nombre_cliente' => 'required|string|max:150',
            'idMesa' => 'required|integer|exists:mesas,id',
            'fecha_reserva' => 'required|date',
            'hora_reserva' => 'required',
            'cantidad_personas' => 'nullable|integer|min:1',
            'telefono_cliente' => 'nullable|string|max:20',
            'nota' => 'nullable|string'
        ]);

        try {
            $user = auth()->user();

            $reserva = new MesaReserva();
            // Asegúrate de que tu modelo User tenga idEmpresa e idSede, si no, ajústalo
            $reserva->idEmpresa = $user->idEmpresa ?? 1;
            $reserva->idSede = $user->idSede ?? 1;
            $reserva->idUsuario = $user->id;

            $reserva->idMesa = $request->idMesa;
            $reserva->nombre_cliente = $request->nombre_cliente;
            $reserva->telefono_cliente = $request->telefono_cliente;
            $reserva->fecha_reserva = $request->fecha_reserva;
            $reserva->hora_reserva = $request->hora_reserva;
            $reserva->cantidad_personas = $request->cantidad_personas ?? 1;
            $reserva->nota = $request->nota;
            $reserva->estado = 1; // 1 = Pendiente
            $reserva->save();

            return response()->json(['success' => true, 'message' => 'Reserva creada con éxito'], 200);
        } catch (\Exception $e) {
            Log::error('Error POST Reserva: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al crear reserva'], 500);
        }
    }

    // DELETE: Cancelar reserva
    public function destroy($id)
    {
        try {
            $reserva = MesaReserva::find($id);
            if (!$reserva) {
                return response()->json(['success' => false, 'message' => 'Reserva no encontrada'], 404);
            }

            // Cambiamos el estado a 0 (Cancelada) en lugar de borrarla físicamente para auditoría
            $reserva->estado = 0;
            $reserva->save();

            return response()->json(['success' => true, 'message' => 'Reserva cancelada con éxito'], 200);
        } catch (\Exception $e) {
            Log::error('Error DELETE Reserva: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al cancelar reserva'], 500);
        }
    }
    // PUT: Actualizar reserva
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre_cliente' => 'required|string|max:150',
            'idMesa' => 'required|integer|exists:mesas,id',
            'fecha_reserva' => 'required|date',
            'hora_reserva' => 'required',
            'cantidad_personas' => 'nullable|integer|min:1',
            'telefono_cliente' => 'nullable|string|max:20',
            'nota' => 'nullable|string'
        ]);

        try {
            $reserva = MesaReserva::find($id);
            if (!$reserva) {
                return response()->json(['success' => false, 'message' => 'Reserva no encontrada'], 404);
            }

            $reserva->idMesa = $request->idMesa;
            $reserva->nombre_cliente = $request->nombre_cliente;
            $reserva->telefono_cliente = $request->telefono_cliente;
            $reserva->fecha_reserva = $request->fecha_reserva;
            $reserva->hora_reserva = $request->hora_reserva;
            $reserva->cantidad_personas = $request->cantidad_personas ?? 1;
            $reserva->nota = $request->nota;
            $reserva->save();

            return response()->json(['success' => true, 'message' => 'Reserva actualizada con éxito'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar reserva'], 500);
        }
    }
}
