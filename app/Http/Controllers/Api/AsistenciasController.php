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
            $horaActual = Carbon::now();

            // Buscar el registro de asistencia de hoy sin hora de salida
            $asistencia = Asistencia::where('codigoUsuario', $dni)
                ->whereNull('fechaSalida')
                ->whereNull('horaSalida')
                ->whereNull('horasTrabajadas')
                ->where('estado', 0)
                ->first();

            if (!$asistencia) {
                return response()->json(['success' => false, 'message' => 'No se encontró un registro de entrada para el DNI proporcionado o ya se ha registrado la salida'], 404);
            }

            // Obtener la hora de salida del horario del empleado
            $persona = Persona::where('documento_identidad', $dni)->first();
            if (!$persona) {
                return response()->json(['success' => false, 'message' => 'No se encontró un registro para el DNI proporcionado'], 404);
            }
            $empleado = Empleado::where('idPersona', $persona->id)->first();
            if (!$empleado) {
                return response()->json(['success' => false, 'message' => 'No se encontró un empleado para la persona proporcionada'], 404);
            }
            $horario = Horario::find($empleado->idHorario);
            if (!$horario) {
                return response()->json(['success' => false, 'message' => 'No se encontró un horario para el empleado proporcionado'], 404);
            }

            $usuario = User::where('idEmpleado', $empleado->id)->first();

            if ($usuario) {
                // BUSCAR SI TIENE HORAS EXTRAS EL DIA DE HOY
                $horasExtras = HoraExtras::where('idUsuario', $usuario->id)
                    ->whereDate('fecha', '=', now()->toDateString())
                    ->first();

                if ($horasExtras) {
                    $horasExtras->estado = 1;
                    $horasExtras->save();
                }
            }

            // Calcular horas trabajadas considerando fecha y hora de entrada y salida
            $fechaEntrada = Carbon::parse($asistencia->fechaEntrada);
            $horaEntrada = Carbon::parse($asistencia->horaEntrada);
            $fechaSalida = Carbon::parse($horaActual->toDateString());
            $horaSalida = Carbon::parse($horaActual->toTimeString());

            // Calcular la diferencia en minutos y luego convertir a horas y minutos
            $diferenciaMinutos = $fechaEntrada->diffInMinutes($fechaSalida) + $horaEntrada->diffInMinutes($horaSalida);
            $horasTrabajadas = floor($diferenciaMinutos / 60); // Horas trabajadas
            $minutosTrabajados = $diferenciaMinutos % 60; // Minutos restantes

            // Calcular la hora de salida del horario del empleado
            $horaSalidaHorario = Carbon::parse($horario->horaSalida);

            // Inicializar horas extras
            $horasExtras = 0;
            $minutosExtrasRestantes = 0;

            // Verificar si se debe registrar horas extras
            if ($horaActual->greaterThan($horaSalidaHorario)) {
                // Calcular las horas extras en minutos desde la hora de salida
                $minutosExtras = $horaSalidaHorario->diffInMinutes($horaActual);
                $horasExtras = floor($minutosExtras / 60); // Horas extras
                $minutosExtrasRestantes = $minutosExtras % 60; // Minutos extras restantes
            }

            // Mantener horas trabajadas en 8 horas si no hay horas extras
            $horasTrabajadasFormateadas = '08:00:00';
            $horasExtrasFormateadas = sprintf('%02d:%02d:00', $horasExtras, $minutosExtrasRestantes); // Formato HH:MM:SS

            // Actualizar el registro de asistencia con los datos calculados
            $asistencia->fechaSalida = $horaActual->toDateString();
            $asistencia->horaSalida = $horaActual->toTimeString();
            $asistencia->horasTrabajadas = $horasTrabajadasFormateadas;
            $asistencia->horas_extras = $horasExtrasFormateadas;
            $asistencia->estado = 1;
            $asistencia->save();

            return response()->json(['success' => true, 'message' => 'Salida registrada con éxito'], 200);
        } catch (\Exception $e) {
            // Manejo de excepciones
            return response()->json(['success' => false, 'message' => 'Error al registrar la salida: ' . $e->getMessage()], 500);
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
