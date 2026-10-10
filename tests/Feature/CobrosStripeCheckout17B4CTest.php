<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CobrosBillingContract;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\Adapters\StripePaymentProvider;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * BLOQUE 17B-4C — endpoint POST de checkout Stripe TEST (cobros.payment.checkout).
 *
 * Cubre: redirección a sesión Checkout con importe/moneda de la cotización
 * interna e idempotencia, denegación a no pagadores y ausencia de credenciales
 * (botón/endpoint inactivo). Stripe SIEMPRE simulado: sin llamadas externas.
 */
class CobrosStripeCheckout17B4CTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} */
    private function context(array $permissions): array
    {
        $company = Company::create([
            'trade_name' => 'Prestamista '.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $role->permissions()->attach($permission);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        app(CompanyCashSettingsProvisioner::class)->provision($company);

        return [$company, $user];
    }

    private function contractFor(Company $company, User $payer): CobrosBillingContract
    {
        return CobrosBillingContract::create([
            'company_id' => $company->id,
            'payer_user_id' => $payer->id,
            'billing_mode' => CobrosBillingContract::MODE_INDIVIDUAL,
            'modality' => 'prestamista',
            'country_iso' => 'CR',
            'currency' => 'CRC',
            'status' => 'draft',
            'seats_total' => 1,
            'monthly_amount' => '10000.0000',
            'admin_included' => false,
            'valid_from' => now()->startOfMonth()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);
    }

    /** Cliente Stripe simulado que captura parámetros y devuelve una sesión fake. */
    private function fakeStripe(array &$capturedParams, array &$capturedOptions): StripePaymentProvider
    {
        $mockSession = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_17b4c',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/pay/cs_test_17b4c',
            'expires_at' => time() + 1800,
            'mode' => 'payment',
        ], 'sk_test_mock');

        $mockSessionService = $this->createMock(\Stripe\Service\Checkout\SessionService::class);
        $mockSessionService
            ->method('create')
            ->willReturnCallback(function ($params, $options) use (&$capturedParams, &$capturedOptions, $mockSession) {
                $capturedParams = $params;
                $capturedOptions = $options;

                return $mockSession;
            });

        $mockCheckoutFactory = new class($mockSessionService) extends \Stripe\Service\Checkout\CheckoutServiceFactory {
            public function __construct(private $sessionService) {}

            public function __get($name)
            {
                return $name === 'sessions' ? $this->sessionService : parent::__get($name);
            }
        };

        $mockClient = new class($mockCheckoutFactory) extends \Stripe\StripeClient {
            public function __construct(private $checkoutFactory) {}

            public function __get($name)
            {
                return $name === 'checkout' ? $this->checkoutFactory : parent::__get($name);
            }
        };

        Config::set('billing.stripe.mode', 'test');
        Config::set('billing.stripe.secret_key_test', 'sk_test_mock');

        $provider = new StripePaymentProvider();
        $provider->isAvailable();

        $property = (new \ReflectionClass($provider))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($provider, $mockClient);

        $this->app->instance(StripePaymentProvider::class, $provider);

        return $provider;
    }

    public function test_payer_checkout_redirects_to_stripe_with_internal_amount(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer);

        $capturedParams = [];
        $capturedOptions = [];
        $this->fakeStripe($capturedParams, $capturedOptions);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect('https://checkout.stripe.com/pay/cs_test_17b4c');

        // Importe/moneda provienen de la cotización interna, nunca del navegador.
        $this->assertSame('crc', $capturedParams['line_items'][0]['price_data']['currency']);
        $this->assertSame(1000000, $capturedParams['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('payment', $capturedParams['mode']);
        $this->assertStringContainsString(
            '/cobros/contratos/'.$contract->id.'/metodos-pago?checkout=exitoso',
            $capturedParams['success_url']
        );
        $this->assertSame((string) $contract->id, $capturedParams['metadata']['contract_reference']);
        $this->assertStringStartsWith('mvs-checkout-', $capturedOptions['idempotency_key']);

        // 17B-4C: el retorno nunca activa licencias (solo el webhook verificado).
        $this->assertSame(0, \DB::table('cobros_collector_licenses')->count());
    }

    public function test_checkout_is_denied_for_non_payer(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        $permission = Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'cobros.acceder', 'module' => 'Test', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertForbidden();
    }

    public function test_checkout_without_stripe_credentials_is_refused(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer);

        Config::set('billing.stripe.mode', 'test');
        Config::set('billing.stripe.secret_key_test', null);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->from(route('cobros.payment.methods', $contract))
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect(route('cobros.payment.methods', $contract))
            ->assertSessionHasErrors('checkout');

        // Sin credenciales el botón queda en «No disponible».
        $this->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('No disponible');
    }
}
