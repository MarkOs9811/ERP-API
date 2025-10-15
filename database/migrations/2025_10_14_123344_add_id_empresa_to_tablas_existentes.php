<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tablas = [
            'adelante_de_sueldo',
            'ajustes_planillas',
            'almacens',
            'areas',
            'asistencias',
            'boletas',
            'bonificaciones',
            'cajas',
            'cargos',
            'cargos_roles',
            'categorias',
            'categoria_platos',
            'clientes',
            'compras',
            'cuentas_contables',
            'cuentas_por_cobrars',
            'cuentas_por_pagars',
            'cuentas_saldadas',
            'cuotas',
            'cuotas_por_pagars',
            'deducciones',
            'detalle_libros',
            'detalle_pedidos',
            'detalle_pedidos_web',
            'documentos_firmados',
            'empleados',
            'empleado_bonificaciones',
            'empleado_deducciones',
            'estado_pedidos',
            'eventos',
            'facturas',
            'grupo_cuentas',
            'horarios',
            'hora_extras',
            'incidencias',
            'inventarios',
            'kardexes',
            'libro_diarios',
            'libro_mayors',
            'mesas',
            'metodo_pagos',
            'movimientos',
            'notificaciones',
            'pagos',
            'pedidos',
            'pedidos_web_registros',
            'pedido_mesa_registros',
            'personas',
            'platos',
            'presupuestacions',
            'preventas',
            'preventas_mesas',
            'proveedores',
            'registros_cajas',
            'registros_ejercicios',
            'sedes',
            'solicitudes',
            'tipo_contratos',
            'unidad_medidas',
            'users',
            'vacaciones',
            'ventas',
        ];

        foreach ($tablas as $tabla) {
            if (Schema::hasTable($tabla) && !Schema::hasColumn($tabla, 'idEmpresa')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->unsignedBigInteger('idEmpresa')->default(2)->after('id');
                    $table->foreign('idEmpresa')->references('id')->on('mi_empresas')->onDelete('cascade');
                });

                // Actualizar registros existentes con idEmpresa = 2
                DB::table($tabla)->update(['idEmpresa' => 2]);
            }
        }
    }

    public function down(): void
    {
        $tablas = [
            // mismas tablas que arriba
        ];

        foreach ($tablas as $tabla) {
            if (Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'idEmpresa')) {
                Schema::table($tabla, function (Blueprint $table) {
                    $table->dropForeign([$table->getTable() . '_idempresa_foreign']);
                    $table->dropColumn('idEmpresa');
                });
            }
        }
    }
};
