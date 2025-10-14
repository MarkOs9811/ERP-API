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
        Schema::create('documentos_firmados', function (Blueprint $table) {
            $table->id();
            $table->string("nombre_archivo");
            $table->string("ruta_archivo");
            $table->unsignedBigInteger("idAsuario");
            $table->foreign("idAsuario")->references("id")->on("users")->onDelete("cascade");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_firmados');
    }
};
