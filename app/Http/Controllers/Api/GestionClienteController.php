<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GestionClienteController extends Controller
{
    public function getClientes(Request $request)
    {
        try {

            $clientes = Cliente::with(['persona', 'ultimaVenta',  'empresa'])
                ->latest()
                ->paginate($request->integer('per_page', 15));

            return response()->json([
                'success' => true,
                'data'    => $clientes->items(),
                'meta'    => [
                    'current_page' => $clientes->currentPage(),
                    'last_page'    => $clientes->lastPage(),
                    'total'        => $clientes->total(),
                    'per_page'     => $clientes->perPage(),
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error en getClientes: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Error al cargar los clientes.'], 500);
        }
    }

    // Endpoint para el Dashboard
    public function getMetricasDashboard()
    {
        try {
            $hoy = now();

            $totalClientes = DB::table('clientes')->count();
            $ticketPromedio = DB::table('ventas')
                ->whereNotNull('idCliente')
                ->avg('total') ?? 0;

            $ingresos_totales = DB::table('ventas')
                ->whereNotNull('idCliente')
                ->sum('total') ?? 0;

            $topClientes = DB::table('ventas')
                ->join('clientes', 'ventas.idCliente', '=', 'clientes.id')
                ->join('personas', 'clientes.idPersona', '=', 'personas.id')
                ->select(
                    'clientes.id as id_cliente',
                    'personas.nombre',
                    'personas.apellidos',
                    'personas.correo',
                    'personas.foto',
                    DB::raw('COUNT(ventas.id) as cantidad_pedidos'),
                    DB::raw('SUM(ventas.total) as total_comprado')
                )

                ->groupBy('clientes.id', 'personas.nombre', 'personas.apellidos', 'personas.correo', 'personas.foto')
                ->orderByDesc('total_comprado') // Ordenamos por el que más gastó
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'kpis' => [
                        'total_clientes'  => $totalClientes,
                        'ticket_promedio' => round($ticketPromedio, 2),
                        'ingresos_totales' => round($ingresos_totales, 2),
                    ],
                    'top_clientes' => $topClientes
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error Dashboard Clientes: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Error al procesar analíticas.'], 500);
        }
    }
}
