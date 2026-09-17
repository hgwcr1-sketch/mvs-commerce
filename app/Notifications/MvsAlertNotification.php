<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MvsAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Alert $alert,
    ) {}

    public function via(object $notifiable): array
    {
        // Piloto N05-N09: alertas in-app únicamente.
        // El canal mail requiere configuración explícita por preferencia/canal
        // y no se dispara automáticamente.
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'type' => $this->alert->type,
            'severity' => $this->alert->severity,
            'title' => $this->title(),
            'message' => $this->message(),
            'link' => $this->alert->link,
            'company_id' => $this->alert->company_id,
            'branch_id' => $this->alert->branch_id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.config('app.name').'] '.$this->title())
            ->greeting('Hola, '.$notifiable->name)
            ->line($this->message());

        if ($this->alert->link) {
            $mail->action('Ver en MVS Commerce', url($this->alert->link));
        }

        return $mail->line('Gracias por usar MVS Commerce.');
    }

    private function title(): string
    {
        $labels = [
            'purchase_verification' => 'Nueva verificación de compra asignada',
        ];

        return $labels[$this->alert->type] ?? 'Nueva alerta';
    }

    private function message(): string
    {
        $metadata = $this->alert->metadata ?? [];

        return match ($this->alert->type) {
            'purchase_verification' => sprintf(
                'Se le ha asignado la verificación %s de la compra %s.',
                $metadata['verification_id'] ?? '#',
                $metadata['purchase_identifier'] ?? '#'
            ),
            default => $this->alert->notes ?? 'Tiene una nueva alerta en el sistema.',
        };
    }
}
