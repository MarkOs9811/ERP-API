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
        Schema::create('configracion_deliveries', function (Blueprint $table) {
            $table->id();

            // Relación con tu tabla personalizada 'mi_Empresas'
            $table->unsignedBigInteger('idEmpresa');
            $table->foreign('idEmpresa')
                ->references('id')
                ->on('mi_Empresas')
                ->onDelete('cascade');

            // Costos (con 2 decimales)
            $table->decimal('costo_base_delivery', 8, 2)->default(5.00);
            $table->decimal('costo_prioridad', 8, 2)->default(2.00)
                ->comment('Costo extra por envío rápido');

            // Tiempos (minutos)
            $table->integer('tiempo_min')->default(45);
            $table->integer('tiempo_max')->default(65);

            // Array de propinas (JSON)
            // Guardará algo como: [2.00, 3.90, 5.00]
            $table->json('propinas_sugeridas')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configracion_deliveries');
    }
};
