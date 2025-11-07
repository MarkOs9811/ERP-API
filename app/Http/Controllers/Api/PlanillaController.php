<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\AdelantoSueldo;
use App\Models\AjustesPlanilla;
use App\Models\Asistencia;
use App\Models\Bonificacione;
use App\Models\CuentasContables;
use App\Models\Deduccione;
use App\Models\Departamento;
use App\Models\DetalleLibro;
use App\Models\Distrito;
use App\Models\Empleado;
use App\Models\EmpleadoBonificacione;
use App\Models\EmpleadoDeduccione;
use App\Models\HoraExtras;
use App\Models\LibroDiario;
use App\Models\Pago;
use App\Models\PeriodoNomina;
use App\Models\Persona;
use App\Models\Provincia;
use App\Models\TipoContrato;
use App\Models\User;
use App\Models\Vacacione;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanillaController extends Controller
{



    public function registroPlanillaEmpleado(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Datos personales
            'nombre' => 'required|string|max:255',
            'apellidos' => 'required|string|max:255',
            'tipo_documento' => 'required|string|in:DNI,Carnet De Extranjeria',
            'num_documento' => 'required|numeric|unique:personas,documento_identidad|digits_between:8,10',
            'telefono' => 'required|numeric|unique:personas,telefono',
            'correo' => 'required|email|unique:personas,correo',
            'direccion' => 'required|string|max:255',
            'fecha_nacimiento' => 'required|date|before_or_equal:-18 years',

            // Ubicación
            'departamento' => 'required|integer|exists:departamentos,id',
            'provincia' => 'required|integer|exists:provincias,id',
            'distrito' => 'required|integer|exists:distritos,id',

            // Contrato
            'contrato' => 'required|integer|exists:tipo_contratos,id',
            'fecha_contrato' => 'required|date',
            'fecha_fin_contrato' => 'required|date|after_or_equal:fecha_contrato',

            // Datos laborales
            'area' => 'required|integer|exists:areas,id',
            'cargo' => 'required|integer|exists:cargos,id',
            'horario' => 'required|exists:horarios,id',
            'salario' => 'required|numeric|min:1025|regex:/^\d+(\.\d{1,2})?$/',

            // Beneficios
            'deducciones' => 'nullable|array',
            'deducciones.*' => 'integer|exists:deducciones,id',
            'bonificaciones' => 'nullable|array',
            'bonificaciones.*' => 'integer|exists:bonificaciones,id',

            'fotoPerfil' => [
                'required',
                'image',
                'mimes:jpeg,png,jpg',
                'max:2048',
            ]
        ]);

        if ($validator->fails()) {
            Log::error('Validación fallida', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {


            // Guardar imagen
            $path = $request->file('fotoPerfil')->store('fotos', 'public');


            // Crear persona
            $persona = Persona::create([
                'idDistrito' => $request->input('distrito'),
                'nombre' => $request->input('nombre'),
                'apellidos' => $request->input('apellidos'),
                'fecha_nacimiento' => $request->input('fecha_nacimiento'),
                'tipo_documento' => $request->input('tipo_documento'),
                'documento_identidad' => $request->input('num_documento'),
                'direccion' => $request->input('direccion'),
                'telefono' => $request->input('telefono'),
                'correo' => $request->input('correo'),
                'estado' => 1,
            ]);


            // Crear empleado
            $empleado = Empleado::create([
                'idPersona' => $persona->id,
                'idArea' => $request->input('area'),
                'idCargo' => $request->input('cargo'),
                'idHorario' => $request->input('horario'),
                'idContrato' => $request->input('contrato'),
                'fecha_contrato' => $request->input('fecha_contrato'),
                'fecha_fin_contrato' => $request->input('fecha_fin_contrato'),
                'salario' => $request->input('salario'),
                'estado' => 1,
            ]);

            // Registrar deducciones y bonificaciones
            if ($request->has('deducciones')) {
                foreach ($request->input('deducciones') as $deduccion) {
                    EmpleadoDeduccione::create([
                        'idEmpleado' => $empleado->id,
                        'idDeduccion' => $deduccion,
                    ]);
                }
            }

            if ($request->has('bonificaciones')) {
                foreach ($request->input('bonificaciones') as $bonificacion) {
                    EmpleadoBonificacione::create([
                        'idEmpleado' => $empleado->id,
                        'idBonificaciones' => $bonificacion,
                    ]);
                }
            }

            // Crear usuario
            $nombre = explode(' ', $request->input('nombre'))[0];
            $email_base = strtolower($nombre) . '.123';
            $email = $email_base;
            $counter = 123;

            while (User::where('email', $email)->exists()) {
                $counter++;
                $email = strtolower($nombre) . '.' . $counter;
            }

            $user = User::create([
                'idEmpleado' => $empleado->id,
                'email' => $email,
                'password' => Hash::make('123'),
                'estadoIncidencia' => 'libre',
                'estado' => 1,
                'fotoPerfil' => $path,
            ]);


            // Obtener roles asociados al cargo
            $roles = DB::table('cargo_roles')->where('idCargo', $request->input('cargo'))->pluck('idRole');

            foreach ($roles as $roleId) {
                // Crear registros en la tabla role_users
                $roleUser = DB::table('role_users')->insertGetId([
                    'idUsuarios' => $user->id,
                    'idRole' => $roleId,
                ]);
                Log::info('Role asignado a usuario', ['role_user_id' => $roleUser]);

                // Asignar permisos por cada role_user
                foreach ([1, 2, 3, 4] as $permisoId) { // Permisos: Crear, Ver, Eliminar, Actualizar
                    DB::table('user_rol_permisos')->insert([
                        'idRolUser' => $roleUser,
                        'idPermiso' => $permisoId,
                    ]);
                    Log::info('Permiso asignado a role_user', ['role_user_id' => $roleUser, 'permiso_id' => $permisoId]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empleado registrado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en el proceso de registro', ['exception' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'errors' => ['exception' => [$e->getMessage()]]
            ], 500);
        }
    }

    public function getPlanilla()
    {

        try {
            $userAuth = Auth::user();

            if (!$userAuth) {
                Log::warning('getPlanilla - Intento de acceso sin autenticación.');
                return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
            }

            Log::info('getPlanilla - Usuario autenticado:', [
                'userId' => $userAuth->id,
                'idEmpresa' => $userAuth->idEmpresa,
                'idSede' => $userAuth->idSede
            ]);


            $periodoActua = PeriodoNomina::where('idEmpresa', $userAuth->idEmpresa)
                ->where('idSede', $userAuth->idSede)
                ->whereIn('estado', [1, 2])
                ->first();

            // --- 2. Manejar si no hay un período activo ---
            if (!$periodoActua) {
                // Log de advertencia si no se encuentra el período
                Log::warning('getPlanilla - No se encontró período de nómina activo.', [
                    'idEmpresa' => $userAuth->idEmpresa,
                    'idSede' => $userAuth->idSede
                ]);

                return response()->json([
                    'success' => false,
                    'data' => [],
                    'message' => 'No se encontró un período de nómina activo para esta empresa y sede.',
                ], 404);
            }

            // Log del período encontrado
            Log::info('getPlanilla - Período activo encontrado:', $periodoActua->toArray());

            // --- 3. Consulta base con "Constrained Eager Loading" ---
            $query = User::with([
                'empleado.persona',
                'empleado.cargo',
                'empleado.horario',
                'empleado.contrato',
                'empleado.area',
                'empleado.asistencias' => function ($query) use ($periodoActua) {
                    $query->where('idPeriodo', $periodoActua->id);
                },
                'empleado.pagos' => function ($query) use ($periodoActua) {
                    $query->where('idPeriodo', $periodoActua->id);
                },
            ])
                ->where('idEmpresa', $userAuth->idEmpresa)
                ->where('idSede', $userAuth->idSede)
                ->orderBy('id', 'desc');

            $usuarios = $query->get();


            $fechaInicio = Carbon::parse($periodoActua->fecha_inicio);
            $fechaFin = Carbon::parse($periodoActua->fecha_fin);
            $totalDiasPeriodo = $fechaInicio->diffInDays($fechaFin) + 1;

            $usuariosArray = $usuarios->map(function ($user) use ($totalDiasPeriodo) {
                $empleado = $user->empleado;

                if (!$empleado) {

                    Log::warning('getPlanilla - Usuario sin empleado asociado:', ['userId' => $user->id]);
                    return null;
                }

                $diasTrabajados = $empleado->asistencias->count();

                $porcentajeDiasTrabajados = $totalDiasPeriodo > 0
                    ? min(100, ($diasTrabajados / $totalDiasPeriodo) * 100)
                    : 0;

                return [
                    'id' => $user->id,
                    'nombre_completo' => ucwords(($empleado->persona->nombre ?? '') . ' ' . ($empleado->persona->apellidos ?? '')),
                    'documento_identidad' => $empleado->persona->documento_identidad ?? '',
                    'dias_trabajados' => $diasTrabajados,
                    'porcentaje_dias_trabajados' => round($porcentajeDiasTrabajados, 2),
                    'cargo' => ucwords($empleado->cargo->nombre ?? ''),
                    'contrato' => ucwords($empleado->contrato->nombre ?? ''),
                    'area' => ucwords($empleado->area->nombre ?? ''),
                    'salario_neto' => optional($empleado->pagos->last())->salario_neto,
                    'usuario' => [
                        'fotoPerfil' => $user->fotoPerfil,
                        'email' => $user->email,
                    ],
                ];
            })->filter();

            return response()->json([
                'success' => true,
                'data' => $usuariosArray->values(),
                'message' => 'Datos de planilla obtenidos correctamente.',
            ], 200);
        } catch (\Exception $e) {


            Log::error('getPlanilla - Ha ocurrido un error:', [
                'mensaje' => $e->getMessage(),
                'linea' => $e->getLine(),
                'archivo' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Error al obtener los datos de planilla: ' . $e->getMessage(),
            ], 500);
        }
    }
    public function getEmpleadoPerfil($idEmpleado)
    {
        try {
            $usuario = User::with(
                'empleado.persona',
                'empleado.cargo',
                'empleado.area',
                'empleado.contrato',
                'empleado.horario'
            )->find($idEmpleado);

            if (!$usuario || !$usuario->empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }
            $empleado = $usuario->empleado;

            if (!$empleado) {
                return null; // Evita errores si algún usuario no tiene empleado asociado
            }

            $diasTrabajados = $empleado->asistencias
                ->filter(fn($a) => Carbon::parse($a->fechaEntrada)->isSameMonth(now()))
                ->count();

            $porcentajeDiasTrabajados = min(100, ($diasTrabajados / 30) * 100);

            $vacaciones = Vacacione::where('idUsuario', $usuario->id)->whereYear('fecha_inicio', Carbon::now()->year)->get();

            // LAS BONIFICACIONES VIENES DE LA RELACION DE EMPLEADO CON BONIFICACIONES, ESTA EN TABLA PIVOT empleado_bonificaciones
            $bonificaciones = $empleado->bonificaciones()->where('estado', 1)->get();
            $deducciones = $empleado->deducciones()->where('estado', 1)->get();

            $data = [
                'usuario' => $usuario,
                'dias_trabajados' => $diasTrabajados,
                'porcentaje_dias_trabajados' => $porcentajeDiasTrabajados,
                'vacaciones' => $porcentajeDiasTrabajados,
                'vacacionesContados' => $vacaciones,
                'bonificaciones' => $bonificaciones,
                'deducciones' => $deducciones,
            ];

            return response()->json(['success' => true, 'data' => $data], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
    public function getTipoContrato()
    {
        try {
            $contratos = TipoContrato::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $contratos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function getBonificaciones()
    {
        try {
            $bonificacion = Bonificacione::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $bonificacion], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
    public function getDeducciones()
    {
        try {
            $deducciones = Deduccione::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $deducciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function getDepartamento()
    {
        try {
            $departamento = Departamento::where('estado', 1)->get();
            return response()->json(['success' => true, 'data' => $departamento], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }
    public function getProvincia($idDepartamento)
    {
        try {
            $provincias =  Provincia::where('estado', 1)->where('idDepartamento', $idDepartamento)->get();
            return response()->json(['success' => true, 'data' => $provincias], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function getDistrito($idProvincia)
    {
        try {
            $distritos = Distrito::where('estado', 1)->where('idProvincia', $idProvincia)->get();
            return response()->json(['success' => true, 'data' => $distritos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function getHorasExtras()
    {
        try {
            $horasExtras = HoraExtras::with('usuario.empleado.persona')->get();
            return response()->json(['success' => true, 'data' => $horasExtras], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function storeHorasExtras(Request $request)
    {
        try {
            Log::info('Inicio del método storeHorasExtras', ['request' => $request->all()]);

            // Convertir el array de objetos a un array de IDs
            $usuarios = collect($request->input('empleados'))->pluck('id')->toArray();
            Log::info('IDs de usuarios extraídos', ['usuarios' => $usuarios]);

            // Reemplazar el campo 'empleados' con los IDs extraídos
            $request->merge(['empleados' => $usuarios]);

            $validator = Validator::make($request->all(), [
                'empleados' => 'required|array|min:1',
                'empleados.*' => 'required|exists:users,id',
                'fecha' => 'required|date',
                'horas_trabajadas' => 'required|numeric|min:1',
            ]);

            Log::info('Validación de datos', ['datos_validados' => $request->all()]);
            if ($validator->fails()) {
                Log::error('Errores de validación', ['errors' => $validator->errors()]);
                return response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422);
            }

            $fecha = $request->fecha;
            $horasTrabajadas = $request->horas_trabajadas;
            $mes_actual = Carbon::now()->format('Y-m');
            Log::info('Datos procesados', ['fecha' => $fecha, 'horas_trabajadas' => $horasTrabajadas, 'mes_actual' => $mes_actual]);

            $resultados = [];

            foreach ($request->empleados as $usuario_id) {
                Log::info('Procesando usuario', ['usuario_id' => $usuario_id]);

                try {
                    // Verificar si ya tiene horas extras registradas para la misma fecha
                    $fecha_repetida = HoraExtras::where('idUsuario', $usuario_id)
                        ->where('fecha', $fecha)
                        ->exists();

                    if ($fecha_repetida) {
                        Log::warning('Horas extras ya registradas para la fecha', ['usuario_id' => $usuario_id, 'fecha' => $fecha]);
                        $resultados[] = [
                            'usuario_id' => $usuario_id,
                            'success' => false,
                            'message' => 'El usuario ya tiene registrada una hora extra para la fecha indicada.',
                        ];
                        continue;
                    }

                    // Verificar horas acumuladas en el mes actual
                    $horas_en_el_mes = HoraExtras::where('idUsuario', $usuario_id)
                        ->whereYear('fecha', '=', substr($mes_actual, 0, 4))
                        ->whereMonth('fecha', '=', substr($mes_actual, 5, 2))
                        ->sum('horas_trabajadas');

                    Log::info('Horas acumuladas en el mes', ['usuario_id' => $usuario_id, 'horas_en_el_mes' => $horas_en_el_mes]);

                    if ($horas_en_el_mes + $horasTrabajadas > 6) {
                        Log::warning('Límite de horas extras excedido', ['usuario_id' => $usuario_id, 'horas_en_el_mes' => $horas_en_el_mes, 'horas_trabajadas' => $horasTrabajadas]);
                        $resultados[] = [
                            'usuario_id' => $usuario_id,
                            'success' => false,
                            'message' => 'El usuario no puede tener más de 6 horas extras en el presente mes.',
                        ];
                        continue;
                    }

                    // Obtener información del usuario
                    $usuario = User::with('empleado')->where('id', $usuario_id)->first();
                    $pagoPorHora = $usuario->empleado->cargo->pagoPorHoras;
                    Log::info('Información del usuario obtenida', ['usuario_id' => $usuario_id, 'pagoPorHora' => $pagoPorHora]);

                    $estado = Carbon::parse($fecha)->isBefore(Carbon::today()) ? 1 : 0;

                    $totalPagoHorasExtras = 0;

                    // Calcular el pago total por horas extras
                    if ($horasTrabajadas <= 2) {
                        $totalPagoHorasExtras += $horasTrabajadas * ($pagoPorHora * 1.25);
                    } else {
                        $totalPagoHorasExtras += (2 * ($pagoPorHora * 1.25)) + (($horasTrabajadas - 2) * ($pagoPorHora * 1.35));
                    }
                    Log::info('Pago total calculado', ['usuario_id' => $usuario_id, 'totalPagoHorasExtras' => $totalPagoHorasExtras]);

                    // Registrar las horas extras
                    $horasExtra = new HoraExtras();
                    $horasExtra->idUsuario = $usuario_id;
                    $horasExtra->idSede = auth()->user()->idSede;
                    $horasExtra->fecha = $fecha;
                    $horasExtra->horas_trabajadas = $horasTrabajadas;
                    $horasExtra->pagoTotal = $totalPagoHorasExtras;
                    $horasExtra->estado = $estado;
                    $horasExtra->save();

                    Log::info('Horas extras registradas', ['usuario_id' => $usuario_id, 'horasExtra' => $horasExtra]);

                    $resultados[] = [
                        'usuario_id' => $usuario_id,
                        'success' => true,
                        'message' => 'Horas extras agregadas correctamente.',
                    ];
                } catch (\Exception $e) {
                    Log::error('Error al procesar horas extras', ['usuario_id' => $usuario_id, 'error' => $e->getMessage()]);
                    $resultados[] = [
                        'usuario_id' => $usuario_id,
                        'success' => false,
                        'message' => 'Error al crear la hora extra: ' . $e->getMessage(),
                    ];
                }
            }

            // Manejo de respuesta para un solo usuario
            if (count($resultados) === 1) {
                return response()->json($resultados[0], $resultados[0]['success'] ? 200 : 422);
            }

            // Manejo de respuesta para múltiples usuarios
            return response()->json([
                'success' => true,
                'resultados' => $resultados,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error inesperado en el método storeHorasExtras', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado al procesar las horas extras.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function getAdelandoSueldo()
    {
        try {
            // Obtener los adelantos de sueldo
            $adelandoSueldos = AdelantoSueldo::with('usuario.empleado.persona')->get();
            return response()->json(['success' => true, 'data' => $adelandoSueldos], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function storeAdelantoSueldo(Request $request)
    {
        try {
            Log::info('Inicio del método storeAdelantoSueldo', ['request' => $request->all()]);

            // Validar datos iniciales
            $validator = Validator::make($request->all(), [
                'empleados' => 'required|array|min:1',
                'empleados.*' => 'required|exists:users,id',
                'fecha' => 'required|date',
                'monto' => 'required|numeric|min:0',
                'documento' => 'nullable|file|mimes:pdf|max:2048',
                'descripcion' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                Log::error('Errores de validación', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $fecha = $request->fecha;
            $monto = $request->monto;
            $errores = [];
            $usuariosValidos = [];

            // Validar cada usuario antes de registrar
            foreach ($request->empleados as $usuario_id) {
                Log::info('Validando usuario', ['usuario_id' => $usuario_id]);

                $user = User::with('empleado.persona', 'empleado.cargo')->find($usuario_id);

                if (!$user || !$user->empleado || $user->empleado->estado !== 1) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El empleado no está activo.',
                    ];
                    continue;
                }

                $documentoIdentidad = $user->empleado->persona->documento_identidad;

                // Verificar asistencias
                $asistencias = Asistencia::where('codigoUsuario', $documentoIdentidad)->count();
                if ($asistencias < 5) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El empleado debe tener al menos 5 asistencias.',
                    ];
                    continue;
                }

                // Verificar si ya se adelantó sueldo este mes
                $existeAdelanto = AdelantoSueldo::where('idUsuario', $usuario_id)
                    ->whereMonth('fecha', date('m', strtotime($fecha)))
                    ->whereYear('fecha', date('Y', strtotime($fecha)))
                    ->exists();

                if ($existeAdelanto) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El empleado ya recibió un adelanto este mes.',
                    ];
                    continue;
                }

                // Verificar si la fecha ya existe
                $fechaRepetida = AdelantoSueldo::where('idUsuario', $usuario_id)
                    ->where('fecha', $fecha)
                    ->exists();

                if ($fechaRepetida) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'La fecha del adelanto ya existe.',
                    ];
                    continue;
                }

                // Verificar el salario del empleado
                $salarioMensual = $user->empleado->cargo->salario ?? 0;
                if ($salarioMensual <= 0) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El salario del empleado no está definido.',
                    ];
                    continue;
                }

                // Verificar el 50% del salario
                $maxAdelanto = $salarioMensual * 0.50;
                if ($monto > $maxAdelanto) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El monto no puede superar el 50% del salario mensual.',
                    ];
                    continue;
                }

                // Si pasa todas las validaciones, agregar a la lista de usuarios válidos
                $usuariosValidos[] = $user;
            }

            // Si hay errores, no registrar nada
            if (!empty($errores)) {
                Log::warning('Errores en la validación de usuarios', ['errores' => $errores]);
                return response()->json([
                    'success' => false,
                    'message' => 'Algunos usuarios no cumplen con las condiciones.',
                    'errors' => $errores,
                ], 422);
            }

            // Registrar adelantos para los usuarios válidos
            $rutaDocumento = null;
            if ($request->hasFile('documento')) {
                $rutaDocumento = $request->file('documento')->store('doc_justificacion_sueldo', 'public');
            }

            foreach ($usuariosValidos as $user) {
                $adelanto = new AdelantoSueldo();
                $adelanto->idUsuario = $user->id;
                $adelanto->fecha = $fecha;
                $adelanto->monto = $monto;
                $adelanto->descripcion = $request->descripcion;
                $adelanto->justificacion = $rutaDocumento;
                $adelanto->estado = 0;
                $adelanto->save();

                Log::info('Adelanto de sueldo registrado', ['usuario_id' => $user->id, 'adelanto' => $adelanto]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Adelantos de sueldo registrados exitosamente.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error inesperado en el método storeAdelantoSueldo', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado al procesar los adelantos de sueldo.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function confirmarPagoAdelantoSueldo(Request $request)
    {
        DB::beginTransaction(); // Inicia una transacción para mantener la integridad de los datos.
        try {
            // Obtener el adelanto de sueldo
            $adelantoSueldo = AdelantoSueldo::findOrFail($request->id);

            if (!$adelantoSueldo) {
                return response()->json(['success' => false, 'error' => 'No se encuentra el registro'], 404);
            }

            // Actualizar estado del adelanto de sueldo a "pagado"
            $adelantoSueldo->estado = 1;
            $adelantoSueldo->save();

            // Obtener el monto del adelanto
            $monto = $adelantoSueldo->monto;

            // Crear el registro en LIBRO_DIARIO
            $libroDiario = new LibroDiario();
            $libroDiario->idUsuario = Auth::id(); // ID del usuario autenticado
            $libroDiario->fecha = Carbon::now(); // Fecha actual
            $libroDiario->estado = 0; // Estado inicial
            $libroDiario->descripcion = 'Adelanto de sueldo';
            $libroDiario->save();

            // Obtener las cuentas contables
            $cuentaAdelanto = CuentasContables::where('codigo', '1412')->firstOrFail(); // Activo: Adelanto de remuneraciones
            $cuentaCaja = CuentasContables::where('codigo', '101')->firstOrFail(); // Caja: Pago en efectivo

            // Crear los detalles del libro diario
            $detalleDebe = new DetalleLibro();
            $detalleDebe->idLibroDiario = $libroDiario->id; // Relación con el libro diario
            $detalleDebe->idCuenta = $cuentaAdelanto->id; // Cuenta contable: 1412
            $detalleDebe->tipo = 'DEBE'; // Movimiento al DEBE
            $detalleDebe->monto = $monto; // Monto del adelanto
            $detalleDebe->accion = 'debe'; // Acción correspondiente
            $detalleDebe->estado = 1; // Activo
            $detalleDebe->save();

            $detalleHaber = new DetalleLibro();
            $detalleHaber->idLibroDiario = $libroDiario->id; // Relación con el libro diario
            $detalleHaber->idCuenta = $cuentaCaja->id; // Cuenta contable: 101
            $detalleHaber->tipo = 'HABER'; // Movimiento al HABER
            $detalleHaber->monto = $monto; // Monto del adelanto
            $detalleHaber->accion = 'haber'; // Acción correspondiente
            $detalleHaber->estado = 1; // Activo
            $detalleHaber->save();

            DB::commit(); // Confirmar la transacción
            return response()->json(['success' => true, 'message' => 'Adelanto de sueldo pagado y registrado con éxito'], 200);
        } catch (\Exception $e) {
            DB::rollBack(); // Revertir la transacción en caso de error
            Log::error('Error al registrar el pago del adelanto de sueldo: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al registrar el adelanto de sueldo: ' . $e->getMessage()], 500);
        }
    }

    public function getVacaciones()
    {
        try {
            $vacaciones = Vacacione::with('usuario.empleado.persona', 'usuario.empleado.cargo')->get();
            return response()->json(['success' => true, 'data' => $vacaciones], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error' . $e->getMessage()], 500);
        }
    }

    public function storeVacaciones(Request $request)
    {
        try {
            // Validación inicial de los datos
            $validator = Validator::make($request->all(), [
                'empleados' => 'required|array|min:1',
                'empleados.*' => 'required|exists:users,id',
                'fechaInicio' => 'required|date',
                'fechaFin' => 'required|date|after_or_equal:fechaInicio',
                'diasTotales' => 'required|integer|min:30',
                'observaciones' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $fechaInicio = $request->fechaInicio;
            $fechaFin = $request->fechaFin;
            $diasTotales = $request->diasTotales;
            $observaciones = $request->observaciones;
            $errores = [];
            $usuariosValidos = [];

            // Validar cada usuario antes de registrar
            foreach ($request->empleados as $usuario_id) {
                $usuario = User::with('empleado.persona')->find($usuario_id);

                if (!$usuario || !$usuario->empleado) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'No se encontró un empleado asociado a este usuario.',
                    ];
                    continue;
                }

                $empleado = $usuario->empleado;
                $documentoIdentidad = $empleado->persona->documento_identidad;

                // Validar que exista al menos una asistencia
                $asistencias = Asistencia::where('codigoUsuario', $documentoIdentidad)->count();
                if ($asistencias < 1) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El empleado no tiene registros de asistencia.',
                    ];
                    continue;
                }

                // Comprobar si el usuario ya tiene un registro de vacaciones pendiente
                $vacacionesPendientes = Vacacione::where('idUsuario', $usuario_id)
                    ->where('estado', 0)
                    ->exists();

                if ($vacacionesPendientes) {
                    $errores[] = [
                        'usuario_id' => $usuario_id,
                        'message' => 'El usuario ya tiene una vacación pendiente.',
                    ];
                    continue;
                }

                // Si pasa todas las validaciones, agregar a la lista de usuarios válidos
                $usuariosValidos[] = $usuario;
            }

            // Si hay errores, no registrar nada
            if (!empty($errores)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Algunos usuarios no cumplen con las condiciones.',
                    'errors' => $errores,
                ], 422);
            }

            // Registrar vacaciones para los usuarios válidos
            foreach ($usuariosValidos as $usuario) {
                $vacacion = new Vacacione();
                $vacacion->idUsuario = $usuario->id;
                $vacacion->fecha_inicio = $fechaInicio;
                $vacacion->fecha_fin = $fechaFin;
                $vacacion->dias_totales = $diasTotales;
                $vacacion->observaciones = $observaciones;
                $vacacion->estado = 0; // Estado por defecto
                $vacacion->save();

                // Cambiar el estado del usuario a 0 para indicar que está en vacaciones
                $usuario->estado = 0;
                $usuario->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Vacaciones registradas exitosamente para todos los usuarios.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado al registrar las vacaciones.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function venderDias(Request $request)
    {
        try {
            // Validar la entrada
            $request->validate([
                'id' => 'required|exists:vacaciones,id',
                'diasVender' => 'required|integer|min:1',
            ], [
                'id.required' => 'El ID de la vacación es obligatorio.',
                'id.exists' => 'La vacación especificada no existe.',
                'diasVender.required' => 'Debe especificar los días a vender.',
                'diasVender.integer' => 'Los días a vender deben ser un número entero.',
                'diasVender.min' => 'Debe vender al menos 1 día.',
            ]);

            // Obtener la vacación
            $vacacion = Vacacione::findOrFail($request->input('id'));

            // Calcular los días disponibles para vender
            $diasDisponibles = $vacacion->dias_totales - $vacacion->dias_utilizados;

            // Verificar si los días a vender son válidos
            if ($request->input('diasVender') > $diasDisponibles) {
                return response()->json([
                    'success' => false,
                    'error' => "No puedes vender más días de los disponibles. Días disponibles: $diasDisponibles.",
                ], 400);
            }

            // Actualizar días vendidos
            $diasVender = $request->input('diasVender');
            $vacacion->dias_vendidos += $diasVender;
            $vacacion->dias_totales = max(0, $vacacion->dias_totales - $diasVender);

            if ($vacacion->dias_totales <= 0) {
                // Si se han vendido todos los días, la fecha_fin debe ser igual a la fecha_inicio
                $vacacion->fecha_fin = $vacacion->fecha_inicio;

                // Cambiar el estado de las vacaciones a 1
                $vacacion->estado = 1;

                // Cambiar el estado del usuario a 1
                if (!empty($vacacion->idUsuario)) {
                    $usuario = User::find($vacacion->idUsuario);
                    if ($usuario) {
                        $usuario->estado = 1;
                        $usuario->save();
                    }
                }
            } else {
                // Actualizar la fecha de fin cuando no se han vendido todos los días
                if ($vacacion->fecha_fin > $vacacion->fecha_inicio) {
                    $fechaFin = new \DateTime($vacacion->fecha_fin);
                    $fechaFin->modify("-$diasVender days");
                    $vacacion->fecha_fin = $fechaFin->format('Y-m-d');
                }
            }

            // Guardar los cambios en la vacación
            $vacacion->save();

            return response()->json(['success' => true, 'message' => 'Días vendidos con éxito.']);
        } catch (ValidationException $e) {
            // Enviar errores de validación detallados
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            // Registrar el error y enviar un mensaje detallado
            Log::error('Error al vender días de vacaciones: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Ocurrió un error al vender la vacación. Detalles: ' . $e->getMessage()], 500);
        }
    }

    public function generarPagosMensuales()
    {
        // Obtener el día de pago registrado en la tabla AjustesPlanilla
        $ajustes = AjustesPlanilla::first();
        $diaPago = $ajustes ? $ajustes->diaPago : null;

        // Obtener el mes y año actual
        $mesActual = Carbon::now()->month;
        $anioActual = Carbon::now()->year;

        // Verificar si hoy es el día de pago registrado
        if (Carbon::now()->day != $diaPago) {
            return response()->json(["success" => "false", 'message' => 'Hoy no es el día de pago establecido.'], 400);
        }

        DB::beginTransaction();
        try {
            // Verificar si ya se generaron pagos para el mes anterior
            $mesAnterior = Carbon::now()->subMonth()->month;
            $anioAnterior = Carbon::now()->subMonth()->year;

            $pagosMesAnterior = Pago::whereMonth('fecha_pago', $mesAnterior)
                ->whereYear('fecha_pago', $anioAnterior)
                ->exists();

            $mesParaCalcular = $pagosMesAnterior ? $mesActual : $mesAnterior;
            $anioParaCalcular = $pagosMesAnterior ? $anioActual : $anioAnterior;

            $empleados = Empleado::with([
                'cargo',
                'asistencias' => function ($query) use ($mesParaCalcular, $anioParaCalcular) {
                    $query->whereMonth('fechaEntrada', $mesParaCalcular)
                        ->whereYear('fechaEntrada', $anioParaCalcular);
                },
                'bonificaciones',
                'deducciones'
            ])->get();

            $pagoTotales = 0;
            foreach ($empleados as $empleado) {
                // Verificar si ya existe un pago para el mes en cuestión
                $pagoExistente = Pago::where('idEmpleado', $empleado->id)
                    ->whereMonth('fecha_pago', $mesParaCalcular)
                    ->whereYear('fecha_pago', $anioParaCalcular)
                    ->exists();

                if ($pagoExistente) {
                    continue;
                }

                // Filtrar asistencias del mes a calcular
                $asistenciasDelMes = $empleado->asistencias;

                // Verificar si el empleado tiene al menos un día trabajado
                if ($asistenciasDelMes->count() == 0) {
                    continue; // No registrar pago si no hay días trabajados
                }

                foreach ($asistenciasDelMes as $asistencia) {
                    if (is_null($asistencia->fechaSalida) || is_null($asistencia->horaSalida)) {
                        DB::rollBack();
                        return response()->json(["success" => "false", 'error' => 'Todavía existen empleados sin marcar hora de salida.'], 400);
                    }
                }

                $totalDiasTrabajados = $asistenciasDelMes->count();
                $totalHorasTrabajadas = $totalDiasTrabajados * 8;

                $salarioBase = $empleado->cargo->salario;
                $pagoPorHora = $empleado->cargo->pagoPorHoras;

                $salarioTotal = round($pagoPorHora * $totalHorasTrabajadas, 2);

                // Calcular el total de bonificaciones
                $totalBonificaciones = $empleado->bonificaciones->sum('monto');

                // Calcular el total de deducciones sumando los porcentajes
                $totalDeduccionesPorcentajes = $empleado->deducciones->sum('porcentaje');

                // Asegúrate de que las deducciones no sean negativas
                $totalDeducciones = round($salarioTotal * $totalDeduccionesPorcentajes, 2);

                // Verificar que las deducciones no sean mayores que el salario base
                if ($totalDeducciones > $salarioTotal) {
                    $totalDeducciones = $salarioTotal; // Ajustar deducción a máximo salario base
                }

                $usuario = User::where('idEmpleado', $empleado->id)->first();

                // Calcular horas extras
                $horasExtras = HoraExtras::where('idUsuario', $usuario->id)
                    ->whereMonth('fecha', $mesParaCalcular)
                    ->whereYear('fecha', $anioParaCalcular)
                    ->get();

                $totalPagoHorasExtras = $horasExtras->sum('pagoTotal');

                // Calcular adelantos de sueldo
                $adelantosSueldo = AdelantoSueldo::where('idUsuario', $usuario->id)
                    ->where('estado', 1)
                    ->whereMonth('fecha', $mesParaCalcular)
                    ->whereYear('fecha', $anioParaCalcular)
                    ->sum('monto');

                // Calcular vacaciones (días vendidos y días utilizados)
                $vacaciones = Vacacione::where('idUsuario', $usuario->id)
                    ->whereYear('fecha_inicio', $anioParaCalcular)
                    ->where('estadoPagado', 0) // Filtrar por estadoPagado = 0
                    ->first();

                $diasVacacionesUtilizados = 0;
                $diasVacacionesVendidos = 0;

                if ($vacaciones) {
                    $diasVacacionesUtilizados = $vacaciones->dias_utilizados ?? 0;
                    $diasVacacionesVendidos = $vacaciones->dias_vendidos ?? 0;

                    // Calcular el pago por días vendidos
                    $pagoPorDiasVendidos = round(($salarioBase / 30) * $diasVacacionesVendidos, 2);

                    // Actualizar el estado de pagado a 1 después del cálculo
                    $vacaciones->estadoPagado = 1; // Marcar como pagado
                    $vacaciones->save(); // Guardar los cambios
                } else {
                    $pagoPorDiasVendidos = 0;
                }


                // Calcular salario neto
                $salarioNeto = $salarioTotal + $totalBonificaciones - $totalDeducciones + $totalPagoHorasExtras - $adelantosSueldo + $pagoPorDiasVendidos;

                // Crear registro de pago
                $pago = new Pago();
                $pago->idEmpleado = $empleado->id;
                $pago->fecha_pago = Carbon::create($anioParaCalcular, $mesParaCalcular, $diaPago);
                $pago->salario_base = $salarioTotal;
                $pago->deducciones = $totalDeducciones;
                $pago->bonificaciones = $totalBonificaciones;
                $pago->salario_neto = round($salarioNeto, 2);
                $pago->horas_extras = round($totalPagoHorasExtras, 2);
                $pago->adelantoSueldo = round($adelantosSueldo, 2);
                $pago->dias_vendidos = $diasVacacionesVendidos;
                $pago->dias_utilizados = $diasVacacionesUtilizados;
                $pago->save();

                $pagoTotales += round($salarioNeto, 2);
            }

            $this->RegistrarTransaccion($pagoTotales);

            DB::commit();

            return response()->json(["success" => "true", 'message' => 'Pagos generados exitosamente.'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al generar los pagos: ' . $e->getMessage());
            return response()->json(["success" => "false", 'message' => 'Error al generar los pagos: ' . $e->getMessage()], 500);
        }
    }
    // Función auxiliar para determinar la acción según el tipo de cuenta y la ubicación (DEBE o HABER)
    private function determinarAccion($tipoCuenta, $ubicacion)
    {
        if ($tipoCuenta === 'activo') {
            return $ubicacion === 'debe' ? 'aumento' : 'disminucion';
        } elseif ($tipoCuenta === 'pasivo' || $tipoCuenta === 'patrimonio neto') {
            return $ubicacion === 'debe' ? 'disminucion' : 'aumento';
        } elseif ($tipoCuenta === 'gasto') { // Nuevo tipo de cuenta para gastos
            return $ubicacion === 'debe' ? 'aumento' : 'desconocido';
        } else {
            return 'desconocido';
        }
    }

    // Método para registrar la transacción
    private function RegistrarTransaccion($pagoTotales)
    {
        // Crear registro en libro_diarios
        $libroDiario = new LibroDiario();
        $libroDiario->idUsuario = Auth::id();
        $libroDiario->fecha = now(); // Fecha actual
        $libroDiario->estado = 0; // Estado inicial
        $libroDiario->descripcion = "Pago de Planilla";
        $libroDiario->save();

        // Obtener la cuenta contable para Sueldos y Salarios
        $cuentaContableDebe = CuentasContables::where('codigo', '6211')->first(); // 6211 Sueldos y Salarios
        $cuentaContableHaber = CuentasContables::where('codigo', '101')->first(); // 101 Caja y Bancos

        // Registrar detalle en el DEBE para Gasto de Sueldos y Salarios
        $detalleDebe = new DetalleLibro();
        $detalleDebe->idLibroDiario = $libroDiario->id;
        $detalleDebe->idCuenta = $cuentaContableDebe->id;
        $detalleDebe->tipo = 'debe';
        $detalleDebe->accion = $this->determinarAccion($cuentaContableDebe->tipo, 'debe');
        $detalleDebe->monto = $pagoTotales;
        $detalleDebe->save();

        // Registrar detalle en el HABER para la salida de Caja
        $detalleHaber = new DetalleLibro();
        $detalleHaber->idLibroDiario = $libroDiario->id;
        $detalleHaber->idCuenta = $cuentaContableHaber->id;
        $detalleHaber->tipo = 'haber';
        $detalleHaber->accion = $this->determinarAccion($cuentaContableHaber->tipo, 'haber');
        $detalleHaber->monto = $pagoTotales;
        $detalleHaber->save();
    }

    public function iniciarValidacion($idPeriodo)
    {
        Log::info("Iniciando SUBSANACIÓN (V-Final + HE) para Periodo ID: $idPeriodo");

        try {
            DB::transaction(function () use ($idPeriodo) {

                // 1. Busca el periodo ABIERTO (Estado 1)
                $periodo = PeriodoNomina::where('id', $idPeriodo)
                    ->where('estado', 1)
                    ->lockForUpdate()
                    ->firstOrFail();

                // 2. Obtener Empleados en un Mapa (key = DNI)
                $mapEmpleados = Empleado::where('estado', 1)
                    ->with(['horario', 'persona', 'usuario']) // <-- Incluimos 'usuario'
                    ->get()
                    ->keyBy('persona.documento_identidad');

                Log::info("Cargados " . $mapEmpleados->count() . " empleados en el mapa.");

                // --- PASO 1: Subsanar Asistencias Existentes ---
                $asistenciasDelPeriodo = Asistencia::where('idPeriodo', $periodo->id)->get();
                Log::info("PASO 1: Encontradas " . $asistenciasDelPeriodo->count() . " asistencias existentes para subsanar.");

                foreach ($asistenciasDelPeriodo as $asistencia) {

                    // (Omitimos si el estado ya es 1, ya fue procesada)
                    if ($asistencia->estado == 1) continue;

                    $empleado = $mapEmpleados->get($asistencia->codigoUsuario);

                    // Verificamos el campo 'horaSalida' de la relación 'horario'
                    if (!$empleado || !$empleado->horario || !$empleado->horario->horaSalida) {
                        Log::warning("No se puede subsanar Asistencia ID {$asistencia->id}: Empleado (DNI: {$asistencia->codigoUsuario}) o su horario (horaSalida) no encontrado.");
                        continue;
                    }

                    $estadosIntocables = ['falta', 'justificada'];
                    if (in_array($asistencia->estadoAsistencia, $estadosIntocables)) {
                        $asistencia->estado = 1;
                        $asistencia->save();
                        continue;
                    }

                    // Si tiene entrada pero no salida (ej. 'tardanza' o 'a tiempo')
                    if ($asistencia->horaEntrada && !$asistencia->horaSalida) {

                        $hora_salida_prog = $empleado->horario->horaSalida;

                        // --- 1. Calcular Fecha/Hora de Salida Correcta (Manejo Turno Nocturno) ---
                        $entrada_dt = Carbon::parse($asistencia->fechaEntrada . ' ' . $asistencia->horaEntrada);
                        $salida_dt = Carbon::parse($asistencia->fechaEntrada . ' ' . $hora_salida_prog);

                        if ($salida_dt->lt($entrada_dt)) {
                            $salida_dt->addDay();
                        }

                        $fechaSalidaCorrecta = $salida_dt->toDateString();

                        // --- 2. Rellenar campos faltantes ---
                        $asistencia->horaSalida = $hora_salida_prog;
                        $asistencia->fechaSalida = $fechaSalidaCorrecta;
                        $asistencia->horas_extras = '00:00:00'; // Valor inicial
                        $asistencia->estado = 1; // Marcamos como procesada

                        // --- 3. Calcular Horas Trabajadas ---
                        try {
                            $segundos = $salida_dt->diffInSeconds($entrada_dt);
                            $asistencia->horasTrabajadas = gmdate('H:i:s', $segundos);
                        } catch (\Exception $e) {
                            Log::warning("Error al calcular horasTrabajadas para Asistencia ID {$asistencia->id}: " . $e->getMessage());
                            $asistencia->horasTrabajadas = '00:00:00';
                        }

                        // --- 4. Guardar todo ---
                        $asistencia->save();
                        Log::info("Autocompletada Asistencia ID {$asistencia->id} (Salida: {$fechaSalidaCorrecta}, Horas, Estado).");
                    }
                }
                Log::info("PASO 1: Subsanación de salidas completada.");


                // --- PASO 2: Crear Registros de Vacaciones ---
                $rangoFechas = CarbonPeriod::create($periodo->fecha_inicio, $periodo->fecha_fin);
                Log::info("PASO 2: Iniciando escaneo de vacaciones...");

                foreach ($mapEmpleados as $documentoEmpleado => $empleado) {

                    // Corrección: Validar que el empleado tenga usuario
                    if (!$empleado->usuario) {
                        Log::warning("PASO 2: Saltando escaneo de vacaciones para Empleado DNI {$documentoEmpleado}. No tiene 'usuario' relacionado.");
                        continue;
                    }

                    $idUsuario = $empleado->usuario->id;

                    foreach ($rangoFechas as $date) {
                        $fechaActual = $date->toDateString();

                        $vacacionAprobada = Vacacione::where('idUsuario', $idUsuario)
                            ->where('estado', 0) // 0 = activas
                            ->where('fecha_inicio', '<=', $fechaActual)
                            ->where('fecha_fin', '>=', $fechaActual)
                            ->exists();

                        if ($vacacionAprobada) {
                            Asistencia::updateOrCreate(
                                [
                                    'codigoUsuario' => $documentoEmpleado,
                                    'fechaEntrada' => $fechaActual
                                ],
                                [
                                    'idPeriodo' => $periodo->id,
                                    'estadoAsistencia' => 'justificada',
                                    'horasTrabajadas' => '00:00:00',
                                    'horas_extras' => '00:00:00',
                                    'fechaSalida' => $fechaActual,
                                    'horaEntrada' => null,
                                    'horaSalida' => null,
                                    'estado' => 1 // Marcamos como procesado
                                ]
                            );
                        }
                    } // Fin bucle días
                } // Fin bucle empleados
                Log::info("PASO 2: Escaneo de vacaciones completado.");


                // --- PASO 3: Recolectar IDs de Usuario (para Pasos 4 y 5) ---
                Log::info("PASO 3: Iniciando recolección de IDs de usuario...");

                $listaIdsUnicos = [];
                $mapIdUsuarioADni = []; // Mapa [idUsuario => DNI]

                foreach ($mapEmpleados as $dni => $empleado) {
                    if ($empleado->usuario) {
                        $idUsuario = $empleado->usuario->id;
                        $listaIdsUnicos[] = $idUsuario;
                        $mapIdUsuarioADni[$idUsuario] = $dni;
                    }
                }
                $listaIdsUnicos = array_unique($listaIdsUnicos);
                Log::info("PASO 3: Recolección completa. " . count($listaIdsUnicos) . " IDs de usuario válidos.");


                // --- PASO 4: Resolver Horas Extra Pendientes (0 -> 1) ---
                Log::info("PASO 4: Resolviendo HE pendientes (0 -> 1)...");

                if (count($listaIdsUnicos) > 0) {
                    HoraExtras::where('fecha', '>=', $periodo->fecha_inicio)
                        ->where('fecha', '<=', $periodo->fecha_fin)
                        ->where('estado', 0) // Pendientes
                        ->whereIn('idUsuario', $listaIdsUnicos)
                        ->update(['estado' => 1]); // Pasan a estado 1
                } else {
                    Log::info("PASO 4: No se encontraron IDs de usuario, se omite HE.");
                }
                Log::info("PASO 4: HE pendientes resueltas.");


                // --- PASO 5: Aplicar Horas Extra APROBADAS a Asistencias ---
                Log::info("PASO 5: Aplicando horas extra APROBADAS a asistencias...");

                // --- ¡REVISA ESTA LÍNEA! ---
                // Asumo que 'estado = 1' son las APROBADAS.
                // Si las aprobadas son 'estado = 1', cambia este valor.
                $estadoAprobadoHE = 1;

                if (count($listaIdsUnicos) > 0) {
                    $horasExtrasAprobadas = HoraExtras::where('estado', $estadoAprobadoHE)
                        ->whereIn('idUsuario', $listaIdsUnicos)
                        ->where('fecha', '>=', $periodo->fecha_inicio)
                        ->where('fecha', '<=', $periodo->fecha_fin)
                        ->get();

                    Log::info("PASO 5: Se encontraron " . $horasExtrasAprobadas->count() . " registros de HE aprobadas para aplicar.");

                    foreach ($horasExtrasAprobadas as $horaExtra) {
                        // Usamos el mapa [userId => DNI] que creamos en PASO 3
                        if (isset($mapIdUsuarioADni[$horaExtra->idUsuario])) {

                            $dni = $mapIdUsuarioADni[$horaExtra->idUsuario];

                            // ===== INICIO DE LA CORRECCIÓN DE FORMATO =====
                            try {
                                // 1. Convertimos el decimal (ej: 2.0) a segundos (ej: 7200)
                                $horasEnDecimal = (float) $horaExtra->horas_trabajadas;
                                $segundosTotales = $horasEnDecimal * 3600;

                                // 2. Formateamos los segundos a 'H:i:s' (ej: '02:00:00')
                                $formatoHis = gmdate('H:i:s', $segundosTotales);

                                // 3. Actualizamos la asistencia con el formato correcto
                                Asistencia::where('codigoUsuario', $dni)
                                    ->where('fechaEntrada', $horaExtra->fecha)
                                    ->update([
                                        'horas_extras' => $formatoHis
                                    ]);
                            } catch (\Exception $e) {
                                Log::warning("PASO 5: Error al convertir horas extra (Valor: {$horaExtra->horas_trabajadas}) para DNI {$dni} en fecha {$horaExtra->fecha}. Error: " . $e->getMessage());
                            }
                            // ===== FIN DE LA CORRECCIÓN DE FORMATO =====
                        }
                    }
                    Log::info("PASO 5: HE Aprobadas aplicadas.");
                } else {
                    Log::info("PASO 5: No se encontraron IDs de usuario, se omite aplicación de HE.");
                }


                // --- PASO 6: Actualizar el Estado del Periodo ---
                $periodo->estado = 2; // Pasa a Estado 2 (En Validación)
                $periodo->save();

                Log::info("Subsanación (V-Final + HE) completa. Periodo ID: $idPeriodo movido a Estado 2.");
            }); // Fin de la Transacción

            return response()->json([
                'message' => 'Proceso de validación completado. Registros autocompletados, vacaciones y horas extra aplicadas.'
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error("Error al iniciar validación: Periodo (ID: $idPeriodo, Estado: 1) no encontrado.");
            return response()->json(['message' => 'Error: No se encontró el periodo abierto para validar.'], 404);
        } catch (\Exception $e) {
            Log::error("Error crítico al iniciar validación (ID: $idPeriodo): " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Ocurrió un error inesperado al procesar las asistencias.', 'error' => $e->getMessage()], 500);
        }
    }


    public function validarNominaCompleta(Request $request, $idPeriodo)
    {
        Log::info("Iniciando CIERRE (Generar Pagos) para Periodo ID: $idPeriodo");
        try {
            DB::transaction(function () use ($idPeriodo) {

                // 1. Busca el periodo EN VALIDACIÓN (Estado 2)
                $periodo = PeriodoNomina::where('id', $idPeriodo)
                    ->where('estado', 2) // <-- Busca estado 2
                    ->lockForUpdate()
                    ->firstOrFail();

                // 2. VERIFICA que el admin haya resuelto todo
                $horasExtraPendientes = HoraExtras::where('idPeriodo', $periodo->id)
                    ->where('estado', 0) // 0 = Pendiente
                    ->count();

                if ($horasExtraPendientes > 0) {
                    Log::warning("Cierre fallido: $horasExtraPendientes H.E. pendientes en Periodo ID: $idPeriodo");
                    throw new \Exception("No se puede cerrar. Aún existen $horasExtraPendientes horas extra pendientes de aprobación.");
                }

                // --- 3. AQUÍ VA TU LÓGICA DE CÁLCULO DE NÓMINA ---
                Log::info("Verificación OK. Generando pagos para Periodo ID: $idPeriodo...");
                // ...
                // ... CalcularSueldos($periodo);
                // ...

                // 4. Actualizar el Estado del Periodo
                $periodo->estado = 3; // <-- Pasa a Estado 3 (Cerrado)
                $periodo->save();
                Log::info("Cierre completo. Periodo ID: $idPeriodo movido a Estado 3.");
            }); // Fin de la Transacción

            return response()->json([
                'message' => 'Nómina generada y periodo cerrado exitosamente.'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("Error al cerrar periodo: Periodo (ID: $idPeriodo, Estado: 2) no encontrado.");
            return response()->json(['message' => 'Error: No se encontró el periodo en validación.'], 404);
        } catch (\Exception $e) {
            Log::error("Error crítico al cerrar periodo (ID: $idPeriodo): " . $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
