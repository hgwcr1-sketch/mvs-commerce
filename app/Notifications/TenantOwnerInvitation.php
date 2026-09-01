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
            ->markdown('mail.tenant-owner-invitation', [
                'activationUrl' => $url,
                'logoUrl' => secure_asset('images/logo-mvs.png'),
                'ownerName' => $notifiable->name,
            ]);
    }
}
