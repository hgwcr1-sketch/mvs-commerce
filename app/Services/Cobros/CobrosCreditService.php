<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosBureauEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 9 — aprobación y desembolso del crédito CobrOS.
 *
 * - APROBAR: crea cobros_credit status=approved con terms_snapshot inmutable.
 *   NO crea CxC, cuotas ni toca cartera. Idempotente por credit_request_id.
 * - DESEMBOLSAR: acción separada, en UNA transacción con lockForUpdate:
 *   Customer (nuevo/existente) + calendario desde el snapshot + CxC Core
 *   (sale_id NULL) + asignación cartera + cuotas + estado disbursed.
 *   TODO o nada; reintentar sobre un crédito ya desembolsado no duplica.
 */
class CobrosCreditService
{
    public function __construct(
        private InstallmentScheduleService $schedule,
        private CobrosCreditRequestService $requests,
        private CobrosBureauService $bureau,
        private PortfolioAssignmentService $assignments,
    ) {}

    /** Términos validados → crédito aprobado (idempotente por solicitud). */
    public function approve(Company $company, CobrosCreditRequest $request, User $actor, array $terms): CobrosCredit
    {
        return DB::transaction(function () use ($company, $request, $actor, $terms): CobrosCredit {
            // 1) Solicitud bloqueada y VERIFICADA (una solicitud solo genera 1 crédito).
            $locked = CobrosCreditRequest::forCompany($company->id)->whereKey($request->id)->lockForUpdate()->first();
            if ($locked === null) {
                abort(404);
            }

            $existing = CobrosCredit::forCompany($company->id)->where('credit_request_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing; // Reintento: jamás un segundo crédito.
            }

            if ($locked->status !== CobrosCreditRequest::STATUS_VERIFIED) {
                throw ValidationException::withMessages([
                    'transition' => 'La solicitud debe estar verificada para definir el crédito.',
                ]);
            }

            if ($locked->cobros_route_id === null) {
                throw ValidationException::withMessages([
                    'route_id' => 'La solicitud no tiene ruta operativa; no puede definirse el crédito.',
                ]);
            }

            // 13) UNA sola autoridad por decisión, resuelta server-side:
            // si el actor ES el cobrador de la solicitud, la política vigente
            // debe ser collector_authorized; con owner_required solo el dueño
            // (actor distinto) puede autorizar. La política se congela abajo.
            $authorityPolicy = \App\Models\CobrosCollectorAuthorityPolicy::policyFor(
                (int) $company->id,
                (int) $locked->collector_user_id
            );
            if ((int) $actor->id === (int) $locked->collector_user_id
                && $authorityPolicy === \App\Models\CobrosCollectorAuthorityPolicy::OWNER_REQUIRED) {
                abort(403, 'Este cobrador requiere la autorización del dueño.');
            }

            // 2) Total con interés simple calculado con el MISMO motor del preview.
            $principal = $this->schedule->money($terms['principal']);
            $totalDue = $this->schedule->totalWithInterest($principal, $terms['interest_type'], $terms['interest_value']);

            $snapshot = [
                'principal' => $principal,
                'interest_type' => $terms['interest_type'],
                'interest_value' => $this->schedule->money($terms['interest_value']),
                'total_due' => $totalDue,
                'installment_count' => (int) $terms['installment_count'],
                'frequency' => $terms['frequency'],
                'first_due_date' => $terms['first_due_date'],
                'late_fee_type' => $terms['late_fee_type'],
                'late_fee_value' => $this->schedule->money($terms['late_fee_value']),
                'late_fee_grace_days' => (int) $terms['late_fee_grace_days'],
                'amount_requested' => (string) $locked->amount_requested,
                // 5) Autoridad CONGELADA para esta decisión: política vigente,
                // quién autorizó y cuándo. Un cambio posterior no la reescribe.
                'authority_policy' => $authorityPolicy,
                'authorized_by' => (int) $actor->id,
                'authorized_at' => now()->toDateTimeString(),
            ];

            $credit = CobrosCredit::create([
                'company_id' => $company->id,
                'credit_request_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'collector_user_id' => $locked->collector_user_id,
                'cobros_route_id' => $locked->cobros_route_id,
                'principal' => $principal,
                'interest_type' => $terms['interest_type'],
                'interest_value' => $snapshot['interest_value'],
                'total_due' => $totalDue,
                'installment_count' => $snapshot['installment_count'],
                'frequency' => $terms['frequency'],
                'first_due_date' => $terms['first_due_date'],
                'late_fee_type' => $terms['late_fee_type'],
                'late_fee_value' => $snapshot['late_fee_value'],
                'late_fee_grace_days' => $snapshot['late_fee_grace_days'],
                'status' => CobrosCredit::STATUS_APPROVED,
                'terms_snapshot' => $snapshot,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            // 3) La solicitud refleja APROBADA (log/Buró/campana vía transición Core).
            $this->requests->approve(
                $company,
                $locked,
                $actor,
                'Crédito autorizado por '.($authorityPolicy === \App\Models\CobrosCollectorAuthorityPolicy::OWNER_REQUIRED ? 'el prestamista.' : 'el cobrador.')
            );

            return $credit;
        });
    }

    /** Desembolso: confirma el crédito aprobado y nace la CxC operativa. */
    public function disburse(Company $company, CobrosCredit $credit, User $actor): CobrosCredit
    {
        return DB::transaction(function () use ($company, $credit, $actor): CobrosCredit {
            $locked = CobrosCredit::forCompany($company->id)->whereKey($credit->id)->lockForUpdate()->first();
            if ($locked === null) {
                abort(404);
            }

            // Reintento idempotente: ya desembolsado → no duplica nada.
            if ($locked->status === CobrosCredit::STATUS_DISBURSED) {
                return $locked;
            }
            if ($locked->status !== CobrosCredit::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'El crédito no está en estado aprobado.',
                ]);
            }

            $request = CobrosCreditRequest::forCompany($company->id)
                ->whereKey($locked->credit_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Revalidación de coherencia: request/company/ruta/cobrador.
            if ($request->status !== CobrosCreditRequest::STATUS_APPROVED
                || (int) $request->cobros_route_id !== (int) $locked->cobros_route_id
                || (int) $request->collector_user_id !== (int) $locked->collector_user_id) {
                throw ValidationException::withMessages([
                    'status' => 'La solicitud asociada ya no permite el desembolso.',
                ]);
            }

            $customer = $this->resolveCustomer($company, $request);
            $collector = User::whereKey($locked->collector_user_id)->firstOrFail();

            // Términos SIEMPRE desde el snapshot congelado en la aprobación.
            $terms = $locked->terms_snapshot;
            $rows = $this->schedule->build(
                (string) $terms['total_due'],
                (int) $terms['installment_count'],
                (string) $terms['frequency'],
                (string) $terms['first_due_date']
            );

            // Sucursal: SOLO compatibilidad técnica del esquema Core (NO autoriza nada).
            $branch = $company->branches()->where('is_active', true)->orderBy('id')->first();
            if ($branch === null) {
                throw ValidationException::withMessages([
                    'branch' => 'La empresa no tiene sucursal para registrar la CxC.',
                ]);
            }

            // CxC Core SIN venta: sale_id nullable (migración 000007), sin duplicar.
            $account = AccountReceivable::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'cobros_route_id' => $locked->cobros_route_id,
                'customer_id' => $customer->id,
                'sale_id' => null,
                'issued_at' => today(),
                'due_date' => (string) $terms['first_due_date'],
                'original_amount' => (string) $terms['total_due'],
                'balance_due' => (string) $terms['total_due'],
                'status' => AccountReceivable::STATUS_PENDING,
                'currency_code' => $company->currency,
            ]);

            // Calendario: generado desde el snapshot con el MISMO motor del preview.
            $now = now();
            foreach ($rows as $row) {
                CobrosCreditInstallment::create([
                    'credit_id' => $locked->id,
                    'number' => $row['number'],
                    'due_date' => $row['due_date'],
                    'contractual_amount' => $row['contractual_amount'],
                    'applied_amount' => '0.0000',
                    'status' => CobrosCreditInstallment::STATUS_PENDING,
                ]);
            }

            // Cartera del cobrador/ruta (servicio Core existente, idempotente).
            $this->assignments->assign(
                $company,
                $customer,
                $collector,
                $actor,
                'Desembolso de crédito #'.$locked->id,
                (int) $locked->cobros_route_id
            );

            $locked->update([
                'customer_id' => $customer->id,
                'account_receivable_id' => $account->id,
                'disbursement_date' => today()->toDateString(),
                'status' => CobrosCredit::STATUS_DISBURSED,
                'disbursed_at' => $now,
                'disbursed_by' => $actor->id,
            ]);

            if ($request->bureau_identity_id !== null) {
                $identity = $request->bureauIdentity()->lockForUpdate()->first();
                if ($identity !== null) {
                    $this->bureau->record(
                        CobrosBureauEvent::TYPE_CREDIT_DISBURSED,
                        $identity,
                        $company,
                        $actor,
                        'desembolso_credito',
                        $request->id
                    );
                }
            }

            return $locked->fresh();
        });
    }

    /**
     * Cliente según el flujo YA existente: "existente" queda vinculado desde
     * el envío de la solicitud; "nuevo" se crea AQUÍ una única vez (idempotente
     * por identificación dentro de la empresa).
     */
    private function resolveCustomer(Company $company, CobrosCreditRequest $request): Customer
    {
        if ($request->customer_id !== null) {
            $customer = Customer::query()
                ->where('company_id', $company->id)
                ->whereKey($request->customer_id)
                ->whereNull('deleted_at')
                ->first();
            if ($customer !== null) {
                return $customer;
            }
        }

        // Mismo cliente ya creado para esta identificación (reintento seguro).
        $existing = Customer::query()
            ->where('company_id', $company->id)
            ->where('identification', (string) $request->identification)
            ->whereNull('deleted_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'persona',
            'identification_type' => $request->identification_type ?? 'cedula',
            'identification' => (string) $request->identification,
            'name' => (string) $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        $request->fill(['customer_id' => $customer->id])->save();

        return $customer;
    }
}
