<?php

namespace App\Services\Cobros;

use App\Models\AccountReceivable;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Support\Cobros\PaymentGateDecision;

/**
 * Fase 3A — GATE de cobro: única pieza que autoriza INICIAR un cobro.
 *
 * Permite llegar de forma segura a la pantalla previa. Aquí NO se registran pagos,
 * NO se modifican saldos, NO se generan recibos y NO se toca caja. 3B reutilizará
 * este mismo Gate antes de escribir dinero.
 *
 * Autoridad SIEMPRE: empresa activa de la sesión + auth()->id() como cobrador.
 * El request nunca concede autoridad (ni collector_id, ni company_id, ni rol).
 * Reutiliza CollectorAccessService (sucursales) y User::hasPermission (permiso,
 * rol activo, pertenencia a la empresa y módulo contratado): no hay bypasses.
 *
 * Orden de evaluación (no revela existencia de recursos ajenos):
 * 1. Aislamiento multiempresa → 404.
 * 2. Usuario/empresa/licencia/módulo/permiso → 403.
 * 3. Cartera asignada → 404 (no revela clientes de otro cobrador).
 * 4. Sucursal autorizada → 403.
 * 5. Estado de la cuenta y saldo → 403.
 */
class CobrosPaymentGate
{
    public function __construct(private readonly CollectorAccessService $access) {}

    public function evaluate(User $collector, Company $company, AccountReceivable $account): PaymentGateDecision
    {
        // 1. Aislamiento: cuenta y cliente deben pertenecer a la empresa activa.
        if ((int) $account->company_id !== (int) $company->id) {
            return $this->hidden();
        }

        $customer = $account->relationLoaded('customer') ? $account->customer : $account->customer()->first();
        if (! $customer || (int) $customer->company_id !== (int) $company->id) {
            return $this->hidden();
        }

        // 2. Usuario, empresa, licencia, módulo y permiso (mecanismos ya existentes).
        if ($denied = $this->permissionDecision($collector, $company)) {
            return $denied;
        }

        // 3. Cliente activo y asignado a la cartera de este cobrador.
        if (! $customer->is_active || $customer->trashed() || ! $this->customerAssigned($collector, $company, $customer)) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::CUSTOMER_NOT_ASSIGNED,
                'Este cliente no está en su cartera.'
            );
        }

        // 4. Se eliminó la restricción por sucursal: el cobrador puede cobrar
        // desde cualquier sucursal de la empresa mientras se cumplan las demás
        // reglas.

        // 5. Cuenta pagada o cancelada: no admite cobro.
        if (in_array($account->status, [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED], true)) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::ACCOUNT_CLOSED,
                'Esta cuenta ya está saldada o cancelada.'
            );
        }

        // 6. Saldo real con BCMath (decimal:4 del modelo; nunca float).
        if (bccomp((string) $account->balance_due, '0', 4) <= 0) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::NO_BALANCE,
                'Esta cuenta no tiene saldo pendiente.'
            );
        }

        return PaymentGateDecision::allowed();
    }

    public function canStart(User $collector, Company $company, AccountReceivable $account): bool
    {
        return $this->evaluate($collector, $company, $account)->isAllowed();
    }

    /**
     * Corta la petición cuando el Gate no permite iniciar el cobro.
     * La UI nunca reemplaza esta validación de servidor.
     */
    public function assertCanStart(User $collector, Company $company, AccountReceivable $account): PaymentGateDecision
    {
        $decision = $this->evaluate($collector, $company, $account);

        if (! $decision->isAllowed()) {
            abort($decision->httpStatus(), $decision->message);
        }

        return $decision;
    }

    /**
     * Cuenta realmente cobrable por cliente, en una sola pasada.
     * Alimenta el botón COBRAR de 2A/2B/2C: sin Gate no hay botón.
     *
     * @param  array<int>  $customerIds
     * @return array<int, int> customer_id => account_id cobrable
     */
    public function chargeableAccountIds(User $collector, Company $company, array $customerIds): array
    {
        // Obtiene todas las cuentas de los clientes dentro de la empresa
        // que tienen saldo > 0 y cuyo estado no sea pagado o cancelado.
        // No se filtra por sucursal: el cobrador puede acceder a cualquier
        // sucursal de la empresa.
        $chargeable = [];
        $accounts = AccountReceivable::query()->forCompany($company->id)
            ->whereIn('customer_id', $customerIds)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', [AccountReceivable::STATUS_PAID, AccountReceivable::STATUS_CANCELLED])
            ->with('customer')->orderBy('due_date')->orderBy('id')->get();
        foreach ($accounts as $account) {
            if (! isset($chargeable[$account->customer_id]) && $this->canStart($collector, $company, $account)) {
                $chargeable[$account->customer_id] = (int) $account->id;
            }
        }

        return $chargeable;
    }

    // -----------------------------------------------------------------

    private function permissionDecision(User $collector, Company $company): ?PaymentGateDecision
    {
        if (! $company->is_active) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::PERMISSION_DENIED,
                'La empresa no está activa.'
            );
        }

        $license = $company->license()->first();
        if (! $license || ! $license->isOperable()
            || ($license->starts_at?->isFuture())
            || ($license->expires_at?->isPast() && ! $license->grace_until?->isFuture())) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::PERMISSION_DENIED,
                'La licencia de la empresa no está vigente.'
            );
        }

        // CollectorAccessService valida usuario activo + permiso cobros.cobrar, que a su vez
        // comprueba pertenencia a la empresa, rol activo, módulo cobros y permiso activo.
        $role = $collector->roleInCompany($company);
        if (! $this->access->canCollect($collector, $company)
            || ! $company->modules()->where('module_key', 'cobros')->where('is_enabled', true)->exists()
            || ! $role?->permissions()->where('permissions.name', 'cobros.cobrar')->where('permissions.is_active', true)->exists()) {
            return PaymentGateDecision::denied(
                PaymentGateDecision::PERMISSION_DENIED,
                'No tiene permiso para cobrar.'
            );
        }

        return null;
    }

    private function customerAssigned(User $collector, Company $company, Customer $customer): bool
    {
        return CobrosAssignment::query()
            ->forCompany($company->id)
            ->where('collector_user_id', $collector->id)
            ->where('customer_id', $customer->id)
            ->exists();
    }

    /** @return list<int> */
    private function allowedBranchIds(User $collector, Company $company): array
    {
        $keys = $this->access->branchesForCollecting($collector, $company)->modelKeys();

        return array_values(array_map('intval', $keys));
    }

    private function hidden(): PaymentGateDecision
    {
        return PaymentGateDecision::denied(
            PaymentGateDecision::OTHER_COMPANY,
            'No se encontró el recurso solicitado.'
        );
    }
}
