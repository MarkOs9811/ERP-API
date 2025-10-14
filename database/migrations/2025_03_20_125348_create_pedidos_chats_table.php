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
        Schema::create('pedidos_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('idPedido')->constrained('pedidos_webs')->onDelete('cascade'); // Relación con pedidos
            $table->foreignId('idUsuario')->nullable()->constrained('users')->onDelete('set null'); // Si el mensaje es de un operador
            $table->enum('remitente', ['cliente', 'operador']); // Quién envió el mensaje
            $table->text('mensaje'); // Mensaje enviado
            $table->boolean('estado')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos_chats');
    }
};
