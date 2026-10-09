<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RegistrationDraftRecovery extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly \DateTimeInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Recupere su borrador de registro MVS CobrOS')
            ->line('Recibimos una solicitud para recuperar el borrador de registro asociado a este correo.')
            ->line('El enlace es de un solo uso y caduca el '.$this->expiresAt->format('d/m/Y H:i').'.')
            ->action('Recuperar borrador', route('cobros.register.recover.token', ['token' => $this->token]))
            ->line('Si no solicitó esta recuperación, ignore este mensaje. Nunca le pediremos su contraseña.');
    }
}
