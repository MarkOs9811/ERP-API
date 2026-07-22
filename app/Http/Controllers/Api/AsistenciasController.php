<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia;
use App\Models\Empleado;
use App\Models\HoraExtras;
use App\Models\Horario;
use App\Models\Persona;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsistenciasController extends Controller
{
    public function ingreso(Request $request)
    {
        try {
            $dni = $request->dni;
            $persona = Persona::where('documento_identidad', $dni)->first();

            if (!$persona) {
                return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);
            }

            $empleado = Empleado::where('idPersona', $persona->id)->first();
            $horario = Horario::find($empleado->idHorario);
            $horaEntrada = Carbon::parse($horario->horaEntrada);
            $horaActual = Carbon::now();
            $fechaHoy = $horaActual->toDateString();

            // Verificar si ya ha registrado entrada hoy
            $asistenciaExistente = Asistencia::where('codigoUsuario', $dni)
                ->whereDate('fechaEntrada', $fechaHoy)
                ->whereNull('fechaSalida')
                ->whereNull('horaSalida')
                ->whereNull('horasTrabajadas')
                ->where('estado', 0)
                ->first();

            if ($asistenciaExistente) {
                return response()->json(['success' => false, 'message' => 'Ya has registrado tu entrada hoy'], 400);
            }

            $estadoAsistencia = $horaActual->greaterThan($horaEntrada) ? 'tardanza' : 'a tiempo';

            $asistencia = new Asistencia();
            $asistencia->codigoUsuario = $dni;
            $asistencia->fechaEntrada = $horaActual->toDateString();
            $asistencia->horaEntrada = $horaActual->toTimeString();
            $asistencia->estadoAsistencia = $estadoAsistencia;
            $asistencia->estado = 0;
            $asistencia->save();

            return response()->json(['success' => true, 'message' => 'Entrada registrada con éxito'], 200);
        } catch (\Exception $e) {
            // Manejo de excepciones
            return response()->json(['success' => false, 'message' => 'Error en el registro de entrada: ' . $e->getMessage()], 500);
        }
    }
    public function salida(Request $request)
    {
        try {
            $dni = $request->dni;
            $salidaReal = Carbon::now(); // Fecha y hora actual completa

            // 1. Buscar el registro pendiente
            $asistencia = Asistencia::where('codigoUsuario', $dni)
                ->whereNull('fechaSalida')
                ->whereNull('horaSalida')
                ->where('estado', 0)
                ->first();

            if (!$asistencia) {
                return response()->json(['success' => false, 'message' => 'No hay entrada pendiente o ya marcó salida.'], 404);
            }

            // Relaciones (Asegúrate de tener foreign keys para cargar esto con Eager Loading en el futuro: $asistencia->empleado->horario)
            $persona = Persona::where('documento_identidad', $dni)->first();
            if (!$persona) return response()->json(['success' => false, 'message' => 'Persona no encontrada'], 404);

            $empleado = Empleado::where('idPersona', $persona->id)->first();
            if (!$empleado) return response()->json(['success' => false, 'message' => 'Empleado no encontrado'], 404);

            $horario = Horario::find($empleado->idHorario);
            if (!$horario) return response()->json(['success' => false, 'message' => 'Horario no encontrado'], 404);

            $usuario = User::where('idEmpleado', $empleado->id)->first();

            // 2. HORAS EXTRAS - Usar la fecha de ENTRADA, NO la de hoy
            if ($usuario) {
                $horasExtrasPendiente = HoraExtras::where('idUsuario', $usuario->id)
                    ->whereDate('fecha', '=', Carbon::parse($asistencia->fechaEntrada)->toDateString())
                    ->first();

                if ($horasExtrasPendiente) {
                    $horasExtrasPendiente->estado = 1;
                    $horasExtrasPendiente->save();
                }
            }

            // 3. Cálculos de tiempo usando Datetimes combinados (Evita errores de cambio de día)
            $entradaCompleta = Carbon::parse($asistencia->fechaEntrada . ' ' . $asistencia->horaEntrada);
            $salidaHorarioCompleta = Carbon::parse($asistencia->fechaEntrada . ' ' . $horario->horaSalida);

            // Si el horario de salida cruza la medianoche (ej. entra 10 PM sale 6 AM)
            if ($salidaHorarioCompleta->lessThan($entradaCompleta)) {
                $salidaHorarioCompleta->addDay();
            }

            $diferenciaMinutosTotales = $entradaCompleta->diffInMinutes($salidaReal);
            $horasTrabajadas = floor($diferenciaMinutosTotales / 60);

            // 4. Calcular horas extras
            $horasExtras = 0;
            $minutosExtrasRestantes = 0;

            if ($salidaReal->greaterThan($salidaHorarioCompleta)) {
                $minutosExtrasTotales = $salidaHorarioCompleta->diffInMinutes($salidaReal);

                // 🔥 REGLA DE NEGOCIO (Evitar fraude por olvido de marcado)
                // Si pasan más de 4 horas extras (240 min), se limita y requiere revisión de RRHH.
                if ($minutosExtrasTotales > 240) {
                    $minutosExtrasTotales = 240; // Tope máximo automático (Ajusta este valor según política)
                    // Opcional: Aquí podrías disparar un flag "requiere_auditoria = true"
                }

                $horasExtras = floor($minutosExtrasTotales / 60);
                $minutosExtrasRestantes = $minutosExtrasTotales % 60;
            }

            // 5. Guardar
            $asistencia->fechaSalida = $salidaReal->toDateString();
            $asistencia->horaSalida = $salidaReal->toTimeString();
            // Si tu política indica que las horas trabajadas regulares siempre son 8, mantenemos tu lógica, sino usarías $horasTrabajadas
            $asistencia->horasTrabajadas = '08:00:00';
            $asistencia->horas_extras = sprintf('%02d:%02d:00', $horasExtras, $minutosExtrasRestantes);
            $asistencia->estado = 1;
            $asistencia->save();

            return response()->json(['success' => true, 'message' => 'Salida registrada con éxito'], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
    public function getAsistencia()
    {
        try {
            $totalEmpleados = Empleado::count();

            $empleadosAusentes = Empleado::leftJoin('personas', 'empleados.idPersona', '=', 'personas.id')
                ->leftJoin('asistencias', function ($join) {
                    $join->on('personas.documento_identidad', '=', 'asistencias.codigoUsuario')
                        ->whereDate('asistencias.fechaEntrada', '=', now()->toDateString());
                })
                ->whereNull('asistencias.id')
                ->count();

            $empleadosATiempo = Empleado::join('personas', 'empleados.idPersona', '=', 'personas.id')
                ->join('asistencias', 'personas.documento_identidad', '=', 'asistencias.codigoUsuario')
                ->where('asistencias.estadoAsistencia', 'a tiempo')
                ->where('asistencias.estado', 0) // solo registros sin salida
                ->whereIn('asistencias.id', function ($query) {
                    $query->select(DB::raw('MAX(id)'))
                        ->from('asistencias as a2')
                        ->whereColumn('a2.codigoUsuario', 'asistencias.codigoUsuario')
                        ->groupBy('a2.codigoUsuario');
                })
                ->count();

            $empleadosTardanza = Empleado::join('personas', 'empleados.idPersona', '=', 'personas.id')
                ->join('asistencias', 'personas.documento_identidad', '=', 'asistencias.codigoUsuario')
                ->where('asistencias.estadoAsistencia', 'tardanza')
                ->where('asistencias.estado', 0)
                ->whereIn('asistencias.id', function ($query) {
                    $query->select(DB::raw('MAX(id)'))
                        ->from('asistencias as a2')
                        ->whereColumn('a2.codigoUsuario', 'asistencias.codigoUsuario')
                        ->groupBy('a2.codigoUsuario');
                })
                ->count();

            Log::info([
                'a_tiempo' => $empleadosATiempo,
                'tarde'    => $empleadosTardanza
            ]);


            $asistenciaHoy = Asistencia::select('estadoAsistencia', DB::raw('count(*) as count'))
                ->where(function ($query) {
                    $query->whereDate('fechaEntrada', now()->toDateString())
                        ->orWhere(function ($q) {
                            $q->whereDate('fechaEntrada', Carbon::yesterday()->toDateString())
                                ->where('estado', 0); // Aún no ha salido
                        });
                })
                ->groupBy('estadoAsistencia')
                ->get();

            $listaAsistenciaHoy = Asistencia::with(['empleado.empleado.usuario'])
                ->where(function ($query) {
                    $query->whereDate('fechaEntrada', '=', now()->toDateString()) // Asistencias de hoy
                        ->orWhere(function ($subQuery) {
                            $subQuery->whereDate('fechaEntrada', '<', now()->toDateString()) // Entraron antes de hoy
                                ->where('estado', 0); // Y aún no salieron
                        });
                })
                ->get();


            $datosPorMes = [];
            $asistenciasPorMes = Asistencia::select(DB::raw('MONTH(fechaEntrada) as mes'), 'estadoAsistencia', DB::raw('count(*) as total'))
                ->whereDate('fechaEntrada', '<=', now())
                ->groupBy('mes', 'estadoAsistencia')
                ->get();

            foreach ($asistenciasPorMes as $asistencia) {
                $mesNombre = date('F', mktime(0, 0, 0, $asistencia->mes, 1));
                if (!isset($datosPorMes[$mesNombre])) {
                    $datosPorMes[$mesNombre] = [
                        'A tiempo' => 0,
                        'Tardanza' => 0
                    ];
                }

                if ($asistencia->estadoAsistencia === 'a tiempo') {
                    $datosPorMes[$mesNombre]['A tiempo'] += $asistencia->total;
                } elseif ($asistencia->estadoAsistencia === 'tardanza') {
                    $datosPorMes[$mesNombre]['Tardanza'] += $asistencia->total;
                }
            }

            $datosObtenidos = [
                'totalEmpleados' => $totalEmpleados,
                'empleadosAusentes' => $empleadosAusentes,
                'empleadosATiempo' => $empleadosATiempo,
                'empleadosTardanza' => $empleadosTardanza,
                'asistenciaHoy' => $asistenciaHoy,
                'listaAsistenciaHoy' => $listaAsistenciaHoy,
                'datosPorMes' => $datosPorMes
            ];

            return response()->json([
                'success' => true,
                'data' => $datosObtenidos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos de asistencia.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getRegistroAsistencias()
    {
        try {

            $asistencias = Asistencia::with(['empleado' => function ($q) {
                $q->select('documento_identidad', 'nombre', 'apellidos');
            }])
                ->orderBy('fechaEntrada', 'desc') // Ordenar por fecha descendente por defecto
                ->get();


            return response()->json(['success' => true, 'data' => $asistencias], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
