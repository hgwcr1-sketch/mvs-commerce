<?php

namespace App\Notifications;

use App\Models\AccountPayable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountPayableDueNotification extends Notification
{
    use Queueable;

    public function __construct(public AccountPayable $account, public string $alertType) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        $overdue = $this->alertType === 'overdue';
        return (new MailMessage)
            ->subject($overdue ? 'Cuenta por pagar vencida' : 'Cuenta por pagar próxima a vencer')
            ->line("La cuenta de {$this->account->supplier->name}, compra {$this->account->purchase->number}, ".($overdue ? 'está vencida.' : "vence el {$this->account->due_date->format('d/m/Y')}."))
            ->line('Saldo pendiente: ₡'.number_format((float)$this->account->balance_due,0,',','.'))
            ->action('Ver cuenta por pagar', route('cuentas-por-pagar.show',$this->account));
    }
}
