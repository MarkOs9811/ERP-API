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
        Schema::create('categoria_platos', function (Blueprint $table) {
            $table->id(); // ID auto incremental
            $table->string('nombre', 255); // Nombre de la categoría
            $table->boolean('estado')->default(1); // Estado (activo o inactivo)
            $table->timestamps(); // Timestamps (created_at y updated_at)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categoria_platos');
    }
};
