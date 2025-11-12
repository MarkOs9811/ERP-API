<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\CategoriaPlato;
use App\Models\Plato;
use App\Traits\EmpresaSedeValidation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GestionMenusController extends Controller
{

    use EmpresaSedeValidation;
    public function getCategoria()
    {
        try {
            $categoria = CategoriaPlato::get();
            return response()->json(['success' => true, 'data' => $categoria], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener categorias:' . $e->getMessage(),], 500);
        }
    }

    public function getCategoriaTrue()
    {
        try {
            $categoria = CategoriaPlato::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $categoria], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener categorias:' . $e->getMessage(),], 500);
        }
    }

    public function getPlatos()
    {
        try {
            $platos = Plato::with('categoria')->orderBy('id', 'Desc')->get();
            return response()->json(['success' => true, 'data' => $platos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener categorias:' . $e->getMessage(),], 500);
        }
    }

    public function registerCategoria(Request $request)
    {
        try {
            // Log de inicio del proceso
            Log::info('Iniciando el registro de una nueva categoría', ['request' => $request->all()]);

            // Validar los datos de entrada
            $request->validate([
                'nombre' => 'required|string|max:255',
            ]);

            // Comprobar si ya existe una categoría con el mismo nombre
            $existeCategoria = CategoriaPlato::where('nombre', $request->nombre)->exists();
            if ($existeCategoria) {
                Log::warning('Intento de registrar un nombre de categoría ya existente', ['nombre' => $request->nombre]);

                return response()->json([
                    'success' => false,
                    'message' => 'El nombre de la categoría ya está registrado.',
                ], 400); // 400: Bad Request
            }

            // Crear una nueva categoría
            $categoria = new CategoriaPlato;
            $categoria->nombre = $request->nombre;
            $categoria->estado = 1;
            $categoria->save();

            Log::info('Categoría registrada exitosamente', ['categoria' => $categoria]);

            return response()->json([
                'success' => true,
                'message' => 'Categoría registrada exitosamente.',
                'data' => $categoria,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al registrar la categoría', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al registrar la categoría.',
                'error' => $e->getMessage(),
            ], 500); // 500: Internal Server Error
        }
    }

    public function deleteCategoria($id)
    {
        try {
            // Busca el categroiaPlato por su ID
            $categoria = CategoriaPlato::find($id);

            // Verifica si el categoria existe
            if (!$categoria) {
                return response()->json(['success' => false, 'message' => 'categoria no encontrado']);
            }

            // Cambia el estado del categoria de 1 a 0
            $categoria->estado = 0; // Cambia el estado
            $categoria->save(); // Guarda los cambios

            return response()->json(['success' => true, 'message' => 'Categoria eliminada correctamente'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Ocurrio un error' . $e->getMessage()], 500);
        }
    }

    public function activarCategoria($id)
    {
        try {
            $categoria = CategoriaPlato::find($id);
            if (!$categoria) {
                return response()->json(['success' => false, 'message' => 'Categoria no encontrada']);
            }
            $categoria->estado = 1;
            $categoria->save();
            return response()->json(['success' => true, 'message' => 'Categoria activada'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Ocurrio un error' . $e->getMessage()], 500);
        }
    }

    public function updateCategoria(Request $request, $id)
    {
        try {
            Log::info('Datos recibidos para actualizar:', [$id]); // Verifica qué datos están llegando

            $request->validate([
                'nombre' => 'required|string|max:255',
            ]);
            $nombreFormateado = strtolower($request->nombre);
            $categoria = CategoriaPlato::find($id);
            if (!$categoria) {
                return response()->json(['success' => false, 'message' => 'Categoría no encontrada'], 404);
            }

            $categoria->nombre = $nombreFormateado;
            $categoria->save();

            return response()->json(['success' => true, 'message' => 'Categoría actualizada']);
        } catch (\Exception $e) {
            Log::error('Error al actualizar categoría:', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error interno del servidor'], 500);
        }
    }

    public function addPlatos(Request $request)
    {
        try {
            $validated = $request->validate([
                'nombre' => ([
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresaSede('platos', 'nombre'),
                ]),
                'precio' => 'numeric|required|min:0',
                'descripcion' => 'string|max:255',
                'categoria' => 'required|numeric|exists:categoria_platos,id',
                'foto' => 'nullable|image|mimes:jpeg,jpg,svg|max:2048',
            ]);

            $plato = new Plato();
            $plato->idCategoria = $validated['categoria'];
            $plato->nombre = strtolower($validated['nombre']);
            $plato->precio = $validated['precio'];
            $plato->descripcion = $validated['descripcion'];

            if ($request->hasFile('foto')) {
                $fotoPath = $request->file('foto')->store('fotosPlatos', 'public');

                $plato->foto = $fotoPath;
            }

            $plato->save();
            return response()->json(['success' => true, 'message' => 'Registro exitoso'], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación.',
                'errors' => $e->errors(), // Detalle de los errores de validación
            ], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updatePlato(Request $request, $id)
    {
        try {
            // Validación
            Log::debug("Validando datos...");
            $validatedData = $request->validate([
                'nombre' => [
                    'required',
                    'string',
                    'max:255',
                    $this->uniqueEmpresaSede('platos', 'nombre', $id),
                ],
                'descripcion' => 'required|string|max:500',
                'precio' => 'required|numeric|min:0',
                'categoria' => 'required|exists:categoria_platos,id',
                'foto' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);
            Log::debug("Validación exitosa", $validatedData);

            // Buscar plato
            Log::debug("Buscando plato con ID: $id");
            $plato = Plato::find($id);

            if (!$plato) {
                Log::warning("Plato no encontrado", ['id' => $id]);
                return response()->json(['success' => false, 'message' => 'Plato no encontrado'], 404);
            }

            // Actualización de campos
            Log::debug("Actualizando campos básicos...");
            $plato->nombre = strtolower($request->nombre);
            $plato->precio = $request->precio;
            $plato->descripcion = $request->descripcion;
            $plato->idCategoria = $request->categoria;

            // Manejo de imagen
            if ($request->hasFile('foto')) {
                Log::debug("Procesando nueva imagen...");

                if ($plato->foto && Storage::disk('public')->exists($plato->foto)) {
                    Log::debug("Eliminando imagen anterior...");
                    Storage::disk('public')->delete($plato->foto);
                }

                $fotoPath = $request->file('foto')->store('fotosPlatos', 'public');
                $plato->foto = $fotoPath;
                Log::debug("Nueva imagen guardada", ['path' => $fotoPath]);
            } else {
                Log::debug("No se recibió nueva imagen");
            }

            // Guardar cambios
            Log::debug("Guardando cambios en BD...");
            $plato->save();

            Log::info("Plato actualizado exitosamente", [
                'plato_id' => $plato->id,
                'changes' => $plato->getChanges()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Plato actualizado correctamente',
                'foto_url' => $plato->foto ? Storage::url($plato->foto) : null
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error("Error de validación", [
                'errors' => $e->errors(),
                'input' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("Error de base de datos", [
                'error' => $e->getMessage(),
                'query' => $e->getSql(),
                'bindings' => $e->getBindings()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error en la base de datos: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error("Error inesperado", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor: ' . $e->getMessage()
            ], 500);
        }
    }
}
