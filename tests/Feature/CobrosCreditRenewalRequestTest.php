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

class CobrosCreditRenewalRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '768M');
    }

    public function test_owner_with_portal_approves_directly(): void
    {
        $ctx = $this->disbursed();
        [$company, , , , $credit, $account] = $ctx;

        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $response = $this->post(route('cobros.collector.renewal.store', $credit->customer_id), [
            'new_capital' => '50000',
            'installment_count' => 4,
            'first_due_date' => now()->addWeek()->toDateString(),
        ]);
        $response->assertRedirect(route('cobros.collector.portfolio.show', $credit->customer_id))
            ->assertSessionHas('renewal_status', 'approved');

        $this->assertSame(1, CobrosCreditRenewalRequest::count());
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_APPROVED, CobrosCreditRenewalRequest::firstOrFail()->status);
    }

    public function test_independent_collector_approves_directly_in_own_portfolio(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector] = $ctx;
        CobrosCollectorAuthorityPolicy::set($company->id, $collector->id, CobrosCollectorAuthorityPolicy::COLLECTOR_AUTHORIZED);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $ctx[2]->id]);
        $this->post(route('cobros.collector.renewal.store', $ctx[4]->customer_id), [
            'new_capital' => '20000',
            'installment_count' => 6,
            'first_due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHas('renewal_status', 'approved');

        $this->assertSame(CobrosCreditRenewalRequest::STATUS_APPROVED, CobrosCreditRenewalRequest::firstOrFail()->status);
    }

    public function test_linked_collector_with_renew_permission_approves_directly(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector] = $ctx; // default policy = owner_required
        $role = Role::create(['company_id' => $company->id, 'name' => 'Renov '.uniqid(), 'is_active' => true]);
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'a', 'module' => 'CobrOS', 'is_active' => true]));
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'cobros.cobrar'], ['label' => 'c', 'module' => 'CobrOS', 'is_active' => true]));
        $role->permissions()->attach(Permission::firstOrCreate(['name' => 'cobros.renovar'], ['label' => 'r', 'module' => 'CobrOS', 'is_active' => true]));
        $collector->companies()->updateExistingPivot($company->id, ['role_id' => $role->id]);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $ctx[2]->id]);
        $this->post(route('cobros.collector.renewal.store', $ctx[4]->customer_id), [
            'new_capital' => '20000',
            'installment_count' => 6,
            'first_due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHas('renewal_status', 'approved');
    }

    public function test_linked_collector_without_permission_gets_pending(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector] = $ctx; // owner_required, sin cobros.renovar

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $ctx[2]->id]);
        $this->post(route('cobros.collector.renewal.store', $ctx[4]->customer_id), [
            'new_capital' => '20000',
            'installment_count' => 6,
            'first_due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHas('renewal_status', 'pending');

        $renewal = CobrosCreditRenewalRequest::firstOrFail();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_PENDING, $renewal->status);
        $this->assertNull($renewal->approved_by);
        $this->assertNull($renewal->approved_at);
    }

    public function test_foreign_tenant_and_unassigned_collector_denied(): void
    {
        $ctx = $this->disbursed();
        [$company, , , , $credit] = $ctx;

        // Empresa ajena: 404 (sin revelar existencia).
        $foreign = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($foreign, $admin, ['cobros']);
        $stranger = $this->member($foreign, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($stranger)->withSession(['active_company_id' => $foreign->id]);
        $this->get(route('cobros.collector.renewal.create', $credit->customer_id))->assertNotFound();

        // Cobrador no asignado en la misma empresa: 403.
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($other)->withSession(['active_company_id' => $company->id]);
        $this->get(route('cobros.collector.renewal.create', $credit->customer_id))->assertForbidden();
        $this->post(route('cobros.collector.renewal.store', $credit->customer_id), [
            'new_capital' => '1000', 'installment_count' => 4, 'first_due_date' => now()->addWeek()->toDateString(),
        ])->assertForbidden();
        $this->assertSame(0, CobrosCreditRenewalRequest::count());
    }

    public function test_duplicate_requests_and_approval_mode_manipulation_rejected(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector] = $ctx;
        $customerId = $ctx[4]->customer_id;

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $ctx[2]->id]);
        $payload = ['new_capital' => '20000', 'installment_count' => 6, 'first_due_date' => now()->addWeek()->toDateString()];
        $this->post(route('cobros.collector.renewal.store', $customerId), $payload)
            ->assertSessionHas('renewal_status', 'pending');

        // Duplicada: rechazada, sin segunda fila.
        $this->post(route('cobros.collector.renewal.store', $customerId), $payload)->assertSessionHasErrors('new_capital');
        $this->assertSame(1, CobrosCreditRenewalRequest::count());

        // Manipular approval_mode desde el request: el servidor lo ignora (sigue pending).
        $this->post(route('cobros.collector.renewal.store', $customerId + 0), $payload + ['approval_mode' => 'direct', 'status' => 'approved'])
            ->assertSessionHasErrors('new_capital');
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_PENDING, CobrosCreditRenewalRequest::firstOrFail()->status);
    }

    public function test_no_credit_payment_or_disbursement_created(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector] = $ctx;
        $creditsBefore = CobrosCredit::count();
        $installmentsBefore = CobrosCreditInstallment::count();
        $balanceBefore = (string) $ctx[5]->fresh()->balance_due;

        $this->actingAs($this->owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.collector.renewal.store', $ctx[4]->customer_id), [
            'new_capital' => '50000', 'installment_count' => 4, 'first_due_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHas('renewal_status', 'approved');

        $this->assertSame($creditsBefore, CobrosCredit::count());
        $this->assertSame($installmentsBefore, CobrosCreditInstallment::count());
        $this->assertSame(0, CobrosReceipt::count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::count());
        $this->assertSame($balanceBefore, (string) $ctx[5]->fresh()->balance_due);
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $ctx[4]->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Helpers (mismo flujo real de CobrosCreditRenewalQuoteTest)
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
        $company = Company::create(['trade_name' => 'RenovC '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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
            'notes' => 'Solicitud renovacion C1',
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
