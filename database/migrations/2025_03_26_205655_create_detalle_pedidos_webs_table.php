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
        Schema::create('detalle_pedidos_webs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idPedido'); // Relación con pedido
            $table->unsignedBigInteger('idPlato'); // Relación con plato
            $table->integer('cantidad');
            $table->decimal('precio', 10, 2); // Precio total (unitario * cantidad)
            $table->timestamps();

            $table->foreign('idPedido')->references('id')->on('pedidos_web_registros')->onDelete('cascade');
            $table->foreign('idPlato')->references('id')->on('platos')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_pedidos_webs');
    }
};
