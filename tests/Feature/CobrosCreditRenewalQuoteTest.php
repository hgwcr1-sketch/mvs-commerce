<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosCreditInstallmentApplication;
use App\Models\CobrosCreditRequest;
use App\Models\CobrosReceipt;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cobros\CollectorLicenseService;
use App\Services\Cobros\CreditRenewalQuoteService;
use App\Services\CompanyLicenseService;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CobrosCreditRenewalQuoteTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '768M');
    }

    public function test_quote_matches_reference_example_and_never_mutates(): void
    {
        $ctx = $this->disbursed([
            'principal' => '46000.00',
            'interest_type' => 'fixed',
            'interest_value' => '10000',
            'installment_count' => '4',
            'frequency' => 'monthly',
        ]);
        [$company, $collector, , , $credit, $account] = $ctx;

        $before = $this->snapshot();

        $quote = app(CreditRenewalQuoteService::class)->quote(
            $collector, $company, $account, '50000', 4, 'monthly', now()->addWeek()->toDateString()
        );

        // Deuda anterior ₡56.000 = capital 46.000 + intereses 10.000.
        $this->assertSame('56000.0000', $quote['previous']['total']);
        $this->assertSame('46000.0000', $quote['previous']['principal']);
        $this->assertSame('10000.0000', $quote['previous']['interest']);
        // Intereses nuevos solo sobre el dinero nuevo: 50.000 fijos → 10.000.
        $this->assertSame('50000.0000', $quote['new_money']['principal']);
        $this->assertSame('10000.0000', $quote['new_money']['interest']);
        $this->assertSame('116000.0000', $quote['consolidated_total']);
        // El desembolso real es únicamente el capital nuevo entregado.
        $this->assertSame('50000.0000', $quote['disbursement']);

        $sum = collect($quote['schedule']['rows'])->pluck('contractual_amount')
            ->reduce(fn ($c, $a) => bcadd($c, $a, 4), '0.0000');
        $this->assertSame('116000.0000', $sum);

        $this->assertSame($before, $this->snapshot(), 'La cotización no escribe nada.');
        $this->assertSame(CobrosCredit::STATUS_DISBURSED, $credit->fresh()->status);
        $this->assertSame(1, CobrosCredit::count());
        $this->assertSame(0, CobrosReceipt::count());
        $this->assertSame(0, CobrosCreditInstallmentApplication::count());
        $this->assertSame($credit->installments()->count(), CobrosCreditInstallment::count());
    }

    public function test_new_interest_uses_percent_only_on_new_capital(): void
    {
        [$company, $collector, , , , $account] = $this->disbursed(); // 1.000 + 12,5% = 1.125

        $quote = app(CreditRenewalQuoteService::class)->quote(
            $collector, $company, $account, '5000', 4, 'monthly', now()->addWeek()->toDateString()
        );

        $this->assertSame('1125.0000', $quote['previous']['total']);
        $this->assertSame('1000.0000', $quote['previous']['principal']);
        $this->assertSame('125.0000', $quote['previous']['interest']);
        // 12,5% de 5.000 = 625, jamás sobre la deuda vieja (que daría 765,625).
        $this->assertSame('625.0000', $quote['new_money']['interest']);
        $this->assertSame('6750.0000', $quote['consolidated_total']);
        $this->assertSame('5000.0000', $quote['disbursement']);
    }

    public function test_partial_payments_split_and_round_exactly(): void
    {
        $ctx = $this->disbursed(); // total 1.125 en 4 cuotas
        [$company, $collector, $route, , , $account] = $ctx;
        $this->pay($ctx, '400.0000'); // pendiente 725 = capital 644,4444 + interés 80,5556

        $quote = app(CreditRenewalQuoteService::class)->quote(
            $collector, $company, $account, '333.3333', 4, 'monthly', now()->addWeek()->toDateString()
        );

        $this->assertSame('725.0000', $quote['previous']['total']);
        $this->assertSame('644.4444', $quote['previous']['principal']);
        $this->assertSame('80.5556', $quote['previous']['interest']);
        // 12,5% de 333,3333 = 41,6666625 → half-up 41,6667.
        $this->assertSame('41.6667', $quote['new_money']['interest']);
        $this->assertSame('1100.0000', $quote['consolidated_total']); // 725 + 333,3333 + 41,6667

        $rows = $quote['schedule']['rows'];
        $this->assertCount(4, $rows);
        $sum = collect($rows)->pluck('contractual_amount')
            ->reduce(fn ($c, $a) => bcadd($c, $a, 4), '0.0000');
        $this->assertSame('1100.0000', $sum);
        $this->assertSame(
            $rows[3]['contractual_amount'],
            bcsub('1100.0000', collect($rows)->take(3)->pluck('contractual_amount')
                ->reduce(fn ($c, $a) => bcadd($c, $a, 4), '0.0000'), 4),
            'La última cuota absorbe el residuo exacto.'
        );
        $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $rows[0]['contractual_amount']);
    }

    public function test_isolation_foreign_account_and_unauthorized_collector(): void
    {
        [$company, $collector, , , , $account] = $this->disbursed();

        // Cartera ajena: otra empresa no ve la CxC (sin sesión activa propia).
        $foreign = Company::create(['trade_name' => 'Ajena '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($foreign, $admin, ['cobros']);
        $strangerCollector = $this->member($foreign, ['cobros.acceder', 'cobros.cobrar']);

        $this->actingAs($strangerCollector)->withSession(['active_company_id' => $foreign->id]);
        try {
            app(CreditRenewalQuoteService::class)->quote(
                $strangerCollector, $foreign, $account, '1000', 4, 'monthly', now()->addWeek()->toDateString()
            );
            $this->fail('La CxC ajena no debe ser cotizable.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $this->assertTrue(true);
        }

        // Cobrador sin asignación ni permiso de dueño sobre el crédito.
        $other = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        try {
            app(CreditRenewalQuoteService::class)->quote(
                $other, $company, $account, '1000', 4, 'monthly', now()->addWeek()->toDateString()
            );
            $this->fail('Un cobrador no asignado no debe cotizar el crédito.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // El dueño con portal sí puede preparar la renovación.
        $quote = app(CreditRenewalQuoteService::class)->quote(
            $this->owner, $company, $account, '1000', 4, 'monthly', now()->addWeek()->toDateString()
        );
        $this->assertSame('2250.0000', $quote['consolidated_total']); // 1.125 + 1.000 + 125
    }

    public function test_invalid_input_rejected_without_writes(): void
    {
        [$company, $collector, , , , $account] = $this->disbursed();
        $service = app(CreditRenewalQuoteService::class);
        $before = $this->snapshot();

        foreach ([
            ['0', 4, 'monthly'],
            ['-5', 4, 'monthly'],
            ['1000', 0, 'monthly'],
            ['1000', 4, 'mensual'],
        ] as [$capital, $count, $freq]) {
            try {
                $service->quote($collector, $company, $account, $capital, $count, $freq, now()->addWeek()->toDateString());
                $this->fail("Debió rechazar capital={$capital} cuotas={$count} frecuencia={$freq}");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        try {
            $service->quote($collector, $company, $account, '1000', 4, 'monthly', now()->subDay()->toDateString());
            $this->fail('Debió rechazar primera cuota en el pasado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame($before, $this->snapshot());
    }

    // ------------------------------------------------------------------
    // Helpers (mismo flujo real 9A/9C de CobrosCreditInstallmentPaymentTest)
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
        $company = Company::create(['trade_name' => 'Renov '.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $admin = User::factory()->create(['is_platform_admin' => true, 'is_active' => true]);
        app(CompanyLicenseService::class)->updateModules($company, $admin, ['cobros', 'notifications']);
        $collector = $this->member($company, ['cobros.acceder', 'cobros.cobrar']);
        $this->owner = $this->member($company, ['cobros.acceder', 'cobros.portal_dueno']);

        $route = CobrosRoute::create(['company_id' => $company->id, 'name' => 'Ruta Norte', 'active' => true]);
        $route->users()->attach($collector->id, ['active' => true]);
        app(CollectorLicenseService::class)->renewForRoute($route, $company, $admin, now()->addMonth());
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'S01']);
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
            'notes' => 'Solicitud renovacion',
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

    private function pay(array $ctx, string $amount): TestResponse
    {
        [$company, $collector, $route, , , $account] = $ctx;

        $this->actingAs($collector)->withSession(['active_company_id' => $company->id, 'active_cobros_route_id' => $route->id]);

        return $this->post(route('cobros.collector.payments.store', $account), [
            'amount' => $amount,
            'payment_method_id' => PaymentMethod::query()->where('company_id', $company->id)->where('code', 'cash')->firstOrFail()->id,
            'client_token' => (string) Str::uuid(),
            'confirm' => '1',
        ]);
    }

    /** Invariantes de no mutación financiera. */
    private function snapshot(): array
    {
        return [
            CobrosCredit::count(),
            CobrosCreditInstallment::count(),
            CobrosCreditInstallmentApplication::count(),
            CobrosReceipt::count(),
            AccountReceivable::query()->sum('balance_due'),
            CobrosCreditInstallment::query()->sum('applied_amount'),
        ];
    }
}
