<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\DetallePedidosWeb;
use App\Models\Direccione;
use App\Models\PedidosWebRegistro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VenderAppCliente extends Controller
{
    public function store(Request $request)
    {
        // 1. LOG DE ENTRADA
        Log::info('➡️ INICIO: Recibiendo solicitud de pedido', [
            'cliente' => $request->idCliente,
            'payload' => $request->all()
        ]);

        $request->validate([
            'idCliente' => 'required',
            'items' => 'required|array|min:1',
            'total' => 'required'
        ]);

        // ---------------------------------------------------------
        // VALIDACIÓN PREVIA: DATOS DEL CLIENTE
        // ---------------------------------------------------------
        Log::info("👤 Validando datos del Cliente ID: " . $request->idCliente);

        $clienteData = Cliente::with('persona')->find($request->idCliente);

        // REGLA DE NEGOCIO: Si no tiene teléfono, rechazamos la venta
        // Verificamos si existe el cliente, la persona, y si el campo telefono tiene valor
        if (!$clienteData || !$clienteData->persona || empty($clienteData->persona->telefono)) {
            Log::warning("⛔ Venta rechazada: Cliente sin teléfono.");

            return response()->json([
                'success' => false,
                'message' => 'El cliente no tiene un teléfono registrado. Por favor actualice su perfil antes de pedir.'
            ], 400); // 400 Bad Request
        }

        $telefonoCliente = $clienteData->persona->telefono;
        $nombreCliente   = trim(($clienteData->persona->nombre ?? 'Cliente') . ' ' . ($clienteData->persona->apellidos ?? ''));

        Log::info("📱 Datos válidos: $nombreCliente - Tel: $telefonoCliente");

        // ---------------------------------------------------------
        // INICIO DE TRANSACCIÓN
        // ---------------------------------------------------------
        try {
            return DB::transaction(function () use ($request, $telefonoCliente, $nombreCliente) {

                Log::info('🔄 Iniciando transacción...');

                // 1. OBTENER LATITUD/LONGITUD
                $direccion = Direccione::find($request->idDireccion);
                $lat = $direccion ? $direccion->latitud : null;
                $lng = $direccion ? $direccion->longitud : null;

                // 2. GENERAR CÓDIGO
                $codigo = 'PED-' . strtoupper(Str::random(6));

                // 3. CREAR PEDIDO
                Log::info('📦 Creando registro en tabla pedidos_web...');

                $pedido = PedidosWebRegistro::create([
                    'idEmpresa'     => $request->idEmpresa,
                    'idSede'        => $request->idSede,
                    'idCliente'     => $request->idCliente,
                    'codigo_pedido' => $codigo,

                    // Datos inyectados del cliente validado
                    'numero_cliente' => $telefonoCliente,
                    'nombre_cliente' => $nombreCliente,

                    'idDireccion'   => $request->idDireccion,
                    'latitud'       => $lat,
                    'longitud'      => $lng,
                    'tipo_entrega'  => $request->tipo_entrega,
                    'idMetodoPago'  => $request->idMetodoPago,
                    'estado_pago'   => $request->estado_pago,
                    'estado_pedido' => 1,
                    'propina'       => $request->propina,
                    'costo_envio'   => $request->costo_envio,
                    'prioridad'     => $request->prioridad,
                    'total'         => $request->total,
                    'fecha'         => now(),
                ]);

                Log::info("✅ Pedido creado con ID: " . $pedido->id);

                // 4. GUARDAR DETALLES
                foreach ($request->items as $item) {
                    DetallePedidosWeb::create([
                        'idPedido' => $pedido->id,
                        'idPlato'  => $item['idPlato'],
                        'producto' => "Plato ID " . $item['idPlato'],
                        'cantidad' => $item['cantidad'],
                        'precio'   => $item['precio'],
                        'estado'   => '1'
                    ]);
                }

                Log::info("🏁 Transacción completada exitosamente.");

                return response()->json([
                    'success' => true,
                    'message' => 'Pedido registrado correctamente',
                    'data'    => $pedido
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error("❌ ERROR CRÍTICO AL GUARDAR PEDIDO: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar pedido: ' . $e->getMessage()
            ], 500);
        }
    }


    public function getMisPedidos(Request $request)
    {
        try {
            $user = $request->user();

            // 1. Log para verificar si el usuario llega bien
            $cliente = Cliente::where('idPersona', $user->id)->first();



            $idCliente = $cliente->id;

            // 2. Ejecutamos la consulta
            $pedidos = PedidosWebRegistro::where('idCliente', $idCliente)
                ->where('estado_pedido', 3) // Agregado según tu requerimiento previo
                ->with(['detallesPedido.plato'])
                ->get();



            return response()->json([
                'status' => 'success',
                'data' => $pedidos
            ], 200);
        } catch (\Exception $e) {
            // 3. LOG CRÍTICO: Aquí verás el error real en storage/logs/laravel.log
            Log::error('Error en getMisPedidos: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error interno en el servidor'
            ], 500);
        }
    }
}
