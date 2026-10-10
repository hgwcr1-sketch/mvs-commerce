<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BillingProviderSetting;
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
 * BLOQUE 18E-2 — selección de proveedor de Checkout vía PaymentProviderRegistry.
 *
 * Cubre: Stripe TEST disponible cuando la elección efectiva es Stripe para el
 * país real del contrato; tarjeta no disponible y POST bloqueado sin llamadas
 * externas cuando la elección es SINPE u otro adaptador; elección
 * deshabilitada sin sustitución automática por Stripe; aislamiento entre
 * países. Importe/moneda siguen saliendo de la cotización interna.
 */
class CobrosCheckoutProviderSelection18E2Test extends TestCase
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

    private function contractFor(Company $company, User $payer, string $countryIso = 'CR'): CobrosBillingContract
    {
        return CobrosBillingContract::create([
            'company_id' => $company->id,
            'payer_user_id' => $payer->id,
            'billing_mode' => CobrosBillingContract::MODE_INDIVIDUAL,
            'modality' => 'prestamista',
            'country_iso' => $countryIso,
            'currency' => 'CRC',
            'status' => 'draft',
            'seats_total' => 1,
            'monthly_amount' => '10000.0000',
            'admin_included' => false,
            'valid_from' => now()->startOfMonth()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ]);
    }

    /** Configura Stripe TEST completo (sin llamadas reales). */
    private function stripeTestConfigured(): void
    {
        Config::set('billing.stripe.mode', 'test');
        Config::set('billing.stripe.secret_key_test', 'sk_test_mock_18e2');
        Config::set('billing.stripe.publishable_key_test', 'pk_test_mock_18e2');
        Config::set('billing.stripe.webhook_secret_test', 'whsec_test_mock_18e2');
    }

    /** Cliente Stripe simulado que redirige sin llamadas externas. */
    private function fakeStripe(): void
    {
        $mockSession = \Stripe\Checkout\Session::constructFrom([
            'id' => 'cs_test_18e2',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/pay/cs_test_18e2',
            'expires_at' => time() + 1800,
            'mode' => 'payment',
        ], 'sk_test_mock');

        $mockSessionService = $this->createMock(\Stripe\Service\Checkout\SessionService::class);
        $mockSessionService->method('create')->willReturn($mockSession);

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

        $provider = new StripePaymentProvider();
        $provider->isAvailable();

        $property = (new \ReflectionClass($provider))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($provider, $mockClient);

        $this->app->instance(StripePaymentProvider::class, $provider);
    }

    public function test_checkout_uses_stripe_when_registry_selects_it_for_contract_country(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer, 'CR');
        $this->stripeTestConfigured();
        $this->fakeStripe();

        // Sin elección explícita: fallback Stripe (primer disponible) → TEST activo.
        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('Stripe TEST')
            ->assertSee('Pagar con tarjeta (Stripe TEST)');

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect('https://checkout.stripe.com/pay/cs_test_18e2');
    }

    public function test_card_checkout_unavailable_when_explicit_provider_is_sinpe(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer, 'CR');
        $this->stripeTestConfigured();
        $this->fakeStripe();

        // Elección explícita SINPE para cobros/CR: la tarjeta queda no
        // disponible y el POST se bloquea SIN sustituir por Stripe.
        BillingProviderSetting::create([
            'product' => 'cobros', 'country_iso' => 'CR', 'provider' => 'sinpe',
            'mode' => 'test', 'is_active' => true,
        ]);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('No disponible')
            ->assertSee('sinpe')
            ->assertDontSee('Pagar con tarjeta (Stripe TEST)');

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect(route('cobros.payment.methods', $contract))
            ->assertSessionHasErrors('checkout');
    }

    public function test_disabled_provider_blocks_checkout_without_stripe_substitution(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer, 'CR');
        $this->stripeTestConfigured();
        $this->fakeStripe();

        BillingProviderSetting::create([
            'product' => 'cobros', 'country_iso' => 'CR', 'provider' => 'stripe',
            'mode' => 'test', 'is_active' => false,
        ]);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contract))
            ->assertOk()
            ->assertSee('No disponible')
            ->assertDontSee('Pagar con tarjeta (Stripe TEST)');

        // POST bloqueado: elección deshabilitada, nunca se sustituye por Stripe.
        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect(route('cobros.payment.methods', $contract))
            ->assertSessionHasErrors('checkout');
    }

    public function test_selection_is_isolated_between_countries(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contractCr = $this->contractFor($company, $payer, 'CR');
        $this->stripeTestConfigured();
        $this->fakeStripe();

        // Elección SINPE solo para Colombia: no afecta a Costa Rica.
        BillingProviderSetting::create([
            'product' => 'cobros', 'country_iso' => 'CO', 'provider' => 'sinpe',
            'mode' => 'test', 'is_active' => true,
        ]);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.payment.methods', $contractCr))
            ->assertOk()
            ->assertSee('Stripe TEST');

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contractCr))
            ->assertRedirect('https://checkout.stripe.com/pay/cs_test_18e2');
    }

    public function test_post_blocked_when_registry_provider_unavailable_even_with_stripe_configured(): void
    {
        [$company, $payer] = $this->context(['cobros.acceder']);
        $contract = $this->contractFor($company, $payer, 'CR');
        $this->stripeTestConfigured();
        $this->fakeStripe();

        // Proveedor deshabilitado para el país del contrato: el registro
        // devuelve indisponible y el controlador no cae a Stripe.
        BillingProviderSetting::create([
            'product' => 'cobros', 'country_iso' => 'CR', 'provider' => 'stripe',
            'mode' => 'test', 'is_active' => false,
        ]);

        $this->actingAs($payer)
            ->withSession(['active_company_id' => $company->id])
            ->post(route('cobros.payment.checkout', $contract))
            ->assertRedirect(route('cobros.payment.methods', $contract))
            ->assertSessionHasErrors('checkout');
    }
}
