<?php

namespace App\Mail;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\PaymentMethod;
use App\Services\Cash\CashClosingSummaryService;
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
        $session = $this->cashSession->loadMissing(['cashRegister:id,name', 'branch:id,name']);
        $summary = app(CashClosingSummaryService::class)->summarize($session);
        $comparison = bccomp($summary['difference'], '0', 4);
        $result = $comparison === 0 ? 'Sin diferencia neta' : ($comparison > 0 ? 'Sobrante' : 'Faltante');

        $envelope = new Envelope(subject: '[MVS] Cierre '.$session->session_number.' — '.$result.' — '.$session->branch->name.' / '.$session->cashRegister->name);
        if (config('mail.notifications.from.address')) {
            $envelope->from(config('mail.notifications.from.address'), config('mail.notifications.from.name') ?? null);
        }
        if (config('mail.reply_to.address')) {
            $envelope->replyTo(config('mail.reply_to.address'), config('mail.reply_to.name') ?? null);
        }

        return $envelope;
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
        $sales = Sale::query()->where('cash_session_id', $session->id)->completed()->selectRaw('COALESCE(SUM(total), 0) as total')->first();
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
        $closingSummary = app(CashClosingSummaryService::class)->summarize($session);
        $incomeSummary = $this->incomeSummary($session, $closingSummary);

        return new Content(
            view: 'emails.cash.session-closed',
            text: 'emails.cash.session-closed-text',
            with: compact('session', 'timezone', 'sales', 'payments', 'movements', 'durationMinutes', 'closingSummary', 'incomeSummary'),
        );
    }

    private function incomeSummary(CashSession $session, array $closingSummary): array
    {
        $cashGenerated = bcsub((string) $session->expected_cash, (string) $session->opening_amount, 4);
        $totals = ['card' => '0.0000', 'sinpe' => '0.0000', 'points' => '0.0000'];
        $others = [];
        $general = $cashGenerated;

        foreach ($session->paymentReconciliations as $row) {
            if ($row->affects_cash_snapshot || $row->payment_method_type_snapshot === PaymentMethod::TYPE_CASH) {
                continue;
            }

            $amount = bcsub(
                bcadd(bcadd((string) $row->sales_amount, (string) $row->receivables_amount, 4), (string) $row->layaways_amount, 4),
                (string) $row->payables_amount,
                4,
            );
            $general = bcadd($general, $amount, 4);
            $bucket = match ($row->payment_method_type_snapshot) {
                PaymentMethod::TYPE_CARD => 'card',
                PaymentMethod::TYPE_SINPE => 'sinpe',
                PaymentMethod::TYPE_LOYALTY_POINTS => 'points',
                default => null,
            };
            if ($bucket !== null) {
                $totals[$bucket] = bcadd($totals[$bucket], $amount, 4);
            } else {
                $others[] = ['name' => $row->payment_method_name_snapshot, 'amount' => $amount];
            }
        }

        return [
            'documents_count' => $closingSummary['documents_count'],
            'cash_generated' => $cashGenerated,
            'card' => $totals['card'],
            'sinpe' => $totals['sinpe'],
            'points' => $totals['points'],
            'others' => $others,
            'general' => $general,
        ];
    }

    private function timezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true) ? $timezone : config('app.timezone', 'UTC');
    }
}
