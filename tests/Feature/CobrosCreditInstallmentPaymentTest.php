<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\CobrosAssignment;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use App\Support\Cobros\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 9B.1 — cobro real aplicado al calendario de cuotas del préstamo.
 * Pago exacto/parcial, cuota más antigua, CxC consistente, bloqueo de
 * adelantos (9B.2), idempotencia y aislamiento. CxC normal sin cambios.
 */
class CobrosCreditInstallmentPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agenda_whatsapp_reminder_uses_due_pending_and_rejects_paid_future_invalid_or_foreign(): void
    {
        [$company, $collector, $route, $owner, $credit, $account] = $this->disbursed();
        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $today = today($company->timezone)->toDateString();
        $customer = $credit->customer;
        $first = $credit->installments()->orderBy('number')->firstOrFail();
        $first->update(['due_date' => $today, 'applied_amount' => '81.2500', 'status' => 'partial']);
        $visit = app(\App\Services\Cobros\VisitService::class)->schedule($collector, $company, $customer, ['scheduled_for' => $today, 'account_receivable_id' => $account->id]);
        $item = ['date' => $today, 'customer' => $customer, 'visit' => $visit];
        $service = app(\App\Services\Cobros\AgendaWhatsAppService::class);
        $url = $service->url($collector, $company, $item);
        $expected = '¡Buenos días, '.$customer->name."! ☀️\n\n"
            .'Le saludamos cordialmente de parte de '.$collector->name.".\n\n"
            .'Queremos recordarle que para hoy tenemos programada una visita para realizar el cobro de su cuota por ₡200.00.'."\n\n"
            .'Muchas gracias por su atención. ¡Que tenga un excelente día!';
        $this->assertSame('https://wa.me/50688888888?text='.rawurlencode($expected), $url);
        $this->get(route('cobros.visits.today'))->assertOk()->assertSee($url);
        $this->assertNull($service->url($owner, $company, $item));
        $foreign = Company::create(['trade_name' => 'Ajena', 'is_active' => true]);
        $this->assertNull($service->url($collector, $foreign, $item));
        $this->assertNull($service->url($collector, $company, array_replace($item, ['date' => today($company->timezone)->addDay()->toDateString()])));
        foreach (['', '123', 'abc88888888', '50688888888888888'] as $invalid) {
            $customer->phone = $invalid;
            $customer->mobile = null;
            $this->assertNull($service->url($collector, $company, $item));
        }
        $customer->phone = '88888888';
        $first->update(['due_date' => today($company->timezone)->subDay()->toDateString()]);
        $this->assertStringContainsString('cuota vencida por ₡200.00', rawurldecode($service->url($collector, $company, $item)));
        $first->update(['applied_amount' => $first->contractual_amount, 'status' => 'paid']);
        // The next installment is future: an advance already covered today's quota.
        $this->assertNull($service->url($collector, $company, $item));
        $credit->installments()->update(['applied_amount' => '281.2500', 'status' => 'paid']);
        $this->assertNull($service->url($collector, $company, $item));
    }

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '768M');
    }

    // ------------------------------------------------------------------
    // Helpers (mismo flujo real 9A/9C: solicitud → verificación →
    // aprobación → desembolso con calendario y CxC).
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

    /** @return array{0: Company, 1: User, 2: CobrosRoute, 3: User} */
    private function context(): array
    {
        $company = Company::create(['trade_name' => 'Cuotas '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        \App\Models\Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);
        app(PaymentMethodProvisioner::class)->provision($company);

        return [$company, $collector, $route, $owner];
    }

    /** @return array<string, mixed> */
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
            'notes' => 'Solicitud cuotas',
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
        $this->assertNotEmpty($m, 'token de revisión ausente');

        return $this->post(route('cobros.collector.credit-requests.store'), $payload + ['review_token' => $m[1]]);
    }

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector, array $payloadOverrides = []): CobrosCreditRequest
    {
        $this->sendAsCollector($this->payload($payloadOverrides), $route, $collector)->assertRedirect();
        $id = CobrosCreditRequest::forCompany($company->id)->orderByDesc('id')->firstOrFail()->id;

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.solicitudes.verify', $id), ['motivo' => 'Datos confirmados'])->assertRedirect();

        return CobrosCreditRequest::forCompany($company->id)->findOrFail($id);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'principal' => '1000.00',
            'interest_type' => 'percent',
            'interest_value' => '12.5',
            'installment_count' => 4,
            'frequency' => 'monthly',
            'first_due_date' => now()->addWeek()->toDateString(),
            'late_fee_type' => 'none',
            'late_fee_value' => '0',
            'late_fee_grace_days' => '0',
        ], $overrides);
    }

    /** Crédito aprobado y DESEMBOLSADO con calendario + CxC reales. */
    private function disbursed(array $overrides = [], ?string $customerName = null): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector, $customerName !== null ? ['name' => $customerName] : []);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms($overrides))->assertRedirect();
        $credit = CobrosCredit::firstOrFail();
        $this->post(route('cobros.owner.creditos.disburse', $credit->id), ['signature' => $this->signaturePng()])->assertRedirect();
        $credit = CobrosCredit::firstOrFail();

        // El flujo de solicitud ya asigna el cliente a la cartera del cobrador.

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

    /** Cobro confirmado del cobrador sobre la CxC del préstamo. */
    private function pay(array $ctx, string $amount, ?string $token = null, bool $confirm = true): TestResponse
    {
        [$company, $collector, $route, , , $account] = $ctx;

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);

        $payload = [
            'amount' => $amount,
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => $token ?? (string) Str::uuid(),
        ];
        if ($confirm) {
            $payload['confirm'] = '1';
        }

        return $this->post(route('cobros.collector.payments.store', $account), $payload);
    }

    private function installments(CobrosCredit $credit)
    {
        return CobrosCreditInstallment::query()->where('credit_id', $credit->id)->orderBy('number')->get();
    }

    // ------------------------------------------------------------------
    // 1) Pago exacto completa la cuota más antigua.
    // ------------------------------------------------------------------
    public function test_exact_payment_completes_oldest_installment_and_updates_cxc(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        // Total 1125.00 / 4 cuotas = 281.25 cada una.
        $this->pay($ctx, '281.25')->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame('281.2500', (string) $rows[0]->applied_amount);
        $this->assertNotNull($rows[0]->paid_at);
        $this->assertSame(CobrosCreditInstallment::STATUS_PENDING, $rows[1]->status);
        $this->assertSame('0.0000', (string) $rows[1]->applied_amount);

        $this->assertSame('843.7500', (string) $account->fresh()->balance_due);
        $this->assertSame('281.2500', (string) CobrosCreditInstallmentApplication::sole()->amount);
    }

    // ------------------------------------------------------------------
    // 2) Pago parcial deja saldo correcto en la cuota.
    // ------------------------------------------------------------------
    public function test_partial_payment_leaves_exact_pending_on_same_installment(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        // 150.3750 de 281.25 → pendiente 130.8750 (decimales no divisibles).
        $this->pay($ctx, '150.3750')->assertRedirect();

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $rows[0]->status);
        $this->assertSame('150.3750', (string) $rows[0]->applied_amount);
        $this->assertSame('130.8750', bcsub((string) $rows[0]->contractual_amount, (string) $rows[0]->applied_amount, 4));
        $this->assertNull($rows[0]->paid_at);
        $this->assertSame('0.0000', (string) $rows[1]->applied_amount);

        // Baja exactamente 10.0000... del saldo global: 1125 - 150.375.
        $this->assertSame('974.6250', (string) $account->fresh()->balance_due);
    }

    // ------------------------------------------------------------------
    // 3) Segundo parcial completa ESA MISMA cuota (no salta).
    // 4) Nunca salta una cuota pendiente.
    // ------------------------------------------------------------------
    public function test_second_partial_completes_same_installment_and_never_skips(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '150.3750')->assertRedirect();
        $this->pay($ctx, '130.8750')->assertRedirect(); // completa la cuota 1

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame('281.2500', (string) $rows[0]->applied_amount);
        $this->assertSame('0.0000', (string) $rows[1]->applied_amount); // la cuota 2 no fue tocada
        $this->assertSame('843.7500', (string) $account->fresh()->balance_due);

        // Siguiente pago: comienza la cuota 2 (la más antigua abierta).
        $this->pay($ctx, '60.1250')->assertRedirect();
        $rows = $this->installments($credit)->values();
        $this->assertSame('60.1250', (string) $rows[1]->applied_amount);
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $rows[1]->status);
        $this->assertSame('281.2500', (string) $rows[0]->applied_amount);
    }

    // ------------------------------------------------------------------
    // 5) Consistencia matemática: aplicaciones = pagos; CxC = total - aplicado.
    // ------------------------------------------------------------------
    public function test_payments_installments_and_cxc_stay_mathematically_consistent(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '150.3750')->assertRedirect();
        $this->pay($ctx, '130.8750')->assertRedirect();
        $this->pay($ctx, '100.0000')->assertRedirect();

        $applied = CobrosCreditInstallmentApplication::query()->sum('amount');
        $paid = CobrosReceipt::query()->sum('amount');
        $installmentSum = CobrosCreditInstallment::query()->where('credit_id', $credit->id)->sum('applied_amount');
        $creditRow = $credit->fresh();

        $this->assertSame(0, bccomp('381.2500', (string) $applied, 4), 'aplicaciones = 381.25');
        $this->assertSame(0, bccomp('381.2500', (string) $paid, 4), 'pagos = 381.25');
        $this->assertSame(0, bccomp('381.2500', (string) $installmentSum, 4), 'cuotas aplicado = 381.25');
        $this->assertSame(0, bccomp((string) $applied, (string) $paid, 4), 'suma de aplicaciones = pagos');
        $this->assertSame('743.7500', (string) $account->fresh()->balance_due);
        $this->assertSame(
            0,
            bccomp(bcsub((string) $creditRow->total_due, (string) $installmentSum, 4), (string) $account->fresh()->balance_due, 4),
            'saldo CxC = total_due - aplicado'
        );
        $this->assertSame('1', (string) bccomp((string) $account->fresh()->balance_due, '0', 4));
    }

    // ------------------------------------------------------------------
    // 6) Monto mayor a la deuda total se BLOQUEA (9B.2A), sin persistencia.
    // ------------------------------------------------------------------
    public function test_amount_above_total_debt_is_blocked_without_persistence(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        // Mayor a la deuda total (1125): confirm y vista previa se rechazan.
        $this->pay($ctx, '1125.0100')->assertSessionHasErrors('amount');
        $this->pay($ctx, '1125.0200', null, confirm: false)->assertSessionHasErrors('amount');

        $this->assertSame(0, CobrosReceipt::query()->count(), 'sin recibo');
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count(), 'sin aplicación');
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due);
        $this->assertSame('0.0000', (string) $this->installments($credit)[0]->applied_amount);

        $message = session('errors')->first('amount');
        $this->assertStringContainsString('supera la deuda total', $message);
    }

    // ------------------------------------------------------------------
    // 7) Idempotencia: repetir el mismo token no cobra dos veces.
    // ------------------------------------------------------------------
    public function test_replaying_same_token_never_applies_twice(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;
        $token = (string) Str::uuid();

        $this->pay($ctx, '100.0000', $token)->assertRedirect();
        $this->pay($ctx, '100.0000', $token)->assertRedirect();

        $this->assertSame(1, CobrosReceipt::query()->count());
        $this->assertSame(1, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('100.0000', (string) $this->installments($credit)[0]->applied_amount);
        $this->assertSame('1025.0000', (string) $account->fresh()->balance_due);
    }

    // ------------------------------------------------------------------
    // 8) Otro collector sin cartera: prohibido.
    // ------------------------------------------------------------------
    public function test_collector_without_assignment_cannot_pay_loan(): void
    {
        $ctx = $this->disbursed();
        [$company, , , , , $account] = $ctx;
        $intruder = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($intruder)->withSession(['active_company_id' => $company->id]);
        $response = $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100.0000',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ]);

        $this->assertContains($response->getStatusCode(), [403, 404], 'Gate bloquea cartera ajena');
        $this->assertSame(0, CobrosReceipt::query()->count());
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due);
    }

    // ------------------------------------------------------------------
    // 9) Otro tenant: aislado, sin escrituras.
    // ------------------------------------------------------------------
    public function test_foreign_tenant_cannot_pay_loan_and_stays_isolated(): void
    {
        $ctx = $this->disbursed();
        [, , , , , $account] = $ctx;

        // Empresa ajena con licencia y permiso, pero sin acceso a esta CxC.
        $foreign = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($foreign, $admin, ['cobros']);
        $stranger = $this->member($foreign, ['cobros.acceder', 'cobros.cobrar']);
        app(PaymentMethodProvisioner::class)->provision($foreign);

        $this->actingAs($stranger)->withSession(['active_company_id' => $foreign->id]);
        $response = $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '100.0000',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $foreign->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ]);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, CobrosReceipt::query()->count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due);
    }

    // ------------------------------------------------------------------
    // 10) CxC normal de Commerce: flujo intacto, cero lógica de cuotas.
    // ------------------------------------------------------------------
    public function test_normal_commerce_cxc_payment_still_works_without_installments(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $branch = \App\Models\Branch::query()->where('company_id', $company->id)->firstOrFail();

        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Normal', 'is_active' => true]);
        CobrosAssignment::create([
            'company_id' => $company->id, 'cobros_route_id' => $route->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'assigned_by' => $owner->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id,
            'issued_at' => today()->subMonth(), 'due_date' => today($company->timezone)->addDays(7),
            'original_amount' => '5000.0000', 'balance_due' => '5000.0000', 'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '1250.7500',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ])->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $this->assertSame('3749.2500', (string) $account->fresh()->balance_due);
        $this->assertSame(1, CobrosReceipt::query()->count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count(), 'CxC normal: sin aplicación de cuotas');
        $this->assertSame(0, CobrosCreditInstallment::query()->count());
    }

    // ------------------------------------------------------------------
    // Escapado visual del recibo: texto plano, sin entidades HTML literales.
    // ------------------------------------------------------------------
    public function test_receipt_shows_plain_text_without_html_entities(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;
        $this->pay($ctx, '100.0000')->assertRedirect();

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();

        $this->assertStringContainsString('Cuota 1:', $html);
        $this->assertStringContainsString('· pendiente', $html);
        $this->assertStringContainsString('Saldo del préstamo', $html);
        $this->assertStringContainsString('Monto pendiente', $html);
        // Separación visible: "· pendiente" + espacio + monto pendiente de la cuota.
        $this->assertStringContainsString('pendiente ₡'.Money::format('181.2500'), strip_tags($html), 'falta espacio entre "pendiente" y el monto');
        // Ninguna entidad HTML literal visible en el cuerpo del recibo.
        $this->assertStringNotContainsString('&#x', $html, 'entidad hex literal visible');
        $this->assertStringNotContainsString('&amp;#', $html, 'entidad escapada doble visible');
        // Referencia pública visible; nunca el id interno.
        $this->assertStringContainsString($credit->publicReference(), $html);
        $this->assertStringNotContainsString('Préstamo #'.$credit->id, $html);
        $this->assertStringNotContainsString('Crédito #'.$credit->id, $html);
    }

    // ------------------------------------------------------------------
    // WhatsApp: recibo de préstamo identifica CR-XXXXXX; CxC normal no.
    // ------------------------------------------------------------------
    public function test_whatsapp_receipt_includes_public_loan_reference(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;
        $this->pay($ctx, '100.0000')->assertRedirect();

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/wa\.me\/[^"]+\?text=([^"]+)"/', $html);
        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $message = urldecode($m[1]);

        $this->assertStringContainsString('Recibo de cobro RCB-00000001', $message);
        $this->assertStringContainsString('Préstamo: '.$credit->publicReference(), $message);
        $this->assertMatchesRegularExpression('/Préstamo: CR-[A-Z0-9]{6}/', $message);
        // Nunca el id interno ni referencias viejas.
        $this->assertStringNotContainsString('Crédito #'.$credit->id, $message);
        $this->assertStringNotContainsString('#'.$credit->id.' ', $message);
    }

    public function test_whatsapp_receipt_for_normal_cxc_has_no_loan_line(): void
    {
        [$company, $collector, $route, $owner] = $this->context();
        $branch = \App\Models\Branch::query()->where('company_id', $company->id)->firstOrFail();

        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Normal', 'is_active' => true, 'phone' => '88888888']);
        CobrosAssignment::create([
            'company_id' => $company->id, 'cobros_route_id' => $route->id, 'customer_id' => $customer->id,
            'collector_user_id' => $collector->id, 'assigned_by' => $owner->id, 'assigned_at' => now(),
        ]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'sale_number' => 'CXC-'.strtoupper(substr(uniqid(), -8)), 'status' => 'completed', 'completed_at' => now()]);
        $account = AccountReceivable::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'sale_id' => $sale->id,
            'issued_at' => today()->subMonth(), 'due_date' => today($company->timezone)->addDays(7),
            'original_amount' => '5000.0000', 'balance_due' => '5000.0000', 'status' => 'pending', 'currency_code' => 'CRC',
        ]);

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);
        $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '1250.7500',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ])->assertRedirect(route('cobros.collector.payments.receipt', 'RCB-00000001'));

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Pago registrado ✓ · Préstamo', $html);
        $this->assertStringNotContainsString('Saldo del préstamo', $html);

        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'enlace wa.me ausente en CxC normal');
        $message = urldecode($m[1]);
        $this->assertStringContainsString('Recibo de cobro RCB-00000001', $message);
        $this->assertStringNotContainsString('Préstamo:', $message, 'CxC normal sin línea de préstamo');
        $this->assertStringNotContainsString('Próximo pago:', $message, 'CxC normal sin compromiso de préstamo');
        $this->assertStringNotContainsString('Préstamo cancelado', $message);
        $this->assertStringContainsString('₡', $message, 'CxC normal conserva símbolo ₡');
    }

    /** UTF-8/encoding: ₡ real tras decode, nombre con ñ/tildes, CR-, próximo pago. */
    public function test_whatsapp_receipt_utf8_currency_symbol_and_localized_name(): void
    {
        $ctx = $this->disbursed([], 'José Antonio Muñoz Fernández');
        [, , , , $credit] = $ctx;
        $due = $this->installments($credit)[0]->due_date->format('d/m/Y');
        $this->pay($ctx, '150.3750')->assertRedirect();

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/wa\.me\/[^"]+\?text=([^"]+)"/', $html);
        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $raw = $m[1];
        $message = urldecode($raw);

        $this->assertStringNotContainsString('%3F', $raw, 'sin signo de interrogación urlencoded');
        $this->assertStringContainsString('₡', $message, 'símbolo ₡ correcto tras decode');
        $this->assertStringContainsString('José Antonio Muñoz Fernández', $message, 'ñ/tildes intactas');
        $this->assertStringContainsString('Préstamo: '.$credit->publicReference(), $message);
        $this->assertStringContainsString('Próximo pago: '.$due, $message);
        $this->assertStringContainsString(Money::format('130.8750'), $message);
        $this->assertStringContainsString('₡', $html, 'pantalla con ₡');
    }

    /** A) Parcial: misma fecha de la cuota + solo su saldo pendiente. */
    public function test_partial_receipt_and_whatsapp_show_same_due_date_and_pending(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;
        $due = $this->installments($credit)[0]->due_date->format('d/m/Y');
        $this->pay($ctx, '150.3750')->assertRedirect(); // parcial: 281.25 - 150.375 = 130.875

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertStringContainsString('Próximo pago', $html);
        $this->assertStringContainsString($due, $html, 'misma fecha contractual de la cuota parcial');
        $this->assertStringContainsString(Money::format('130.8750'), $html, 'saldo pendiente de la cuota');
        $this->assertStringNotContainsString('Préstamo cancelado', $html);

        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $message = urldecode($m[1]);
        $this->assertStringContainsString('Próximo pago: '.$due, $message);
        $this->assertStringContainsString(Money::format('130.8750'), $message);
        $this->assertStringNotContainsString('#'.$credit->id, $message, 'nunca credit.id');
        $this->assertStringNotContainsString('Préstamo #'.$credit->id, $html, 'nunca credit.id en pantalla');
    }

    /** B) Cuota pagada: siguiente cuota del calendario (fecha + monto). */
    public function test_paid_installment_receipt_shows_next_installment_commitment(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;
        $rows = $this->installments($credit);
        $this->pay($ctx, '281.25')->assertRedirect(); // completa cuota 1

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertStringContainsString('Próximo pago', $html);
        $this->assertStringContainsString($rows[1]->due_date->format('d/m/Y'), $html, 'fecha de la cuota 2');
        $this->assertStringContainsString(Money::format('281.2500'), $html, 'monto de la cuota 2');
        $this->assertStringNotContainsString('Préstamo cancelado', $html);

        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $message = urldecode($m[1]);
        $this->assertStringContainsString('Próximo pago: '.$rows[1]->due_date->format('d/m/Y'), $message);
        $this->assertStringContainsString(Money::format('281.2500'), $message);
        $this->assertStringNotContainsString('#'.$credit->id, $message);
    }

    /** C) Última cuota pagada: "Préstamo cancelado ✓", sin fecha ni monto. */
    public function test_fully_paid_loan_shows_cancelado_without_next_commitment(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        foreach (['281.25', '281.25', '281.25', '281.25'] as $amount) {
            $this->pay($ctx, $amount)->assertRedirect();
        }
        $this->assertSame('0.0000', (string) $account->fresh()->balance_due);

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000004'))->assertOk()->getContent();
        $this->assertStringContainsString('Préstamo cancelado ✓', $html);
        $this->assertStringNotContainsString('Próximo pago', $html);

        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $message = urldecode($m[1]);
        $this->assertStringContainsString('Préstamo: '.$credit->publicReference(), $message);
        $this->assertStringContainsString('Préstamo cancelado ✓', $message);
        $this->assertStringNotContainsString('Próximo pago:', $message);
        $this->assertStringNotContainsString('#'.$credit->id, 'nunca credit.id');
    }

    // ------------------------------------------------------------------
    // 9B.2A — distribución del pago entre varias cuotas en orden.
    // ------------------------------------------------------------------

    /** 1) Un pago cubre exactamente 2 cuotas completas. */
    public function test_multi_payment_covers_two_exact_installments(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '562.5000')->assertRedirect();

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[1]->status);
        $this->assertSame('281.2500', (string) $rows[1]->applied_amount);
        $this->assertSame('0.0000', (string) $rows[2]->applied_amount, 'cuota 3 intacta');
        $this->assertNotNull($rows[0]->paid_at);
        $this->assertNotNull($rows[1]->paid_at);
        $this->assertSame(2, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('562.5000', (string) CobrosReceipt::sole()->amount);
        $this->assertSame('562.5000', (string) $account->fresh()->balance_due);
    }

    /** 2) 2 completas + parcial de tercera; decimal no divisible; suma apps = recibo. */
    public function test_multi_payment_covers_two_plus_partial_third_with_indivisible_decimal(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        // 562.5001 no es múltiplo de 281.25: sobra 0.0001 para la cuota 3.
        $this->pay($ctx, '562.5001')->assertRedirect();

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[1]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $rows[2]->status);
        $this->assertSame('0.0001', (string) $rows[2]->applied_amount);
        $this->assertSame('281.2499', bcsub((string) $rows[2]->contractual_amount, (string) $rows[2]->applied_amount, 4));
        $this->assertSame('0.0000', (string) $rows[3]->applied_amount, 'cuota 4 intacta');

        $sum = (string) CobrosCreditInstallmentApplication::query()->sum('amount');
        $this->assertSame(0, bccomp('562.5001', $sum, 4), 'suma de aplicaciones = monto recibo');
        $this->assertSame(0, bccomp($sum, (string) CobrosReceipt::sole()->amount, 4), 'apps = recibo');
        $this->assertSame('562.4999', (string) $account->fresh()->balance_due, 'saldo baja exactamente el pago');
    }

    /** 3) Primera cuota ya parcial → se completa y CONTINÚA a la siguiente. */
    public function test_partial_installment_then_larger_payment_completes_and_continues(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '100.0000')->assertRedirect(); // cuota 1 parcial
        $this->pay($ctx, '500.0000')->assertRedirect(); // 181.25 + 281.25 + 37.50

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame('281.2500', (string) $rows[0]->applied_amount);
        $this->assertNotNull($rows[0]->paid_at);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[1]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $rows[2]->status);
        $this->assertSame('37.5000', (string) $rows[2]->applied_amount);
        $this->assertSame('525.0000', (string) $account->fresh()->balance_due, '1125 - 600 pagados');
    }

    /** 4) Pago exacto de la deuda total → préstamo cancelado en un solo recibo. */
    public function test_full_debt_payment_cancels_loan_in_single_receipt(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '1125.0000')->assertRedirect();

        foreach ($this->installments($credit) as $row) {
            $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $row->status);
            $this->assertNotNull($row->paid_at);
        }
        $this->assertSame(4, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('0.0000', (string) $account->fresh()->balance_due);

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertStringContainsString('Préstamo cancelado ✓', $html);
        $this->assertStringNotContainsString('Próximo pago', $html);
    }

    /** 7) Orden cronológico obligatorio: siempre desde la cuota más antigua. */
    public function test_distribution_is_always_chronological_from_oldest_open(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;

        $this->pay($ctx, '100.0000')->assertRedirect(); // cuota 1 parcial
        $this->pay($ctx, '462.6000')->assertRedirect(); // 181.25 + 281.25 + 0.10

        $numbers = CobrosCreditInstallmentApplication::query()
            ->orderBy('id')->with('installment')->get()
            ->map(fn ($a) => (int) $a->installment->number)->all();
        $this->assertSame([1, 1, 2, 3], $numbers, 'nunca salta una cuota pendiente');

        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame('0.1000', (string) $rows[2]->applied_amount);
        $this->assertSame('0.0000', (string) $rows[3]->applied_amount, 'cuota 4 jamás tocada');
    }

    /** 8) Fechas contractuales intactas tras pago adelantado. */
    public function test_contractual_due_dates_never_change_after_advance_payments(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit] = $ctx;
        $before = $this->installments($credit)->map(fn ($i) => $i->due_date->format('Y-m-d'))->all();

        $this->pay($ctx, '700.0000')->assertRedirect(); // cuotas 1-2 completas + parcial 3

        $after = $this->installments($credit)->map(fn ($i) => $i->due_date->format('Y-m-d'))->all();
        $this->assertSame($before, $after, 'las fechas contractuales no cambian');
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $this->installments($credit)[0]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $this->installments($credit)[2]->status);
    }

    /** 9) Replay de un pago multi-aplicación: mismo token → cero duplicado. */
    public function test_replay_of_multi_installment_payment_never_duplicates(): void
    {
        $ctx = $this->disbursed();
        [, , , , $credit, $account] = $ctx;
        $token = (string) Str::uuid();

        $this->pay($ctx, '562.5001', $token)->assertRedirect();
        $this->pay($ctx, '562.5001', $token)->assertRedirect();

        $this->assertSame(1, CobrosReceipt::query()->count(), 'un solo recibo');
        $this->assertSame(3, CobrosCreditInstallmentApplication::query()->count(), 'sin apps duplicadas (las 3 del primer envío)');
        $this->assertSame('562.4999', (string) $account->fresh()->balance_due, 'saldo reducido una sola vez');
        $rows = $this->installments($credit);
        $this->assertSame('0.0001', (string) $rows[2]->applied_amount, 'cuota 3 aplicada una sola vez');
    }

    // ------------------------------------------------------------------
    // 9B.3 — LECTURA de mora en la pantalla de cobrar (solo informativa).
    // first_due es after_or_equal:today en aprobación: la cuota se vuelve
    // vencida moviendo SOLO el due_date en el setup del test.
    // ------------------------------------------------------------------
    private function cobrar(array $ctx): TestResponse
    {
        [, $collector, $route, , , $account] = $ctx;
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);

        return $this->get(route('cobros.collector.payments.create', $account->id));
    }

    private function moraTerms(int $daysLate, string $grace = '3'): array
    {
        return [
            'late_fee_type' => 'fixed',
            'late_fee_value' => '5000',
            'late_fee_grace_days' => $grace,
        ];
    }

    private function vencida(array $ctx, int $daysAgo): array
    {
        [, , , , $credit] = $ctx;
        $this->installments($credit)[0]->update(['due_date' => now()->subDays($daysAgo)->toDateString()]);

        return $ctx;
    }

    public function test_overdue_installment_shows_correct_late_fee_on_payment_screen(): void
    {
        $ctx = $this->vencida($this->disbursed($this->moraTerms(10)), 10);

        $html = $this->cobrar($ctx)->assertOk()->getContent();

        $this->assertStringContainsString('Mora pendiente', $html);
        $this->assertStringContainsString('Total pendiente hoy', $html);
        $this->assertStringContainsString(Money::format('5000.0000'), $html, 'mora fija visible');
        $this->assertStringContainsString(Money::format('5281.2500'), $html, 'total hoy = cuota 281.25 + mora 5000');
        $this->assertStringContainsString('de mora por atraso', strip_tags($html), 'frase humana');
        $this->assertStringNotContainsString('late_fee_due', $html, 'sin términos técnicos internos');
    }

    public function test_installment_within_grace_does_not_show_late_fee_as_debt(): void
    {
        $ctx = $this->vencida($this->disbursed($this->moraTerms(2)), 2);

        $html = $this->cobrar($ctx)->assertOk()->getContent();

        $this->assertStringContainsString('Todavía está dentro de los días de gracia', strip_tags($html));
        $this->assertStringNotContainsString('Mora pendiente', $html, 'gracia: sin mora como deuda');
        $this->assertStringNotContainsString('Total pendiente hoy', $html, 'gracia: sin total con mora');
    }

    public function test_without_late_fee_configured_no_late_fee_block_is_shown(): void
    {
        $ctx = $this->vencida($this->disbursed(), 10);

        $html = $this->cobrar($ctx)->assertOk()->getContent();

        $this->assertStringNotContainsString('Mora pendiente', $html);
        $this->assertStringNotContainsString('Total pendiente hoy', $html);
        $this->assertStringNotContainsString('días de gracia', strip_tags($html));
    }

    public function test_advance_payment_does_not_show_late_fee(): void
    {
        $ctx = $this->disbursed($this->moraTerms(0));

        $this->pay($ctx, '281.2500')->assertRedirect(); // cuota 1 pagada antes del vencimiento

        $html = $this->cobrar($ctx)->assertOk()->getContent();

        $this->assertStringNotContainsString('Mora pendiente', $html, 'pago adelantado: sin mora');
        $this->assertStringNotContainsString('Total pendiente hoy', $html);
        $this->assertStringNotContainsString('días de gracia', strip_tags($html));
    }

    /** 9B.3: la mora se cobra primero; la CxC solo baja por la porción de cuota. */
    public function test_preview_charges_late_fee_first_without_sums_into_cxc(): void
    {
        $ctx = $this->vencida($this->disbursed($this->moraTerms(10)), 10);

        $html = $this->pay($ctx, '5281.2500', confirm: false)->assertOk()->getContent();

        $this->assertStringContainsString('Este pago se aplicará así', $html);
        $this->assertStringContainsString('Mora por atraso', $html, 'fila de mora primero');
        $this->assertStringContainsString(Money::format('5000.0000'), $html, 'mora exigible en el preview');
        $this->assertStringContainsString('Cuota 1', $html, 'fila de cuota después de la mora');
        $this->assertStringContainsString(Money::format('281.2500'), $html, 'porción de cuota');
        $this->assertStringContainsString('Saldo después del pago', $html);
        $this->assertStringContainsString(Money::format('843.7500'), $html, 'saldo 1125 - 281.25 sin descontar mora');
        $this->assertStringNotContainsString('la mora no se cobra en este pago', strip_tags($html), 'aviso obsoleto retirado');
    }

    public function test_viewing_late_fee_does_not_change_cxc_installments_or_credit(): void
    {
        $ctx = $this->vencida($this->disbursed($this->moraTerms(10)), 10);
        [, , , , $credit, $account] = $ctx;
        $balanceBefore = (string) $account->fresh()->balance_due;
        $rowsBefore = $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all();
        $termsBefore = $credit->terms_snapshot;

        $this->cobrar($ctx)->assertOk();

        $this->assertSame($balanceBefore, (string) $account->fresh()->balance_due, 'CxC intacta');
        $this->assertSame($rowsBefore, $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all(), 'cuotas intactas');
        $this->assertSame($termsBefore, $credit->terms_snapshot, 'terms_snapshot intacto');
    }

    public function test_late_fee_block_uses_normal_escaping_without_entities_or_script(): void
    {
        $ctx = $this->vencida($this->disbursed($this->moraTerms(10)), 10);

        $html = $this->cobrar($ctx)->assertOk()->getContent();

        $this->assertStringNotContainsString('&#x', $html, 'sin entidades hex literales');
        $this->assertStringNotContainsString('&amp;#', $html, 'sin doble escaping');
        $this->assertStringNotContainsString('late_fee_due', $html, 'sin términos técnicos crudos');
        // El bloque de mora sale solo de Money::format (dígitos) y literales escapados con {{ }}.
        $this->assertMatchesRegularExpression('/Mora pendiente<\/dt>\s*<dd[^>]*>\s*<span class="cobros-money">[^<]*<\/span>/', $html, 'mora renderizada como texto simple, sin markup inyectado');
    }
}
