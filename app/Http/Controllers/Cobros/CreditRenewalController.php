<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosCredit;
use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Cobros\CreditInstallmentService;
use App\Services\Cobros\CreditRenewalConsentService;
use App\Services\Cobros\CreditRenewalQuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * REN-03C1 — Solicitud y autorización de renovación de crédito.
 *
 * NO crea créditos, pagos, desembolsos ni cierra créditos anteriores.
 * Solo prepara la solicitud con el cálculo del motor REN-03B y decide
 * server-side si se aprueba directa o queda «Pendiente de aprobación».
 */
class CreditRenewalController extends Controller
{
    public function create(
        Request $request,
        Customer $customer,
        CreditInstallmentService $installments,
        CreditRenewalQuoteService $quoteService,
    ): View {
        $company = Company::findOrFail((int) session('active_company_id'));
        $credit = $this->disbursedCreditForCustomer($company, $customer, $installments);
        $mode = $this->approvalMode($request->user(), $company, $credit);

        $quote = null;
        $newCapital = trim((string) $request->query('new_capital', ''));
        if ($newCapital !== '') {
            $quote = $quoteService->quote(
                $request->user(),
                $company,
                $credit->account()->firstOrFail(),
                $newCapital,
                max(1, (int) $request->query('installments', $credit->installment_count)),
                (string) $credit->frequency,
                today($company->timezone ?: config('app.timezone'))->addWeek()->toDateString(),
            );
        }

        return view('cobros.collector.renewal-create', [
            'company' => $company,
            'customer' => $customer,
            'credit' => $credit,
            'mode' => $mode,
            'quote' => $quote,
        ]);
    }

    public function store(
        Request $request,
        Customer $customer,
        CreditInstallmentService $installments,
        CreditRenewalQuoteService $quoteService,
    ): RedirectResponse {
        $company = Company::findOrFail((int) session('active_company_id'));
        $credit = $this->disbursedCreditForCustomer($company, $customer, $installments);
        $mode = $this->approvalMode($request->user(), $company, $credit);

        $existing = CobrosCreditRenewalRequest::query()
            ->where('company_id', $company->id)
            ->where('credit_id', $credit->id)
            ->whereIn('status', [CobrosCreditRenewalRequest::STATUS_PENDING, CobrosCreditRenewalRequest::STATUS_APPROVED])
            ->exists();
        if ($existing) {
            throw ValidationException::withMessages([
                'new_capital' => 'Ya existe una renovación solicitada o aprobada para este crédito.',
            ]);
        }

        $data = $request->validate([
            'new_capital' => ['required', 'string'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:120'],
            'first_due_date' => ['required', 'date'],
        ]);

        $account = $credit->account()->firstOrFail();
        $quote = $quoteService->quote(
            $request->user(),
            $company,
            $account,
            $data['new_capital'],
            (int) $data['installment_count'],
            (string) $credit->frequency,
            $data['first_due_date'],
        );

        $approved = $mode === 'direct';
        $renewal = CobrosCreditRenewalRequest::create([
            'company_id' => $company->id,
            'credit_id' => $credit->id,
            'account_receivable_id' => $account->id,
            'collector_user_id' => $credit->collector_user_id,
            'new_capital' => $quote['new_money']['principal'],
            'previous_pending' => $quote['previous']['total'],
            'previous_principal' => $quote['previous']['principal'],
            'previous_interest' => $quote['previous']['interest'],
            'new_interest' => $quote['new_money']['interest'],
            'consolidated_total' => $quote['consolidated_total'],
            'disbursement' => $quote['disbursement'],
            'installment_count' => $quote['schedule']['installment_count'],
            'frequency' => $quote['schedule']['frequency'],
            'first_due_date' => $quote['schedule']['first_due_date'],
            'schedule_snapshot' => $quote['schedule']['rows'],
            'status' => $approved ? CobrosCreditRenewalRequest::STATUS_APPROVED : CobrosCreditRenewalRequest::STATUS_PENDING,
            'requested_by' => $request->user()->id,
            'approved_by' => $approved ? $request->user()->id : null,
            'approved_at' => $approved ? now() : null,
        ]);

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('renewal_status', $approved ? 'approved' : 'pending')
            ->with('renewal_id', $renewal->id);
    }

    /** REN-03D1: aprobación persistente de una renovación pendiente. */
    public function approve(Request $request, Customer $customer, int $renewal, CreditInstallmentService $installments, CreditRenewalConsentService $consent): RedirectResponse
    {
        $row = $this->authorizedRenewal($request, $customer, $renewal, $installments, resolve: true);
        $consent->approve($row, $request->user());

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('renewal_status', 'approved')
            ->with('renewal_id', $row->id);
    }

    /** REN-03D1: rechazo persistente de una renovación pendiente. */
    public function reject(Request $request, Customer $customer, int $renewal, CreditInstallmentService $installments, CreditRenewalConsentService $consent): RedirectResponse
    {
        $row = $this->authorizedRenewal($request, $customer, $renewal, $installments, resolve: true);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $consent->reject($row, $request->user(), $data['reason'] ?? null);

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('renewal_status', 'rejected')
            ->with('renewal_id', $row->id);
    }

    /**
     * REN-03D1: consentimiento firmado del cliente. Exige aprobación previa
     * y firma NUEVA vinculada por hash a los términos exactos de la renovación.
     */
    public function consent(Request $request, Customer $customer, int $renewal, CreditInstallmentService $installments, CreditRenewalConsentService $consent): RedirectResponse
    {
        $row = $this->authorizedRenewal($request, $customer, $renewal, $installments);
        $data = $request->validate([
            'signature' => ['required', 'string'],
            'terms_hash' => ['nullable', 'string', 'size:64'],
        ]);
        $consent->consent($row, $request->user(), $data['signature'], $data['terms_hash'] ?? null);

        return redirect()
            ->route('cobros.collector.portfolio.show', $customer)
            ->with('renewal_status', 'consented')
            ->with('renewal_id', $row->id);
    }

    private function disbursedCreditForCustomer(Company $company, Customer $customer, CreditInstallmentService $installments): CobrosCredit
    {
        $credit = CobrosCredit::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('status', CobrosCredit::STATUS_DISBURSED)
            ->orderByDesc('id')
            ->first();

        if ($credit === null) {
            abort(404, 'Este cliente no tiene un crédito desembolsado para renovar.');
        }

        return $credit;
    }

    /**
     * Localiza la renovación dentro del tenant y del crédito del cliente y
     * valida autorización server-side. Para resolver (aprobar/rechazar) exige
     * modo 'direct'; para consentimiento basta con acceso a esa cartera.
     */
    private function authorizedRenewal(
        Request $request,
        Customer $customer,
        int $renewalId,
        CreditInstallmentService $installments,
        bool $resolve = false,
    ): CobrosCreditRenewalRequest {
        $company = Company::findOrFail((int) session('active_company_id'));
        $credit = $this->disbursedCreditForCustomer($company, $customer, $installments);
        $mode = $this->approvalMode($request->user(), $company, $credit);

        if ($resolve && $mode !== 'direct') {
            abort(403, 'Solo el prestamista o un usuario autorizado puede resolver esta renovación.');
        }

        $renewal = CobrosCreditRenewalRequest::query()
            ->where('company_id', $company->id)
            ->where('credit_id', $credit->id)
            ->whereKey($renewalId)
            ->first();
        if ($renewal === null) {
            abort(404, 'Renovación no encontrada.');
        }

        // Firma: solo el cobrador asignado o el prestamista de esa cartera.
        if (! $resolve && (int) $renewal->collector_user_id !== (int) $request->user()->id && $mode !== 'direct') {
            abort(403, 'No tiene permiso para registrar el consentimiento de esta renovación.');
        }

        return $renewal;
    }

    /**
     * Autorización server-side de la renovación:
     *  - Prestamista (portal_dueno/supervisar): aprobación directa.
     *  - Cobrador independiente (política collector_authorized): directa.
     *  - Cobrador vinculado con permiso cobros.renovar: directa.
     *  - Cobrador vinculado sin permiso: requiere aprobación del prestamista.
     */
    private function approvalMode($actor, Company $company, CobrosCredit $credit): string
    {
        $isSuperior = $actor->hasPermission('cobros.portal_dueno', $company)
            || $actor->hasPermission('cobros.supervisar', $company);
        if ($isSuperior) {
            return 'direct';
        }

        $isAssigned = (int) $credit->collector_user_id === (int) $actor->id
            && $actor->hasPermission('cobros.cobrar', $company);
        if (! $isAssigned) {
            abort(403, 'No tiene permiso para renovar este crédito.');
        }

        $policy = CobrosCollectorAuthorityPolicy::policyFor($company->id, $actor->id);
        if ($policy === CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED) {
            return 'direct';
        }

        return $actor->hasPermission('cobros.renovar', $company) ? 'direct' : 'approval';
    }
}
