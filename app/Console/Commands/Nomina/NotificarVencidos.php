<?php

namespace App\Console\Commands\Nomina;

// 1. IMPORTACIONES DE TODO LO QUE USAREMOS
use Illuminate\Console\Command;
use App\Models\PeriodoNomina;
use App\Models\User; // O tu modelo de Admin
use Illuminate\Support\Facades\Notification;
use App\Notifications\PeriodoVencidoNotification; // La notificación que ya creamos
use Carbon\Carbon;
use Illuminate\Support\Facades\DB; // Para tu tabla personalizada
use Illuminate\Support\Facades\Log; // Para registrar logs

class NotificarVencidos extends Command
{

    protected $signature = 'nomina:notificar-vencidos';


    protected $description = 'Busca periodos abiertos (estado 1) que ya vencieron y notifica a los admins';


    /**
     * Ejecuta la lógica del comando.
     */
    public function handle()
    {
        Log::info('[CronJob] Iniciando nomina:notificar-vencidos...');
        $this->info('Buscando periodos vencidos para notificar...');

        // --- PASO A: Buscar periodos vencidos ---
        // (Esto ya incluye el idEmpresa en cada objeto $periodo)
        $periodosVencidos = PeriodoNomina::where('estado', 1) // Que estén Abiertos
            ->where('fecha_fin', '<', Carbon::today()) // Cuya fecha fin ya pasó
            ->get();

        if ($periodosVencidos->isEmpty()) {
            Log::info('[CronJob] No se encontraron periodos vencidos.');
            $this->info('No se encontraron periodos vencidos.');
            return 0; // 0 = Éxito
        }

        // --- PASO B (ELIMINADO) ---
        // Ya no buscamos a todos los admins aquí.

        $fechaActual = Carbon::now();
        $nombreCargoAdmin = 'administrador'; // El nombre del cargo que buscamos

        // --- PASO C: Iterar sobre CADA periodo vencido ---
        foreach ($periodosVencidos as $periodo) {

            $idEmpresaDelPeriodo = $periodo->idEmpresa;
            $idSedeDelPeriodo = $periodo->idSede;
            $this->warn("Periodo ID: {$periodo->id} (Empresa: {$idEmpresaDelPeriodo} Sede: {$idSedeDelPeriodo}) está VENCIDO.");

            // --- PASO D: Buscar admins SÓLO de esta empresa (Corregido) ---

            $adminsDeEstaEmpresa = User::where('idEmpresa', $idEmpresaDelPeriodo) // 1. Filtra Users por la Empresa correcta
                ->whereHas('empleado.cargo', function ($cargoQuery) use ($nombreCargoAdmin) {
                    // 2. Y que además tengan el cargo de admin
                    $cargoQuery->where('nombre', $nombreCargoAdmin);
                })
                ->where('idSede', $idSedeDelPeriodo)
                ->get();

            // --- PASO E: Notificar SÓLO a esos admins ---
            if ($adminsDeEstaEmpresa->isEmpty()) {
                Log::warning("[CronJob] Periodo {$periodo->id} vencido, pero no se encontraron admins para notificar en la Empresa ID: {$idEmpresaDelPeriodo}. Sede ID{$idSedeDelPeriodo}");
                continue; // Pasamos al siguiente periodo vencido
            }

            foreach ($adminsDeEstaEmpresa as $admin) {

                // 1. Enviar el Email
                $admin->notify(new PeriodoVencidoNotification($periodo));

                // 2. Escribir en tu tabla 'notificaciones' personalizada
                DB::table('notificaciones')->insert([
                    'idEmpresa' => $idEmpresaDelPeriodo,
                    'idSede' => $idSedeDelPeriodo,
                    'idUsuario' => $admin->id,
                    'mensaje' => "El periodo {$periodo->nombre} debe ser validado.",
                    'titulo' => 'Periodo Vencido',
                    'estado' => 0, // 0 = No leído
                    'created_at' => $fechaActual,
                    'updated_at' => $fechaActual,

                    // --- CAMPOS NUEVOS ---
                    'tipo' => 'alerta',
                    'prioridad' => 'alta'
                ]);
            }
        }

        Log::info("[CronJob] Notificaciones de periodos vencidos enviadas.");
        $this->info('Notificaciones enviadas y registradas en la DB.');
        return 0;
    }
}
