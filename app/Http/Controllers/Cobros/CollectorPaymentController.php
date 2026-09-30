<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\AccountReceivable;
use App\Models\CobrosReceipt;
use App\Models\CobrosVisit;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Services\Cobros\CobrosPaymentGate;
use App\Services\Cobros\SecurePaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    public function create(Request $request, AccountReceivable $account, CobrosPaymentGate $gate): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $gate->assertCanStart($request->user(), $company, $account->load(['customer', 'branch', 'sale']));
        $visit = $this->visit($request, $company, $account);

        return $this->form($company, $account, $visit, clientToken: (string) Str::uuid());
    }

    /**
     * Captura → confirmación con detalle → aplicación. Sin `confirm` no hay
     * escritura alguna: la pantalla de confirmación se genera en servidor.
     */
    public function store(Request $request, AccountReceivable $account, CobrosPaymentGate $gate, SecurePaymentService $payments): RedirectResponse|View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $visit = $this->visit($request, $company, $account);

        $request->validate([
            'amount' => ['required', 'string', 'max:32'],
            'payment_method_id' => ['required', 'numeric'],
            'reference' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
            'client_token' => ['required', 'string', 'max:64'],
        ]);

        $payload = [
            'amount' => $request->input('amount'),
            'payment_method_id' => $request->input('payment_method_id'),
            'reference' => $request->input('reference'),
            'notes' => $request->input('notes'),
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

            return $this->form($company, $account, $visit, (string) $request->input('client_token'), [
                'amount' => $normalized ?? $raw,
                'remaining' => $normalized !== null
                    ? bcsub((string) $account->balance_due, $normalized, 4)
                    : (string) $account->balance_due,
                'method' => $method,
                'reference' => $request->input('reference'),
                'notes' => $request->input('notes'),
            ]);
        }

        $result = $payments->register($request->user(), $company, $account, $payload);

        return redirect()
            ->route('cobros.collector.payments.receipt', $result['receipt']->receipt_number)
            ->with('cobros_receipt_replay', $result['replayed']);
    }

    /** Recibo propio (o del dueño) — 404 seguro ante recursos ajenos. */
    public function receipt(Request $request, string $receipt): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $model = CobrosReceipt::query()->forCompany($company->id)->where('receipt_number', $receipt)->firstOrFail();

        $isOwner = (bool) $request->user()->hasPermission('cobros.portal_dueno', $company);
        abort_unless((int) $model->collector_user_id === (int) $request->user()->id || $isOwner, 404);

        return view('cobros.collector.payment-receipt', [
            'company' => $company,
            'receipt' => $model->load(['customer', 'accountReceivable', 'paymentMethod', 'collector', 'branch']),
        ]);
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

    private function form(Company $company, AccountReceivable $account, ?CobrosVisit $visit, string $clientToken, ?array $preview = null): View
    {
        return view('cobros.collector.payment-create', [
            'company' => $company,
            'account' => $account,
            'customer' => $account->customer,
            'visit' => $visit,
            'clientToken' => $clientToken,
            'preview' => $preview,
            'methods' => PaymentMethod::query()->forCompany($company->id)->active()
                ->whereNotIn('type', [PaymentMethod::TYPE_CREDIT, PaymentMethod::TYPE_LOYALTY_POINTS])
                ->orderBy('sort_order')->get(),
        ]);
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
