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
        Schema::create('direcciones', function (Blueprint $table) {
            $table->id();
            // Relación con la tabla clientes
            $table->unsignedBigInteger('idCliente')->nullable();

            $table->string("alias")->nullable(); // Ej: Casa, Trabajo
            $table->string("calle")->nullable();
            $table->string("numero")->nullable();
            $table->string("detalles")->nullable(); // Ej: Piso 2, Dpto 201
            $table->string("latitud")->nullable();
            $table->string("longitud")->nullable();

            // Estado: 1 = Activo, 0 = Desactivado
            $table->boolean('estado')->default(1);

            // Foreign Key hacia la tabla 'clientes'
            $table->foreign('idCliente')->references('id')->on('clientes')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('direcciones');
    }
};
