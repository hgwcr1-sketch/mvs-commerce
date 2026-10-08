<?php

namespace Tests\Feature;

use App\Models\AccountReceivablePayment;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosLateFeeApplication;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
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
 * 9B.3 — cobro REAL de mora vencida:
 * - la mora exigible se cobra PRIMERO, sobre la cuota abierta más antigua;
 * - el resto recorre las cuotas (9B.2A) y SOLO eso reduce la CxC;
 * - snapshot inmutable por aplicación (monto, % efectivo, reparto);
 * - tope = capital + mora exigible; transaccional e idempotente;
 * - sin mora configurada: comportamiento 9B.2A exacto e intacto.
 */
class CobrosLateFeePaymentTest extends TestCase
{
    use RefreshDatabase;

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
        $company = Company::create(['trade_name' => 'Mora '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
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
            'notes' => 'Solicitud mora',
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

    private function verifiedRequest(Company $company, User $owner, CobrosRoute $route, User $collector): CobrosCreditRequest
    {
        $this->sendAsCollector($this->payload(), $route, $collector)->assertRedirect();
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
    private function disbursed(array $overrides = []): array
    {
        [$company, $collector, $route, $owner] = $this->context();
        $request = $this->verifiedRequest($company, $owner, $route, $collector);

        $this->actingAs($owner)->withSession(['active_company_id' => $company->id]);
        $this->post(route('cobros.owner.creditos.approve', $request->id), $this->terms($overrides))->assertRedirect();
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

    private function cobrar(array $ctx): TestResponse
    {
        [, $collector, $route, , , $account] = $ctx;
        $this->actingAs($collector)->withSession(['active_company_id' => $route->company_id, 'active_cobros_route_id' => $route->id]);

        return $this->get(route('cobros.collector.payments.create', $account->id));
    }

    private function installments(CobrosCredit $credit)
    {
        return CobrosCreditInstallment::query()->where('credit_id', $credit->id)->orderBy('number')->get();
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

    /** Pago de mora exigible fija (₡5.000) + cuota 1 (₡281,25) sobre 1125. */
    private function moraCtx(): array
    {
        return $this->vencida($this->disbursed($this->moraTerms(10)), 10);
    }

    // ------------------------------------------------------------------
    // 1-5) Orden de aplicación: mora exigible primero, luego cuotas.
    // ------------------------------------------------------------------

    /** 1) Pago exacto mora + cuota: la CxC baja SOLO la porción de cuota. */
    public function test_exact_payment_charges_late_fee_first_then_installment(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '5281.2500')->assertRedirect();

        $mora = CobrosLateFeeApplication::sole();
        $this->assertSame('5000.0000', (string) $mora->late_fee_charged, 'mora exigible cobrada primero');
        $this->assertSame('50.00', (string) $mora->effective_pct, '% efectivo vigente en el cobro');

        $this->assertSame(1, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('281.2500', (string) CobrosCreditInstallmentApplication::sole()->amount, 'solo la porción de cuota');
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $this->installments($credit)[0]->status);
        $this->assertSame('843.7500', (string) $account->fresh()->balance_due, 'CxC baja SOLO 1125 - 281.25');

        $this->assertSame('5281.2500', (string) CobrosReceipt::sole()->amount, 'recibo = dinero total');
        $this->assertSame('843.7500', (string) CobrosReceipt::sole()->balance_after, 'saldo del préstamo en el recibo');
        $this->assertSame('281.2500', (string) AccountReceivablePayment::sole()->amount, 'pago CxC = capital aplicado');
    }

    /** 2) Mora completa + parcial de la cuota en un solo cobro. */
    public function test_payment_covers_late_fee_and_partial_installment(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '5200.0000')->assertRedirect();

        $this->assertSame('5000.0000', (string) CobrosLateFeeApplication::sole()->late_fee_charged);
        $rows = $this->installments($credit);
        $this->assertSame('200.0000', (string) $rows[0]->applied_amount, 'resto a la cuota 1');
        $this->assertSame(CobrosCreditInstallment::STATUS_PARTIAL, $rows[0]->status);
        $this->assertSame('81.2500', (string) bcsub((string) $rows[0]->contractual_amount, (string) $rows[0]->applied_amount, 4));
        $this->assertSame('925.0000', (string) $account->fresh()->balance_due, 'CxC baja SOLO 200');
    }

    /** 3) Pago menor a la mora: mora parcial, cuota intacta, mora pendiente restante. */
    public function test_partial_late_fee_payment_leaves_installment_untouched(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '3000.0000')->assertRedirect();

        $this->assertSame('3000.0000', (string) CobrosLateFeeApplication::sole()->late_fee_charged);
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count(), 'cuota sin aplicación');
        $this->assertSame('0.0000', (string) $this->installments($credit)[0]->applied_amount, 'cuota intacta');
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due, 'CxC intacta');
        $this->assertSame('0.0000', (string) AccountReceivablePayment::sole()->amount, 'capital aplicado = 0');
        $this->assertSame('1125.0000', (string) CobrosReceipt::sole()->balance_after, 'saldo del recibo no baja por mora');
    }

    /** 4) Segundo pago completa la mora pendiente sin duplicarla. */
    public function test_second_payment_completes_pending_late_fee_without_duplicating(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '3000.0000')->assertRedirect();
        $this->pay($ctx, '2000.0000')->assertRedirect();

        $rows = CobrosLateFeeApplication::query()->orderBy('id')->get();
        $this->assertSame(2, $rows->count(), 'pagos parciales = filas separadas');
        $this->assertSame('3000.0000', (string) $rows[0]->late_fee_charged);
        $this->assertSame('2000.0000', (string) $rows[1]->late_fee_charged);
        $this->assertSame('5000.0000', bcadd((string) $rows->sum('late_fee_charged'), '0', 4), 'suma = exigible, sin duplicado');
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count(), 'mora no toca cuotas');
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due, 'CxC intacta');
        $this->assertSame(2, CobrosReceipt::query()->count());
        $this->assertSame('1125.0000', (string) CobrosReceipt::orderBy('id')->get()[1]->balance_after, 'recibo 2 sin reducir capital');
    }

    /** 5) Tras completar la mora, el pago continúa con la cuota. */
    public function test_after_late_fee_completed_payment_continues_with_installment(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '3000.0000')->assertRedirect();
        $this->pay($ctx, '2000.0000')->assertRedirect();
        $this->pay($ctx, '281.2500')->assertRedirect();

        $this->assertSame(2, CobrosLateFeeApplication::query()->count(), 'sin fila nueva de mora');
        $this->assertSame(1, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $this->installments($credit)[0]->status);
        $this->assertSame('843.7500', (string) $account->fresh()->balance_due);
        $this->assertSame('281.2500', (string) CobrosReceipt::orderBy('id')->get()[2]->amount);
    }

    /** 6) Mora ya pagada: nunca se vuelve a exigir ni a re-cobrar. */
    public function test_fully_paid_late_fee_is_never_charged_again(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;

        $this->pay($ctx, '5000.0000')->assertRedirect(); // mora completa
        $this->pay($ctx, '562.5000')->assertRedirect(); // sigue vencida: 2 cuotas

        $this->assertSame(1, CobrosLateFeeApplication::query()->count(), 'sin segunda mora');
        $this->assertSame(2, CobrosCreditInstallmentApplication::query()->count());
        $rows = $this->installments($credit);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[0]->status);
        $this->assertSame(CobrosCreditInstallment::STATUS_PAID, $rows[1]->status);
        $this->assertSame('562.5000', (string) AccountReceivablePayment::orderBy('id')->get()[1]->amount, 'segundo pago = 100% capital');
        $this->assertSame('562.5000', (string) CobrosReceipt::orderBy('id')->get()[1]->amount);
        $this->assertSame('562.5000', (string) $account->fresh()->balance_due, '1125 - 562.50');
    }

    // ------------------------------------------------------------------
    // 7-8) Recibo y WhatsApp: montos separados, sin reparto interno.
    // ------------------------------------------------------------------

    /** 7) Recibo: Monto cobrado / Aplicado al préstamo / Mora pagada / Saldo / Próximo. */
    public function test_receipt_separates_charged_loan_applied_and_late_fee_paid(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit] = $ctx;
        $nextDue = $this->installments($credit)[1]->due_date->format('d/m/Y');

        $this->pay($ctx, '5281.2500')->assertRedirect();

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        $this->assertStringContainsString('Monto cobrado', $html);
        $this->assertStringContainsString(Money::format('5281.2500'), $html, 'monto total cobrado');
        $this->assertStringContainsString('Aplicado al préstamo', $html);
        $this->assertStringContainsString(Money::format('281.2500'), $html, 'capital aplicado');
        $this->assertStringContainsString('Mora pagada', $html);
        $this->assertStringContainsString(Money::format('5000.0000'), $html, 'mora cobrada');
        $this->assertStringContainsString('Saldo del préstamo', $html);
        $this->assertStringContainsString(Money::format('843.7500'), $html, 'saldo real del préstamo');
        $this->assertStringContainsString('Próximo pago', $html);
        $this->assertStringContainsString($nextDue, $html, 'próximo compromiso contractual');
        $this->assertStringNotContainsString('reparto', strip_tags($html), 'sin reparto interno');
        $this->assertStringNotContainsString('lender', strip_tags($html), 'sin términos internos');
    }

    /** 8) WhatsApp: Monto abonado, Mora pagada solo si > 0, saldo y próximo. */
    public function test_whatsapp_shows_late_fee_paid_without_internal_split(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit] = $ctx;
        $nextDue = $this->installments($credit)[1]->due_date->format('d/m/Y');

        $this->pay($ctx, '5281.2500')->assertRedirect();

        $html = $this->get(route('cobros.collector.payments.receipt', 'RCB-00000001'))->assertOk()->getContent();
        preg_match('/wa\.me\/[^"]+\?text=([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'enlace wa.me ausente');
        $message = urldecode($m[1]);

        $this->assertStringContainsString('Monto abonado: '.Money::symbol('CRC').Money::format('5281.2500'), $message);
        $this->assertStringContainsString('Mora pagada: '.Money::symbol('CRC').Money::format('5000.0000'), $message, 'mora visible solo si > 0');
        $this->assertStringContainsString('Saldo nuevo: '.Money::symbol('CRC').Money::format('843.7500'), $message);
        $this->assertStringContainsString('Próximo pago: '.$nextDue, $message);
        $this->assertStringNotContainsString('50.00', $message, 'sin % de reparto');
        $this->assertStringNotContainsString('reparto', $message, 'sin reparto interno');
        $this->assertStringNotContainsString('collector', $message, 'sin claves internas');
    }

    // ------------------------------------------------------------------
    // 9-11) Snapshot inmutable, idempotencia y rollback total.
    // ------------------------------------------------------------------

    /** 9) Cambiar el % posterior NO reescribe snapshots anteriores. */
    public function test_late_fee_snapshot_keeps_effective_percentage_at_charge_time(): void
    {
        $ctx = $this->moraCtx();
        [$company] = $ctx;

        $this->pay($ctx, '1000.0000')->assertRedirect();
        $company->update(['collector_late_fee_pct' => '60.00']);
        $this->pay($ctx, '4000.0000')->assertRedirect();

        $rows = CobrosLateFeeApplication::query()->orderBy('id')->get();
        $this->assertSame(2, $rows->count());

        $this->assertSame('1000.0000', (string) $rows[0]->late_fee_charged);
        $this->assertSame('50.00', (string) $rows[0]->effective_pct, 'fila 1 conserva el % vigente en su cobro');
        $this->assertSame('500.0000', (string) $rows[0]->collector_amount);
        $this->assertSame('500.0000', (string) $rows[0]->lender_amount);

        $this->assertSame('4000.0000', (string) $rows[1]->late_fee_charged);
        $this->assertSame('60.00', (string) $rows[1]->effective_pct, 'fila 2 usa el % nuevo');
        $this->assertSame('2400.0000', (string) $rows[1]->collector_amount);
        $this->assertSame('1600.0000', (string) $rows[1]->lender_amount);

        $this->assertSame((int) $ctx[1]->id, (int) $rows[0]->collector_user_id, 'cobrador trazado');
        $this->assertNotNull($rows[0]->paid_at, 'fecha de cobro registrada');
    }

    /** 10) Mismo token con mora: replay devuelve el mismo cobro, cero duplicado. */
    public function test_replay_with_late_fee_never_duplicates(): void
    {
        $ctx = $this->moraCtx();
        [, , , , , $account] = $ctx;
        $token = (string) Str::uuid();

        $this->pay($ctx, '5281.2500', $token)->assertRedirect();
        $this->pay($ctx, '5281.2500', $token)->assertRedirect();

        $this->assertSame(1, CobrosReceipt::query()->count(), 'un solo recibo');
        $this->assertSame(1, CobrosLateFeeApplication::query()->count(), 'mora sin duplicar');
        $this->assertSame(1, CobrosCreditInstallmentApplication::query()->count(), 'cuota sin duplicar');
        $this->assertSame(1, AccountReceivablePayment::query()->count(), 'pago sin duplicar');
        $this->assertSame('843.7500', (string) $account->fresh()->balance_due, 'CxC reducida una sola vez');
    }

    /** 11) Error dentro de la transacción: rollback total, sin restos. */
    public function test_error_inside_transaction_rolls_back_everything(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;
        $balanceBefore = (string) $account->fresh()->balance_due;
        $rowsBefore = $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all();

        CobrosReceipt::creating(function (): void {
            throw new \RuntimeException('fallo forzado para rollback');
        });

        $this->pay($ctx, '5281.2500')->assertStatus(500);

        $this->assertSame(0, CobrosReceipt::query()->count(), 'sin recibo');
        $this->assertSame(0, AccountReceivablePayment::query()->count(), 'sin pago');
        $this->assertSame(0, CobrosLateFeeApplication::query()->count(), 'sin mora');
        $this->assertSame(0, CobrosCreditInstallmentApplication::query()->count(), 'sin cuotas');
        $this->assertSame($balanceBefore, (string) $account->fresh()->balance_due, 'CxC intacta');
        $this->assertSame($rowsBefore, $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all(), 'cuotas intactas');
    }

    // ------------------------------------------------------------------
    // 12-14) Tope, compatibilidad 9B.2A e integridad de capital.
    // ------------------------------------------------------------------

    /** 12) Pago > (capital + mora exigible): rechazado sin persistir nada. */
    public function test_payment_above_capital_plus_late_fee_is_rejected_without_persistence(): void
    {
        $ctx = $this->moraCtx();
        [, , , , , $account] = $ctx;

        $this->pay($ctx, '6125.0100') // 1125 + 5000 = 6125 → un centésimo arriba
            ->assertRedirect()
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, CobrosReceipt::query()->count());
        $this->assertSame(0, AccountReceivablePayment::query()->count());
        $this->assertSame(0, CobrosLateFeeApplication::query()->count());
        $this->assertSame('1125.0000', (string) $account->fresh()->balance_due);
    }

    /** 13) Sin mora configurada: 9B.2A exacto (pago = recibo = capital). */
    public function test_without_late_fee_configured_behavior_is_exactly_9b2a(): void
    {
        $ctx = $this->disbursed(); // late_fee_type: none
        [, , , , , $account] = $ctx;

        $this->pay($ctx, '562.5000')->assertRedirect();

        $this->assertSame(0, CobrosLateFeeApplication::query()->count(), 'sin filas de mora');
        $this->assertSame(2, CobrosCreditInstallmentApplication::query()->count());
        $this->assertSame('562.5000', (string) CobrosReceipt::sole()->amount);
        $this->assertSame('562.5000', (string) AccountReceivablePayment::sole()->amount, 'pago = recibo sin mora');
        $this->assertSame('562.5000', (string) CobrosReceipt::sole()->balance_after);
        $this->assertSame('562.5000', (string) $account->fresh()->balance_due);
    }

    /** 14) Cobrar mora NO capitaliza ni altera cuotas, fechas ni terms. */
    public function test_late_fee_payment_never_touches_capital_installments_or_terms(): void
    {
        $ctx = $this->moraCtx();
        [, , , , $credit, $account] = $ctx;
        $rowsBefore = $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all();
        $termsBefore = $credit->terms_snapshot;
        $balanceBefore = (string) $account->fresh()->balance_due;

        $this->pay($ctx, '3000.0000')->assertRedirect(); // solo mora parcial

        $this->assertSame($rowsBefore, $this->installments($credit)->map(fn ($i) => $i->getAttributes())->all(), 'cuotas sin cambios');
        $this->assertSame($termsBefore, $credit->fresh()->terms_snapshot, 'terms_snapshot intacto');
        $this->assertSame($balanceBefore, (string) $account->fresh()->balance_due, 'capital sin cambios');
        $this->assertSame('5000.0000', (string) $credit->fresh()->late_fee_value, 'la mora no se capitaliza');
    }

    // ------------------------------------------------------------------
    // 15-16) Aislamiento y UX de la pantalla COBRAR.
    // ------------------------------------------------------------------

    /** 15) Otro tenant/cobrador no puede cobrar esta cuenta. */
    public function test_collector_from_another_company_cannot_charge_this_account(): void
    {
        $ctx = $this->moraCtx();
        [$company, , , , , $account] = $ctx;

        $otherCompany = Company::create(['trade_name' => 'Otro '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $intruder = $this->member($otherCompany, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($intruder)->withSession(['active_company_id' => $otherCompany->id]);
        $response = $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => '5281.2500',
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ]);

        $this->assertTrue(in_array($response->status(), [403, 404], true), 'prohibido para otro tenant, recibió '.$response->status());
        $this->assertSame(0, CobrosReceipt::query()->count());
        $this->assertSame(0, AccountReceivablePayment::query()->count());
        $this->assertSame(0, CobrosLateFeeApplication::query()->count());
    }

    /** 16) UX: pantalla con Cuota/Mora/Total; preview con mora PRIMERO. */
    public function test_payment_screen_and_preview_show_late_fee_charged_first(): void
    {
        $ctx = $this->moraCtx();

        $screen = $this->cobrar($ctx)->assertOk()->getContent();
        $this->assertStringContainsString('Cuota pendiente', $screen);
        $this->assertStringContainsString('Mora pendiente', $screen);
        $this->assertStringContainsString('Total pendiente hoy', $screen);
        $this->assertStringContainsString(Money::format('5000.0000'), $screen, 'mora pendiente visible');
        $this->assertStringContainsString(Money::format('5281.2500'), $screen, 'total hoy = 281.25 + 5000');
        $this->assertStringNotContainsString('la mora no se cobra en este pago', strip_tags($screen), 'aviso obsoleto');
        $this->assertStringContainsString('la mora se aplica primero', strip_tags($screen), 'orden explicado');

        $preview = $this->pay($ctx, '5281.2500', confirm: false)->assertOk()->getContent();
        $this->assertStringContainsString('Este pago se aplicará así', $preview, 'preview con mora');
        $this->assertStringContainsString('Mora por atraso', $preview);
        $this->assertStringContainsString(Money::format('5000.0000'), $preview, 'fila de mora');
        $this->assertStringContainsString('Cuota 1', $preview, 'fila de cuota después');
        $this->assertStringContainsString(Money::format('843.7500'), $preview, 'saldo del préstamo sin mora');
        $this->assertStringNotContainsString('la mora no se cobra en este pago', strip_tags($preview));

        $moraPos = strpos($preview, 'Mora por atraso');
        $cuotaPos = strpos($preview, 'Cuota 1');
        $this->assertNotFalse($moraPos);
        $this->assertNotFalse($cuotaPos);
        $this->assertLessThan($cuotaPos, $moraPos, 'la mora se muestra ANTES que la cuota');
    }
}
