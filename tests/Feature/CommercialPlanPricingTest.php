<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyLicense;
use App\Models\ElectronicDocument;
use App\Models\FiscalConsumption;
use App\Models\LicensePlan;
use App\Models\User;
use App\Services\Billing\CommercialPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialPlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $code): LicensePlan
    {
        return LicensePlan::query()->where('code', $code)->firstOrFail();
    }

    public function test_official_templates_are_seeded(): void
    {
        $commerce = $this->plan('mvs-commerce');
        $this->assertSame('MVS Commerce', $commerce->name);
        $this->assertSame(1, $commerce->branch_limit);
        $this->assertSame(2, $commerce->user_limit);
        $this->assertSame('48.00', $commerce->base_price_usd);
        $this->assertSame('25.00', $commerce->extra_branch_price_usd);
        $this->assertSame('10.00', $commerce->extra_user_price_usd);

        $multi = $this->plan('multi-sucursal');
        $this->assertSame('MultiSucursal', $multi->name);
        $this->assertSame(2, $multi->branch_limit);
        $this->assertSame(5, $multi->user_limit);
        $this->assertSame('90.00', $multi->base_price_usd);

        $this->assertTrue($this->plan('personalizado')->is_custom);
    }

    public function test_commerce_price_is_recalculated_per_branches_and_users(): void
    {
        $service = new CommercialPricingService();
        $plan = $this->plan('mvs-commerce');

        $included = $service->quote($plan, 1, 2);
        $this->assertSame(48.0, $included['commerce']['price_usd']);
        $this->assertSame(0, $included['commerce']['extra_branches']);
        $this->assertSame(0, $included['commerce']['extra_users']);

        $extras = $service->quote($plan, 3, 4);
        $this->assertSame(2, $extras['commerce']['extra_branches']);
        $this->assertSame(2, $extras['commerce']['extra_users']);
        $this->assertSame(48.0 + 50.0 + 20.0, $extras['commerce']['price_usd']);

        $multi = $service->quote($this->plan('multi-sucursal'), 2, 5);
        $this->assertSame(90.0, $multi['commerce']['price_usd']);
        $this->assertSame(0, $multi['commerce']['extra_users']);
    }

    public function test_manual_override_wins_over_calculated_price(): void
    {
        $service = new CommercialPricingService();

        $quote = $service->quote($this->plan('mvs-commerce'), 2, 3, 'none', 60.00);

        $this->assertSame(60.0, $quote['commerce']['price_usd']);
        $this->assertTrue($quote['commerce']['manual_override']);
        $this->assertSame(48.0 + 25.0 + 10.0, $quote['commerce']['calculated_price_usd']);
    }

    public function test_custom_template_without_price_is_blocked(): void
    {
        $service = new CommercialPricingService();

        $this->expectException(ValidationException::class);

        $service->quote($this->plan('personalizado'), 1, 1);
    }

    public function test_custom_template_requires_manual_price(): void
    {
        $service = new CommercialPricingService();

        $quote = $service->quote($this->plan('personalizado'), 4, 6, 'none', 120.00);

        $this->assertSame(120.0, $quote['commerce']['price_usd']);
        $this->assertTrue($quote['commerce']['is_custom']);
    }

    public function test_fiscal_plan_catalog_and_iva(): void
    {
        $service = new CommercialPricingService();
        $catalog = $service->fiscalCatalog();

        $this->assertSame(['none', 'fiscal100', 'fiscal250', 'fiscal500', 'fiscal1000', 'custom'], array_keys($catalog));
        $this->assertFalse($catalog['none']['enabled'] ?? false);
        $this->assertSame(100, $catalog['fiscal100']['quota']);
        $this->assertSame(5000.0, $catalog['fiscal100']['price_crc']);
        $this->assertSame(9000.0, $catalog['fiscal250']['price_crc']);
        $this->assertSame(15000.0, $catalog['fiscal500']['price_crc']);
        $this->assertSame(25000.0, $catalog['fiscal1000']['price_crc']);
        $this->assertSame(650.0, $catalog['fiscal100']['iva_crc']);
        $this->assertSame(5650.0, $catalog['fiscal100']['total_crc']);
    }

    public function test_fiscal_plan_applies_quota_and_enables_fiscal_gate(): void
    {
        $service = new CommercialPricingService();

        $quote = $service->quote($this->plan('mvs-commerce'), 1, 2, 'fiscal250');

        $this->assertTrue($quote['fiscal']['enabled']);
        $this->assertSame(250, $quote['fiscal']['monthly_quota']);
        $this->assertSame(9000.0, $quote['fiscal']['price_crc']);
        $this->assertSame(1170.0, $quote['fiscal']['iva_crc']);
        $this->assertSame(10170.0, $quote['fiscal']['total_crc']);

        $attributes = $service->licenseFiscalAttributes($quote['fiscal']);
        $this->assertTrue($attributes['fiscal_enabled']);
        $this->assertSame(250, $attributes['fiscal_monthly_quota']);

        $disabled = $service->licenseFiscalAttributes($service->quote($this->plan('mvs-commerce'), 1, 2, 'none')['fiscal']);
        $this->assertFalse($disabled['fiscal_enabled']);
        $this->assertNull($disabled['fiscal_monthly_quota']);
    }

    public function test_usd_and_crc_are_never_summed(): void
    {
        $service = new CommercialPricingService();

        $quote = $service->quote($this->plan('multi-sucursal'), 3, 7, 'fiscal500');

        $this->assertSame('USD', $quote['commerce']['currency']);
        $this->assertSame('CRC', $quote['fiscal']['currency']);
        $this->assertSame(90.0 + 25.0 + 20.0, $quote['commerce']['price_usd']);
        $this->assertSame(16950.0, $quote['fiscal']['total_crc']);
        $this->assertArrayNotHasKey('total', $quote);
    }

    public function test_snapshot_survives_future_plan_price_changes(): void
    {
        $service = new CommercialPricingService();
        $plan = $this->plan('mvs-commerce');

        $quote = $service->quote($plan, 1, 2, 'fiscal100');
        $snapshot = $service->snapshot($quote, $plan);

        $plan->update(['base_price_usd' => 99]);
        $recalculated = $service->quote($plan->fresh(), 1, 2, 'fiscal100');

        $this->assertSame(99.0, $recalculated['commerce']['price_usd']);
        $this->assertSame(48.0, $snapshot['commerce']['price_usd']);
        $this->assertSame(5000.0, $snapshot['fiscal']['price_crc']);
        $this->assertSame('mvs-commerce', $snapshot['plan_code']);
    }

    public function test_new_company_stores_contract_snapshot(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($admin)->post(route('platform.companies.store'), [
            'trade_name' => 'Comercio Norte',
            'owner' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            'license_plan_id' => $this->plan('mvs-commerce')->id,
            'branches' => 3,
            'users' => 4,
            'fiscal_plan' => 'fiscal100',
            'status' => 'active',
            'notes' => null,
            'modules' => ['sales'],
        ])->assertRedirect();

        $company = Company::query()->where('trade_name', 'Comercio Norte')->firstOrFail();
        $license = CompanyLicense::query()->where('company_id', $company->id)->firstOrFail();

        $this->assertTrue($license->hasContractSnapshot());
        $snapshot = $license->contractSnapshot();
        $this->assertSame(118.0, (float) $snapshot['commerce']['price_usd']);
        $this->assertSame(3, (int) $snapshot['commerce']['branches'] ?? 3);
        $this->assertSame(5000.0, (float) $snapshot['fiscal']['price_crc']);
        $this->assertSame(5650.0, (float) $snapshot['fiscal']['total_crc']);
        $this->assertTrue($license->fiscal_enabled);
        $this->assertSame(100, (int) $license->fiscal_monthly_quota);
        $this->assertSame(3, (int) $license->branch_limit);
        $this->assertSame(4, (int) $license->user_limit);
    }

    public function test_new_company_accepts_manual_price_override(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($admin)->post(route('platform.companies.store'), [
            'trade_name' => 'Comercio Sur',
            'owner' => ['name' => 'Luis', 'email' => 'luis@example.com'],
            'license_plan_id' => $this->plan('multi-sucursal')->id,
            'branches' => 2,
            'users' => 5,
            'commerce_price_usd' => 77.50,
            'status' => 'active',
            'modules' => ['sales'],
        ])->assertRedirect();

        $company = Company::query()->where('trade_name', 'Comercio Sur')->firstOrFail();
        $snapshot = CompanyLicense::query()->where('company_id', $company->id)->firstOrFail()->contractSnapshot();

        $this->assertSame(77.5, (float) $snapshot['commerce']['price_usd']);
        $this->assertTrue($snapshot['commerce']['manual_override']);
        $this->assertFalse((bool) CompanyLicense::query()->where('company_id', $company->id)->value('fiscal_enabled'));
    }

    public function test_legacy_company_without_snapshot_is_pending_and_never_inferred(): void
    {
        $service = new CommercialPricingService();
        $company = Company::create([
            'trade_name' => 'Legacy',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        CompanyLicense::create([
            'company_id' => $company->id,
            'status' => 'active',
            'plan' => 'Antigua',
            'branch_limit' => 3,
            'user_limit' => 5,
        ]);

        $summary = $service->summaryFor($company->fresh('license'));

        $this->assertFalse($summary['has_snapshot']);
        $this->assertTrue($summary['pending']);
        $this->assertNull($summary['commerce']);
        $this->assertNull($summary['fiscal']);
        $this->assertSame(0, $summary['fiscal_used']);
        $this->assertSame('Precio contractual pendiente', $service->label());
    }

    public function test_dashboard_summary_reports_consumption_and_state(): void
    {
        $service = new CommercialPricingService();
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($admin)->post(route('platform.companies.store'), [
            'trade_name' => 'Comercio Este',
            'owner' => ['name' => 'Eva', 'email' => 'eva@example.com'],
            'license_plan_id' => $this->plan('mvs-commerce')->id,
            'branches' => 1,
            'users' => 2,
            'fiscal_plan' => 'fiscal250',
            'status' => 'active',
            'modules' => ['sales'],
        ])->assertRedirect();

        $company = Company::query()->where('trade_name', 'Comercio Este')->firstOrFail();
        $document = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'consumo-prueba',
            'status' => 'accepted',
        ]);
        FiscalConsumption::create([
            'company_id' => $company->id,
            'electronic_document_id' => $document->id,
            'document_type' => '01',
            'period' => now()->startOfMonth()->toDateString(),
            'classification' => 'included',
        ]);

        $summary = $service->summaryFor($company->fresh('license'));

        $this->assertTrue($summary['has_snapshot']);
        $this->assertFalse($summary['pending']);
        $this->assertSame(48.0, (float) $summary['commerce']['price_usd']);
        $this->assertSame(10170.0, (float) $summary['fiscal']['total_crc']);
        $this->assertSame(250, (int) $summary['fiscal_quota']);
        $this->assertSame(1, $summary['fiscal_used']);
        $this->assertSame('active', $summary['state']);
    }

    public function test_platform_pages_render_contract_columns(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $this->actingAs($admin)->get(route('platform.companies.create'))
            ->assertOk()
            ->assertSee('MVS Commerce')
            ->assertSee('MultiSucursal')
            ->assertSee('Fiscal100')
            ->assertSee('Recalculado automáticamente');

        $legacy = Company::create([
            'trade_name' => 'Sin Snapshot',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        CompanyLicense::create([
            'company_id' => $legacy->id,
            'status' => 'active',
            'plan' => 'Antigua',
            'branch_limit' => 2,
            'user_limit' => 3,
        ]);

        $this->actingAs($admin)->get(route('platform.index'))
            ->assertOk()
            ->assertSee('Commerce USD/mes')
            ->assertSee('Plan FE (CRC/mes)')
            ->assertSee('Consumo FE')
            ->assertSee('Antigua')
            ->assertSee('Precio contractual pendiente');
    }
}