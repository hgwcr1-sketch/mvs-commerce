<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CobrosCollectorAuthorityPolicy;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CobrosCreditRenewalConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '768M');
    }

    public function test_pending_renewal_can_only_be_resolved_by_authorized(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, $route] = $ctx;
        $customerId = $ctx[4]->customer_id;
        $renewal = $this->pendingRenewal($company, $collector, $ctx[4], $ctx[5]);

        // Cobrador vinculado sin renovar: NO puede aprobar ni rechazar (403).
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.renewal.approve', [$customerId, $renewal->id]))->assertForbidden();
        $this->post(route('cobros.collector.renewal.reject', [$customerId, $renewal->id]))->assertForbidden();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_PENDING, $renewal->fresh()->status);

        // Usuario ajeno (otra persona de la empresa, sin cartera): 403.
        $stranger = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($stranger)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.approve', [$customerId, $renewal->id]))->assertForbidden();

        // Prestamista: aprueba y rechaza según corresponda (aquí aprueba).
        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.approve', [$customerId, $renewal->id]))
            ->assertRedirect(route('cobros.collector.portfolio.show', $customerId))
            ->assertSessionHas('renewal_status', 'approved');
        $fresh = $renewal->fresh();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($this->owner->id, (int) $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_rejection_persists_with_reason_and_trace(): void
    {
        $ctx = $this->disbursed();
        [$company] = $ctx;
        $renewal = $this->pendingRenewal($company, $ctx[1], $ctx[4], $ctx[5]);

        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.reject', [$ctx[4]->customer_id, $renewal->id]), ['reason' => 'Monto excede política'])
            ->assertSessionHas('renewal_status', 'rejected');

        $fresh = $renewal->fresh();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_REJECTED, $fresh->status);
        $this->assertSame($this->owner->id, (int) $fresh->rejected_by);
        $this->assertSame('Monto excede política', $fresh->reject_reason);
        $this->assertDatabaseHas('cobros_credit_request_logs', ['company_id' => $company->id]);
    }

    public function test_direct_collector_can_approve_and_consent_needs_fresh_signature(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, $route] = $ctx;
        CobrosCollectorAuthorityPolicy::set($company->id, $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);
        $renewal = $this->pendingRenewal($company, $collector, $ctx[4], $ctx[5]);

        // Independiente con cartera propia: aprueba directo.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.renewal.approve', [$ctx[4]->customer_id, $renewal->id]))
            ->assertSessionHas('renewal_status', 'approved');

        // Consentimiento sin firma: rechazado.
        $this->post(route('cobros.collector.renewal.consent', [$ctx[4]->customer_id, $renewal->id]), ['signature' => ''])
            ->assertSessionHasErrors('signature');

        // Firma válida: consentimiento persistente con hash de términos exactos.
        $this->post(route('cobros.collector.renewal.consent', [$ctx[4]->customer_id, $renewal->id]), [
            'signature' => $this->signaturePng(),
        ])->assertSessionHas('renewal_status', 'consented');

        $fresh = $renewal->fresh();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_CONSENTED, $fresh->status);
        $this->assertNotNull($fresh->consented_at);
        $this->assertNotNull($fresh->signature_path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $fresh->terms_hash);
        $this->assertIsArray($fresh->consent_snapshot);
        $this->assertEqualsWithDelta((float) $renewal->consolidated_total, (float) ($fresh->consent_snapshot['total_consolidado'] ?? 0), 0.001);

        // Firma reutilizada (segundo consentimiento): rechazado.
        $this->post(route('cobros.collector.renewal.consent', [$ctx[4]->customer_id, $renewal->id]), [
            'signature' => $this->signaturePng(),
        ])->assertSessionHasErrors('signature');
    }

    public function test_tampered_terms_hash_and_non_approved_consent_rejected(): void
    {
        $ctx = $this->disbursed();
        [$company] = $ctx;
        $renewal = $this->pendingRenewal($company, $ctx[1], $ctx[4], $ctx[5]);
        $customerId = $ctx[4]->customer_id;

        // Consentimiento sobre pendiente: rechazado.
        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.consent', [$customerId, $renewal->id]), [
            'signature' => $this->signaturePng(),
        ])->assertSessionHasErrors('renewal');

        $this->post(route('cobros.collector.renewal.approve', [$customerId, $renewal->id]))->assertSessionHas('renewal_status', 'approved');

        // Hash de términos manipulado en el navegador: rechazado.
        $this->post(route('cobros.collector.renewal.consent', [$customerId, $renewal->id]), [
            'signature' => $this->signaturePng(),
            'terms_hash' => str_repeat('a', 64),
        ])->assertSessionHasErrors('terms_hash');

        $this->assertNull($renewal->fresh()->consented_at);
    }

    public function test_foreign_tenant_cannot_resolve_or_consent(): void
    {
        $ctx = $this->disbursed();
        [$company, , , , $credit] = $ctx;
        $renewal = $this->pendingRenewal($company, $ctx[1], $credit, $ctx[5]);

        $foreign = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($foreign, $admin, ['cobros']);
        $stranger = $this->member($foreign, ['cobros.acceder', 'cobros.portal_dueno']);

        $this->actingAs($stranger)->withSession(['active_company_id' => $foreign->id]);
        $this->post(route('cobros.collector.renewal.approve', [$credit->customer_id, $renewal->id]))->assertNotFound();
        $this->post(route('cobros.collector.renewal.consent', [$credit->customer_id, $renewal->id]), [
            'signature' => $this->signaturePng(),
        ])->assertNotFound();

        $this->assertSame(CobrosCreditRenewalRequest::STATUS_PENDING, $renewal->fresh()->status);
        $this->assertNull($renewal->fresh()->signature_path);
    }

    public function test_no_financial_movements_after_approval_and_consent(): void
    {
        $ctx = $this->disbursed();
        [$company] = $ctx;
        $credits = CobrosCredit::count();
        $installments = CobrosCreditInstallment::count();
        $balance = (string) $ctx[5]->fresh()->balance_due;
        $renewal = $this->pendingRenewal($company, $ctx[1], $ctx[4], $ctx[5]);

        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.approve', [$ctx[4]->customer_id, $renewal->id]));
        $this->post(route('cobros.collector.renewal.consent', [$ctx[4]->customer_id, $renewal->id]), [
            'signature' => $this->signaturePng(),
        ]);

        $this->assertSame($credits, CobrosCredit::count());
        $this->assertSame($installments, CobrosCreditInstallment::count());
        $this->assertSame(0, CobrosReceipt::count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::count());
        $this->assertSame($balance, (string) $ctx[5]->fresh()->balance_due);
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $ctx[4]->fresh()->status);
    }

    public function test_double_approval_rejected(): void
    {
        $ctx = $this->disbursed();
        [$company] = $ctx;
        $renewal = $this->pendingRenewal($company, $ctx[1], $ctx[4], $ctx[5]);

        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.approve', [$ctx[4]->customer_id, $renewal->id]))->assertSessionHas('renewal_status', 'approved');
        $this->post(route('cobros.collector.renewal.approve', [$ctx[4]->customer_id, $renewal->id]))->assertSessionHasErrors('renewal');
        $this->post(route('cobros.collector.renewal.reject', [$ctx[4]->customer_id, $renewal->id]))->assertSessionHasErrors('renewal');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function member(Company $company, array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $role->permissions()->attach(Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'CobrOS', 'is_active' => true]));
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        return $user;
    }

    private function context(): array
    {
        $company = Company::create(['trade_name' => 'RenovD '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company, $collector, $route, $this->owner];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'nuevo',
            'identification_type' => 'cedula',
            'identification' => '102030405',
            'name' => 'Maria Solis',
            'phone' => '88888888',
            'address' => 'San Ramon, calle 1',
            'amount_requested' => '1500.50',
            'notes' => 'Solicitud renovacion D1',
        ], $overrides);
    }

    private function sendAsCollector(array $payload, CobrosRoute $route, User $collector): TestResponse
    {
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);
        $review = $this->post(route('cobros.collector.credit-requests.review'), $payload + [
            'id_front' => UploadedFile::fake()->image('frente.jpg', 100, 80),
            'id_back' => UploadedFile::fake()->image('reverso.jpg', 100, 80),
        ]);
        $review->assertOk();
        preg_match('/name="review_token" value="([A-Za-z0-9]+)"/', $review->getContent(), $m);
        $this->assertNotEmpty($m);

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]]);
    }

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;
        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $id), ['motivo' => 'Datos confirmados'])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->findOrFail($id);
    }

    private function terms(): array
    {
        return [
            'principal' => '1000.00',
            'interest_type' => 'percent',
            'interest_value' => '12.5',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => '0',
        ];
    }

    private function disbursed(): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms())->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $credit = CobrosCredit::firstOrFail();

        return [$company, $collector, $route, $owner, $credit, $credit->account()->first()];
    }

    /** Renovación pending real vía el servicio REN-03B (sin tocar estados). */
    private function pendingRenewal(Company $company, User $collector, CobrosCredit $credit, $account): CobrosCreditRenewalRequest
    {
        $service = app(\App\Services\Cobros\CreditRenewalQuoteService::class);
        $quote = $service->quote(
            $collector,
            $company,
            $account,
            '500.00',
            3,
            (string) $credit->frequency,
            now()->addWeek()->toDateString(),
        );

        return CobrosCreditRenewalRequest::create([
            'company_id' => $company->id,
            'credit_id' => $credit->id,
            'account_receivable_id' => $account->id,
            'collector_user_id' => $collector->id,
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
            'status' => CobrosCreditRenewalRequest::STATUS_PENDING,
            'requested_by' => $collector->id,
        ]);
    }

    private function signaturePng(): string
    {
        $image = imagecreatetruecolor(400, 120);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $ink = imagecolorallocate($image, 15, 23, 42);
        imagesetthickness($image, 3);
        imageline($image, 20, 80, 200, 40, $ink);
        imageline($image, 200, 40, 380, 90, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
