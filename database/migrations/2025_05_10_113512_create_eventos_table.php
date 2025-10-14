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
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('idUsuario'); // Relación con usuarios
            $table->string('google_event_id');     // ID del evento en Google Calendar
            $table->string('summary');
            $table->text('description')->nullable();
            $table->datetime('start');
            $table->datetime('end');
            $table->json('attendees')->nullable(); // Guarda los correos electrónicos invitados
            $table->string('status')->nullable();  // status del evento (confirmed, cancelled, etc.)
            $table->string('html_link')->nullable(); // Link para ver el evento en Google Calendar
            $table->timestamps();

            $table->foreign('idUsuario')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
