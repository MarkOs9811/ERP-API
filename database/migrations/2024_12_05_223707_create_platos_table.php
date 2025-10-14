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
        Schema::create('platos', function (Blueprint $table) {
            $table->id(); // ID auto incremental
            $table->unsignedBigInteger('idCategoria'); // Relación con categoría de platos
            $table->string('nombre', 255); // Nombre del plato
            $table->text('descripcion')->nullable(); // Descripción del plato
            $table->decimal('precio', 8, 2); // Precio del plato (máximo 8 dígitos con 2 decimales)
            $table->string('foto')->nullable(); // Foto del plato (URL o ruta)
            $table->boolean('estado')->default(1); // Estado (activo o inactivo)
            $table->timestamps(); // Timestamps (created_at y updated_at)

            // Definir la clave foránea
            $table->foreign('idCategoria')
                ->references('id')
                ->on('categoria_platos')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platos');
    }
};
