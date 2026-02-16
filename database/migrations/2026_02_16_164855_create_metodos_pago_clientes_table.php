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
        Schema::create('metodos_pago_clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idCliente');

            // Enum ampliado
            $table->enum('tipo', ['TARJETA', 'YAPE', 'PLIN', 'EFECTIVO', 'POS'])->default('TARJETA');

            // Datos Tarjeta
            $table->string('banco')->nullable();
            $table->string('numero_tarjeta')->nullable(); // **** 1234
            $table->string('marca')->nullable();

            // Datos Billetera Digital (NUEVO)
            $table->string('telefono_vinculado')->nullable(); // Para Yape/Plin

            // Visual
            $table->string('color')->nullable();
            $table->string('token_pasarela')->nullable(); // Token de Culqi/Izipay/MercadoPago

            $table->boolean('estado')->default(1);
            $table->foreign('idCliente')->references('id')->on('clientes')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metodos_pago_clientes');
    }
};
