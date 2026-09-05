<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Exception;

class ReiniciarSistema extends Command
{
    protected $signature = 'sistema:reinicio-limpio {--force : Forzar el reinicio sin confirmación por consola}';
    protected $description = 'Vacía transacciones de forma segura mediante transacciones atómicas';

    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('¿Estás seguro de vaciar el sistema?')) {
                return self::SUCCESS;
            }
        }

        try {
            DB::transaction(function () {

                DB::statement('SET FOREIGN_KEY_CHECKS=0');

                try {

                    $tablasAVaciar = [
                        'adelanto_sueldos',
                        'ajustes_estilos',
                        'ajustes_planillas',
                        'almacens',
                        'areas',
                        'asistencias',
                        'boletas',
                        'bonificaciones',
                        'cajas',
                        'campana_promos',
                        'cargo_roles',
                        'cargos',
                        'clientes',
                        'compras',
                        'configuracion_deliveries',
                        'cuentas_por_cobrars',
                        'cuentas_por_pagars',
                        'cuentas_saldadas',
                        'cuotas',
                        'cuotas_por_pagars',
                        'detalle_libros',
                        'detalle_pedidos',
                        'detalle_pedidos_webs',
                        'direcciones',
                        'documentos_firmados',
                        'empleado_bonificaciones',
                        'empleado_deducciones',
                        'empresas',
                        'empresa_roles',
                        'estado_pedidos',
                        'eventos',
                        'facturas',
                        'failed_jobs',
                        'feedbacks',
                        'horarios',
                        'hora_extras',
                        'incidencias',
                        'inventarios',
                        'kardexes',
                        'libro_diarios',
                        'libro_mayors',
                        'mesas',
                        'mesa_reservas',
                        'metodo_pagos',
                        'metodos_pago_clientes',
                        'mi_empresas',
                        'movimientos',
                        'notificaciones',
                        'pagos',
                        'password_reset_tokens',
                        'pedido_mesa_registros',
                        'pedidos',
                        'pedidos_web_registros',
                        'periodo_nominas',
                        'permisos',
                        'personal_access_tokens',
                        'platos',
                        'presupuestacions',
                        'preventa_mesas',
                        'preventas',
                        'promociones_apps',
                        'promotional_banners',
                        'proveedores',
                        'registros_cajas',
                        'registros_ejercicios',
                        'roles',
                        'role_users',
                        'sedes',
                        'serie_correlativos',
                        'solicitudes',
                        'user_rol_permisos',
                        'vacaciones',
                        'ventas',
                        'personas',
                        'empleados',
                        'users',
                    ];

                    foreach ($tablasAVaciar as $tabla) {
                        DB::table($tabla)->delete();
                    }

                    Artisan::call('db:seed', [
                        '--class' => 'SuperAdminSeeder',
                        '--force' => true,
                    ]);

                    Artisan::call('db:seed', [
                        '--class' => 'UbigeoSeeder',
                        '--force' => true,
                    ]);

                    // Si el seeder terminó correctamente, continuamos.

                } finally {

                    // SIEMPRE volver a activar las FK
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            });

            $this->info('¡Reinicio atómico completado con éxito!');

            return self::SUCCESS;
        } catch (\Throwable $e) {

            $this->error(
                'El reinicio falló. Se realizó rollback de la transacción.'
            );

            report($e);

            throw $e;
        }
    }
}
