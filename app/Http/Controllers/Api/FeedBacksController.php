<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FeedBacksController extends Controller
{
    // SECCION FEEDBACKS

    public function getFeedbacks(Request $request)
    {
        try {
            $feedbacks = DB::table('feedbacks')
                // 1. Eslabón 1: Del feedback saltamos a la venta
                ->join('ventas', 'feedbacks.idVenta', '=', 'ventas.id')

                // 2. Eslabón 2: De la venta saltamos al cliente
                ->join('clientes', 'ventas.idCliente', '=', 'clientes.id')

                // 3. Eslabón 3: Del cliente llegamos a la persona
                ->join('personas', 'clientes.idPersona', '=', 'personas.id')

                ->select(
                    'feedbacks.id',
                    'personas.nombre',
                    'personas.apellidos',
                    'personas.correo',
                    'personas.foto',
                    'feedbacks.comentario',
                    'feedbacks.calificacion',
                    'feedbacks.created_at'
                )
                ->orderByDesc('feedbacks.created_at')
                ->paginate($request->integer('per_page', 15));

            return response()->json([
                'success' => true,
                'data'    => $feedbacks->items(),
                'meta'    => [
                    'current_page' => $feedbacks->currentPage(),
                    'last_page'    => $feedbacks->lastPage(),
                    'total'        => $feedbacks->total(),
                    'per_page'     => $feedbacks->perPage(),
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Error en getFeedbacks: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Error al cargar los feedbacks.'], 500);
        }
    }

    public function getAllFeedBack()
    {
        try {
            $promedioCalificacion = DB::table('feedbacks')->avg('calificacion') ?? 0;
            $totalFeedbacks = DB::table('feedbacks')->count();


            $comentarios = DB::table('feedbacks')->whereNotNull('comentario')->pluck('comentario');


            $palabras = $comentarios->flatMap(function ($comentario) {

                $limpio = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($comentario, 'UTF-8'));

                return explode(' ', $limpio);
            })->reject(function ($palabra) {
                $stopWords = [
                    'y',
                    'que',
                    'en',
                    'de',
                    'la',
                    'el',
                    'los',
                    'las',
                    'un',
                    'una',
                    'por',
                    'con',
                    'para',
                    'lo',
                    'al',
                    'es',
                    'muy',
                    'me',
                    'se',
                    'del',
                    'a',
                    'su',
                    'sus',
                    'mi',
                    'nos',
                    'te',
                    'como',
                    'pero',
                    'mas',
                    'más'
                ];

                return empty($palabra) || strlen($palabra) <= 2 || in_array($palabra, $stopWords);
            })->countBy()->sortDesc(); // Contar ocurrencias y ordenar de mayor a menor

            // 4. Obtener la primera palabra (la más repetida)
            $palabraMasRepetida = $palabras->keys()->first() ?? 'Ninguna';

            $dataFeedBack = [
                'promedio_calificacion' => round($promedioCalificacion, 2),
                'total_feedbacks' => $totalFeedbacks,
                'palabra_mas_repetida' => ucfirst($palabraMasRepetida) // Primera letra en mayúscula
            ];

            return response()->json(['success' => true, 'data' => $dataFeedBack], 200);
        } catch (\Exception $e) {
            Log::error("Error en getAllFeedBack: {$e->getMessage()}");
            return response()->json(['success' => false, 'message' => 'Error al cargar los feedbacks.'], 500);
        }
    }
}
