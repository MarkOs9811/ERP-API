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

    public function getPlanilla(Request $request) // <--- Agregamos Request
    {
        try {
            $userAuth = Auth::user();

            if (!$userAuth) {
                return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
            }
            $parametroPeriodo = $request->query('periodo'); // Formato esperado: "YYYY-MM"
            $periodoConsulta = null;
            if ($parametroPeriodo) {

                $year = substr($parametroPeriodo, 0, 4);
                $month = substr($parametroPeriodo, 5, 2);


                $fechaReferencia = Carbon::createFromDate($year, $month, 15)->format('Y-m-d');

                // 3. Buscamos el periodo que "envuelva" o contenga esa fecha
                // Lógica: fecha_inicio <= 15-Dic  Y  fecha_fin >= 15-Dic
                $periodoConsulta = PeriodoNomina::where('idEmpresa', $userAuth->idEmpresa)
                    ->where('idSede', $userAuth->idSede)
                    ->whereDate('fecha_inicio', '<=', $fechaReferencia)
                    ->whereDate('fecha_fin', '>=', $fechaReferencia)
                    ->first();
            } else {
                // Si no hay filtro, busca el activo (estado 1 o 2)
                $periodoConsulta = PeriodoNomina::where('idEmpresa', $userAuth->idEmpresa)
                    ->where('idSede', $userAuth->idSede)
                    ->whereIn('estado', [1, 2])
                    ->orderBy('id', 'desc')
                    ->first();
            }

            if (!$periodoConsulta) {
                return response()->json([
                    'success' => true, // Respondemos true pero con data vacía para no romper el front
                    'data' => [
                        'resumen' => [
                            'totalEmpleados' => 0,
                            'totalPagar' => 0,
                            'estado' => 'SIN DATOS'
                        ],
                        'detalles' => []
                    ],
                    'message' => 'No se encontraron registros para el periodo solicitado.',
                ], 200);
            }

            $query = User::with([
                'empleado.persona',
                'empleado.cargo',
                'empleado.area',

                'empleado.pagos' => function ($q) use ($periodoConsulta) {
                    $q->where('idPeriodo', $periodoConsulta->id);
                },
                'empleado.asistencias' => function ($q) use ($periodoConsulta) {
                    $q->where('idPeriodo', $periodoConsulta->id);
                },
            ])
                ->where('idEmpresa', $userAuth->idEmpresa)
                ->where('idSede', $userAuth->idSede)
                ->whereHas('empleado', function ($q) {
                    $q->where('estado', 1);
                });

            $usuarios = $query->get();

            // 3. PROCESAMIENTO DE DATOS

            $detalles = [];
            $totalPagarMes = 0;
            $empleadosEnPlanilla = 0;

            foreach ($usuarios as $user) {
                $empleado = $user->empleado;
                if (!$empleado) continue;

                // Obtenemos el registro de pago ESPECÍFICO de este periodo (gracias al filtro en la consulta principal)
                $pago = $empleado->pagos->first();

                // =================================================================================
                // 🛑 FILTRO DE SEGURIDAD (CRÍTICO)
                // =================================================================================

                // CASO A: EL PERIODO YA FUE PAGADO (Histórico)
                // La regla es simple: Si no hay boleta de pago en la BD, la persona no existe en esa nómina.
                if ($periodoConsulta->estado == 3) {
                    if (!$pago) {
                        continue; // SALTAR: No mostrar fantasmas en nóminas pasadas.
                    }
                }

                // CASO B: EL PERIODO ESTÁ ABIERTO (Proyección)
                // Aquí usamos las fechas porque aún no existen pagos.
                if ($periodoConsulta->estado < 3) {
                    // Preferencia: Usar fecha de inicio de contrato, si no, fecha de creación del usuario
                    // Asegúrate de que tu modelo Empleado tenga la relación 'contrato' cargada o usa created_at
                    $fechaInicio = $empleado->contrato ? \Carbon\Carbon::parse($empleado->contrato->fecha_inicio) : \Carbon\Carbon::parse($user->created_at);
                    $fechaFinPeriodo = \Carbon\Carbon::parse($periodoConsulta->fecha_fin);

                    // Si empezó a trabajar DESPUÉS de que cerró este periodo, no debe salir.
                    if ($fechaInicio->gt($fechaFinPeriodo)) {
                        continue; // SALTAR
                    }
                }
                // =================================================================================

                // --- 1. CÁLCULOS (Solo se ejecutan si pasó el filtro) ---

                // Si hay pago real, usamos sus datos. Si no, proyectamos ceros.
                $montoBonificaciones = $pago ? floatval($pago->bonificaciones) : 0;
                $montoHorasExtras = $pago ? floatval($pago->horas_extras) : 0;
                $totalBonos = $montoBonificaciones + $montoHorasExtras;

                $montoDeducciones = $pago ? floatval($pago->deducciones) : 0;
                $montoAdelantos = $pago ? floatval($pago->adelantoSueldo) : 0;
                $totalDescuentos = $montoDeducciones + $montoAdelantos;

                // Sueldo base: Si ya pagamos, tratamos de sacar el histórico (si lo guardaste), sino el actual
                $sueldoBase = $pago ? floatval($pago->salario_base) : ($empleado->salario ?? 0);

                // Neto: LA VERDAD es lo que dice la tabla pagos. Si es proyección, calculamos.
                $netoPagar = $pago ? floatval($pago->salario_neto) : ($sueldoBase + $totalBonos - $totalDescuentos);

                // Estado INDIVIDUAL del empleado (No del periodo global)
                $estadoEmpleado = 'PENDIENTE';
                if ($pago) {
                    $estadoEmpleado = 'PAGADO'; // Si existe registro en BD, está pagado.
                }

                // Acumuladores (Solo sumamos a gente válida)
                $totalPagarMes += $netoPagar;
                $empleadosEnPlanilla++;

                $detalles[] = [
                    'id' => $user->id,
                    'nombre_completo' => ($empleado->persona->nombre ?? '') . ' ' . ($empleado->persona->apellidos ?? ''),
                    'cargo' => $empleado->cargo->nombre ?? 'Sin cargo',
                    'sueldo_base' => $sueldoBase,
                    'total_bonificaciones' => $totalBonos,
                    'total_descuentos' => $totalDescuentos,
                    'neto_pagar' => $netoPagar,
                    'estado_pago' => $estadoEmpleado, // <--- CORREGIDO: Ahora depende de si tiene boleta
                    'dias_trabajados' => $empleado->asistencias->count(), // <--- Dato informativo
                ];
            }


            $estadoTexto = 'PENDIENTE';
            if ($periodoConsulta->estado == 3) $estadoTexto = 'PAGADO';
            if ($periodoConsulta->estado == 2) $estadoTexto = 'EN VALIDACIÓN';

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen' => [
                        'totalEmpleados' => $empleadosEnPlanilla,
                        'totalPagar' => round($totalPagarMes, 2),
                        'estado' => $estadoTexto,
                        'periodo_id' => $periodoConsulta->id
                    ],
                    'detalles' => $detalles
                ],
                'message' => 'Datos obtenidos correctamente.'
            ], 200);
        } catch (\Exception $e) {
            Log::error('getPlanilla Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error interno.'], 500);
        }
    }
    public function getEmpleadoPerfil($idEmpleado)
    {
        try {

            $usuario = User::with(
                'empleado.persona',
                'empleado.cargo',
                'empleado.area',
                'empleado.pagos',
                'empleado.contrato',
                'empleado.horario'
            )->find($idEmpleado);

            if (!$usuario || !$usuario->empleado) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $empleado = $usuario->empleado;
            $ultimoPago = $empleado->pagos->sortByDesc('fecha_pago')->first();

            // Si existe un pago, sacamos los montos. Si no, enviamos 0.
            $montoUltimaBonificacion = $ultimoPago ? $ultimoPago->bonificaciones : 0;
            $montoUltimaDeduccion    = $ultimoPago ? $ultimoPago->deducciones : 0;
            $sueldoNeto = $ultimoPago ? $ultimoPago->salario_neto : 0;
            $sueldoBase    = $ultimoPago ? $ultimoPago->salario_base : 0;



            $diasTrabajados = $empleado->asistencias
                ->filter(fn($a) => Carbon::parse($a->fechaEntrada)->isSameMonth(now()))
                ->count();

            $porcentajeDiasTrabajados = min(100, ($diasTrabajados / 30) * 100);

            $vacaciones = Vacacione::where('idUsuario', $usuario->id)
                ->whereYear('fecha_inicio', Carbon::now()->year)
                ->get();

            // Opcional: Listas de configuración (si las necesitas)
            $listaBonificacionesConfig = $empleado->bonificaciones()->where('estado', 1)->get();
            $listaDeduccionesConfig = $empleado->deducciones()->where('estado', 1)->get();

            $data = [
                'usuario' => $usuario,
                'dias_trabajados' => $diasTrabajados,
                'porcentaje_dias_trabajados' => $porcentajeDiasTrabajados,
                'vacacionesContados' => $vacaciones,
                'totalBonificaciones' => $montoUltimaBonificacion,
                'totalDeducciones' => $montoUltimaDeduccion,
                'bonificaciones' => $listaBonificacionesConfig,
                'deducciones' => $listaDeduccionesConfig,
                'sueldoBase' => $sueldoBase,
                'sueldoNeto' => $sueldoNeto
            ];

            return response()->json(['success' => true, 'data' => $data], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'error: ' . $e->getMessage()], 500);
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


            // Convertir el array de objetos a un array de IDs
            $usuarios = collect($request->input('empleados'))->pluck('id')->toArray();

            // Reemplazar el campo 'empleados' con los IDs extraídos
            $request->merge(['empleados' => $usuarios]);

            $validator = Validator::make($request->all(), [
                'empleados' => 'required|array|min:1',
                'empleados.*' => 'required|exists:users,id',
                'fecha' => 'required|date',
                'horas_trabajadas' => 'required|numeric|min:1',
            ]);


            if ($validator->fails()) {

                return response()->json(['success' => false, 'message' => 'Errores de validación', 'errors' => $validator->errors()], 422);
            }

            $fecha = $request->fecha;
            $horasTrabajadas = $request->horas_trabajadas;
            $mes_actual = Carbon::now()->format('Y-m');

            $resultados = [];

            foreach ($request->empleados as $usuario_id) {

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
    public function upDateHorasExtras(Request $request, $id)
    {

        $datosValidados = $request->validate([
            'fecha' => 'required|date',
            'horas_trabajadas' => 'required|numeric|min:1',
        ]);


        try {
            $horasExtra = HoraExtras::find($id);

            if (!$horasExtra) {
                return response()->json([
                    'success' => false,
                    'message' => 'El registro de horas extras no fue encontrado.'
                ], 404);
            }

            // Usamos los datos validados (más seguro)
            $fecha = $datosValidados['fecha'];
            $horasTrabajadas = $datosValidados['horas_trabajadas'];
            $usuario_id = $horasExtra->idUsuario;

            // 3. Validación de Regla de Negocio (Fecha repetida)
            $fecha_repetida = HoraExtras::where('idUsuario', $usuario_id)
                ->where('fecha', $fecha)
                ->where('id', '!=', $id)
                ->exists();

            if ($fecha_repetida) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario ya tiene registrada una hora extra para la fecha indicada.',
                ], 422);
            }

            // 4. Validación de Regla de Negocio (Límite 6 horas)
            $mes_a_validar = Carbon::parse($fecha);

            $horas_en_el_mes = HoraExtras::where('idUsuario', $usuario_id)
                ->whereYear('fecha', '=', $mes_a_validar->year)
                ->whereMonth('fecha', '=', $mes_a_validar->month)
                ->where('id', '!=', $id)
                ->sum('horas_trabajadas');

            if (($horas_en_el_mes + $horasTrabajadas) > 6) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario no puede tener más de 6 horas extras en el presente mes.',
                ], 422);
            }

            // 5. Obtener información del usuario para calcular el pago
            $usuario = User::with('empleado.cargo')->find($usuario_id);

            if (!$usuario || !$usuario->empleado || !$usuario->empleado->cargo) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo encontrar la información del empleado o su cargo.'
                ], 404);
            }

            $pagoPorHora = $usuario->empleado->cargo->pagoPorHoras;

            // 6. Calcular el pago total
            $totalPagoHorasExtras = 0;
            if ($horasTrabajadas <= 2) {
                $totalPagoHorasExtras = $horasTrabajadas * ($pagoPorHora * 1.25);
            } else {
                $totalPagoHorasExtras = (2 * ($pagoPorHora * 1.25)) + (($horasTrabajadas - 2) * ($pagoPorHora * 1.35));
            }

            // 7. Calcular el estado
            $estado = Carbon::parse($fecha)->isBefore(Carbon::today()) ? 1 : 0;

            // 8. Actualizar el registro
            $horasExtra->fecha = $fecha;
            $horasExtra->horas_trabajadas = $horasTrabajadas;
            $horasExtra->pagoTotal = $totalPagoHorasExtras;
            $horasExtra->estado = $estado;
            $horasExtra->save();

            Log::info('Horas extras actualizadas correctamente', ['horasExtra_id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Horas extras actualizadas correctamente.',
                'data' => $horasExtra
            ], 200);
        } catch (\Exception $e) {
            // Este catch ahora SÓLO se activa por errores de "Lógica de Negocio"
            // (por ejemplo, si falla la base de datos), pero NO por la validación.
            Log::error('Error inesperado en el método update HorasExtras', ['error' => $e->getMessage(), 'id' => $id]);
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error inesperado al actualizar las horas extras.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteHorasExtras($id)
    {
        try {
            $horasExtras = HoraExtras::where('estado', 0)->findOrFail($id);
            if (!$horasExtras) {
                return response()->json(['success' => false, 'message' => "No se encontro un registro"], 422);
            }
            $horasExtras->delete();
            return response()->json(['success' => true, 'message' => "Se eliminó correctamente"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Error" . $e->getMessage()], 500);
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
            // 1. VALIDACIÓN AUTOMÁTICA (El "validate genérico")
            // Si esto falla, Laravel envía el JSON 422 "¡pum!"
            $datosValidados = $request->validate([
                'empleados' => 'required|array|min:1',
                'empleados.*' => 'required|exists:users,id',
                'fechaInicio' => 'required|date',
                'fechaFin' => 'required|date|after_or_equal:fechaInicio',
                'diasTotales' => 'required|integer|min:7', // Mantengo tu lógica de 7
                'observaciones' => 'nullable|string',
            ]);

            // 2. Extracción de datos (ya validados)
            $fechaInicio = $datosValidados['fechaInicio'];
            $fechaFin = $datosValidados['fechaFin'];
            $diasTotales = $datosValidados['diasTotales'];
            $observaciones = $datosValidados['observaciones'] ?? null;

            $errores = [];
            $usuariosValidos = [];

            // 3. VALIDACIÓN DE LÓGICA DE NEGOCIO (Iterar sobre empleados)
            // Esta parte es tu lógica personalizada y está perfecta.
            foreach ($datosValidados['empleados'] as $usuario_id) {
                $usuario = User::with('empleado.persona')->find($usuario_id);

                if (!$usuario || !$usuario->empleado) {
                    $errores[] = ['usuario_id' => $usuario_id, 'message' => 'No se encontró un empleado asociado a este usuario.'];
                    continue;
                }

                $documentoIdentidad = $usuario->empleado->persona->documento_identidad;

                // Validar que exista al menos una asistencia
                $asistencias = Asistencia::where('codigoUsuario', $documentoIdentidad)->count();
                if ($asistencias < 1) {
                    $errores[] = ['usuario_id' => $usuario_id, 'message' => 'El empleado no tiene registros de asistencia.'];
                    continue;
                }

                // Comprobar si el usuario ya tiene un registro de vacaciones pendiente
                $vacacionesPendientes = Vacacione::where('idUsuario', $usuario_id)
                    ->where('estado', 0)
                    ->exists();

                if ($vacacionesPendientes) {
                    $errores[] = ['usuario_id' => $usuario_id, 'message' => 'El usuario ya tiene una vacación pendiente.'];
                    continue;
                }

                $usuariosValidos[] = $usuario;
            }

            // 4. RESPUESTA DE ERROR DE LÓGICA DE NEGOCIO
            // Si hay errores de negocio, los devuelves.
            if (!empty($errores)) {
                // Tu frontend leerá "Algunos usuarios no cumplen con las condiciones."
                return response()->json([
                    'success' => false,
                    'message' => 'Algunos usuarios no cumplen con las condiciones.',
                    'errors' => $errores, // Lista de errores específicos por usuario
                ], 422);
            }

            // 5. REGISTRAR VACACIONES (Solo si todo es válido)
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
        } catch (ValidationException $e) {
            // Este catch es redundante si usas $request->validate()
            // porque Laravel ya lo maneja. Pero si quieres ser explícito:
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // Este catch es para errores de base de datos u otros
            Log::error('Error en storeVacaciones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function venderDias(Request $request)
    {
        try {
            // Validación (la dejé como la tenías, pero añadí mensajes)
            $request->validate([
                'id' => 'required|exists:vacaciones,id',
                'diasVender' => 'required|integer|min:1',
            ], [
                'id.required' => 'El ID de la vacación es obligatorio.',
                'id.exists' => 'La vacación especificada no existe.',
                'diasVender.required' => 'Debe especificar los días a vender.',
                'diasVender.min' => 'Debe vender al menos 1 día.',
            ]);


            $vacacion = Vacacione::findOrFail($request->input('id'));
            $diasVender = $request->input('diasVender');


            $diasDisponibles = $vacacion->dias_totales - $vacacion->dias_utilizados - $vacacion->dias_vendidos;

            // --- CORRECCIÓN 2: VERIFICAR SI SE PUEDE VENDER ---
            if ($diasVender > $diasDisponibles) {
                // Devolvemos 422 (Error de validación) con un 'message'
                return response()->json([
                    'success' => false,
                    'message' => "No puedes vender $diasVender días. Días disponibles reales: $diasDisponibles.",
                ], 422);
            }


            $vacacion->dias_vendidos += $diasVender;


            $vacacion->estadoPagado = 1;


            $diasConsumidos = $vacacion->dias_utilizados + $vacacion->dias_vendidos;


            if ($diasConsumidos >= $vacacion->dias_totales) {

                $vacacion->estado = 1; // 1 = Completado


                if (!empty($vacacion->idUsuario)) {
                    $usuario = User::find($vacacion->idUsuario);
                    // Si el usuario estaba en vacaciones (estado 0), lo activamos (estado 1)
                    if ($usuario && $usuario->estado == 0) {
                        $usuario->estado = 1;
                        $usuario->save();
                    }
                }
            }

            // Guardar los cambios en la vacación
            $vacacion->save();

            return response()->json(['success' => true, 'message' => 'Días vendidos con éxito.']);
        } catch (ValidationException $e) {
            // --- CORRECCIÓN 5: RESPUESTA DE VALIDACIÓN CONSISTENTE ---
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(), // Mensaje general
                'errors' => $e->errors()  // Errores detallados (por si los usas)
            ], 422);
        } catch (\Exception $e) {
            // --- CORRECCIÓN 5: RESPUESTA DE ERROR CONSISTENTE ---
            Log::error('Error al vender días de vacaciones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al vender la vacación. Detalles: ' . $e->getMessage()
            ], 500);
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
            // ---------------------------------------------------------
            // 1. VALIDACIÓN PREVIA (Validar Fecha sin bloquear BD)
            // ---------------------------------------------------------
            $periodoPreliminar = PeriodoNomina::findOrFail($idPeriodo);

            // Verificamos si HOY es menor a la fecha fin del periodo.
            // Usamos startOfDay() para ignorar las horas y comparar solo fechas.
            if (Carbon::now()->startOfDay()->lt(Carbon::parse($periodoPreliminar->fecha_fin)->startOfDay())) {
                return response()->json([
                    'success' => false,
                    'message' => "No es posible iniciar la validación. El periodo finaliza el " . Carbon::parse($periodoPreliminar->fecha_fin)->format('d/m/Y') . ". Debes esperar al cierre."
                ], 400);
            }

            // ---------------------------------------------------------
            // 2. INICIO DE LA TRANSACCIÓN (Proceso pesado)
            // ---------------------------------------------------------
            return DB::transaction(function () use ($idPeriodo) {

                // A. Buscamos el periodo y lo bloqueamos para que nadie más lo toque
                $periodo = PeriodoNomina::where('id', $idPeriodo)
                    ->where('estado', 1) // Debe estar Abierto
                    ->lockForUpdate()
                    ->first();

                if (!$periodo) {
                    throw new \Exception("El periodo no se encuentra en estado 'Abierto' (1) o ya está siendo procesado.");
                }

                // IDs de contexto (CRUCIALES para el aislamiento de datos)
                $idEmpresa = $periodo->idEmpresa;
                $idSede = $periodo->idSede;

                // B. Obtener Empleados FILTRADOS por Empresa y Sede
                $mapEmpleados = Empleado::where('estado', 1)
                    ->where('idEmpresa', $idEmpresa) // <--- FILTRO DE SEGURIDAD
                    ->where('idSede', $idSede)       // <--- FILTRO DE SEGURIDAD
                    ->with(['horario', 'persona', 'usuario'])
                    ->get()
                    ->keyBy('persona.documento_identidad');

                Log::info("Cargados " . $mapEmpleados->count() . " empleados válidos de la Sede $idSede.");

                // ---------------------------------------------------------
                // PASO 1: Subsanar Asistencias Existentes (Completar salidas)
                // ---------------------------------------------------------
                $asistenciasDelPeriodo = Asistencia::where('idPeriodo', $periodo->id)->get();

                foreach ($asistenciasDelPeriodo as $asistencia) {
                    if ($asistencia->estado == 1) continue;

                    // Si la asistencia pertenece a un empleado que NO es de esta sede (filtro mapa), la saltamos
                    if (!$mapEmpleados->has($asistencia->codigoUsuario)) {
                        continue;
                    }

                    $empleado = $mapEmpleados->get($asistencia->codigoUsuario);

                    // Validar horario
                    if (!$empleado || !$empleado->horario || !$empleado->horario->horaSalida) {
                        Log::warning("Asistencia ID {$asistencia->id}: Empleado sin horario definido. Omitiendo.");
                        continue;
                    }

                    $estadosIntocables = ['falta', 'justificada'];
                    if (in_array($asistencia->estadoAsistencia, $estadosIntocables)) {
                        $asistencia->estado = 1;
                        $asistencia->save();
                        continue;
                    }

                    // Lógica de autocompletado de salida
                    if ($asistencia->horaEntrada && !$asistencia->horaSalida) {
                        $hora_salida_prog = $empleado->horario->horaSalida;

                        $entrada_dt = Carbon::parse($asistencia->fechaEntrada . ' ' . $asistencia->horaEntrada);
                        $salida_dt = Carbon::parse($asistencia->fechaEntrada . ' ' . $hora_salida_prog);

                        // Ajuste turno nocturno
                        if ($salida_dt->lt($entrada_dt)) {
                            $salida_dt->addDay();
                        }

                        $fechaSalidaCorrecta = $salida_dt->toDateString();

                        $asistencia->horaSalida = $hora_salida_prog;
                        $asistencia->fechaSalida = $fechaSalidaCorrecta;
                        $asistencia->horas_extras = '00:00:00';
                        $asistencia->estado = 1; // Marcamos como procesada

                        try {
                            $segundos = $salida_dt->diffInSeconds($entrada_dt);
                            $asistencia->horasTrabajadas = gmdate('H:i:s', $segundos);
                        } catch (\Exception $e) {
                            $asistencia->horasTrabajadas = '00:00:00';
                        }
                        $asistencia->save();
                    }
                }
                Log::info("PASO 1: Subsanación completada.");

                // ---------------------------------------------------------
                // PASO 2: Crear Asistencias desde Vacaciones
                // ---------------------------------------------------------
                $rangoFechas = CarbonPeriod::create($periodo->fecha_inicio, $periodo->fecha_fin);

                foreach ($mapEmpleados as $documentoEmpleado => $empleado) {
                    if (!$empleado->usuario) continue;

                    $idUsuario = $empleado->usuario->id;

                    foreach ($rangoFechas as $date) {
                        $fechaActual = $date->toDateString();

                        // Verificar si hay vacación activa en esa fecha
                        $vacacionAprobada = Vacacione::where('idUsuario', $idUsuario)
                            ->where('estado', 0) // 0 = Activa/Aprobada
                            ->where('fecha_inicio', '<=', $fechaActual)
                            ->where('fecha_fin', '>=', $fechaActual)
                            ->exists();

                        if ($vacacionAprobada) {
                            // Creamos/Actualizamos la asistencia
                            Asistencia::updateOrCreate(
                                [
                                    'codigoUsuario' => $documentoEmpleado,
                                    'fechaEntrada' => $fechaActual
                                ],
                                [
                                    'idPeriodo' => $periodo->id,
                                    // IMPORTANTE: Aseguramos contexto explícito
                                    'idEmpresa' => $idEmpresa,
                                    'idSede' => $idSede,
                                    'estadoAsistencia' => 'justificada', // O 'vacaciones' si tienes ese estado
                                    'horasTrabajadas' => '00:00:00',
                                    'horas_extras' => '00:00:00',
                                    'fechaSalida' => $fechaActual,
                                    'horaEntrada' => null,
                                    'horaSalida' => null,
                                    'estado' => 1
                                ]
                            );
                        }
                    }
                }
                Log::info("PASO 2: Vacaciones procesadas.");

                // ---------------------------------------------------------
                // PASO 3: Recolectar IDs de Usuario (Filtrados)
                // ---------------------------------------------------------
                $listaIdsUnicos = [];
                $mapIdUsuarioADni = [];

                foreach ($mapEmpleados as $dni => $empleado) {
                    if ($empleado->usuario) {
                        $idUsuario = $empleado->usuario->id;
                        $listaIdsUnicos[] = $idUsuario;
                        $mapIdUsuarioADni[$idUsuario] = $dni;
                    }
                }
                $listaIdsUnicos = array_unique($listaIdsUnicos);

                // ---------------------------------------------------------
                // PASO 4: Aprobar Horas Extra Pendientes (Masivo)
                // ---------------------------------------------------------
                if (count($listaIdsUnicos) > 0) {
                    HoraExtras::where('fecha', '>=', $periodo->fecha_inicio)
                        ->where('fecha', '<=', $periodo->fecha_fin)
                        ->where('estado', 0) // Pendientes
                        ->whereIn('idUsuario', $listaIdsUnicos) // SOLO usuarios de esta sede
                        ->update(['estado' => 1]); // Aprobar
                }
                Log::info("PASO 4: Horas extras pendientes aprobadas.");

                // ---------------------------------------------------------
                // PASO 5: Aplicar Horas Extra a Asistencias (Formato H:i:s)
                // ---------------------------------------------------------
                $estadoAprobadoHE = 1;

                if (count($listaIdsUnicos) > 0) {
                    $horasExtrasAprobadas = HoraExtras::where('estado', $estadoAprobadoHE)
                        ->whereIn('idUsuario', $listaIdsUnicos)
                        ->where('fecha', '>=', $periodo->fecha_inicio)
                        ->where('fecha', '<=', $periodo->fecha_fin)
                        ->get();

                    foreach ($horasExtrasAprobadas as $horaExtra) {
                        if (isset($mapIdUsuarioADni[$horaExtra->idUsuario])) {
                            $dni = $mapIdUsuarioADni[$horaExtra->idUsuario];
                            try {
                                // Conversión Decimal -> Hora
                                $horasEnDecimal = (float) $horaExtra->horas_trabajadas;
                                $segundosTotales = $horasEnDecimal * 3600;
                                $formatoHis = gmdate('H:i:s', $segundosTotales);

                                Asistencia::where('codigoUsuario', $dni)
                                    ->where('fechaEntrada', $horaExtra->fecha)
                                    ->update(['horas_extras' => $formatoHis]);
                            } catch (\Exception $e) {
                                // Continuar si falla formato, no es crítico para detener todo
                            }
                        }
                    }
                }
                Log::info("PASO 5: Horas extras aplicadas a asistencias.");

                // ---------------------------------------------------------
                // PASO 6: Finalizar Transición
                // ---------------------------------------------------------
                $periodo->estado = 2; // Estado: En Validación
                $periodo->save();

                Log::info("Validación completada. Periodo ID: $idPeriodo movido a Estado 2.");

                return response()->json([
                    'success' => true,
                    'message' => 'Validación iniciada correctamente. Se han procesado incidencias, vacaciones y horas extra.'
                ], 200);
            }); // Fin Transacción

        } catch (\Exception $e) {
            Log::error("Error crítico en validación (Periodo $idPeriodo): " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al procesar la validación: ' . $e->getMessage()
            ], 500);
        }
    }


    public function validarNominaCompleta(Request $request, $idPeriodo)
    {
        Log::info("Iniciando CIERRE (Generar Pagos) para Periodo ID: $idPeriodo");

        try {
            DB::beginTransaction();

            // 1. Busca el periodo EN VALIDACIÓN (Estado 2)
            $periodo = PeriodoNomina::where('id', $idPeriodo)
                ->where('estado', 2)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. VERIFICA horas extras pendientes
            $horasExtraPendientes = HoraExtras::where('idPeriodo', $periodo->id)
                ->where('estado', 0)
                ->count();

            if ($horasExtraPendientes > 0) {
                throw new \Exception("No se puede cerrar. Aún existen $horasExtraPendientes horas extra pendientes de aprobación.");
            }

            // --- 3. LÓGICA DE CÁLCULO DE NÓMINA ---
            Log::info("Verificación OK. Generando pagos para Periodo ID: $idPeriodo...");

            // Calculamos pagos (usando la función corregida con filtros de sede/empresa)
            $totalNomina = $this->calcularPagosMasivos($periodo);

            // 4. Actualizar el Estado del Periodo Actual a CERRADO (3)
            $periodo->estado = 3;
            $periodo->save();

            // --- NUEVO PASO: 4.5 APERTURAR EL SIGUIENTE PERIODO ---
            // Buscamos el siguiente periodo cronológico de la misma empresa y sede
            $siguientePeriodo = PeriodoNomina::where('idEmpresa', $periodo->idEmpresa)
                ->where('idSede', $periodo->idSede)
                ->where('estado', 0) // Buscamos solo los que están en espera (0)
                ->where('fecha_inicio', '>', $periodo->fecha_inicio) // Que inicie después del actual
                ->orderBy('fecha_inicio', 'asc') // El más próximo
                ->first();

            $mensajeExtra = "";

            if ($siguientePeriodo) {
                $siguientePeriodo->estado = 1; // Lo pasamos a ABIERTO
                $siguientePeriodo->save();
                Log::info("Transición Automática: Se aperturó el periodo '{$siguientePeriodo->nombrePeriodo}' (ID: {$siguientePeriodo->id}).");
                $mensajeExtra = " Y se ha aperturado el periodo: " . $siguientePeriodo->nombrePeriodo;
            } else {
                Log::warning("Cierre completado, pero no se encontró un periodo futuro para abrir automáticamente.");
                $mensajeExtra = " (No se encontró un periodo siguiente para abrir).";
            }
            // -----------------------------------------------------

            // 5. Registrar en Contabilidad (Libro Diario)
            $this->RegistrarTransaccion($totalNomina);

            DB::commit();
            Log::info("Cierre completo. Periodo ID: $idPeriodo movido a Estado 3.");

            return response()->json([
                'success' => true,
                'message' => 'Nómina cerrada y pagos generados exitosamente.' . $mensajeExtra
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            Log::error("Error al cerrar periodo: Periodo no encontrado o estado incorrecto.");
            return response()->json(['success' => false, 'message' => 'Error: No se encontró el periodo en validación.'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error crítico al cerrar periodo (ID: $idPeriodo): " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function calcularPagosMasivos($periodo)
    {
        // LOG 1: Inicio del proceso
        Log::info("--- [DEBUG-PAGOS] INICIO del cálculo para Periodo ID: {$periodo->id} ---");

        $fechaInicio = $periodo->fecha_inicio;
        $fechaFin = $periodo->fecha_fin;

        $idEmpresa = $periodo->idEmpresa;
        $idSede = $periodo->idSede;

        // LOG 2: Verificar filtros de contexto
        Log::info("[DEBUG-PAGOS] Filtros -> Empresa: {$idEmpresa}, Sede: {$idSede}, Fechas: {$fechaInicio} al {$fechaFin}");

        $empleados = Empleado::with([
            'cargo',
            'bonificaciones',
            'deducciones',
            'usuario'
        ])
            ->where('estado', 1)
            ->where('idEmpresa', $idEmpresa)
            ->where('idSede', $idSede)
            ->get();

        // LOG 3: Resultado de la búsqueda de empleados
        $cantidad = $empleados->count();
        Log::info("[DEBUG-PAGOS] Empleados encontrados: {$cantidad}");

        if ($empleados->isEmpty()) {
            Log::warning("[DEBUG-PAGOS] ALERTA: No se encontraron empleados para esta Empresa/Sede. Retornando 0.");
            return 0;
        }

        $pagoTotalPlanilla = 0;
        $contadorProcesados = 0;

        foreach ($empleados as $empleado) {

            // LOG 4: Inicio iteración empleado
            Log::info("[DEBUG-PAGOS] > Procesando Empleado ID: {$empleado->id}");

            // 1. Evitar duplicados
            $pagoExistente = Pago::where('idEmpleado', $empleado->id)
                ->where('idPeriodo', $periodo->id)
                ->exists();

            if ($pagoExistente) {
                Log::info("[DEBUG-PAGOS] SKIP: El empleado {$empleado->id} ya tiene pago registrado en este periodo.");
                continue;
            }

            // A. SUELDO BASE Y BONOS
            $salarioBase = $empleado->cargo->salario;
            $totalBonificaciones = $empleado->bonificaciones->sum('monto');

            // B. VARIABLES (Horas Extra)
            $totalPagoHorasExtras = 0;
            if ($empleado->usuario) {
                $totalPagoHorasExtras = HoraExtras::where('idUsuario', $empleado->usuario->id)
                    ->where('idPeriodo', $periodo->id)
                    ->where('estado', 1)
                    ->sum('pagoTotal');
            }

            // C. DEDUCCIONES Y ADELANTOS
            $porcentajeDeduccion = $empleado->deducciones->sum('porcentaje');
            $montoDeduccionesFijas = round($salarioBase * $porcentajeDeduccion, 2);

            $adelantosSueldo = 0;
            if ($empleado->usuario) {
                $adelantosSueldo = AdelantoSueldo::where('idUsuario', $empleado->usuario->id)
                    ->where('estado', 1)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('monto');
            }

            // D. VACACIONES
            $pagoPorDiasVendidos = 0;
            $diasVendidos = 0;
            $diasUtilizados = 0;

            if ($empleado->usuario) {
                $vacaciones = Vacacione::where('idUsuario', $empleado->usuario->id)
                    ->where('estado', 1)
                    ->whereBetween('fecha_inicio', [$fechaInicio, $fechaFin])
                    ->first();

                if ($vacaciones) {
                    $diasUtilizados = $vacaciones->dias_utilizados ?? 0;
                    $diasVendidos = $vacaciones->dias_vendidos ?? 0;

                    if ($diasVendidos > 0) {
                        $pagoPorDiasVendidos = round(($salarioBase / 30) * $diasVendidos, 2);
                    }
                }
            }

            // E. CÁLCULO NETO
            $totalIngresos = $salarioBase + $totalBonificaciones + $totalPagoHorasExtras + $pagoPorDiasVendidos;
            $totalEgresos = $montoDeduccionesFijas + $adelantosSueldo;

            if ($totalEgresos > $totalIngresos) {
                $totalEgresos = $totalIngresos;
            }

            $salarioNeto = round($totalIngresos - $totalEgresos, 2);

            // LOG 5: Verificar cálculos antes de guardar
            Log::info("[DEBUG-PAGOS] Datos Calc -> Base: {$salarioBase}, Ingresos: {$totalIngresos}, Egresos: {$totalEgresos}, Neto: {$salarioNeto}");

            // F. GUARDAR PAGO
            try {
                $pago = new Pago();
                $pago->idEmpleado = $empleado->id;
                $pago->idEmpresa = $idEmpresa;
                $pago->idPeriodo = $periodo->id;

                $pago->fecha_pago = Carbon::now();
                $pago->salario_base = $salarioBase;
                $pago->deducciones = $montoDeduccionesFijas;
                $pago->bonificaciones = $totalBonificaciones;
                $pago->horas_extras = round($totalPagoHorasExtras, 2);
                $pago->adelantoSueldo = round($adelantosSueldo, 2);
                $pago->dias_vendidos = $diasVendidos;
                $pago->dias_utilizados = $diasUtilizados;
                $pago->salario_neto = $salarioNeto;

                $pago->save();

                Log::info("[DEBUG-PAGOS] OK: Pago guardado correctamente (ID Pago: {$pago->id})");
                $contadorProcesados++;
            } catch (\Exception $e) {
                // LOG CRÍTICO: Si falla el save()
                Log::error("[DEBUG-PAGOS] ERROR FATAL al guardar pago empleado {$empleado->id}: " . $e->getMessage());
                throw $e; // Re-lanzar para que falle la transacción
            }

            $pagoTotalPlanilla += $salarioNeto;
        }

        Log::info("--- [DEBUG-PAGOS] FIN. Total procesados: {$contadorProcesados}. Monto Total: {$pagoTotalPlanilla} ---");

        return $pagoTotalPlanilla;
    }
}
