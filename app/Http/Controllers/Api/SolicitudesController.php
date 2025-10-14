<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Solicitud;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SolicitudesController extends Controller
{
    public function getMisSolicitudes()
    {
        try {
            $myId = auth()->user()->id;
            $MisSolicitudes = Solicitud::with('usuario.empleado.persona', 'area', 'unidad', 'categoria')->where('idUsuarioOrigen', $myId)->orderBy('id', 'Desc')->get();
            return response()->json(['success' => true, 'data' => $MisSolicitudes, 'message' => 'Mis SOlicitudes obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getSolicitudes()
    {
        try {
            $solicitud = Solicitud::with('usuario.empleado.persona', 'area')->get();
            return response()->json(['success' => true, 'data' => $solicitud, 'message' => 'Solicitudes obtenidas'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function actualizarMiSolicitud(Request $request, $id)
    {
        try {
            Log::info('Intentando actualizar solicitud con ID: ' . $id, $request->all());

            $solicitud = Solicitud::find($id);

            if (!$solicitud) {
                Log::warning("Solicitud con ID {$id} no encontrada");
                return response()->json(['success' => false, 'error' => 'Solicitud no encontrada'], 404);
            }

            // Validar los datos de la solicitud
            $request->validate([
                'nombre_solicitante' => 'required|string|max:255',
                'idArea' => 'required|exists:areas,id',
                'correo_electronico' => 'required|email|max:255',
                'telefono' => 'nullable|string|max:15',
                'nombre_producto' => 'required|string|max:255',
                'marcaProd' => 'required|string|max:255',
                'descripcion' => 'required|string',
                'cantidad' => 'required|integer|min:1',
                'idUnidad' => 'required|exists:unidad_medidas,id',
                'idCategoria' => 'required|exists:categorias,id',
                'precio_estimado' => 'nullable|numeric|min:0',
                'motivo' => 'required|string',
                'uso_previsto' => 'required|string',
                'prioridad' => 'required|in:Alta,Media,Baja',
            ]);

            // Actualizar los campos de la solicitud
            $solicitud->nombre_solicitante = $request->nombre_solicitante;
            $solicitud->idArea = $request->idArea;
            $solicitud->idUnidadMedida = $request->idUnidad;
            $solicitud->idCategoria = $request->idCategoria;
            $solicitud->correo_electronico = $request->correo_electronico;
            $solicitud->telefono = $request->telefono;
            $solicitud->nombre_producto = $request->nombre_producto;
            $solicitud->marcaProd = $request->marcaProd;
            $solicitud->descripcion = $request->descripcion;
            $solicitud->cantidad = $request->cantidad;
            $solicitud->precio_estimado = $request->precio_estimado;
            $solicitud->motivo = $request->motivo;
            $solicitud->uso_previsto = $request->uso_previsto;
            $solicitud->prioridad = $request->prioridad;
            $solicitud->save();

            Log::info("Solicitud con ID {$id} actualizada correctamente");

            return response()->json(['success' => true, 'message' => 'Solicitud actualizada correctamente'], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Error de validación al actualizar solicitud con ID {$id}", [
                'errores' => $e->errors()
            ]);
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error("Error inesperado al actualizar solicitud con ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Error al actualizar solicitud'], 500);
        }
    }


    public function elimiarmiSolicitud($id)
    {
        try {
            $solicitud = Solicitud::find($id);

            if (!$solicitud) {
                return response()->json(['success' => false, 'error' => 'Solicitud no encontrada'], 404);
            }

            $solicitud->delete();

            return response()->json(['success' => true, 'message' => 'Solicitud eliminada correctamente'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar solicitud: ' . $e->getMessage()], 500);
        }
    }
    public function registrarSolicitud(Request $request)
    {
        try {
            Log::info('datos', $request->all());
            // Validar los datos de la solicitud
            $request->validate([
                'nombre_solicitante' => 'required|string|max:255',
                'area' => 'required|exists:areas,id',
                'correo_electronico' => 'required|email|max:255',
                'telefono' => 'nullable|string|max:15',
                'nombre_producto' => 'required|string|max:255',
                'marcaProd' => 'required|string|max:255',
                'descripcion' => 'required|string',
                'cantidad' => 'required|integer|min:1',
                'unidad_medida' => 'required|exists:unidad_medidas,id',
                'categoria' => 'required|exists:categorias,id',
                'precio_estimado' => 'nullable|numeric|min:0',
                'motivo' => 'required|string',
                'uso_previsto' => 'required|string',
                'prioridad' => 'required|in:Alta,Media,Baja',
            ]);

            // Crear una nueva solicitud
            $solicitud = new Solicitud();
            $solicitud->nombre_solicitante = $request->nombre_solicitante;
            $solicitud->idUsuarioOrigen = auth()->user()->id;
            $solicitud->idArea = $request->area;
            $solicitud->idUnidadMedida = $request->unidad_medida;
            $solicitud->idCategoria = $request->categoria;
            $solicitud->correo_electronico = $request->correo_electronico;
            $solicitud->telefono = $request->telefono;
            $solicitud->nombre_producto = $request->nombre_producto;
            $solicitud->marcaProd = $request->marcaProd;
            $solicitud->descripcion = $request->descripcion;
            $solicitud->cantidad = $request->cantidad;
            $solicitud->precio_estimado = $request->precio_estimado;
            $solicitud->motivo = $request->motivo;
            $solicitud->uso_previsto = $request->uso_previsto;
            $solicitud->prioridad = $request->prioridad;
            $solicitud->estado = 0;
            $solicitud->save();

            // Responder con éxito
            return response()->json(['success' => true, 'message' => 'Solicitud guardada exitosamente'], 200);
        } catch (\Exception $e) {
            // Manejar cualquier error
            return response()->json(['success' => false, 'message' => 'Ocurrió un error al guardar la solicitud', 'error' => $e->getMessage()], 500);
        }
    }

    public function changeState(Request $request)
    {
        try {
            Log::info('Intentando cambiar estado', $request->all());
            $solicitud = Solicitud::findOrFail($request->id);

            $solicitud->estado = 1;
            $solicitud->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado cambiado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error al cambiar estado: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al cambiar estado'
            ], 500);
        }
    }
}
