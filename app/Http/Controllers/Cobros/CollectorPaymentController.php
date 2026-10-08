<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosPaymentReversal;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Services\Cobros\CobrosAnnulmentService;
use App\Services\Cobros\CobrosPaymentGate;
use App\Services\Cobros\CreditInstallmentService;
use App\Services\Cobros\CreditLateFeeService;
use App\Services\Cobros\SecurePaymentService;
use App\Support\Cobros\WhatsApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 3A + 3B — pantalla previa y registro real del cobro.
 *
 * La autoridad vive SOLO en la sesión (empresa activa) y auth()->id().
 * El Gate revalida TODO en servidor. 3B aplica pagos parciales o totales con
 * confirmación explícita, idempotencia por token y recibo único por empresa.
 * Nunca abre sesión de caja ni ingresa efectivo a caja: custodia pendiente.
 */
class CollectorPaymentController extends Controller
{
    public function __construct(private CreditLateFeeService $lateFees, private CreditInstallmentService $installments)
    {
    }

    public function create(Request $request, AccountReceivable $account, CobrosPaymentGate $gate, CreditInstallmentService $installments): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $gate->assertCanStart($request->user(), $company, $account->load(['customer', 'branch', 'sale']));
        $visit = $this->visit($request, $company, $account);

        return $this->form($company, $account, $visit, clientToken: (string) Str::uuid(), loan: $installments->contextFor($company, $account));
    }

    /**
     * Captura → confirmación con detalle → aplicación. Sin `confirm` no hay
     * escritura alguna: la pantalla de confirmación se genera en servidor.
     */
    public function store(Request $request, AccountReceivable $account, CobrosPaymentGate $gate, SecurePaymentService $payments, CreditInstallmentService $installments): RedirectResponse|View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $visit = $this->visit($request, $company, $account);

        $request->validate([
            'amount' => ['required', 'string', 'max:32'],
            'payment_method_id' => ['required', 'numeric'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'tendered' => ['nullable', 'string', 'max:32'],
            'client_token' => ['required', 'string', 'max:64'],
        ]);

        $payload = [
            'amount' => $request->input('amount'),
            'payment_method_id' => $request->input('payment_method_id'),
            'reference' => $request->input('reference'),
            'notes' => $request->input('notes'),
            'tendered' => $request->input('tendered'),
            'client_token' => $request->input('client_token'),
            'visit_id' => $visit?->id,
        ];

        // Primer envío: solo vista de confirmación con detalle, cero escrituras.
        // Aquí se exige el Gate; en confirmación lo re-evalúa el servicio DESPUÉS
        // de resolver la idempotencia, para que un reintento siempre devuelva
        // el mismo pago aunque la cuenta ya esté saldada.
        if ($request->input('confirm') !== '1') {
            $gate->assertCanStart($request->user(), $company, $account->load(['customer', 'branch', 'sale']));
            $method = PaymentMethod::query()->forCompany($company->id)->active()->find((int) $request->input('payment_method_id'));
            $raw = trim((string) $request->input('amount'));
            $normalized = preg_match('/^\d{1,12}(\.\d{1,4})?$/', $raw) ? bcadd($raw, '0', 4) : null;
            // "Paga con" (efectivo): formato y monto >= cobrado. Solo informativo.
            $rawTendered = trim((string) $request->input('tendered'));
            $tendered = null;
            if ($rawTendered !== '') {
                if (! preg_match('/^\d{1,12}(\.\d{1,4})?$/', $rawTendered)) {
                    throw ValidationException::withMessages(['tendered' => 'Ingrese el efectivo recibido con hasta cuatro decimales.']);
                }
                $tendered = bcadd($rawTendered, '0', 4);
            }
            if ($tendered !== null && $normalized !== null && bccomp($tendered, $normalized, 4) < 0) {
                throw ValidationException::withMessages(['tendered' => 'El efectivo recibido no puede ser menor al monto cobrado.']);
            }

            // 9B.1: bloquea en pantalla si supera la cuota más antigua del préstamo.
            $loan = $installments->contextFor($company, $account);
            $plan = null;
            if ($normalized !== null) {
                $installments->assertChargeable($company, $account, $normalized);
                $plan = $installments->planFor($company, $account, $normalized);
            }

            return $this->form($company, $account, $visit, (string) $request->input('client_token'), [
                'amount' => $normalized ?? $raw,
                // 9B.3: el saldo restante es del PRÉSTAMO (la mora no toca la CxC).
                'remaining' => $normalized !== null
                    ? (string) ($plan['saldo_after'] ?? bcsub((string) $account->balance_due, $normalized, 4))
                    : (string) $account->balance_due,
                'method' => $method,
                'reference' => $request->input('reference'),
                'notes' => $request->input('notes'),
                'tendered' => $tendered,
                'change' => $tendered !== null && $normalized !== null ? bcsub($tendered, $normalized, 4) : null,
            ], loan: $loan, plan: $plan);
        }

        $result = $payments->register($request->user(), $company, $account, $payload);

        return redirect()
            ->route('cobros.collector.payments.receipt', $result['receipt']->receipt_number)
            ->with('cobros_receipt_replay', $result['replayed']);
    }

    /** Recibo propio (o del dueño / quien tiene cobros.anular) — 404 seguro. */
    public function receipt(Request $request, string $receipt, CreditInstallmentService $installments): View
    {
        [$company, $model] = $this->readableReceipt($request, $receipt);
        $loan = $installments->applicationForReceipt($company, $model);

        return view('cobros.collector.payment-receipt', [
            'company' => $company,
            'loanApplication' => $loan,
            'receipt' => $model->load(['customer', 'accountReceivable', 'paymentMethod', 'collector', 'branch', 'annulledBy']),
            'reversal' => CobrosPaymentReversal::query()->forCompany($company->id)
                ->where('receipt_id', $model->id)->first(),
            'mayAnnul' => ! $model->annulled_at && $request->user()->hasPermission('cobros.anular', $company),
            'annulToken' => (string) Str::uuid(),
            'whatsappUrl' => WhatsApp::receiptUrl(
                $company,
                $model->customer,
                (string) $model->receipt_number,
                (string) $model->amount,
                (string) ($model->balance_after ?? $model->accountReceivable()->first()?->balance_due ?? '0'),
                $model->paid_at->timezone($company->timezone)->format('d/m/Y H:i'),
                $model->currency_code,
                $loan['reference'] ?? null,
                $loan['next_due'] ?? null,
                $loan['next_amount'] ?? null,
                (bool) ($loan['cancelled'] ?? false),
                (string) ($loan['mora'] ?? '0.0000')
            ),
        ]);
    }

    /**
     * Recibo formal print-friendly: vista independiente (sin navbar, menús ni
     * footer del sistema) para que la impresión salga SOLO con el recibo.
     */
    public function receiptPrint(Request $request, string $receipt, CreditInstallmentService $installments): View
    {
        [$company, $model] = $this->readableReceipt($request, $receipt);

        return view('cobros.collector.payment-receipt-print', [
            'company' => $company,
            'loanApplication' => $installments->applicationForReceipt($company, $model),
            'receipt' => $model->load(['customer', 'accountReceivable', 'paymentMethod', 'collector', 'branch', 'annulledBy']),
            'reversal' => CobrosPaymentReversal::query()->forCompany($company->id)
                ->where('receipt_id', $model->id)->first(),
        ]);
    }

    /** @return array{0: Company, 1: CobrosReceipt} */
    private function readableReceipt(Request $request, string $receipt): array
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $model = CobrosReceipt::query()->forCompany($company->id)->where('receipt_number', $receipt)->firstOrFail();

        $user = $request->user();
        $mayRead = (int) $model->collector_user_id === (int) $user->id
            || $user->hasPermission('cobros.portal_dueno', $company)
            || $user->hasPermission('cobros.anular', $company);
        abort_unless($mayRead, 404);

        return [$company, $model];
    }

    /** Anulación (3C): solo cobros.anular; nunca borra ni modifica el pago. */
    public function annul(Request $request, string $receipt, CobrosAnnulmentService $annulments): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $model = CobrosReceipt::query()->forCompany($company->id)->where('receipt_number', $receipt)->firstOrFail();

        $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:250'],
            'client_token' => ['required', 'string', 'max:64'],
        ]);

        $result = $annulments->annul($request->user(), $company, $model, [
            'reason' => $request->input('reason'),
            'client_token' => $request->input('client_token'),
        ]);

        return redirect()
            ->route('cobros.collector.payments.receipt', $model->receipt_number)
            ->with('cobros_reversal', $result['reversal']->reversal_number)
            ->with('cobros_reversal_replay', $result['replayed']);
    }

    /** Historial de recibos del cobrador autenticado. */
    public function history(Request $request): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        return view('cobros.collector.payments-history', [
            'company' => $company,
            'receipts' => CobrosReceipt::query()->forCompany($company->id)
                ->where('collector_user_id', $request->user()->id)
                ->with(['customer', 'paymentMethod'])
                ->orderByDesc('paid_at')->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    private function form(Company $company, AccountReceivable $account, ?CobrosVisit $visit, string $clientToken, ?array $preview = null, ?array $loan = null, ?array $plan = null): View
    {
        return view('cobros.collector.payment-create', [
            'company' => $company,
            'account' => $account,
            'customer' => $account->customer,
            'visit' => $visit,
            'clientToken' => $clientToken,
            'preview' => $preview,
            'loan' => $loan,
            'plan' => $plan,
            'lateFee' => $this->lateFeeView($loan),
            'methods' => PaymentMethod::query()->forCompany($company->id)->active()
                ->whereNotIn('type', [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS])
                ->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * 9B.3: mora pendiente (exigible ya cobrada) de la cuota abierta más
     * antigua. Se cobra PRIMERO al confirmar; la vista nunca persiste nada.
     *
     * @return array{state: string, pending?: string, amount?: string, total?: string}|null
     */
    private function lateFeeView(?array $loan): ?array
    {
        if ($loan === null || $loan['installment'] === null) {
            return null;
        }

        $credit = $loan['credit'];
        $installment = $loan['installment'];
        $result = $this->lateFees->evaluate($credit, $installment, today());

        if (bccomp($result['late_fee_due'], '0', 4) > 0) {
            $pending = (string) $loan['pending'];
            $moraPending = bcsub($result['late_fee_due'], $this->installments->lateFeePaid($installment), 4);
            $moraPending = bccomp($moraPending, '0', 4) > 0 ? $moraPending : '0.0000';

            if (bccomp($moraPending, '0', 4) > 0) {
                return [
                    'state' => 'overdue',
                    'pending' => $pending,
                    'amount' => $moraPending,
                    'total' => bcadd($pending, $moraPending, 4),
                ];
            }
        }

        $terms = $credit->terms_snapshot ?? [];
        $type = (string) ($terms['late_fee_type'] ?? $credit->late_fee_type);
        if ($type !== CobrosCredit::LATE_FEE_NONE
            && ! $result['is_overdue']
            && $installment->due_date->lte(today())) {
            return ['state' => 'grace'];
        }

        return null;
    }

    private function visit(Request $request, Company $company, AccountReceivable $account): ?CobrosVisit
    {
        if (! $request->has('visit')) {
            return null;
        }
        abort_unless(is_scalar($request->query('visit')) && ctype_digit((string) $request->query('visit')), 404);

        $visit = CobrosVisit::query()->forCompany($company->id)
            ->forCollector($request->user()->id)
            ->where('customer_id', $account->customer_id)
            ->findOrFail($request->query('visit'));
        abort_if($visit->account_receivable_id !== null && (int) $visit->account_receivable_id !== (int) $account->id, 404);

        return $visit;
    }
}
