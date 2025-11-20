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
        Schema::create('empresa_roles', function (Blueprint $table) {
            $table->id();

            // --- 1. PRIMERO: Declaramos las columnas ---
            $table->unsignedBigInteger('idEmpresa'); // <--- ESTA LÍNEA DEBE IR PRIMERO
            $table->unsignedBigInteger('idRole');    // <--- ESTA TAMBIÉN

            // --- 2. SEGUNDO: Declaramos las restricciones (Foreign Keys) ---

            // Relación con Empresas
            $table->foreign('idEmpresa')
                ->references('id')
                ->on('mi_empresas') // Asegúrate que tu tabla se llame así en la BD
                ->onDelete('cascade');

            // Relación con Roles
            $table->foreign('idRole')
                ->references('id')
                ->on('roles') // Asegúrate que tu tabla se llame 'roles'
                ->onDelete('cascade');

            // Otros campos
            $table->boolean('estado')->default(1);
            $table->date('fecha_expiracion')->nullable();

            $table->timestamps();

            // Evitar duplicados
            $table->unique(['idEmpresa', 'idRole']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresa_roles');
    }
};
