<?php

namespace App\Rules;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Contracts\Validation\Rule;

/**
 * R01 MVS RouteOS — Seguridad del crédito del cliente.
 *
 * El límite y los días de crédito solo pueden modificarse por usuarios con
 * el permiso granular `routeos.credito.administrar`. Un usuario con permiso
 * de edición de clientes pero sin este permiso:
 *  - puede conservar los valores actuales (edición normal intacta);
 *  - no puede cambiarlos (rechazo de validación a nivel backend).
 *
 * En creación (sin cliente existente) solo se permite límite 0 sin permiso.
 */
class CanAdministerCustomerCredit implements Rule
{
    public function __construct(private ?Customer $customer)
    {
    }

    public function passes($attribute, $value): bool
    {
        $company = Company::query()->find(session('active_company_id'));
        $user = auth()->user();

        if (! $user || ! $company) {
            return false;
        }

        if ($user->hasPermission('routeos.credito.administrar', $company)) {
            return true;
        }

        if ($this->customer === null) {
            // Creación: sin el permiso solo se puede registrar sin crédito.
            return bccomp((string) $value, '0', 2) === 0;
        }

        $current = $attribute === 'credit_limit'
            ? $this->customer->credit_limit
            : $this->customer->credit_days;

        return bccomp((string) ($current ?? '0'), (string) $value, 2) === 0;
    }

    public function message(): string
    {
        return 'Solo un usuario con el permiso routeos.credito.administrar puede cambiar el crédito del cliente.';
    }
}
