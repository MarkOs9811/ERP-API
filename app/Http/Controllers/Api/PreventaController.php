<?php

namespace App\Http\Controllers\Api;

use App\Events\PedidoCocinaEvent;
use App\Http\Controllers\Controller;
use App\Models\EstadoPedido;
use App\Models\Mesa;
use App\Models\PedidoMesaRegistro;
use App\Models\Plato;
use App\Models\PreventaMesa;
use App\Services\EstadoPedidoController;
use App\Services\ImpresionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PreventaController extends Controller
{
    public function addPlatosPreVentaMesa(Request $request)
    {
        // 🔥 LOG 1: Vemos todo lo que entra al servidor antes de hacer nada
        Log::info('=== INICIO PETICIÓN: addPlatosPreVentaMesa ===');
        Log::info('Datos recibidos desde React:', $request->all());

        $data = $request->input('pedidos');

        if (empty($data)) {
            Log::warning('Petición rechazada: El array de pedidos vino vacío.');
            return response()->json(['success' => true, 'message' => 'No se enviaron platos para registrar.'], 200);
        }

        $request->validate([
            'pedidos' => 'required|array',
            'pedidos.*.idCaja' => 'required|integer|exists:cajas,id',
            'pedidos.*.idPlato' => 'required|integer|exists:platos,id',
            'pedidos.*.idMesa' => 'required|integer|exists:mesas,id',
            'pedidos.*.cantidad' => 'required|integer|min:1',
            'pedidos.*.precio' => 'required|numeric|min:0',
            'pedidos.*.nota' => 'nullable|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            $user = auth()->user();
            $idMesa = $data[0]['idMesa'];
            $idCaja = $data[0]['idCaja'];

            // 1. Validar la mesa
            $mesa = Mesa::find($idMesa);
            if (!$mesa) throw new \Exception('Mesa no encontrada.');

            // 2. Obtener o crear la cabecera del pedido (Método Privado)
            $idPedido = $this->obtenerOCrearPedido($user->id, $idMesa);

            // 3. Procesar los platos masivamente (Método Privado OPTIMIZADO)
            $detallePlatosArray = $this->procesarPlatosMasivo($data, $user->id, $idPedido, $idMesa, $idCaja);

            // 4. Cambiar estado de la mesa
            if ($mesa->estado !== 0) {
                $mesa->estado = 0;
                $mesa->save();
            }

            // 5. Procesar Ticket de Cocina (Método Privado)
            $this->procesarTicketCocina($idPedido, $idCaja, $detallePlatosArray, $request->nota, $mesa->numero);

            DB::commit();
            Log::info('=== TRANSACCIÓN DB EXITOSA ===');

            // 6. Imprimir Comanda
            // La solución definitiva será usar Laravel Queues -> dispatch(new ImprimirComandaJob(...))
            $this->imprimirComandaRapida($mesa->numero, $user->name ?? 'Mozo', $detallePlatosArray, $request->nota);

            $pedidoCompleto = PedidoMesaRegistro::with(['preVentas.plato'])->find($idPedido);

            return response()->json([
                'success' => true,
                'message' => 'Pedidos registrados exitosamente.',
                'data' => [
                    'pedidoRegistro' => $pedidoCompleto->preVentas
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            // 🔥 LOG 2: Capturamos el error exacto con línea y archivo
            Log::error('Error CRÍTICO al registrar los pedidos: ' . $e->getMessage() . ' en la línea ' . $e->getLine() . ' del archivo ' . $e->getFile());

            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /* =========================================================
       METODOS PRIVADOS OPTIMIZADOS
       ========================================================= */

    private function obtenerOCrearPedido($userId, $idMesa)
    {
        $pedidoExistente = PedidoMesaRegistro::where('idUsuario', $userId)
            ->where('estado', 0)
            ->whereHas('preVentas', function ($q) use ($idMesa) {
                $q->where('idMesa', $idMesa);
            })
            ->first();

        if ($pedidoExistente) {
            return $pedidoExistente->id;
        }

        $registroPedido = new PedidoMesaRegistro();
        $registroPedido->idUsuario = $userId;
        $registroPedido->fechaPedido = now();
        $registroPedido->estado = 0;
        $registroPedido->save();

        return $registroPedido->id;
    }

    private function procesarPlatosMasivo($data, $userId, $idPedido, $idMesa, $idCaja)
    {
        // OPTIMIZACIÓN MÁXIMA: Evitamos hacer consultas SQL dentro del bucle foreach.
        $platoIds = array_column($data, 'idPlato');

        // Traemos todos los platos implicados de un solo golpe
        $platos = Plato::whereIn('id', $platoIds)->get()->keyBy('id');

        // Traemos todas las preventas existentes de esta mesa de un solo golpe
        $preventasExistentes = PreventaMesa::where('idPedido', $idPedido)
            ->where('idUsuario', $userId)
            ->where('idMesa', $idMesa)
            ->where('idCaja', $idCaja)
            ->whereIn('idPlato', $platoIds)
            ->get()
            ->keyBy('idPlato');

        $detallePlatosArray = [];

        foreach ($data as $pedido) {
            if ($pedido['idMesa'] != $idMesa) {
                throw new \Exception('Error de integridad: Mesas mezcladas.');
            }

            $plato = $platos->get($pedido['idPlato']);
            if (!$plato) {
                throw new \Exception('Plato con ID ' . $pedido['idPlato'] . ' no encontrado.');
            }

            // Buscamos en memoria RAM, no en la base de datos (Ultra rápido)
            $preventaExistente = $preventasExistentes->get($pedido['idPlato']);

            if ($preventaExistente) {
                // Usamos la forma clásica que sabíamos que te funcionaba bien
                $preventaExistente->cantidad += $pedido['cantidad'];
                $preventaExistente->precio = $pedido['precio'];
                $preventaExistente->save();
            } else {
                PreventaMesa::create([
                    'idUsuario' => $userId,
                    'idCaja'    => $idCaja,
                    'idPlato'   => $pedido['idPlato'],
                    'idMesa'    => $idMesa,
                    'cantidad'  => $pedido['cantidad'],
                    'precio'    => $pedido['precio'],
                    'idPedido'  => $idPedido,
                ]);
            }

            $detallePlatosArray[] = [
                'nombre'   => $plato->nombre,
                'cantidad' => $pedido['cantidad']
            ];
        }

        return $detallePlatosArray;
    }

    private function procesarTicketCocina($idPedido, $idCaja, $detallePlatosArray, $nota, $numeroMesa)
    {
        $estadoPedido = EstadoPedido::where('idPedidoMesa', $idPedido)
            ->where('estado', 0)
            ->first();

        if ($estadoPedido) {
            $detalleActual = json_decode($estadoPedido->detalle_platos, true) ?? [];
            $platosIndexados = [];

            foreach ($detalleActual as $item) {
                $platosIndexados[$item['nombre']] = $item['cantidad'];
            }

            foreach ($detallePlatosArray as $nuevo) {
                if (isset($platosIndexados[$nuevo['nombre']])) {
                    $platosIndexados[$nuevo['nombre']] += $nuevo['cantidad'];
                } else {
                    $platosIndexados[$nuevo['nombre']] = $nuevo['cantidad'];
                }
            }

            $nuevoDetalle = [];
            foreach ($platosIndexados as $nombre => $cantidad) {
                $nuevoDetalle[] = ['nombre' => $nombre, 'cantidad' => $cantidad];
            }

            $estadoPedido->detalle_platos = json_encode($nuevoDetalle);
            $estadoPedido->save();

            event(new PedidoCocinaEvent($estadoPedido->id, $nuevoDetalle, 'mesa', $estadoPedido->estado));
        } else {
            $estadoService = new EstadoPedidoController(
                'mesa',
                $idCaja,
                json_encode($detallePlatosArray),
                $idPedido,
                $nota,
                $numeroMesa
            );
            $estadoService->registrar();
        }
    }

    private function imprimirComandaRapida($numeroMesa, $usuario, $detallePlatosArray, $nota)
    {
        $datosComanda = [
            'mesa'      => $numeroMesa,
            'fecha'     => date('d/m/Y H:i:s'),
            'usuario'   => $usuario,
            'productos' => $detallePlatosArray,
            'nota'      => $nota
        ];

        try {
            $impresionService = new ImpresionService();
            $impresionService->imprimirComandaCocina($datosComanda);
        } catch (\Exception $eImpresion) {
            Log::error("Error al imprimir comanda de mesa: " . $eImpresion->getMessage());
        }
    }
}
