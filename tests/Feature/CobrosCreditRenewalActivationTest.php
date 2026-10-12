<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRenewalRequest;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CreditRenewalActivationService;
use App\Services\Cobros\CreditRenewalConsentService;
use App\Services\Cobros\CreditRenewalQuoteService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CobrosCreditRenewalActivationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    public function test_http_activation_signed_flow_access_isolation_and_idempotency(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        [$company, $collector, $route, , $previous, $account] = $this->disbursed();
        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');
        $customer = $previous->customer;
        $url = route('cobros.collector.renewal.activate', [$customer, $renewal->id]);
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->get(route('cobros.collector.portfolio.show', $customer))->assertOk()->assertSee('Activar renovación');
        $renewal->update(['status' => 'approved']);
        $this->post($url, ['status' => 'consented'])->assertSessionHasErrors('renewal');
        $renewal->update(['status' => 'consented']);
        $path = $renewal->signature_path;
        $renewal->update(['signature_path' => 'missing.png']);
        $this->post($url)->assertSessionHasErrors('renewal');
        $renewal->update(['signature_path' => $path]);
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->actingAs($other)->post($url)->assertForbidden();
        $wrongCustomer = \App\Models\Customer::create(['company_id' => $company->id, 'name' => 'Ajeno', 'is_active' => true]);
        $this->actingAs($collector)->post(route('cobros.collector.renewal.activate', [$wrongCustomer, $renewal->id]))->assertNotFound();
        $before = CobrosCredit::count();
        $this->post($url)->assertRedirect(route('cobros.collector.portfolio.show', $customer))->assertSessionHas('renewal_status', 'activated');
        $this->assertSame($before + 1, CobrosCredit::count());
        $this->post($url)->assertRedirect();
        $this->assertSame($before + 1, CobrosCredit::count());
        $this->get(route('cobros.collector.portfolio.show', $customer))->assertOk()->assertSee('Calendario del nuevo crédito')->assertDontSee('Activar renovación');
    }

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '768M');
    }

    public function test_activation_consolidates_exact_amounts_and_closes_previous(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, $route, , $previous, $account] = $ctx;
        $this->assertSame('56000', $this->money(app(\App\Services\Cobros\CreditInstallmentService::class)->totalPending($previous)));

        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');

        // ₡56.000 anteriores + ₡50.000 nuevos + ₡10.000 interés nuevo = ₡116.000; desembolso ₡50.000.
        $this->assertSame('116000', $this->money($renewal->fresh()->consolidated_total));
        $this->assertSame('50000', $this->money($renewal->fresh()->disbursement));
        $this->assertSame('10000', $this->money($renewal->fresh()->new_interest));

        $creditsBefore = CobrosCredit::count();
        $accountsBefore = AccountReceivable::count();
        $receiptsBefore = CobrosReceipt::count();

        $credit = app(CreditRenewalActivationService::class)->activate($renewal, $this->owner);

        // Exactamente un crédito consolidado y una CxC nuevos.
        $this->assertSame($creditsBefore + 1, CobrosCredit::count());
        $this->assertSame($accountsBefore + 1, AccountReceivable::count());
        $this->assertSame('116000', $this->money($credit->total_due));
        $this->assertSame('106000', $this->money($credit->principal)); // 56.000 + 50.000
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $credit->status);

        // Calendario exacto del snapshot, sin duplicados.
        $rows = CobrosCreditInstallment::query()->where('credit_id', $credit->id)->orderBy('number')->get();
        $this->assertSame(4, $rows->count());
        $this->assertSame('116000', $this->money($rows->sum('contractual_amount')));

        // Crédito anterior cerrado por renovación; historial intacto.
        $this->assertSame(CobrosCredit::STATUS_CLOSED_RENEWAL, $previous->fresh()->status);
        $this->assertSame(4, $previous->installments()->count());

        // CxC anterior cancelada: no cobrable por el gate, sin pagos ficticios.
        $this->assertSame(AccountReceivable::STATUS_CANCELLED, $account->fresh()->status);
        $this->assertSame($receiptsBefore, CobrosReceipt::count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::count());

        // Solicitud activada con vínculo al crédito consolidado.
        $fresh = $renewal->fresh();
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_ACTIVATED, $fresh->status);
        $this->assertSame($credit->id, (int) $fresh->activated_credit_id);
    }

    public function test_activation_is_idempotent_and_blocks_double_disbursement(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, , , $previous, $account] = $ctx;
        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');

        $service = app(CreditRenewalActivationService::class);
        $first = $service->activate($renewal, $this->owner);
        $credits = CobrosCredit::count();
        $accounts = AccountReceivable::count();
        $installments = CobrosCreditInstallment::count();

        $second = $service->activate($renewal->fresh(), $this->owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($credits, CobrosCredit::count());
        $this->assertSame($accounts, AccountReceivable::count());
        $this->assertSame($installments, CobrosCreditInstallment::count());
    }

    public function test_rejects_unsigned_pending_and_tampered_terms(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, , , $previous, $account] = $ctx;
        $service = app(CreditRenewalActivationService::class);

        // Sin firma (solo pending): rechazada, sin crear crédito consolidado.
        $pending = $this->pendingRenewal($company, $collector, $previous, $account, '50000');
        try {
            $service->activate($pending, $this->owner);
            $this->fail('Debió rechazar renovación sin consentimiento firmado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('renewal', $e->errors());
        }
        $this->assertSame(0, CobrosCredit::where('terms_snapshot->source', 'renewal')->count());
    }

    public function test_rejects_tampered_terms_after_signature(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, , , $previous, $account] = $ctx;
        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');

        // Manipulación posterior a la firma: capital nuevo alterado en BD.
        CobrosCreditRenewalRequest::query()->whereKey($renewal->id)->update(['new_capital' => '99999']);

        try {
            app(CreditRenewalActivationService::class)->activate($renewal->fresh(), $this->owner);
            $this->fail('Debió rechazar términos alterados.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('terms_hash', $e->errors());
        }

        $this->assertSame(CobrosCreditRenewalRequest::STATUS_CONSENTED, $renewal->fresh()->status);
        $this->assertSame(0, CobrosCredit::where('terms_snapshot->source', 'renewal')->count());
    }

    public function test_rejects_when_pending_balance_changed_after_signature(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, $route, , $previous, $account] = $ctx;
        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');

        // Pago real posterior a la firma: el saldo pendiente cambia.
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '10000',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ])->assertRedirect();

        try {
            app(CreditRenewalActivationService::class)->activate($renewal->fresh(), $this->owner);
            $this->fail('Debió rechazar saldo modificado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('pending', $e->errors());
        }

        $this->assertSame(0, CobrosCredit::where('terms_snapshot->source', 'renewal')->count());
    }

    public function test_foreign_tenant_cannot_load_renewal_for_activation(): void
    {
        $ctx = $this->disbursed();
        [$company, $collector, , , $previous, $account] = $ctx;
        $renewal = $this->consentedRenewal($company, $collector, $previous, $account, '50000');

        $foreign = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($foreign, $admin, ['cobros']);

        // El aislamiento lo garantiza el ámbito del controlador (forCompany
        // implícito al cargar la renovación del cliente en su tenant): una
        // renovación de company_id ajeno no se resuelve bajo esta sesión.
        $this->assertNull(
            CobrosCreditRenewalRequest::query()
                ->withoutGlobalScopes()
                ->where('company_id', $foreign->id)
                ->whereKey($renewal->id)
                ->first(),
        );
        $this->assertSame(CobrosCreditRenewalRequest::STATUS_CONSENTED, $renewal->fresh()->status);
        $this->assertSame(0, CobrosCredit::where('terms_snapshot->source', 'renewal')->count());
    }

    // ------------------------------------------------------------------
    // Fixtures
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
        $company = Company::create(['trade_name' => 'RenovD2 '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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
            'notes' => 'Solicitud renovacion D2',
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

    /**
     * Crédito desembolsado con términos EXACTOS del caso REN-03D2B:
     * capital ₡46.000 + interés fijo ₡10.000 = total ₡56.000 pendiente.
     */
    private function disbursed(): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), [
            'principal' => '46000.00',
            'interest_type' => 'fixed',
            'interest_value' => '10000.00',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => '0',
        ])->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $credit = CobrosCredit::firstOrFail();

        return [$company, $collector, $route, $owner, $credit, $credit->account()->first()];
    }

    private function pendingRenewal(Company $company, User $collector, CobrosCredit $credit, $account, string $newCapital): CobrosCreditRenewalRequest
    {
        $quote = app(CreditRenewalQuoteService::class)->quote(
            $collector,
            $company,
            $account,
            $newCapital,
            4,
            (string) $credit->frequency,
            now()->addWeeks(2)->toDateString(),
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

    /** Renovación aprobada + firmada vía los servicios REN-03D1 (sin HTTP). */
    private function consentedRenewal(Company $company, User $collector, CobrosCredit $credit, $account, string $newCapital): CobrosCreditRenewalRequest
    {
        $renewal = $this->pendingRenewal($company, $collector, $credit, $account, $newCapital);
        $consent = app(CreditRenewalConsentService::class);
        $consent->approve($renewal, $this->owner);
        $consent->consent($renewal->fresh(), $collector, $this->signaturePng(), null);

        return $renewal->fresh();
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

    /** Normaliza montos decimales (BD devuelve sin ceros a la derecha). */
    private function money(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
