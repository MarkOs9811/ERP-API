<?php

namespace App\Http\Controllers\Api;

use setasign\Fpdi\Fpdi;

use App\Http\Controllers\Controller;

use App\Models\Almacen;
use App\Models\Area;
use App\Models\Inventario;
use App\Models\Kardex;
use App\Models\Movimiento;
use App\Models\UnidadMedida;
use DragonCode\Contracts\Cashier\Auth\Auth;
use Illuminate\Console\View\Components\Alert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule; // Agregar esta línea


class AlmacenController extends Controller
{
    public function showAlmacen(Request $request)
    {
        try {
            $perPage = $request->input('limit', 20);
            $page = $request->input('page', 1);

            $query = Almacen::with('categoria', 'unidad', 'proveedor')
                ->orderBy('id', 'DESC');

            $results = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => $results->items(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total()
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function eliminarActivo($id)
    {
        // Busca el activo por su ID
        $activo = Almacen::find($id);

        // Verifica si el activo existe
        if (!$activo) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado']);
        }

        // Cambia el estado del activo de 1 a 0
        $activo->estado = 0; // Cambia el estado
        $activo->save(); // Guarda los cambios

        return response()->json(['success' => true, 'message' => 'Producto Desactivado correctamente']);
    }

    public function activarActivo($id)
    {
        $activo = Almacen::find($id);
        if (!$activo) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado']);
        }

        $activo->estado = 1;
        $activo->save();
        return response()->json(['success' => true, 'message' => 'Producto activado corectamente']);
    }

    public function saveAlmacen(Request $request)
    {
        try {
            // Validar y guardar archivos
            $request->validate([
                'pdf_file' => 'required|mimes:pdf|max:10000', // Tamaño máximo de 10MB
                'image_file' => 'required|image|max:5000', // Tamaño máximo de 5MB
                'nombreProducto' => 'required|string|max:255',
                'descripcion' => 'required|string',
                'marca' => 'required|string',
                'categoria' => 'required|exists:categorias,id',
                'unidad' => 'required|exists:unidad_medidas,id',
                'proveedor' => 'required|exists:proveedores,id',
                'precioUnit' => 'required|numeric|min:0', // Validar precioUnit
            ]);

            // Validar si ya existe un producto con el mismo nombre, laboratorio y presentación
            $existingProduct = Almacen::where('nombre', $request->nombreProducto)
                ->where('laboratorio', $request->laboratorio)
                ->where('presentacion', $request->presentacion)
                ->first();

            if ($existingProduct) {
                return response()->json(['errors' => ['Ya existe un producto con el mismo nombre, laboratorio y presentación.']], 422);
            }

            $pdfFilePath = $request->file('pdf_file')->store('pdfs');
            $imageFilePath = $request->file('image_file')->store('images');

            $pdfFullPath = storage_path('app/' . $pdfFilePath);
            $imageFullPath = storage_path('app/' . $imageFilePath);

            if (!file_exists($pdfFullPath) || !file_exists($imageFullPath)) {
                return response()->json(['errors' => ['Archivo no encontrado.']], 422);
            }

            $fpdi = new Fpdi();

            // Cargar el PDF original y agregar la firma
            $pageCount = $fpdi->setSourceFile($pdfFullPath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $fpdi->importPage($pageNo);
                $fpdi->AddPage();
                $fpdi->useTemplate($templateId, 0, 0);

                // Ajustar coordenadas para la imagen de firma y texto
                $x = 20; // Coordenada X (horizontal)
                $y = 251; // Coordenada Y (vertical) para la firma
                $textY = $y + 16; // Coordenada Y para el texto "Firma almacen"

                // Agregar la imagen de firma en la parte inferior izquierda
                $fpdi->Image($imageFullPath, $x, $y, 50); // Ajustar tamaño y posición según sea necesario

                // Agregar texto "Firma del almacenero" debajo de la firma
                $fpdi->SetFont('Arial', 'B', 12);
                $fpdi->SetTextColor(0, 0, 0);
                $fpdi->SetXY($x, $textY); // Ajustar la posición Y para el texto para que esté alineado con la firma
                $fpdi->Cell(0, 10, 'Firma almacen', 0, 1, 'L'); // Texto alineado a la izquierda
            }


            // Generar nombre único para el PDF firmado
            $outputPdfPath = 'documentosFirmados/documentoCompleto_' . time() . '.pdf';
            $outputPdfFullPath = storage_path('app/public/' . $outputPdfPath); // Guardar en la carpeta public
            $fpdi->Output($outputPdfFullPath, 'F');

            // Generar la URL pública
            $pdfUrl = Storage::url($outputPdfPath);

            // Eliminar los archivos originales
            Storage::delete($pdfFilePath);
            Storage::delete($imageFilePath);

            // Generar código de producto único
            $codigoProd = $this->generateUniqueProductCode();

            // Guardar en la base de datos
            $producto = new Almacen();
            $producto->codigoProd = $codigoProd; // Asignar código generado
            $producto->idCategoria = $request->categoria;
            $producto->idUnidadMedida = $request->unidad;
            $producto->idProveedor = $request->proveedor;
            $producto->nombre = $request->nombreProducto;
            $producto->marca = $request->marca;
            $producto->descripcion = $request->descripcion;
            $producto->cantidad = $request->cantidad;
            $producto->precioUnit = $request->precioUnit; // Guardar precioUnit
            $producto->estado = 1; // Estado activo por defecto
            $producto->save();

            // Registro en el kardex
            $kardex = new Kardex();
            $kardex->idProducto = $producto->id;
            $kardex->idUsuario = auth()->user()->id;
            $kardex->cantidad = $request->cantidad;
            $kardex->tipo_movimiento = 'entrada';
            $kardex->descripcion = 'Nuevo ingreso';
            $kardex->stock_anterior = 0; // Nuevo producto, no tiene stock anterior
            $kardex->stock_actual = $request->cantidad; // El stock actual es igual a la cantidad ingresada
            $kardex->fecha_movimiento = now();
            $kardex->documento = $pdfUrl; // Guardar la URL pública del documento
            $kardex->save();

            // Devolver respuesta JSON de éxito
            return response()->json(['success' => true, 'message' => 'Producto ingresado correctamente'], 200);
        } catch (\Exception $e) {
            // Devolver errores detallados en la respuesta JSON
            return response()->json(['error' => false, 'message' => 'Error al procesar la solicitud: ' . $e->getMessage()], 500);
        }
    }

   

    public function acualizarProducto(Request $request, $id)
    {
        try {
            // Validar datos recibidos
            $validator = Validator::make($request->all(), [
                'nombre' => [
                    'required',
                    'string',
                    'max:255',
                    // Validar único excepto el producto actual
                    Rule::unique('almacens')->ignore($id)->where(function ($query) use ($request) {
                        return $query->where('marca', $request->marca)
                                    ->where('presentacion', $request->presentacion);
                    })
                ],
                'marca' => 'required|string|max:255',
                'cantidad' => 'required|numeric|min:0',
                'precioUnit' => 'required|numeric|min:0',
                'unidad' => 'required|exists:unidad_medidas,id', // Validar que exista en la tabla unidad_medidas
                'categoria' => 'required|exists:categorias,id', // Validar que exista en la tabla categorias
                'proveedor' => 'required|exists:proveedores,id', // Validar que exista en la tabla proveedores
                'presentacion' => 'required|string|max:255',
                'fecha_vencimiento' => [
                    'required',
                    'date',
                    'after:today' // La fecha debe ser posterior a hoy
                ],
                'registro_sanitario' => 'required|string|max:50'
            ], [
                'nombre.unique' => 'Ya existe un producto con el mismo nombre, marca y presentación',
                'unidad.exists' => 'La unidad de medida seleccionada no existe',
                'categoria.exists' => 'La categoría seleccionada no existe',
                'proveedor.exists' => 'El proveedor seleccionado no existe',
                'fecha_vencimiento.after' => 'La fecha de vencimiento debe ser posterior a hoy'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Buscar el producto
            $producto = Almacen::find($id);
            if (!$producto) {
                return response()->json([
                    'success' => false, 
                    'message' => 'Producto no encontrado'
                ], 404);
            }

            // Actualizar los datos del producto
            $producto->nombre = $request->nombre;
            $producto->marca = $request->marca;
            $producto->cantidad = $request->cantidad;
            $producto->precioUnit = $request->precioUnit;
            $producto->idUnidadMedida = $request->unidad;
            $producto->idCategoria = $request->categoria;
            $producto->idProveedor = $request->proveedor;
            $producto->presentacion = $request->presentacion;
            $producto->fecha_vencimiento = $request->fecha_vencimiento;
            $producto->registro_sanitario = $request->registro_sanitario;

            // Guardar los cambios
            $producto->save();
            
            return response()->json([
                'success' => true, 
                'message' => 'Producto actualizado correctamente',
                'data' => $producto
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el producto: ' . $e->getMessage()
            ], 500);
        }
    }
        /**
     * Método privado para generar un código de producto único.
     */
    private function generateUniqueProductCode()
    {
        $prefixes = range('A', 'Z'); // Prefijos de A a Z
        $suffix = 1;
        $code = '';

        do {
            foreach ($prefixes as $prefix) {
                $code = $prefix . $suffix . substr(md5(mt_rand()), 0, 6);
                if (!Almacen::where('codigoProd', $code)->exists()) {
                    return $code;
                }
            }
            $suffix++;
        } while (true);
    }
    public function addStock(Request $request)
    {
        // Validación de datos del formulario
        $validatedData = $request->validate([
            'idProductoEdit' => 'required|integer',
            'stockActual' => 'required|numeric',
            'cantidadIngresar' => 'required|numeric',
            'pdf_file' => 'required|file|mimes:pdf',
            'image_file' => 'required|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        try {
            $user = auth()->user();  // Obtiene el usuario actual desde Sanctum
            Log::info('Inicio de la operación de agregar stock.');

            // Almacenar archivos
            $pdfFilePath = $request->file('pdf_file')->store('pdfs');
            $imageFilePath = $request->file('image_file')->store('images');
            Log::info('Archivos almacenados:', ['pdf' => $pdfFilePath, 'image' => $imageFilePath]);

            // Verificar existencia de archivos
            $pdfFullPath = storage_path('app/' . $pdfFilePath);
            $imageFullPath = storage_path('app/' . $imageFilePath);
            if (!file_exists($pdfFullPath) || !file_exists($imageFullPath)) {
                Log::error('Uno de los archivos no existe:', ['pdf' => $pdfFullPath, 'image' => $imageFullPath]);
                return response()->json(['errors' => ['Archivo no encontrado.']], 422);
            }

            // Manipular PDF con FPDI
            $fpdi = new Fpdi();
            $pageCount = $fpdi->setSourceFile($pdfFullPath);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $fpdi->importPage($pageNo);
                $fpdi->AddPage();
                $fpdi->useTemplate($templateId, 0, 0);

                // Agregar firma y texto
                $fpdi->Image($imageFullPath, 20, 250, 50);
                $fpdi->SetFont('Arial', 'B', 12);
                $fpdi->SetTextColor(0, 0, 0);
                $fpdi->SetXY(20, 265);
                $fpdi->Cell(0, 10, 'Firma Almacen', -250, 1, 'C');
            }

            // Guardar PDF firmado
            $outputPdfPath = 'documentosFirmados/documentoCompleto_' . time() . '.pdf';
            $outputPdfFullPath = storage_path('app/public/' . $outputPdfPath);
            $fpdi->Output($outputPdfFullPath, 'F');
            $pdfUrl = Storage::url($outputPdfPath);
            Storage::delete($pdfFilePath);
            Storage::delete($imageFilePath);
            Log::info('PDF firmado almacenado correctamente.', ['path' => $pdfUrl]);

            // Actualizar stock del producto
            $producto = Almacen::findOrFail($validatedData['idProductoEdit']);
            $nuevoStock = $producto->cantidad + $validatedData['cantidadIngresar'];
            $producto->cantidad = $nuevoStock;
            $producto->save();
            Log::info('Stock actualizado correctamente.', ['producto_id' => $producto->id, 'nuevo_stock' => $nuevoStock]);

            // Guardar en el Kardex
            $kardex = new Kardex();
            $kardex->idProducto = $producto->id;
            $kardex->idUsuario = $user->id;
            $kardex->cantidad = $validatedData['cantidadIngresar'];
            $kardex->tipo_movimiento = 'entrada';
            $kardex->descripcion = 'Actualización de stock';
            $kardex->stock_anterior = $producto->cantidad - $validatedData['cantidadIngresar'];
            $kardex->stock_actual = $nuevoStock;
            $kardex->fecha_movimiento = now();
            $kardex->documento = $pdfUrl;
            $kardex->save();
            Log::info('Registro en Kardex completado.', ['kardex_id' => $kardex->id]);

            return response()->json([
                'status' => 'success',
                'message' => 'Stock actualizado correctamente',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en la operación de agregar stock.', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Hubo un error al actualizar el stock: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function transferirToinventario(Request $request)
    {
        $request->validate([
            'idDestino' => 'required|exists:areas,id',
            'productos' => 'required|json',
            'archivo' => 'nullable|file|mimes:pdf,doc,docx',
        ]);

        // Decodificar el JSON de productos
        $productos = json_decode($request->productos, true);

        // Validar cada producto individualmente
        foreach ($productos as $producto) {
            $validator = Validator::make($producto, [
                'id' => 'required|exists:almacens,id',
                'cantidad' => 'required|integer|min:1',
                'precioUnit' => 'required|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => 'Datos de producto inválidos', 'errors' => $validator->errors()], 400);
            }
        }

        DB::beginTransaction();

        try {
            $user = auth()->user();
            $areaOrigen = Area::where('nombre', 'almacen')->firstOrFail();
            $idAreaOrigen = $areaOrigen->id;

            // Manejar la subida del archivo
            $documentoPath = null;
            if ($request->hasFile('archivo')) {
                $outputPdfPath = 'documentosFirmados/documentoCompleto_' . time() . '.pdf';
                $outputPdfFullPath = storage_path('app/public/' . $outputPdfPath);
                $request->file('archivo')->storeAs('public', $outputPdfPath);
                $documentoPath = Storage::url($outputPdfPath);
            }

            // Procesar cada producto
            foreach ($productos as $producto) {
                $productoAlmacen = Almacen::findOrFail($producto['id']);
                $stockActual = $productoAlmacen->cantidad;

                if ($producto['cantidad'] > $stockActual) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Cantidad de transferencia excede la cantidad disponible en el almacén para el producto: ' . $productoAlmacen->nombre,
                        'producto' => $productoAlmacen->nombre,
                        'cantidad_disponible' => $stockActual,
                        'cantidad_solicitada' => $producto['cantidad']
                    ], 400);
                }

                // Transferencia a área de ventas
                if ($request->idDestino == Area::where('nombre', 'ventas')->value('id')) {
                    $unidadMedida = UnidadMedida::findOrFail($productoAlmacen->idUnidadMedida);
                    $cantidadUnidades = $producto['cantidad'];

                    // Conversión de unidades
                    if ($unidadMedida->nombre == 'docena') {
                        $cantidadUnidades *= 12;
                    } elseif ($unidadMedida->nombre == 'caja') {
                        $cantidadUnidades *= 24;
                    }

                    // Buscar o crear en inventario
                    $productoInventario = Inventario::where('codigoProd', $productoAlmacen->codigoProd)->first();

                    if ($productoInventario) {
                        $productoInventario->stock += $cantidadUnidades;
                        $productoInventario->save();
                    } else {
                        Inventario::create([
                            'idCategoria' => $productoAlmacen->idCategoria,
                            'idUnidad' => UnidadMedida::where('nombre', 'unidad')->value('id'),
                            'codigoProd' => $productoAlmacen->codigoProd,
                            'nombre' => $productoAlmacen->nombre,
                            'marca' => $productoAlmacen->marca,
                            'presentacion' => $productoAlmacen->presentacion,
                            'lote' => $productoAlmacen->lote,
                            'registro_sanitario' => $productoAlmacen->registro_sanitario,
                            'laboratorio' => $productoAlmacen->laboratorio,
                            'descripcion' => $productoAlmacen->descripcion,
                            'stock' => $cantidadUnidades,
                            'fecha_vencimiento' => $productoAlmacen->fecha_vencimiento,
                            'precio' => $producto['precioUnit'],
                            'foto' => null,
                            'estado' => 1,
                        ]);
                    }
                }

                // Registrar movimiento
                Movimiento::create([
                    'idProductoAlmacen' => $productoAlmacen->id,
                    'idAreaOrigen' => $idAreaOrigen,
                    'idAreaDestino' => $request->idDestino,
                    'idUsuario' => $user->id,
                    'tipo_movimiento' => 'transferencia',
                    'cantidad' => $producto['cantidad'],
                    'fecha_movimiento' => now(),
                    'documento' => $documentoPath
                ]);

                // Actualizar stock en almacén
                $stockAnterior = $productoAlmacen->cantidad;
                $productoAlmacen->cantidad -= $producto['cantidad'];
                $productoAlmacen->save();

                // Registrar en kardex
                Kardex::create([
                    'idProducto' => $productoAlmacen->id,
                    'idUsuario' => $user->id,
                    'cantidad' => $producto['cantidad'],
                    'tipo_movimiento' => 'salida',
                    'descripcion' => 'Salida por transferencia de almacén a área',
                    'stock_anterior' => $stockAnterior,
                    'stock_actual' => $productoAlmacen->cantidad,
                    'fecha_movimiento' => now(),
                    'documento' => $documentoPath
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Transferencia de ' . count($productos) . ' productos realizada con éxito',
                'productos_transferidos' => count($productos)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error en la transferencia: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTrace() : null
            ], 500);
        }
    }
}
