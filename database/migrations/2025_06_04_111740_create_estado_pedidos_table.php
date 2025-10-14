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
        Schema::create('estado_pedidos', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_pedido', ['mesa', 'llevar', 'web']);
            $table->text('detalle_platos');

            $table->unsignedBigInteger('idPreventaMesa')->nullable();
            $table->unsignedBigInteger('idPedidoLLevar')->nullable(); // apunta a `pedidos`
            $table->unsignedBigInteger('idPedidoWsp')->nullable();    // apunta a `pedidos_web_registros`

            $table->text('detalle_cliente')->nullable();
            $table->tinyInteger('estado')->default(0); // 0: pendiente, 1: atendido
            $table->timestamps();

            // Claves foráneas
            $table->foreign('idPreventaMesa')
                ->references('id')->on('preventa_mesas')
                ->onDelete('set null');

            $table->foreign('idPedidoLLevar')
                ->references('id')->on('pedidos')
                ->onDelete('set null');

            $table->foreign('idPedidoWsp')
                ->references('id')->on('pedidos_web_registros')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estado_pedidos');
    }
};
