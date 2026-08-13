<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Peru\Jne\DniFactory;
use Peru\Sunat\RucFactory;

class ConsultasGenericas extends Controller
{
    // 🔥 TU TOKEN DE DECOLECTA
    private $decolectaToken = 'sk_18342.6g2wn9hg0T0e40gNmpCSoWsWKzcvbP44';

    public function ConsultaDatosUsuario($tipo, $numero)
    {
        $tipo = strtoupper($tipo);
        Log::info("🔵 [CONSULTA DOC] Iniciando cascada de búsqueda. Tipo: {$tipo} | Número: {$numero}");

        if ($tipo === 'DNI') {
            if (strlen($numero) !== 8) {
                return response()->json(['success' => false, 'message' => 'El DNI debe tener 8 dígitos'], 400);
            }
            return $this->buscarDni($numero);
        }

        if ($tipo === 'RUC') {
            if (strlen($numero) !== 11) {
                return response()->json(['success' => false, 'message' => 'El RUC debe tener 11 dígitos'], 400);
            }
            return $this->buscarRuc($numero);
        }

        return response()->json(['success' => false, 'message' => 'Tipo de documento inválido'], 400);
    }

    // ==========================================
    // LÓGICA DE BÚSQUEDA PARA DNI (CASCADA)
    // ==========================================
    private function buscarDni($numero)
    {
        // 1️⃣ INTENTO 1: PAQUETE LOCAL (Peru-Consult)
        try {
            $factory = new DniFactory();
            $dni = $factory->create();
            $person = $dni->get($numero);

            if ($person) {
                $fullName = trim(preg_replace('/\s+/', ' ', "{$person->nombres} {$person->apellidoPaterno} {$person->apellidoMaterno}"));
                Log::info("✅ [CONSULTA DOC] DNI encontrado vía: LOCAL (Peru-Consult)");
                return response()->json(['success' => true, 'nombre' => $fullName]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló LOCAL para DNI {$numero}. Saltando a Opción 2...");
        }

        // 2️⃣ INTENTO 2: APIS.NET.PE (Gratis)
        try {
            $response = Http::timeout(5)->get("https://api.apis.net.pe/v1/dni", ['numero' => $numero]);

            if ($response->successful() && isset($response->json()['nombre'])) {
                Log::info("✅ [CONSULTA DOC] DNI encontrado vía: APIS.NET.PE");
                return response()->json(['success' => true, 'nombre' => $response->json()['nombre']]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló APIS.NET.PE para DNI {$numero}. Saltando a Opción 3...");
        }

        // 3️⃣ INTENTO 3: DECOLECTA (Token / De pago)
        try {
            $response = Http::withToken($this->decolectaToken)
                ->timeout(5)
                ->get("https://api.decolecta.com/v1/reniec/dni", ['numero' => $numero]);

            if ($response->successful()) {
                $data = $response->json();
                $fullName = $data['full_name'] ?? trim("{$data['first_name']} {$data['first_last_name']} {$data['second_last_name']}");

                Log::info("✅ [CONSULTA DOC] DNI encontrado vía: DECOLECTA");
                return response()->json(['success' => true, 'nombre' => $fullName]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló DECOLECTA para DNI {$numero}.");
        }

        // ❌ SI LAS 3 FALLAN
        Log::error("🔴 [CONSULTA DOC] DNI {$numero} no encontrado en ninguna API.");
        return response()->json(['success' => false, 'message' => 'DNI no encontrado o servicios no disponibles'], 404);
    }

    // ==========================================
    // LÓGICA DE BÚSQUEDA PARA RUC (CASCADA)
    // ==========================================
    private function buscarRuc($numero)
    {
        // 1️⃣ INTENTO 1: PAQUETE LOCAL (Peru-Consult)
        try {
            $factory = new RucFactory();
            $ruc = $factory->create();
            $company = $ruc->get($numero);

            if ($company) {
                Log::info("✅ [CONSULTA DOC] RUC encontrado vía: LOCAL (Peru-Consult)");
                return response()->json(['success' => true, 'nombre' => $company->razonSocial]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló LOCAL para RUC {$numero}. Saltando a Opción 2...");
        }

        // 2️⃣ INTENTO 2: APIS.NET.PE (Gratis)
        try {
            $response = Http::timeout(5)->get("https://api.apis.net.pe/v1/ruc", ['numero' => $numero]);

            if ($response->successful() && isset($response->json()['nombre'])) {
                Log::info("✅ [CONSULTA DOC] RUC encontrado vía: APIS.NET.PE");
                return response()->json(['success' => true, 'nombre' => $response->json()['nombre']]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló APIS.NET.PE para RUC {$numero}. Saltando a Opción 3...");
        }

        // 3️⃣ INTENTO 3: DECOLECTA (Token / De pago)
        try {
            $response = Http::withToken($this->decolectaToken)
                ->timeout(5)
                ->get("https://api.decolecta.com/v1/sunat/ruc", ['numero' => $numero]);

            if ($response->successful()) {
                $data = $response->json();
                $razonSocial = $data['razon_social'] ?? 'RAZON SOCIAL DESCONOCIDA';

                Log::info("✅ [CONSULTA DOC] RUC encontrado vía: DECOLECTA");
                return response()->json(['success' => true, 'nombre' => $razonSocial]);
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ [CONSULTA DOC] Falló DECOLECTA para RUC {$numero}.");
        }

        // ❌ SI LAS 3 FALLAN
        Log::error("🔴 [CONSULTA DOC] RUC {$numero} no encontrado en ninguna API.");
        return response()->json(['success' => false, 'message' => 'RUC no encontrado o servicios no disponibles'], 404);
    }
}
