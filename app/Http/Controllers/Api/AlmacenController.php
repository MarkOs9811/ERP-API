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
use App\Traits\EmpresaSedeValidation;
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
    use EmpresaSedeValidation;
    public function showAlmacen(Request $request)
    {
        try {

            $almacen = Almacen::with('categoria', 'unidad', 'proveedor')->orderBy('id', 'DESC')->get();
            return response()->json([
                'success' => true,
                'data' => $almacen,
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
        DB::beginTransaction();

        try {
            // 1. Validar datos (Archivos ahora son nullable)
            $request->validate([
                'pdf_file' => 'nullable|mimes:pdf|max:10000',
                'image_file' => 'nullable|image|max:5000',
                'nombreProducto' => 'required|string|max:255',
                'descripcion' => 'required|string',
                'marca' => 'required|string',
                'categoria' => 'required|exists:categorias,id',
                'unidad' => 'required|exists:unidad_medidas,id',
                'proveedor' => 'required|exists:proveedores,id',
                'precioUnit' => 'required|numeric|min:0',
                'cantidad' => 'required|integer|min:1',
                'transferir_inventario' => 'nullable|boolean',
            ]);

            // 2. Validar si ya existe el producto
            $existingProduct = Almacen::where('nombre', $request->nombreProducto)
                ->where('laboratorio', $request->laboratorio ?? null)
                ->where('presentacion', $request->presentacion ?? null)
                ->first();

            if ($existingProduct) {
                return response()->json(['errors' => ['Ya existe un producto con el mismo nombre, laboratorio y presentación.']], 422);
            }

            // Variables para almacenar las rutas finales en S3
            $outputPdfPath = null;
            $fotoProductoPath = null;

            // 3. PROCESAR ARCHIVOS SOLO SI SE ENVIARON
            if ($request->hasFile('pdf_file') && $request->hasFile('image_file')) {

                // Guardado temporal local para FPDI
                $pdfFilePath = $request->file('pdf_file')->store('tmp', 'local');
                $imageFilePath = $request->file('image_file')->store('tmp', 'local');

                $pdfFullPath = storage_path('app/' . $pdfFilePath);
                $imageFullPath = storage_path('app/' . $imageFilePath);

                // 4. Procesar PDF con FPDI
                $fpdi = new Fpdi();
                $pageCount = $fpdi->setSourceFile($pdfFullPath);

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $fpdi->importPage($pageNo);
                    $fpdi->AddPage();
                    $fpdi->useTemplate($templateId, 0, 0);

                    // Coordenadas de firma
                    $x = 20;
                    $y = 251;
                    $textY = $y + 16;

                    $fpdi->Image($imageFullPath, $x, $y, 50);
                    $fpdi->SetFont('Arial', 'B', 12);
                    $fpdi->SetTextColor(0, 0, 0);
                    $fpdi->SetXY($x, $textY);
                    $fpdi->Cell(0, 10, 'Firma almacen', 0, 1, 'L');
                }

                // 5. Guardar PDF firmado temporalmente en disco local
                $tempOutputName = 'temp_signed_' . time() . '.pdf';
                $tempOutputFullPath = storage_path('app/tmp/' . $tempOutputName);
                $fpdi->Output($tempOutputFullPath, 'F');

                // 6. SUBIDA DEFINITIVA A CLOUDFLARE R2 (S3)
                $outputPdfPath = 'documentosFirmados/documentoCompleto_' . time() . '.pdf';
                Storage::disk('s3')->put($outputPdfPath, file_get_contents($tempOutputFullPath));

                // Guardar la firma también como foto del producto
                $fotoProductoPath = 'fotosAlmacen/foto_' . time() . '.jpg';
                Storage::disk('s3')->put($fotoProductoPath, file_get_contents($imageFullPath));

                // 7. LIMPIEZA DE BASURA LOCAL
                Storage::disk('local')->delete([$pdfFilePath, $imageFilePath]);
                if (file_exists($tempOutputFullPath)) {
                    unlink($tempOutputFullPath);
                }
            } elseif ($request->hasFile('image_file')) {
                // Si solo envió la imagen (para usarla como foto del producto)
                $imageFilePath = $request->file('image_file')->store('tmp', 'local');
                $imageFullPath = storage_path('app/' . $imageFilePath);

                $fotoProductoPath = 'fotosAlmacen/foto_' . time() . '.jpg';
                Storage::disk('s3')->put($fotoProductoPath, file_get_contents($imageFullPath));

                Storage::disk('local')->delete([$imageFilePath]);
            } elseif ($request->hasFile('pdf_file')) {
                // Si solo envió el PDF, lo subimos directo a S3 sin firmar
                $outputPdfPath = 'documentosFirmados/documento_' . time() . '.pdf';
                Storage::disk('s3')->put($outputPdfPath, file_get_contents($request->file('pdf_file')));
            }

            // 8. Generar código de producto único
            $codigoProd = $this->generateUniqueProductCode();

            // Lógica del switch "Transferir a Inventario"
            $transferir = $request->boolean('transferir_inventario');
            $cantidadAlmacen = $transferir ? 0 : $request->cantidad;

            // 9. Guardar en la base de datos (Almacen)
            $producto = new Almacen();
            $producto->codigoProd = $codigoProd;
            $producto->idCategoria = $request->categoria;
            $producto->idUnidadMedida = $request->unidad;
            $producto->idProveedor = $request->proveedor;
            $producto->nombre = $request->nombreProducto;
            $producto->marca = $request->marca;
            $producto->laboratorio = $request->laboratorio ?? null;
            $producto->presentacion = $request->presentacion ?? null;
            $producto->lote = $request->lote ?? null;
            $producto->registro_sanitario = $request->registro_sanitario ?? null;
            $producto->fecha_vencimiento = $request->fecha_vencimiento ?? null;
            $producto->descripcion = $request->descripcion;
            $producto->cantidad = $cantidadAlmacen;
            $producto->precioUnit = $request->precioUnit;
            $producto->estado = 1;
            $producto->foto = $fotoProductoPath; // Puede ser null, la BD debe permitirlo
            $producto->save();

            // 10. Si el checkbox estaba marcado, transferir directamente al Inventario
            if ($transferir) {
                $productoInventario = new Inventario();
                $productoInventario->codigoProd = $producto->codigoProd;
                $productoInventario->nombre = $producto->nombre;
                $productoInventario->descripcion = $producto->descripcion;
                $productoInventario->presentacion = $producto->presentacion;
                $productoInventario->marca = $producto->marca;
                $productoInventario->laboratorio = $producto->laboratorio;
                $productoInventario->lote = $producto->lote;
                $productoInventario->registro_sanitario = $producto->registro_sanitario;
                $productoInventario->fecha_vencimiento = $producto->fecha_vencimiento;
                $productoInventario->precio = $producto->precioUnit;
                $productoInventario->idCategoria = $producto->idCategoria;
                $productoInventario->idUnidad = $producto->idUnidadMedida;
                $productoInventario->estado = 1;
                $productoInventario->foto = $producto->foto;
                $productoInventario->stock = $request->cantidad;
                $productoInventario->save();
            }

            // 11. Registro en el Kardex
            $kardex = new Kardex();
            $kardex->idProducto = $producto->id;
            $kardex->idUsuario = auth()->user()->id;
            $kardex->cantidad = $request->cantidad;
            $kardex->tipo_movimiento = 'entrada';
            $kardex->descripcion = $transferir
                ? 'Ingreso directo (Transferido a Inventario)'
                : 'Nuevo ingreso a Almacén';
            $kardex->stock_anterior = 0;
            $kardex->stock_actual = $cantidadAlmacen;
            $kardex->fecha_movimiento = now();
            $kardex->documento = $outputPdfPath; // Puede ser null
            $kardex->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $transferir
                    ? 'Producto guardado en almacén y transferido a inventario correctamente.'
                    : 'Producto ingresado a almacén correctamente.'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            if (isset($tempOutputFullPath) && file_exists($tempOutputFullPath)) {
                unlink($tempOutputFullPath);
            }
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
            ], 500);
        }
    }


    public function acualizarProducto(Request $request, $id)
    {
        // 1. Validar TODOS los campos recibidos
        $validator = Validator::make($request->all(), [
            'nombre' => [
                'required',
                'string',
                'max:100', // max:100 según BD
                $this->uniqueEmpresaSede('almacens', 'nombre', $id),
            ],
            'marca' => [
                'required',
                'string',
                'max:100', // max:100 según BD
                $this->uniqueEmpresaSede('almacens', 'marca', $id),
            ],
            'descripcion' => 'nullable|string|max:255',
            'presentacion' => 'required|string|max:100',
            'laboratorio' => 'required|string|max:100', // Agregado para farmacia
            'registro_sanitario' => 'required|string|max:100',
            'lote' => 'required|string|max:100', // Agregado para farmacia
            'cantidad' => 'required|numeric|min:0',
            'precioUnit' => 'required|numeric|min:0',
            'unidad' => 'required|exists:unidad_medidas,id',
            'categoria' => 'required|exists:categorias,id',
            'proveedor' => 'required|exists:proveedores,id',
            'fecha_vencimiento' => [
                'required',
                'date',
                'after:today'
            ]
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

        DB::beginTransaction();

        try {
            // 2. Buscar el producto en Almacén
            $producto = Almacen::find($id);
            if (!$producto) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Producto no encontrado'
                ], 404);
            }

            // 3. Actualizar los datos en la tabla `almacens`
            $producto->nombre = $request->nombre;
            $producto->descripcion = $request->descripcion;
            $producto->presentacion = $request->presentacion;
            $producto->marca = $request->marca;
            $producto->laboratorio = $request->laboratorio;
            $producto->registro_sanitario = $request->registro_sanitario;
            $producto->lote = $request->lote;
            $producto->precioUnit = $request->precioUnit;
            $producto->cantidad = $request->cantidad; // STOCK almacén
            $producto->fecha_vencimiento = $request->fecha_vencimiento;

            // Llaves foráneas de Almacén
            $producto->idUnidadMedida = $request->unidad;
            $producto->idCategoria = $request->categoria;
            $producto->idProveedor = $request->proveedor;

            $producto->save();

            // 4. Buscar su par en la tabla `inventarios` por el código del producto
            $productoInventario = Inventario::where('codigoProd', $producto->codigoProd)->first();

            if ($productoInventario) {
                // Actualizamos los datos informativos en Inventario
                $productoInventario->nombre = $request->nombre;
                $productoInventario->marca = $request->marca;
                $productoInventario->presentacion = $request->presentacion;
                $productoInventario->laboratorio = $request->laboratorio;
                $productoInventario->lote = $request->lote;
                $productoInventario->registro_sanitario = $request->registro_sanitario;
                $productoInventario->descripcion = $request->descripcion;
                $productoInventario->fecha_vencimiento = $request->fecha_vencimiento;

                // Actualizamos el precio (En inventarios el campo se llama 'precio')
                $productoInventario->precio = $request->precioUnit;

                // Llaves foráneas de Inventario (Mapeo corregido)
                $productoInventario->idCategoria = $request->categoria;
                $productoInventario->idUnidad = $request->unidad; // Ojo: en inventarios se llama 'idUnidad'

                // IMPORTANTE: NO tocamos `$productoInventario->stock`

                $productoInventario->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado correctamente en Almacén e Inventario',
                'data' => $producto
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
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
            'pdf_file' => 'nullable|file|mimes:pdf',
            'image_file' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        DB::beginTransaction();

        try {
            $user = auth()->user();  // Obtiene el usuario actual desde Sanctum
            Log::info('Inicio de la operación de agregar stock.');

            $outputPdfPath = null;

            // 1. Procesar archivos SOLO si fueron enviados
            if ($request->hasFile('pdf_file') && $request->hasFile('image_file')) {
                // Almacenamiento local temporal para procesamiento con FPDI
                $pdfFilePath = $request->file('pdf_file')->store('tmp', 'local');
                $imageFilePath = $request->file('image_file')->store('tmp', 'local');
                Log::info('Archivos almacenados temporalmente:', ['pdf' => $pdfFilePath, 'image' => $imageFilePath]);

                // Verificar existencia de archivos locales temporales
                $pdfFullPath = storage_path('app/' . $pdfFilePath);
                $imageFullPath = storage_path('app/' . $imageFilePath);

                if (!file_exists($pdfFullPath) || !file_exists($imageFullPath)) {
                    Log::error('Uno de los archivos temporales no existe:', ['pdf' => $pdfFullPath, 'image' => $imageFullPath]);
                    return response()->json(['errors' => ['Archivo temporal no encontrado.']], 422);
                }

                // 2. Manipular PDF con FPDI
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
                    $fpdi->Cell(0, 10, 'Firma Almacen', 0, 1, 'C');
                }

                // 3. Generar y guardar el PDF firmado localmente de forma temporal
                $tempOutputName = 'temp_signed_' . time() . '.pdf';
                $tempOutputFullPath = storage_path('app/tmp/' . $tempOutputName);
                $fpdi->Output($tempOutputFullPath, 'F');

                // 4. SUBIDA DEFINITIVA A CLOUDFLARE R2
                $outputPdfPath = 'documentosFirmados/documentoCompleto_' . time() . '.pdf';
                Storage::disk('s3')->put($outputPdfPath, file_get_contents($tempOutputFullPath));
                Log::info('PDF firmado subido a la nube correctamente.', ['path' => $outputPdfPath]);

                // 5. Limpieza de archivos temporales locales
                Storage::disk('local')->delete([$pdfFilePath, $imageFilePath]);
                if (file_exists($tempOutputFullPath)) {
                    unlink($tempOutputFullPath);
                }
            } elseif ($request->hasFile('pdf_file')) {
                // Si solo envió el PDF, lo subimos directo sin firmar
                $outputPdfPath = 'documentosFirmados/documento_' . time() . '.pdf';
                Storage::disk('s3')->put($outputPdfPath, file_get_contents($request->file('pdf_file')));
                Log::info('PDF original subido a la nube correctamente (sin firma).', ['path' => $outputPdfPath]);
            }

            // 6. Actualizar stock del producto
            $producto = Almacen::findOrFail($validatedData['idProductoEdit']);
            $nuevoStock = $producto->cantidad + $validatedData['cantidadIngresar'];
            $producto->cantidad = $nuevoStock;
            $producto->save();
            Log::info('Stock actualizado correctamente.', ['producto_id' => $producto->id, 'nuevo_stock' => $nuevoStock]);

            // 7. Guardar en el Kardex
            $kardex = new Kardex();
            $kardex->idProducto = $producto->id;
            $kardex->idUsuario = $user->id;
            $kardex->cantidad = $validatedData['cantidadIngresar'];
            $kardex->tipo_movimiento = 'entrada';
            $kardex->descripcion = 'Actualización de stock';
            $kardex->stock_anterior = $producto->cantidad - $validatedData['cantidadIngresar']; // El stock anterior real antes del save()
            $kardex->stock_actual = $nuevoStock;
            $kardex->fecha_movimiento = now();
            $kardex->documento = $outputPdfPath; // Puede ser null si no se enviaron archivos, la BD debe permitirlo
            $kardex->save();

            Log::info('Registro en Kardex completado.', ['kardex_id' => $kardex->id]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stock actualizado correctamente',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            // Limpieza de emergencia en caso de error
            if (isset($tempOutputFullPath) && file_exists($tempOutputFullPath)) {
                unlink($tempOutputFullPath);
            }

            Log::error('Error en la operación de agregar stock.', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
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
            $areaOrigen = Area::where('nombre', 'almacen')->first();

            if (!$areaOrigen) {
                DB::rollBack();
                return response()->json(['message' => 'Área de origen no encontrada, talves quisiste elegir Almacen'], 404);
            }
            $idAreaOrigen = $areaOrigen->id;

            // Manejar la subida del archivo directamente a Cloudflare R2
            $documentoPath = null;
            if ($request->hasFile('archivo')) {
                // Generamos un nombre único con su extensión original
                $extension = $request->file('archivo')->getClientOriginalExtension();
                $nombreArchivo = 'documentoCompleto_' . time() . '.' . $extension;

                // storeAs(carpeta_destino, nombre_archivo, disco)
                // Esto devuelve la ruta corta: "documentosFirmados/documentoCompleto_123.pdf"
                $documentoPath = $request->file('archivo')->storeAs('documentosFirmados', $nombreArchivo, 's3');
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

                // Registrar movimiento (guardando la ruta corta del documento)
                Movimiento::create([
                    'idProductoAlmacen' => $productoAlmacen->id,
                    'idAreaOrigen' => $idAreaOrigen,
                    'idAreaDestino' => $request->idDestino,
                    'idUsuario' => $user->id,
                    'idEmpresa' => $user->idEmpresa,
                    'tipo_movimiento' => 'transferencia',
                    'cantidad' => $producto['cantidad'],
                    'fecha_movimiento' => now(),
                    'documento' => $documentoPath
                ]);

                // Actualizar stock en almacén
                $stockAnterior = $productoAlmacen->cantidad;
                $productoAlmacen->cantidad -= $producto['cantidad'];
                $productoAlmacen->save();

                // Registrar en kardex (guardando la ruta corta del documento)
                Kardex::create([
                    'idProducto' => $productoAlmacen->id,
                    'idUsuario' => $user->id,
                    'cantidad' => $producto['cantidad'],
                    'tipo_movimiento' => 'salida',
                    'descripcion' => 'Salida por transferencia de almacén a área',
                    'stock_anterior' => $stockAnterior,
                    'stock_actual' => $productoAlmacen->cantidad,
                    'fecha_movimiento' => now(),
                    'documento' => $documentoPath // <--- AQUÍ VA LA RUTA CORTA
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
