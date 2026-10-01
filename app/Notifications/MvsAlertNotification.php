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
            'cobros.payment_registered' => 'Nuevo cobro registrado en CobrOS',
            'cobros.payment_annulled' => 'Cobro anulado en CobrOS',
            'cobros.promise_overdue' => 'Promesa de pago vencida en CobrOS',
            'cobros.customer_not_found' => 'Cliente no localizado en CobrOS',
            'cobros.custody_pending' => 'Custodia pendiente en CobrOS',
            'cobros.license_expiring' => 'Licencia próxima a vencer de CobrOS',
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
            'cobros.payment_registered' => sprintf(
                'Cobro %s por %s registrado en CobrOS.',
                $metadata['receipt_number'] ?? '#',
                $metadata['amount'] ?? '0'
            ),
            'cobros.payment_annulled' => sprintf(
                'Cobro %s anulado (%s) en CobrOS.',
                $metadata['receipt_number'] ?? '#',
                $metadata['reversal_number'] ?? '#'
            ),
            'cobros.promise_overdue' => sprintf(
                'Promesa de pago vencida de %s (fecha %s).',
                $metadata['customer_name'] ?? ($metadata['customer_id'] ?? 'cliente'),
                $metadata['promise_date'] ?? '-'
            ),
            'cobros.customer_not_found' => sprintf(
                'Cliente no localizado: %s.',
                $metadata['customer_name'] ?? ($metadata['customer_id'] ?? 'cliente')
            ),
            'cobros.custody_pending' => sprintf(
                'Custodia pendiente de %s sin ingresar a caja.',
                $metadata['net'] ?? '0'
            ),
            'cobros.license_expiring' => sprintf(
                'La licencia vence el %s (%s días).',
                $metadata['expires_at'] ?? '-',
                $metadata['days_left'] ?? '-'
            ),
            default => $this->alert->notes ?? 'Tiene una nueva alerta en el sistema.',
        };
    }
}
