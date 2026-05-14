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
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();

            // Relación a la venta
            $table->unsignedBigInteger('idVenta')->nullable();

            // Datos netos para analítica
            $table->tinyInteger('calificacion'); // 1-5
            $table->enum('categoria', ['comida', 'servicio', 'delivery', 'general'])->default('general');
            $table->text('comentario')->nullable(); // Ahora es el foco de la tabla

            // Eliminamos el campo 'estado'

            $table->timestamps();
            $table->foreign('idVenta')->references('id')->on('ventas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
