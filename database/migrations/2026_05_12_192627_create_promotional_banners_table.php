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
        Schema::create('promotional_banners', function (Blueprint $table) {
            $table->id();

            // 1. Definición de las columnas para los IDs
            $table->unsignedBigInteger('idEmpresa');
            $table->unsignedBigInteger('idSede');

            // 2. Creación de las relaciones (Llaves Foráneas)
            $table->foreign('idEmpresa')->references('id')->on('mi_empresas');
            $table->foreign('idSede')->references('id')->on('sedes');

            // Textos (algunos permitimos que sean nulos por si no los llenan)
            $table->string('tag')->nullable();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('offer')->nullable();
            $table->string('code')->nullable();

            // Configuración Visual
            $table->string('theme')->default('custom');
            $table->boolean('has_icon')->default(false);
            $table->string('icon_name')->nullable();
            $table->string('border_radius')->nullable();

            // Personalización Avanzada (Colores)
            $table->string('bg_color')->default('#ffffff');
            $table->string('text_color')->default('#000000');

            $table->boolean('gradient')->default(false);
            $table->string('gradient_color')->nullable();

            $table->boolean('has_aura')->default(false);
            $table->string('aura_color')->nullable();

            // Campo extra recomendado para activar/desactivar el banner en tu app
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotional_banners');
    }
};
