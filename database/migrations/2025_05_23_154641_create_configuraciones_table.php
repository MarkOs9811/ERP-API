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
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idEmpresa');
            $table->string('nombre'); // Ej: 'API OpenAI', 'Google Client ID')
            $table->string('clave'); // Ej: 'openai_api_key', 'google_client_id'
            $table->text('valor1')->nullable(); // Puede ser api_key, ruta, etc.
            $table->text('valor2')->nullable(); // Para client_secret, lang, etc.
            $table->text('valor3')->nullable(); // En caso se necesite
            $table->text('valor4')->nullable(); // En caso se necesite

            $table->text('descripcion')->nullable(); // Para mostrar en interfaz
            $table->timestamps();

            $table->foreign('idEmpresa')->references('id')->on('mi_empresas')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
