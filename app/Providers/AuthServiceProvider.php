<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            // Asegúrate de que el puerto 3000 sea el que usa tu React (o cámbialo por el tuyo)
            return "http://localhost:3000/restablecer-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
            return (new MailMessage)
                ->subject('Recuperación de Contraseña - Tu ERP Restaurant') // Asunto del correo
                ->greeting('¡Hola, Chef!') // Un saludo personalizado
                ->line('Estás recibiendo este correo porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta.')
                ->action('Restablecer Contraseña', $url) // El botón
                ->line('Este enlace de recuperación caducará en 60 minutos.')
                ->line('Si no solicitaste este cambio, puedes ignorar este correo de forma segura.')
                ->salutation('Saludos cordiales, El equipo de administración.'); // Despedida

        });
    }
}
