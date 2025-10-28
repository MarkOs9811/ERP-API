<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('periodo_nominas', function (Blueprint $table) {
            // ID principal de la tabla
            $table->id();

            // Para identificar a qué empresa pertenece el periodo
            $table->unsignedBigInteger('idEmpresa');

            // Para identificar a qué sede (sucursal) pertenece
            $table->unsignedBigInteger('idSede');

            // --- Columnas de Datos (Tu solicitud) ---

            // Nombre descriptivo. Ej: "Noviembre 2024"
            $table->string('nombrePeriodo', 100);

            // El primer día que cuenta para este periodo
            $table->date('fecha_inicio');

            // El último día (la "Fecha de Corte" que discutimos)
            $table->date('fecha_fin');

            /**
             * Estado del periodo (La clave de nuestra lógica de seguridad)
             * 0 = Pendiente (Aún no empieza)
             * 1 = Abierto (Activo, recibiendo marcas)
             * 2 = En_Validacion (Cerrado, subsanando incidencias)
             * 3 = Cerrado (Pagado y bloqueado)
             */
            $table->integer('estado')->default(0);

            // --- Timestamps y Foreign Keys (Buenas Prácticas) ---

            // Campos 'created_at' y 'updated_at'
            $table->timestamps();

            // Definimos las llaves foráneas (asumiendo que tus tablas se llaman 'empresas' y 'sedes')
            $table->foreign('idEmpresa')->references('id')->on('mi_empresas');
            $table->foreign('idSede')->references('id')->on('sedes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periodo_nominas');
    }
};
