<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class MoodleController extends Controller
{
    public function generarAcciónConsultarCurso(Request $request, OpenAIService $openAiService)
    {
        try {
            // AHORA RECIBIMOS UN MENSAJE NATURAL, YA NO UN SIMPLE CORREO
            $mensajeUsuario = $request->query('mensaje', '¿Qué cursos tiene junior.pari@uarm.pe?');

            // ¡EL GRAN CAMBIO! La URL ahora apunta al servidor del plugin MCP
            $moodleMcpUrl = 'https://av-pruebas.uarm.tmp.vis-hosting.com/webservice/mcp/server.php';
            $token = '9a6a1ddf3df5b2f4c68b2c6c44735ef7';

            // =========================================================
            // PASO 1: Pedirle el menú de herramientas al plugin MCP
            // =========================================================
            $responseMcp = Http::withToken($token)->post($moodleMcpUrl, [
                'jsonrpc' => '2.0',
                'id' => uniqid(),
                'method' => 'tools/list'
            ]);

            $datosMcp = $responseMcp->json();

            if (!isset($datosMcp['result']['tools'])) {
                return response()->json(['success' => false, 'message' => 'El plugin no devolvió herramientas. Revisa tu token.'], 400);
            }

            // =========================================================
            // PASO 2: Traducir el menú de MCP al formato de OpenAI
            // =========================================================
            $herramientasOpenAI = [];
            foreach ($datosMcp['result']['tools'] as $tool) {
                $herramientasOpenAI[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? 'Función de Moodle',
                        'parameters' => $tool['inputSchema'] // ¡El plugin ya hace el JSON Schema por nosotros!
                    ]
                ];
            }

            $respuestaIA = $openAiService->consultarAgenteMoodle($mensajeUsuario, $herramientasOpenAI, $moodleMcpUrl, $token);

            return response()->json(['success' => true, 'data' => $respuestaIA], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}
