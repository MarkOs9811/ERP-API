<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Services\OpenAIService;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VentasController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function getVentas()
    {
        try {
            // Obtener las ventas ordenadas por fecha de la más reciente a la más antigua
            $ventas = Venta::with('metodoPago', 'cliente', 'pedido.detallePedidos.producto', 'pedidoWeb.detallesPedido.plato', 'usuario.empleado.persona', 'detallePedidos', 'boleta', 'factura')
                ->orderBy('id', 'desc') // Ordenar por fechaVenta en orden descendente
                ->get();

            // Depurar los resultados
            foreach ($ventas as $venta) {
                if (!$venta->metodoPago) {
                    Log::info('Método de pago no encontrado para la venta:', ['id' => $venta->id]);
                }
            }

            return response()->json(['success' => true, 'data' => $ventas, 'message' => 'Ventas obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    public function getVentasIA()
    {
        try {
            // 1. Aumentamos el rango a 30 días para que la IA detecte patrones semanales
            $fechaInicio = now()->subDays(30)->startOfDay();
            $fechaFin = now()->subDay()->endOfDay(); // Hasta ayer

            // 2. Consulta a BD (Solo trae días con ventas)
            $ventasBD = Venta::selectRaw('DATE(fechaVenta) as fecha, SUM(total) as total')
                ->whereBetween('fechaVenta', [$fechaInicio, $fechaFin])
                ->groupBy('fecha')
                ->orderBy('fecha')
                ->get()
                ->keyBy('fecha'); // Indexamos por fecha para buscar rápido

            // 3. RELLENADO DE HUECOS (La clave para arreglar tu gráfica)
            $historialCompleto = [];
            $periodo = CarbonPeriod::create($fechaInicio, $fechaFin);

            foreach ($periodo as $date) {
                $fechaStr = $date->format('Y-m-d');

                // Si existe venta en BD la usamos, si no, ponemos 0
                $totalDia = isset($ventasBD[$fechaStr]) ? (float)$ventasBD[$fechaStr]->total : 0;

                $historialCompleto[] = [
                    'fecha' => $fechaStr,
                    'total' => $totalDia, // Aquí le decimos a la IA explícitamente que fue 0
                ];
            }

            Log::info("Enviando a OpenAI:", $historialCompleto); // <--- VERIFICA ESTO EN TU LOG
            $resultados = $this->openAIService->predecirVentas(collect($historialCompleto));

            Log::info("Resultados de la IA: ", ['resultados' => $resultados]);

            return response()->json([
                'success' => true,
                'data' => $resultados,
                'historial' => $historialCompleto // Enviamos también el historial corregido al front
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error en getVentasIA: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al predecir ventas.'], 500);
        }
    }
    public function generarRecomendaciones()
    {
        try {
            // Obtener las ventas reales (históricas)
            $ventasRealesResponse = $this->getVentas();
            $ventasReales = json_decode($ventasRealesResponse->getContent(), true)['data']; // Convertir a array

            // Obtener las predicciones generadas por la IA
            $ventasIAResponse = $this->getVentasIA();
            $ventasIA = json_decode($ventasIAResponse->getContent(), true)['data']; // Convertir a array

            // Llamar al método que genera las recomendaciones
            $recomendaciones = $this->openAIService->generarRecomendacionesIA($ventasReales, $ventasIA);

            return response()->json(['success' => true, 'data' => $recomendaciones], 200);
        } catch (\Exception $e) {
            Log::error("Error al generar recomendaciones: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al generar recomendaciones.'], 500);
        }
    }
}
