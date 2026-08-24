<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ensambla la información del portal del cliente de Fidelización (F30).
 *
 * Solo lectura: reutiliza los servicios y modelos existentes, nunca calcula
 * puntos por su cuenta. El acceso real del cliente (QR/enlace) corresponde a
 * las fases F31-F35; esta clase recibe empresa y cliente ya resueltos y
 * garantiza que todo dato quede acotado a esa pareja (company_id, customer_id).
 */
class LoyaltyCustomerPortalService
{
    public function __construct(private readonly LoyaltyPointValueService $pointValues, private readonly LoyaltyPromotionService $promotions) {}

    /** @return array{company:Company,customer:Customer,module_active:bool,balance_points:string,balance_money:?string,movements:LengthAwarePaginator,rewards:Collection,promotions:Collection,multipliers:Collection} */
    public function data(Company $company, Customer $customer): array
    {
        $moduleActive = LoyaltySetting::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->exists();

        $account = LoyaltyAccount::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->first();

        $balancePoints = (string) ($account->balance ?? '0.0000');

        return [
            'company' => $company,
            'customer' => $customer,
            'module_active' => $moduleActive,
            'balance_points' => $balancePoints,
            'balance_money' => $this->balanceMoney($company, $balancePoints),
            'movements' => $this->movements((int) $company->id, (int) $customer->id),
            'rewards' => $this->rewards((int) $company->id),
            'promotions' => $this->publicity($company),
            'multipliers' => $this->multipliers($company),
        ];
    }

    /** Valor monetario del saldo usando el servicio central (F14); null si la configuración es inválida. */
    private function balanceMoney(Company $company, string $balancePoints): ?string
    {
        try {
            return $this->pointValues->moneyFromPoints($balancePoints, $company);
        } catch (ValidationException) {
            return null;
        }
    }

    private function movements(int $companyId, int $customerId): LengthAwarePaginator
    {
        return LoyaltyMovement::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();
    }

    private function rewards(int $companyId): Collection
    {
        return LoyaltyReward::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('points_cost')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'description', 'points_cost']);
    }

    /** Publicidad/promociones administrables vigentes de la empresa (F35). */
    private function publicity(Company $company): Collection
    {
        return $this->promotions->vigentes($company);
    }

    /** Multiplicadores vigentes (mecanismo de puntos existente en la arquitectura, F12). */
    private function multipliers(Company $company): Collection
    {
        $instant = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->utc();

        return LoyaltyMultiplier::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>=', $instant)
            ->with('branch:id,company_id,name')
            ->orderByDesc('starts_at')
            ->get(['id', 'company_id', 'branch_id', 'name', 'multiplier', 'starts_at', 'ends_at']);
    }
}
