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
        Schema::create('pedidos_web_registros', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_pedido')->unique(); // Código único del pedido
            $table->unsignedBigInteger('idCliente')->nullable(); // Puede ser null si es nuevo
            $table->string('nombre_cliente')->nullable(); // Nombre corto del cliente
            $table->string('numero_cliente'); // Número de WhatsApp del cliente
            $table->integer('tiempo_transcurrido')->default(0); // Aumentará cada 5 min si está pendiente
            $table->enum('estado_pago', ['pagado', 'por pagar'])->default('por pagar');
            $table->tinyInteger('estado_pedido')->default(1); // Estado del pedido en números
            $table->boolean('estado')->default(1); // 1 = Activo, 0 = Eliminado
            $table->timestamps();

            $table->foreign('idCliente')->references('id')->on('clientes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_web_registros');
    }
};
