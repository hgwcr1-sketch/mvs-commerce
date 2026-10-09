<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * BLOQUE 13-2B — cambio seguro de empresa/prestamista sin cerrar sesión.
 *
 * Reglas:
 *  - Solo membresías activas y autorizadas: empresa `is_active` + rol activo.
 *  - El cambio solo escribe `active_company_id` y limpia el contexto de la
 *    empresa anterior (sucursal y ruta CobrOS activas). No hereda roles,
 *    rutas ni autorizaciones: permisos y modalidad se resuelven por empresa.
 *  - Rechaza empresas ajenas/inactivas (sesión obsoleta) y cambios durante
 *    operaciones sensibles (sesión de caja abierta o en cierre).
 *  - Historial y operaciones quedan en su empresa original: nada se mueve.
 */
class CompanySwitchService
{
    /**
     * Membresías que el usuario puede operar: empresa activa + rol activo.
     * Trae `modality_key` (membresía propia, nunca heredada de otra empresa).
     */
    public function switchable(User $user): Collection
    {
        return Company::query()
            ->join('company_user', 'company_user.company_id', '=', 'companies.id')
            ->join('roles', 'roles.id', '=', 'company_user.role_id')
            ->where('company_user.user_id', $user->id)
            ->where('companies.is_active', true)
            ->whereColumn('roles.company_id', 'companies.id')
            ->where('roles.is_active', true)
            ->select('companies.*')
            ->addSelect('company_user.cobros_modality as modality_key')
            ->orderBy('companies.trade_name')
            ->get();
    }

    /** true si el usuario tiene una operación sensible en curso en esa empresa. */
    public function hasSensitiveOperation(User $user, int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        return CashSession::query()
            ->where('company_id', $companyId)
            ->where('opened_by', $user->id)
            ->whereIn('status', [CashSession::STATUS_OPEN, CashSession::STATUS_CLOSING])
            ->exists();
    }

    /**
     * Cambia la empresa activa de la sesión. Valida el destino en el momento
     * (contra sesiones obsoletas) y limpia el contexto de la empresa anterior.
     *
     * @throws ValidationException si no hay acceso autorizado u operación sensible
     */
    public function switchTo(User $user, int $companyId): Company
    {
        $company = $this->switchable($user)->firstWhere('id', $companyId);

        if ($company === null) {
            throw ValidationException::withMessages([
                'company_id' => 'No tiene acceso autorizado a esa empresa.',
            ]);
        }

        $currentCompanyId = (int) session('active_company_id', 0);

        if ($currentCompanyId !== $companyId && $this->hasSensitiveOperation($user, $currentCompanyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'Tiene una sesión de caja abierta. Ciérela antes de cambiar de empresa.',
            ]);
        }

        // No heredar contexto de la empresa anterior: sucursal y ruta CobrOS.
        session()->forget(['active_branch_id', 'active_cobros_route_id']);
        session(['active_company_id' => $companyId]);

        return $company;
    }
}
