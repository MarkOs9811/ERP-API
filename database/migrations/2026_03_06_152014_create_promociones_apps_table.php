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
        Schema::create('promociones_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idEmpresa')->constrained('mi_empresas')->onDelete('cascade');

            $table->foreignId('idSede')->constrained('sedes')->onDelete('cascade');
            $table->foreignId('idPlato')->constrained('platos')->onDelete('cascade');

            $table->string('titulo');

            // --- AQUÍ ESTÁ LA MAGIA ---
            $table->integer('porcentaje_descuento')->nullable()->comment('Ejemplo: 20 para 20%');
            $table->decimal('precio_promocional', 8, 2)->comment('El precio final que pagará el cliente');
            // --------------------------

            $table->string('imagen_banner')->nullable();
            $table->dateTime('fecha_inicio')->nullable();
            $table->dateTime('fecha_fin')->nullable();
            $table->boolean('estado')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promociones_apps');
    }
};
