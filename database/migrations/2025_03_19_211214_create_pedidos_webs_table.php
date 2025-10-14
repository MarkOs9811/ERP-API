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
        Schema::create('pedidos_webs', function (Blueprint $table) {
            $table->id();
            $table->string('cliente'); // Número de WhatsApp del cliente
            $table->text('mensaje'); // Mensaje recibido
            $table->enum('estado', ['1', '2', '3'])->default('1'); // Estado del pedido
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_webs');
    }
};
