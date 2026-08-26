<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyMultiplier;
use App\Models\LoyaltyPortalCredential;
use App\Models\LoyaltyPortalLink;
use App\Models\LoyaltyPortalPost;
use App\Models\LoyaltyReward;
use App\Models\LoyaltySetting;
use App\Models\Product;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
    public function __construct(private readonly LoyaltyPointValueService $pointValues, private readonly LoyaltyPromotionService $promotions, private readonly LoyaltyRedemptionEligibilityService $eligibility) {}

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
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();

        return [
            'company' => $company,
            'customer' => $customer,
            'module_active' => $moduleActive,
            'balance_points' => $balancePoints,
            'total_earned' => (string) ($account->total_earned ?? '0.0000'),
            'total_redeemed' => (string) ($account->total_redeemed ?? '0.0000'),
            'balance_money' => $this->balanceMoney($company, $balancePoints),
            'movements' => $this->movements((int) $company->id, (int) $customer->id),
            'rewards' => $this->rewards((int) $company->id, $balancePoints),
            'promotions' => $this->publicity($company),
            'multipliers' => $this->multipliers($company),
            'redemption' => $account ? $this->eligibility->evaluate($account, $company) : null,
            'expiration' => $this->expiration($company, $setting, $account),
            'sales' => $this->sales((int) $company->id, (int) $customer->id),
            'offers' => Product::query()->where('company_id', $company->id)->where('is_active', true)->whereNotNull('special_price')->latest()->limit(6)->get(['id', 'name', 'image', 'sale_price', 'special_price']),
            'recommended' => $this->recommended((int) $company->id, (int) $customer->id),
            'credentialExists' => LoyaltyPortalCredential::query()->where('company_id', $company->id)->where('customer_id', $customer->id)->exists(),
            'posts' => LoyaltyPortalPost::query()->where('company_id', $company->id)->where('is_active', true)->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->with('product:id,company_id,name,image,sale_price,special_price')->orderByDesc('is_featured')->orderBy('sort_order')->get(),
            'portalLinks' => LoyaltyPortalLink::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('sort_order')->get(),
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
            ->with('branch:id,company_id,name')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();
    }

    private function sales(int $companyId, int $customerId): LengthAwarePaginator
    {
        return Sale::query()->where('company_id', $companyId)->where('customer_id', $customerId)
            ->whereIn('status', [Sale::STATUS_COMPLETED, Sale::STATUS_PARTIALLY_RETURNED, Sale::STATUS_RETURNED, Sale::STATUS_VOIDED])
            ->with('branch:id,company_id,name')->latest('completed_at')->paginate(8, ['*'], 'sales_page')->withQueryString();
    }

    private function expiration(Company $company, ?LoyaltySetting $setting, ?LoyaltyAccount $account): ?array
    {
        if (! $setting?->expiration_enabled || ! $account?->last_qualifying_purchase_at || (int) $setting->expiration_months < 1) {
            return null;
        }
        $due = CarbonImmutable::instance($account->last_qualifying_purchase_at)->setTimezone($company->timezone ?: config('app.timezone'))->addMonthsNoOverflow((int) $setting->expiration_months)->startOfDay();
        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone'))->startOfDay();

        return ['date' => $due, 'days' => max(0, $today->diffInDays($due, false)), 'near' => $today->diffInDays($due, false) <= 30, 'months' => (int) $setting->expiration_months];
    }

    private function recommended(int $companyId, int $customerId): Collection
    {
        $categoryIds = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.company_id', $companyId)->where('sales.customer_id', $customerId)->whereNotNull('products.category_id')->selectRaw('products.category_id, COUNT(*) purchases')->groupBy('products.category_id')->orderByDesc('purchases')->limit(3)->pluck('products.category_id');

        return Product::query()->where('company_id', $companyId)->where('is_active', true)->whereIn('category_id', $categoryIds)->latest()->limit(6)->get(['id', 'name', 'image', 'sale_price', 'special_price']);
    }

    private function rewards(int $companyId, string $balancePoints): Collection
    {
        $rewards = LoyaltyReward::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('points_cost')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'description', 'points_cost']);

        return $rewards->each(function (LoyaltyReward $reward) use ($balancePoints) {
            $reward->setAttribute('missing_points', bccomp((string) $reward->points_cost, $balancePoints, 4) > 0
                ? bcsub((string) $reward->points_cost, $balancePoints, 4)
                : '0.0000');
        });
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
