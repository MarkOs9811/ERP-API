<?php

namespace App\Notifications;

// 1. IMPORTAMOS LOS MÓDULOS NECESARIOS
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue; // <-- Para ponerlo en cola
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\PeriodoNomina; // <-- El modelo que vamos a pasar

// 2. HACEMOS QUE LA NOTIFICACIÓN SE PUEDA PONER EN COLA
class PeriodoVencidoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // 3. PROPIEDAD PARA GUARDAR EL PERIODO
    protected $periodo;


    public function __construct(PeriodoNomina $periodo)
    {
        // 4. RECIBIMOS EL PERIODO Y LO GUARDAMOS
        $this->periodo = $periodo;
    }

    public function via(object $notifiable): array
    {

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // 6. MENSAJE DE EMAIL CONFIGURADO
        return (new MailMessage)
            ->subject("Alerta: Periodo de Nómina Vencido")
            ->line("Hola " . ($notifiable->name ?? 'Administrador') . ",")
            ->line("El periodo de nómina '{$this->periodo->nombre}' ha vencido (finalizó el {$this->periodo->fecha_fin}) y sigue en estado 'Abierto'.")
            ->line('Por favor, ingresa al sistema para validarlo y procesar los pagos.')
            ->action('Validar Periodo Ahora', url('/nomina/periodos/' . $this->periodo->id)) // <-- Ajusta esta URL a tu app
            ->line('¡Gracias por usar la aplicación!');
    }


    public function toArray(object $notifiable): array
    {
        // 7. DATOS PARA EL ICONO DE CAMPANA (Base de Datos)
        return [
            'titulo' => 'Periodo Vencido',
            'mensaje' => "El periodo {$this->periodo->nombre} debe ser validado.",
            'periodo_id' => $this->periodo->id,
            'url' => '/nomina/periodos/' . $this->periodo->id // <-- Ajusta esta URL
        ];
    }
}
