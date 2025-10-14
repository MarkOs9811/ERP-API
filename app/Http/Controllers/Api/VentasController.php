<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Venta;
use App\Services\OpenAIService;
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
            // Obtener las ventas de los últimos 30 días
            $ventas = Venta::selectRaw('DATE(fechaVenta) as fecha, SUM(total) as total')
                ->where('fechaVenta', '>=', now()->subDays(7))
                ->groupBy('fecha')
                ->orderBy('fecha')
                ->get();


            // Usar el servicio para predecir las ventas
            $resultados = $this->openAIService->predecirVentas($ventas);
            Log::info("Resultados de la IA: ", ['resultados' => $resultados]);
            return response()->json(['success' => true, 'data' => $resultados], 200);
        } catch (\Exception $e) {
            Log::error("Error en getVentasIA: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al predecir las ventas.'], 500);
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
