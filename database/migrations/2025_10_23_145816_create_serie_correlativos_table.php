<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Laravel usará el plural 'sere_correlativos' como nombre de tabla
        Schema::create('serie_correlativos', function (Blueprint $table) {

            $table->id();
            $table->unsignedBigInteger('idEmpresa');
            $table->unsignedBigInteger('idSede');
            $table->string('tipo_documento_sunat', 2)
                ->comment("Código SUNAT: 01=Factura, 03=Boleta, 07=N.Crédito, 08=N.Débito");
            $table->string('serie', 4)->comment("Serie del comprobante: F001, B001, etc.");
            $table->bigInteger('correlativo_actual')
                ->default(0)
                ->comment("Último número correlativo utilizado para esta serie");
            $table->boolean('estado')->default(1);
            // Timestamps de Laravel
            $table->timestamps(); // Agrega created_at y updated_at
            $table->foreign('idEmpresa')->references('id')->on('mi_empresas')->onDelete('cascade');
            $table->foreign('idSede')->references('id')->on('sedes')->onDelete('cascade');

            $table->unique(
                ['idEmpresa', 'idSede', 'tipo_documento_sunat', 'serie'],
                'idx_serie_unica_por_sede' // Nombre opcional para el índice
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serie_correlativos');
    }
};
