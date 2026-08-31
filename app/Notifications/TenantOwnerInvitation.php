<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantOwnerInvitation extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('Active su acceso a MVS Commerce')
            ->greeting('Hola '.$notifiable->name)
            ->line('MVS creó el acceso comercial de su empresa.')
            ->line('Defina una contraseña segura para activar su cuenta y completar el onboarding de su tenant.')
            ->action('Activar mi acceso', $url)
            ->line('Este enlace es personal, expirable y de un solo uso.');
    }
}
