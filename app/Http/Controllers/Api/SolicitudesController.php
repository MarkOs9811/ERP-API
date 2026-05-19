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
use Illuminate\Support\Facades\Storage;

class
SolicitudesController extends Controller
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
        $tempFiles = []; // Array dinámico para rastrear y limpiar archivos locales temporales

        try {
            $usuario = Auth::user();

            // ✅ Validar datos y archivos de entrada
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
            $empresa = \App\Models\MiEmpresa::find($usuario->idEmpresa);
            $area = \App\Models\Area::find($validated['area_origen']);
            $proveedor = \App\Models\Proveedore::find($validated['proveedor']);
            $unidad = \App\Models\UnidadMedida::find($validated['unidad_medida']);

            if (!$area || !$proveedor || !$unidad) {
                return response()->json([
                    'error' => 'Datos no válidos',
                    'details' => 'Alguno de los registros (área, proveedor o unidad) no existe.',
                ], 422);
            }

            // 1. 🖋️ Guardar firmas TEMPORALMENTE en el disco local para FPDF
            $firmaSolicitanteLocal = $request->file('firmaSolicitante')->store('tmp', 'local');
            $tempFiles[] = $firmaSolicitanteLocal;

            $firmaAprobadorLocal = null;
            if ($request->hasFile('firmaAprobador')) {
                $firmaAprobadorLocal = $request->file('firmaAprobador')->store('tmp', 'local');
                $tempFiles[] = $firmaAprobadorLocal;
            }

            // 2. 🏢 Descargar el logo de S3 temporalmente si existe
            $logoLocalPath = null;
            if ($empresa && $empresa->logo && Storage::disk('s3')->exists($empresa->logo)) {
                $logoContent = Storage::disk('s3')->get($empresa->logo);
                $logoLocalPath = 'tmp/logo_temp_' . time() . '.png';
                Storage::disk('local')->put($logoLocalPath, $logoContent);
                $tempFiles[] = $logoLocalPath;
            }

            // 3. 🧾 Crear PDF con FPDF
            require_once base_path('vendor/setasign/fpdf/fpdf.php');
            $pdf = new \FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);

            // Estampar el Logo desde la ruta local temporal
            if ($logoLocalPath) {
                $pdf->Image(storage_path('app/' . $logoLocalPath), 10, 10, 25, 25);
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
                "Nombre: " . utf8_decode($validated['nombre_solicitante']) . "\n" .
                    "Area: " . utf8_decode($area->nombre) . "\n" .
                    "Correo: " . utf8_decode($validated['correo_electronico']) . "\n" .
                    "Telefono: " . utf8_decode($validated['telefono'])
            );
            $pdf->Ln(6);

            // 📦 Detalles del producto o solicitud
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 8, utf8_decode('Detalles del Producto / Solicitud:'), 0, 1);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(
                0,
                7,
                utf8_decode("Descripción: {$validated['descripcion']}\n") .
                    "Marca: " . utf8_decode($validated['marcaProducto'] ?? 'N/A') . "\n" .
                    "Cantidad: {$validated['cantidad']} " . utf8_decode($unidad->nombre) . "\n" .
                    "Proveedor: " . utf8_decode($proveedor->nombre) . "\n" .
                    "Precio Estimado: S/ {$validated['precio_estimado']}\n" .
                    "Motivo: " . utf8_decode($validated['motivo']) . "\n" .
                    "Uso Previsto: " . utf8_decode($validated['uso_previsto']) . "\n" .
                    "Prioridad: " . ucfirst($validated['prioridad'])
            );
            $pdf->Ln(10);

            // 📅 Fecha y firmas
            $pdf->Cell(0, 8, utf8_decode('Fecha de solicitud: ') . now()->format('d/m/Y'), 0, 1);
            $pdf->Ln(10);

            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 8, utf8_decode('Firma del Solicitante:'), 0, 1);
            $pdf->Image(storage_path('app/' . $firmaSolicitanteLocal), 10, $pdf->GetY(), 40);
            $pdf->Ln(30);

            if ($firmaAprobadorLocal) {
                $pdf->Cell(0, 8, utf8_decode('Firma del Aprobador:'), 0, 1);
                $pdf->Image(storage_path('app/' . $firmaAprobadorLocal), 10, $pdf->GetY(), 40);
                $pdf->Ln(30);
            }

            // 4. 📁 Guardar PDF localmente de forma temporal
            $tempPdfPath = 'tmp/solicitud_' . time() . '.pdf';
            $tempFiles[] = $tempPdfPath;
            $pdf->Output(storage_path('app/' . $tempPdfPath), 'F');

            // 5. ☁️ Subir PDF definitivo a la carpeta estructurada en Cloudflare R2
            $finalPdfPath = 'documentos_solicitud/solicitud_' . time() . '.pdf';
            Storage::disk('s3')->put($finalPdfPath, file_get_contents(storage_path('app/' . $tempPdfPath)));

            // 6. 🧹 Limpieza masiva de archivos temporales locales
            Storage::disk('local')->delete($tempFiles);

            // ✅ Respuesta final con la URL generada dinámicamente desde R2
            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada correctamente',
                'pdf_url' => Storage::disk('s3')->url($finalPdfPath),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (!empty($tempFiles)) {
                Storage::disk('local')->delete($tempFiles);
            }
            return response()->json([
                'error' => 'Errores de validación',
                'details' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Limpieza de emergencia ante cualquier excepción
            if (!empty($tempFiles)) {
                Storage::disk('local')->delete($tempFiles);
            }
            Log::error('Error al generar PDF: ' . $e->getMessage(), ['exception' => $e]);
            return response()->json([
                'error' => 'Error al generar PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
