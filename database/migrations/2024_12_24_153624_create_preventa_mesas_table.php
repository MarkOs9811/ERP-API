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
        Schema::create('preventa_mesas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idUsuario');
            $table->unsignedBigInteger('idCaja');
            $table->unsignedBigInteger('idPlato');
            $table->unsignedBigInteger('idMesa');
            $table->integer('cantidad');
            $table->decimal('precio', 10, 2);
            $table->timestamps();
            $table->foreign('idUsuario')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('idCaja')->references('id')->on('cajas')->onDelete('cascade');
            $table->foreign('idPlato')->references('id')->on('platos')->onDelete('cascade');
            $table->foreign('idMesa')->references('id')->on('mesas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('preventa_mesas');
    }
};
