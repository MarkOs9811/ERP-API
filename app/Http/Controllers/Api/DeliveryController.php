<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromocionesApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Dotenv\Exception\ValidationException;
use App\Models\ConfiguracionDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    public function getPromociones()
    {
        try {
            $promociones = PromocionesApp::with('plato')->get();

            return response()->json(['success' => true, 'data' => $promociones], 200);
        }
        catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function savePromociones(Request $request)
    {
        try {

            $request->validate([
                'idPlato' => 'required|integer',
                'titulo' => 'required|string|max:255',
                'porcentaje_descuento' => 'required|numeric',
                'precio_promocional' => 'required|numeric',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date',
                'imagen_banner' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            $promociones = new PromocionesApp();


            $promociones->idPlato = $request->idPlato;
            $promociones->titulo = $request->titulo;
            $promociones->porcentaje_descuento = $request->porcentaje_descuento;
            $promociones->precio_promocional = $request->precio_promocional;
            $promociones->fecha_inicio = $request->fecha_inicio;
            $promociones->fecha_fin = $request->fecha_fin;
            $promociones->estado = $request->has('estado') ? $request->estado : 1;

            // 2. Guardado de imagen
            if ($request->hasFile('imagen_banner')) {
                $archivo = $request->file('imagen_banner');
                $nombreArchivo = time() . '_' . $archivo->getClientOriginalName();


                $path = $archivo->storeAs('fotosPromociones', $nombreArchivo, 'public');
                $promociones->imagen_banner = $path;
            }

            $promociones->save();

            return response()->json([
                'success' => true,
                'data' => $promociones
            ], 200);

        }
        catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updatePromociones(Request $request, $id)
    {
        try {
            // Buscamos la promoción que queremos editar
            $promociones = PromocionesApp::findOrFail($id);

            // Validamos. OJO: imagen_banner es 'nullable' por si solo editan textos
            $request->validate([
                'idPlato' => 'required|integer',
                'titulo' => 'required|string|max:255',
                'porcentaje_descuento' => 'required|numeric',
                'precio_promocional' => 'required|numeric',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date',
                'imagen_banner' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            // Actualizamos los datos de texto
            $promociones->idPlato = $request->idPlato;
            $promociones->titulo = $request->titulo;
            $promociones->porcentaje_descuento = $request->porcentaje_descuento;
            $promociones->precio_promocional = $request->precio_promocional;
            $promociones->fecha_inicio = $request->fecha_inicio;
            $promociones->fecha_fin = $request->fecha_fin;

            // Manejo de la imagen (solo si el frontend envió una nueva)
            if ($request->hasFile('imagen_banner')) {

                // 1. Si ya existía una imagen anterior, la borramos del disco para ahorrar espacio
                if ($promociones->imagen_banner) {
                    Storage::disk('public')->delete($promociones->imagen_banner);
                }

                // 2. Guardamos la nueva imagen exactamente como lo hicimos en el método save
                $archivo = $request->file('imagen_banner');
                $nombreArchivo = time() . '_' . $archivo->getClientOriginalName();
                $path = $archivo->storeAs('fotosPromociones', $nombreArchivo, 'public');

                $promociones->imagen_banner = $path;
            }

            $promociones->save();

            return response()->json([
                'success' => true,
                'data' => $promociones,
                'message' => 'Promoción actualizada correctamente'
            ], 200);

        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateEstadoPromociones(Request $request, $id)
    {
        try {
            $promociones = PromocionesApp::findOrFail($id);
            $promociones->estado = $request->estado;
            $promociones->save();

            return response()->json([
                'success' => true,
                'data' => $promociones,
                'message' => 'Promoción actualizada correctamente'
            ], 200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deletePromociones(Request $request, $id)
    {
        try {
            $promociones = PromocionesApp::findOrFail($id);
            if ($promociones->imagen_banner) {
                if (Storage::disk('public')->exists($promociones->imagen_banner)) {
                    Storage::disk('public')->delete($promociones->imagen_banner);
                }
            }
            $promociones->delete();

            return response()->json([
                'success' => true,
                'message' => 'Promoción eliminada correctamente'
            ], 200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar: ' . $e->getMessage()
            ], 500);
        }
    }


    // ZONAS Y TARIFAS}

    public function saveConfiguracionZonas(Request $request)
    {
        try {

            $request->validate([
                'sede_id' => 'required|integer',
                'costo_base_delivery' => 'required|numeric',
                'costo_prioridad' => 'nullable|numeric',
                'tiempo_min' => 'required|integer',
                'tiempo_max' => 'required|integer',
                'propinas_sugeridas' => 'nullable|json',
            ]);


            $configuracion = ConfiguracionDelivery::updateOrCreate(
            [
                'idSede' => $request->sede_id,
            ],
            [
                'costo_base_delivery' => $request->costo_base_delivery,
                'costo_prioridad' => $request->costo_prioridad,
                'tiempo_min' => $request->tiempo_min,
                'tiempo_max' => $request->tiempo_max,
                'propinas_sugeridas' => $request->propinas_sugeridas ? json_decode($request->propinas_sugeridas, true) : null,
                'estado' => '1',
            ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Configuración de zona guardada correctamente',
                'data' => $configuracion
            ], 200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateEstadoZonas(Request $request, $id)
    {
        try {
            $configuracion = ConfiguracionDelivery::findOrFail($id);
            $configuracion->estado = $request->estado;
            $configuracion->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado de zona actualizado correctamente',
                'data' => $configuracion
            ], 200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado: ' . $e->getMessage()
            ], 500);
        }
    }

    public function deleteConfiguracionZonas(Request $request, $id)
    {
        try {
            $configuracion = ConfiguracionDelivery::findOrFail($id);
            $configuracion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Configuración de zona eliminada correctamente'
            ], 200);

        }
        catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar configuración: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateRepartidores(Request $request, $id)
    {
        try {
            // 1. Buscamos al usuario (repartidor) usando el ID que viene en la URL
            $user = User::findOrFail($id);

            // Navegamos por tus relaciones para llegar a la tabla Persona
            // (Asumo que tienes definidas las relaciones empleado y persona en tus modelos)
            $persona = $user->empleado->persona;

            // 2. Validamos los datos de entrada
            $request->validate([
                'nombres' => 'required|string|max:255',
                'apellidos' => 'required|string|max:255',
                'dni' => 'required|string|min:8|max:12',
                'telefono' => 'required|string|max:20',
                'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            ]);

            // Iniciamos la transacción para asegurar que ambas tablas se guarden juntas
            DB::beginTransaction();

            // 3. Actualizamos la tabla Persona
            $persona->nombre = $request->nombres;
            $persona->apellidos = $request->apellidos;
            $persona->telefono = $request->telefono;
            $persona->documento_identidad = $request->dni;

            // Lógica rápida: si tiene exactamente 8 dígitos, asumimos DNI. Si tiene más, Carné de Extranjería.
            $persona->tipo_documento = strlen($request->dni) == 8 ? 'DNI' : 'CE';

            // Si envió un email, lo actualizamos también en el campo correo de persona
            if ($request->filled('email')) {
                $persona->correo = $request->email;
            }

            $persona->save();

            // 4. Actualizamos la tabla User
            if ($request->filled('email')) {
                $user->email = $request->email;
                $user->save();
            }

            // Confirmamos que todo salió bien y guardamos permanentemente
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Repartidor actualizado correctamente',
                // Retornamos el usuario con sus relaciones frescas para que tu frontend se actualice
                'data' => $user->load('empleado.persona')
            ], 200);

        }
        catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack(); // Revertimos cambios si falla la validación
            return response()->json([
                'success' => false,
                'message' => 'Error de validación: ' . json_encode($e->errors())
            ], 422);
        }
        catch (\Exception $e) {
            DB::rollBack(); // Revertimos cambios si hay error de servidor
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar repartidor: ' . $e->getMessage()
            ], 500);
        }
    }
}
