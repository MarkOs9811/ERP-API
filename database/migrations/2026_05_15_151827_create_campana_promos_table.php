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
        Schema::create('campana_promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idEmpresa')->constrained('empresas')->onDelete('cascade');
            $table->foreignId('idSede')->constrained('sedes')->onDelete('cascade');


            $table->string('nombre');
            $table->enum('tipo', ['cupon', 'puntos', 'recompensa'])->default('cupon');

            $table->string('codigo_cupon')->nullable()->unique();
            $table->enum('tipo_descuento', ['porcentaje', 'monto_fijo'])->nullable();
            $table->decimal('valor_descuento', 10, 2)->nullable();
            $table->decimal('monto_minimo_compra', 10, 2)->nullable();
            $table->integer('limite_uso')->nullable()->comment('Límite total de usos de la campaña');

            // Vigencia y estado
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('estado')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campana_promos');
    }
};
