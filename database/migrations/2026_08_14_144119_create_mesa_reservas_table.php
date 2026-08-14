<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('mesa_reservas', function (Blueprint $table) {
            $table->id();

            //  Arquitectura Multi-Empresa / Sede
            $table->unsignedBigInteger('idEmpresa');
            $table->unsignedBigInteger('idSede');

            //  Auditoría y Relaciones
            $table->unsignedBigInteger('idUsuario'); // Quién registró la reserva en el sistema
            $table->unsignedBigInteger('idMesa');    // Qué mesa se está reservando

            // Datos del Cliente (Híbrido)
            $table->unsignedBigInteger('idCliente')->nullable(); // Opcional por si es cliente frecuente
            $table->string('nombre_cliente', 150); // Texto libre rápido
            $table->string('telefono_cliente', 20)->nullable(); // Celular de contacto

            //  Datos de la Reserva
            $table->date('fecha_reserva');
            $table->time('hora_reserva');
            $table->integer('cantidad_personas')->default(1);

            //  Estado de la Reserva: 1 = Pendiente, 2 = Atendida/Completada, 0 = Cancelada/No Show
            $table->tinyInteger('estado')->default(1);

            $table->text('nota')->nullable(); // Ej: "Traen pastel de cumpleaños", "Quieren cerca a la ventana"

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mesa_reservas');
    }
};
