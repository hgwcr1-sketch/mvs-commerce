<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * Token para restablecer la contraseña.
     */
    protected string $token;

    /**
     * Crear una nueva notificación.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /**
     * Canales de envío.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Contenido del correo.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Restablecer contraseña | MVS Commerce')
            ->greeting('Hola ' . $notifiable->name)
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta en MVS Commerce.')
            ->action('Restablecer contraseña', $url)
            ->line('Este enlace de recuperación expirará en 60 minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar este correo. Tu contraseña permanecerá sin cambios.')
            ->salutation('MVS Commerce');
    }

    /**
     * Representación de la notificación.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}