<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\FiscalConsumption;
use App\Models\LicensePlan;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo y cotización comercial del Panel Maestro.
 *
 * Dos monedas, dos contratos, un solo snapshot:
 * - Commerce: USD (base + extras por sucursal/usuario).
 * - Facturación electrónica (FE): CRC (plan + IVA 13%).
 *
 * Nunca se suman USD y CRC en un mismo total: son contratos distintos.
 */
class CommercialPricingService
{
    /** IVA costarricense aplicado al plan FE (los precios del plan son netos). */
    public const IVA_RATE = 0.13;

    /**
     * Planes de facturación electrónica. El precio es neto en CRC y la cuota
     * mensual es la que consume FiscalConsumptionService.
     *
     * @var array<string, array{label: string, price_crc: ?float, quota: ?int}>
     */
    public const FISCAL_PLANS = [
        'none' => ['label' => 'Ninguno', 'price_crc' => null, 'quota' => null],
        'fiscal100' => ['label' => 'Fiscal100', 'price_crc' => 5000.00, 'quota' => 100],
        'fiscal250' => ['label' => 'Fiscal250', 'price_crc' => 9000.00, 'quota' => 250],
        'fiscal500' => ['label' => 'Fiscal500', 'price_crc' => 15000.00, 'quota' => 500],
        'fiscal1000' => ['label' => 'Fiscal1000', 'price_crc' => 25000.00, 'quota' => 1000],
        'custom' => ['label' => 'Personalizado', 'price_crc' => null, 'quota' => null],
    ];

    public const CURRENCY_COMMERCE = 'USD';

    public const CURRENCY_FISCAL = 'CRC';

    /**
     * Catálogo FE listo para la vista (incluye el total con IVA calculado).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fiscalCatalog(): array
    {
        $catalog = [];

        foreach (self::FISCAL_PLANS as $code => $plan) {
            $catalog[$code] = $plan + [
                'iva_crc' => $this->round($this->iva($plan['price_crc'])),
                'total_crc' => $this->round($this->withIva($plan['price_crc'])),
                'option_label' => $plan['price_crc'] === null
                    ? $plan['label']
                    : $plan['label'].' · ₡'.number_format($plan['price_crc'], 0).' + IVA',
            ];
        }

        return $catalog;
    }

    public function iva(?float $netCrc): float
    {
        return (float) $this->round(($netCrc ?? 0) * self::IVA_RATE);
    }

    public function withIva(?float $netCrc): float
    {
        return (float) $this->round(($netCrc ?? 0) + $this->iva($netCrc));
    }

    /**
     * Cotiza el contrato comercial (USD) y el plan FE (CRC) por separado.
     *
     * El precio manual (override) manda sobre el cálculo automático: nunca
     * se mezcla ni se recalcula en silencio.
     *
     * @return array{commerce: array<string, mixed>, fiscal: array<string, mixed>}
     */
    public function quote(
        ?LicensePlan $plan,
        int $branches = 1,
        int $users = 1,
        string $fiscalPlan = 'none',
        ?float $commercePriceOverride = null,
        ?float $fiscalPriceOverride = null,
        ?int $fiscalQuotaOverride = null,
    ): array {
        $branches = max(0, $branches);
        $users = max(0, $users);

        return [
            'commerce' => $this->quoteCommerce($plan, $branches, $users, $commercePriceOverride),
            'fiscal' => $this->quoteFiscal($plan, $fiscalPlan, $fiscalPriceOverride, $fiscalQuotaOverride),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteCommerce(?LicensePlan $plan, int $branches, int $users, ?float $override): array
    {
        $base = $plan?->base_price_usd !== null ? (float) $plan->base_price_usd : null;
        $extraBranchPrice = (float) ($plan?->extra_branch_price_usd ?? 0);
        $extraUserPrice = (float) ($plan?->extra_user_price_usd ?? 0);
        $includedBranches = (int) ($plan?->branch_limit ?? 0);
        $includedUsers = (int) ($plan?->user_limit ?? 0);

        $extraBranches = max(0, $branches - $includedBranches);
        $extraUsers = max(0, $users - $includedUsers);

        $calculated = $base === null
            ? null
            : $base + ($extraBranches * $extraBranchPrice) + ($extraUsers * $extraUserPrice);

        $total = $override ?? $calculated;

        if ($total === null) {
            throw ValidationException::withMessages([
                'commerce_price_usd' => 'La plantilla no tiene precio base: indique el precio manual en USD.',
            ]);
        }

        return [
            'currency' => self::CURRENCY_COMMERCE,
            'plan_code' => $plan?->code,
            'plan_name' => $plan?->name ?? 'Personalizado',
            'is_custom' => (bool) ($plan?->is_custom ?? true),
            'branches' => $branches,
            'users' => $users,
            'base_price_usd' => $base,
            'included_branches' => $includedBranches,
            'included_users' => $includedUsers,
            'extra_branches' => $extraBranches,
            'extra_users' => $extraUsers,
            'extra_branch_price_usd' => $extraBranchPrice,
            'extra_user_price_usd' => $extraUserPrice,
            'calculated_price_usd' => $calculated === null ? null : $this->round($calculated),
            'manual_override' => $override !== null,
            'price_usd' => $this->round($total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteFiscal(?LicensePlan $plan, string $fiscalPlan, ?float $priceOverride, ?int $quotaOverride): array
    {
        $code = array_key_exists($fiscalPlan, self::FISCAL_PLANS) ? $fiscalPlan : 'none';
        $catalog = self::FISCAL_PLANS[$code];

        $net = $priceOverride ?? ($catalog['price_crc'] ?? $plan?->fiscal_monthly_price_crc);
        $quota = $quotaOverride ?? ($catalog['quota'] ?? $plan?->fiscal_included_quota);

        if ($code === 'custom' && $net === null) {
            throw ValidationException::withMessages([
                'fiscal_price_crc' => 'El plan FE personalizado requiere el precio manual en CRC.',
            ]);
        }

        return [
            'currency' => self::CURRENCY_FISCAL,
            'plan_code' => $code,
            'plan_name' => $catalog['label'],
            'enabled' => $code !== 'none',
            'price_crc' => $net,
            'iva_rate' => self::IVA_RATE,
            'iva_crc' => $this->iva($net),
            'total_crc' => $this->withIva($net),
            'manual_override' => $priceOverride !== null,
            'monthly_quota' => $quota,
        ];
    }

    /**
     * Snapshot inmutable que se guarda en la licencia: cambios futuros de la
     * plantilla NO alteran contratos ya emitidos.
     *
     * @param  array{commerce: array<string, mixed>, fiscal: array<string, mixed>}  $quote
     * @return array<string, mixed>
     */
    public function snapshot(array $quote, ?LicensePlan $plan = null): array
    {
        return [
            'version' => 1,
            'taken_at' => now()->toIso8601String(),
            'plan_code' => $plan?->code ?? $quote['commerce']['plan_code'],
            'plan_name' => $quote['commerce']['plan_name'],
            'commerce' => $quote['commerce'],
            'fiscal' => $quote['fiscal'],
        ];
    }

    /**
     * Resumen para el Panel Maestro. Empresas antiguas sin snapshot muestran
     * "Precio contractual pendiente": NUNCA se infiere un precio.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Company $company): array
    {
        $license = $company->license;
        $snapshot = is_array($license?->contract_snapshot) ? $license->contract_snapshot : null;

        $branches = (int) $company->branches_count;
        $users = (int) $company->users_count;

        if ($snapshot === null) {
            return [
                'has_snapshot' => false,
                'pending' => true,
                'plan_name' => $license?->plan ?? 'Sin plan',
                'branches' => $branches,
                'users' => $users,
                'commerce' => null,
                'fiscal' => null,
                'fiscal_used' => 0,
                'fiscal_quota' => null,
                'state' => $license?->status ?? 'sin configurar',
            ];
        }

        $fiscal = is_array($snapshot['fiscal'] ?? null) ? $snapshot['fiscal'] : [];
        $fiscalEnabled = (bool) ($fiscal['enabled'] ?? false);

        return [
            'has_snapshot' => true,
            'pending' => false,
            'plan_name' => $snapshot['plan_name'] ?? ($license?->plan ?? 'Sin plan'),
            'branches' => $branches,
            'users' => $users,
            'commerce' => is_array($snapshot['commerce'] ?? null) ? $snapshot['commerce'] : null,
            'fiscal' => $fiscal ?: null,
            'fiscal_enabled' => $fiscalEnabled,
            'fiscal_used' => $fiscalEnabled ? $this->fiscalUsed($company->id) : 0,
            'fiscal_quota' => $fiscal['monthly_quota'] ?? null,
            'state' => $license?->status ?? 'sin configurar',
        ];
    }

    public function fiscalUsed(int $companyId): int
    {
        return FiscalConsumption::query()
            ->where('company_id', $companyId)
            ->whereDate('period', now()->startOfMonth()->toDateString())
            ->count();
    }

    /**
     * Aplica el plan FE al contrato de licencia existente: los gates de
     * CompanyLicense (fiscal_enabled / quota) siguen siendo la autoridad.
     *
     * @param  array<string, mixed>  $fiscal
     * @return array<string, mixed>
     */
    public function licenseFiscalAttributes(array $fiscal): array
    {
        return [
            'fiscal_enabled' => (bool) ($fiscal['enabled'] ?? false),
            'fiscal_monthly_quota' => $fiscal['enabled'] ? ($fiscal['monthly_quota'] ?? null) : null,
        ];
    }

    public function label(): string
    {
        return 'Precio contractual pendiente';
    }

    public function apply(CompanyLicense $license, array $snapshot): CompanyLicense
    {
        $license->forceFill(['contract_snapshot' => $snapshot])->save();

        return $license->refresh();
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}