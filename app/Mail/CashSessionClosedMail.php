<?php

namespace App\Mail;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CashSessionClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CashSession $cashSession) {}

    public function envelope(): Envelope
    {
        $session = $this->cashSession->loadMissing('cashRegister:id,name');
        $difference = (float) $session->difference_amount;
        $result = $difference === 0.0 ? 'Sin diferencia' : ($difference > 0 ? 'Sobrante' : 'Faltante');
        return new Envelope(subject: '[MVS] Cierre '.$session->session_number.' — '.$result.' — '.$session->cashRegister->name);
    }

    public function content(): Content
    {
        $session = $this->cashSession->loadMissing([
            'company:id,trade_name,timezone', 'branch:id,name', 'cashRegister:id,name', 'openedBy:id,name',
            'closedBy:id,name', 'differenceAuthorizedBy:id,name',
            'countDetails' => fn ($query) => $query->closing()->orderByDesc('denomination_value'),
            'paymentReconciliations' => fn ($query) => $query->orderBy('id'),
        ]);
        $timezone = $this->timezone((string) $session->company->timezone);
        $sales = Sale::query()->where('cash_session_id', $session->id)->completed()->selectRaw('COUNT(*) as quantity, COALESCE(SUM(total), 0) as total')->first();
        $payments = DB::table('sale_payments as payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->join('payment_methods as methods', 'methods.id', '=', 'payments.payment_method_id')
            ->where('payments.cash_session_id', $session->id)
            ->where('payments.status', SalePayment::STATUS_COMPLETED)
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->groupBy('methods.id', 'methods.code', 'methods.name')
            ->selectRaw('methods.code, methods.name, SUM(payments.amount) as amount')
            ->orderBy('methods.name')
            ->get();
        $movements = CashMovement::query()->forSession($session->id)
            ->groupBy('type', 'direction')
            ->selectRaw('type, direction, SUM(amount) as amount')
            ->orderBy('type')
            ->get();
        $durationMinutes = $session->closed_at ? (int) $session->opened_at->diffInMinutes($session->closed_at) : 0;

        return new Content(
            view: 'emails.cash.session-closed',
            text: 'emails.cash.session-closed-text',
            with: compact('session', 'timezone', 'sales', 'payments', 'movements', 'durationMinutes'),
        );
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone', 'UTC');
    }
}
