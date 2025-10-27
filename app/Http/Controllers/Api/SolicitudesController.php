<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\MiEmpresa;
use App\Models\Proveedore;
use App\Models\Solicitud;
use App\Models\UnidadMedida;
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
            $solicitud->tipo = 'interno';
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

    // PARA SOLICITUDES EXTERNAS
    public function solicitudAddExterna(Request $request)
    {
        try {
            $usuario = Auth::user();

            // ✅ Validar datos y archivos
            $validated = $request->validate([
                'unidad_medida' => 'required|exists:unidad_medidas,id',
                'proveedor' => 'required|exists:proveedores,id',
                'area_origen' => 'required|exists:areas,id',
                'nombre_solicitante' => 'required|string|max:255',
                'correo_electronico' => 'required|email|max:255',
                'telefono' => 'required|string|max:20',
                'marcaProducto' => 'nullable|string|max:255',
                'descripcion' => 'required|string|max:500',
                'cantidad' => 'required|integer|min:1',
                'precio_estimado' => 'required|numeric|min:0',
                'motivo' => 'required|string|max:500',
                'uso_previsto' => 'required|string|max:500',
                'prioridad' => 'required|string|in:alta,media,baja',
                'firmaSolicitante' => 'required|image|mimes:jpg,png,jpeg|max:2048',
                'firmaAprobador' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
            ]);

            // ✅ Buscar registros relacionados
            $empresa = MiEmpresa::find($usuario->idEmpresa);
            $area = Area::find($validated['area_origen']);
            $proveedor = Proveedore::find($validated['proveedor']);
            $unidad = UnidadMedida::find($validated['unidad_medida']);

            if (!$area || !$proveedor || !$unidad) {
                return response()->json([
                    'error' => 'Datos no válidos',
                    'details' => 'Alguno de los registros (área, proveedor o unidad) no existe.',
                ], 422);
            }

            // 🖋️ Guardar firmas
            $firmaSolicitantePath = $request->file('firmaSolicitante')->store('public/firmas');
            $firmaAprobadorPath = $request->hasFile('firmaAprobador')
                ? $request->file('firmaAprobador')->store('public/firmas')
                : null;

            // 🧾 Crear PDF con FPDF
            require_once base_path('vendor/setasign/fpdf/fpdf.php');
            $pdf = new \FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);

            // 🏢 Encabezado de empresa
            if ($empresa && $empresa->logo && file_exists(public_path('storage/' . $empresa->logo))) {
                $pdf->Image(public_path('storage/' . $empresa->logo), 10, 10, 25, 25);
            }

            $pdf->Cell(0, 10, utf8_decode($empresa->nombre ?? 'Mi Empresa'), 0, 1, 'C');
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, utf8_decode('Solicitud Externa de Activos'), 0, 1, 'C');
            $pdf->Ln(10);

            // 🧍 Datos del solicitante
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, utf8_decode('Datos del Solicitante:'), 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(
                0,
                7,
                "Nombre: {$validated['nombre_solicitante']}\n" .
                    "Área: {$area->nombre}\n" .
                    "Correo: {$validated['correo_electronico']}\n" .
                    "Teléfono: {$validated['telefono']}"
            );
            $pdf->Ln(6);

            // 📦 Detalles del producto o solicitud
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, utf8_decode('Detalles del Producto / Solicitud:'), 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(
                0,
                7,
                "Descripción: {$validated['descripcion']}\n" .
                    "Marca: " . ($validated['marcaProducto'] ?? 'N/A') . "\n" .
                    "Cantidad: {$validated['cantidad']} {$unidad->nombre}\n" .
                    "Proveedor: {$proveedor->nombre}\n" .
                    "Precio Estimado: S/ {$validated['precio_estimado']}\n" .
                    "Motivo: {$validated['motivo']}\n" .
                    "Uso Previsto: {$validated['uso_previsto']}\n" .
                    "Prioridad: " . ucfirst($validated['prioridad'])
            );
            $pdf->Ln(10);

            // 📅 Fecha y firmas
            $pdf->Cell(0, 8, utf8_decode('Fecha de solicitud: ') . now()->format('d/m/Y'), 0, 1);
            $pdf->Ln(10);

            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 8, utf8_decode('Firma del Solicitante:'), 0, 1);
            if (file_exists(storage_path('app/' . $firmaSolicitantePath))) {
                $pdf->Image(storage_path('app/' . $firmaSolicitantePath), 10, $pdf->GetY(), 40);
            }
            $pdf->Ln(30);

            if ($firmaAprobadorPath && file_exists(storage_path('app/' . $firmaAprobadorPath))) {
                $pdf->Cell(0, 8, utf8_decode('Firma del Aprobador:'), 0, 1);
                $pdf->Image(storage_path('app/' . $firmaAprobadorPath), 10, $pdf->GetY(), 40);
                $pdf->Ln(30);
            }

            // 📁 Guardar PDF en storage
            $pdfFileName = 'solicitud_' . time() . '.pdf';
            $pdfPath = 'public/documentos_solicitud/' . $pdfFileName;
            $pdf->Output(storage_path('app/' . $pdfPath), 'F');

            // ✅ Respuesta con URL pública
            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada correctamente',
                'pdf_url' => asset(str_replace('public/', 'storage/', $pdfPath)),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Errores de validación',
                'details' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error al generar PDF: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'error' => 'Error al generar PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
