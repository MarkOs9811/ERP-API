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
        Schema::table('ventas', function (Blueprint $table) {
            // Agregamos idCaja. Puede ser nullable temporalmente para no romper datos viejos
            $table->unsignedBigInteger('idCaja')->nullable()->after('idSede');

            // Opcional pero recomendado: llave foránea
            // $table->foreign('idCaja')->references('id')->on('cajas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('idCaja');
        });
    }
};
