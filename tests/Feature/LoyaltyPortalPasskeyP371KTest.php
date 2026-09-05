<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyPortalCredential;
use App\Models\LoyaltyPortalPasskey;
use App\Services\Loyalty\LoyaltyPortalPasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyPortalPasskeyP371KTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_view_offers_a_passkey_button_that_targets_the_dedicated_endpoints(): void
    {
        [$company] = $this->bootstrap();
        $view = $this->get(route('loyalty.customer.login', $company))->assertOk();
        $view->assertSee('Ingresar con passkey', false)
            ->assertSee(route('loyalty.customer.passkeys.auth.start', $company), false)
            ->assertSee(route('loyalty.customer.passkeys.auth.finish', $company), false);
    }

    public function test_register_completes_enrollment_and_persists_only_an_encrypted_secret(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        $start = $service->startRegistration($credential->customer, $company, 'Mi passkey');
        $secret = random_bytes(32);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        $result = $service->finishRegistration(
            $credential->customer,
            $company,
            $start['challenge'],
            'cred-1',
            $this->b64($secret),
            $this->b64($signature),
            'Mi passkey',
        );

        $this->assertSame('Mi passkey', $result['passkey']->name);
        $this->assertSame('HS256', $result['passkey']->algorithm);
        $this->assertSame(0, $result['passkey']->sign_count);
        $stored = $result['passkey']->public_key_jwk;
        $this->assertArrayHasKey('secret', $stored);
        $this->assertArrayHasKey('hint', $stored);
        $this->assertNotSame($secret, $stored['secret'], 'El server debe cifrar el secret antes de persistir.');
        $this->assertSame(hash('sha256', $secret), hash('sha256', $result['enrollment_secret']));
    }

    public function test_authentication_succeeds_with_a_valid_signature_and_increments_counter(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey, $secret] = $this->register($service, $credential->customer, $company, 'Laptop');
        $start = $service->startAuthentication($company, $credential->username);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        $result = $service->finishAuthentication(
            $company,
            $credential->username,
            $passkey->credential_id,
            $start['challenge'],
            $this->b64($signature),
        );

        $this->assertSame($credential->customer_id, $result['customer']->id);
        $this->assertSame(1, $passkey->fresh()->sign_count);
        $this->assertNotNull($passkey->fresh()->last_used_at);
    }

    public function test_authentication_fails_for_unknown_credential_id_and_revsoke(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey, $secret] = $this->register($service, $credential->customer, $company, 'Celular');
        $start = $service->startAuthentication($company, $credential->username);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        try {
            $service->finishAuthentication(
                $company,
                $credential->username,
                'credencial-que-no-existe',
                $start['challenge'],
                $this->b64($signature),
            );
            $this->fail('Debió fallar la autenticación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('passkey', $e->errors());
        }

        $this->assertNull($passkey->fresh()->revoked_at);
    }

    public function test_authentication_fails_with_a_wrong_signature_and_revokes_the_credential(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey, $secret] = $this->register($service, $credential->customer, $company, 'Tablet');
        $start = $service->startAuthentication($company, $credential->username);
        $bad = hash_hmac('sha256', $this->rawChallenge($start['challenge']), 'secret-que-no-es', true);

        try {
            $service->finishAuthentication(
                $company,
                $credential->username,
                $passkey->credential_id,
                $start['challenge'],
                $this->b64($bad),
            );
            $this->fail('Debió fallar la autenticación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('passkey', $e->errors());
        }

        $this->assertNotNull($passkey->fresh()->revoked_at, 'Firma inválida debe revocar la passkey automáticamente.');
    }

    public function test_revoked_passkeys_cannot_authenticate(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey, $secret] = $this->register($service, $credential->customer, $company, 'Dispositivo');
        $service->revoke($credential->customer, $company, $passkey->id, '127.0.0.1');
        $start = $service->startAuthentication($company, $credential->username);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        try {
            $service->finishAuthentication(
                $company,
                $credential->username,
                $passkey->credential_id,
                $start['challenge'],
                $this->b64($signature),
            );
            $this->fail('Debió fallar la autenticación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('passkey', $e->errors());
        }
    }

    public function test_credentials_are_strictly_isolated_between_companies(): void
    {
        [$company, $credential] = $this->bootstrap();
        $otherCompany = Company::create(['trade_name' => 'Otra empresa', 'legal_name' => 'Otra', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $otherCompany->id, 'name' => 'S', 'code' => 'S'.uniqid(), 'is_active' => true]);
        $otherCustomer = Customer::create(['company_id' => $otherCompany->id, 'customer_type' => 'individual', 'name' => 'Otro Cliente', 'is_active' => true]);
        $otherCredential = LoyaltyPortalCredential::create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'username' => 'otro_user',
            'email' => 'otro@example.com',
            'password' => 'OtraClave1',
        ]);

        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey] = $this->register($service, $credential->customer, $company, 'A');
        $start = $service->startAuthentication($company, $credential->username);
        $secret = $this->extractSecret($passkey);

        // La passkey de la Empresa A no autentica a la Empresa B aunque el
        // username esté duplicado en la otra empresa.
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);
        try {
            $service->finishAuthentication(
                $otherCompany,
                $otherCredential->username,
                $passkey->credential_id,
                $start['challenge'],
                $this->b64($signature),
            );
            $this->fail('La passkey de la Empresa A no debe autenticar contra la Empresa B.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identifier', $e->errors());
        }
    }

    public function test_password_login_continues_to_work_for_recovery(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        $this->register($service, $credential->customer, $company, 'A');

        $response = $this->post(route('loyalty.customer.login.store', $company), [
            'username' => $credential->username,
            'password' => 'ClaveSegura1',
        ]);
        $response->assertRedirect(route('loyalty.customer.home', $company));
        $this->assertSame($credential->customer_id, (int) session('loyalty_portal_customer_id'));
    }

    public function test_manage_view_lists_passkeys_and_supports_revoke(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey] = $this->register($service, $credential->customer, $company, 'A');
        session(['loyalty_portal_company_id' => $company->id, 'loyalty_portal_customer_id' => $credential->customer_id]);

        $this->get(route('loyalty.customer.passkeys.manage', $company))->assertOk()->assertSee('A');
        $this->delete(route('loyalty.customer.passkeys.revoke', [$company, $passkey->id]))->assertRedirect();
        $this->assertNotNull($passkey->fresh()->revoked_at);
    }

    public function test_rename_changes_the_display_name(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey] = $this->register($service, $credential->customer, $company, 'Original');
        session(['loyalty_portal_company_id' => $company->id, 'loyalty_portal_customer_id' => $credential->customer_id]);

        $this->patch(route('loyalty.customer.passkeys.rename', [$company, $passkey->id]), ['name' => 'Nuevo'])->assertRedirect();
        $this->assertSame('Nuevo', $passkey->fresh()->name);
    }

    public function test_registration_max_limit_is_enforced(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        for ($i = 0; $i < LoyaltyPortalPasskeyService::MAX_PASSKEYS_PER_CUSTOMER; $i++) {
            $this->register($service, $credential->customer, $company, 'A'.$i);
        }
        $start = $service->startRegistration($credential->customer, $company, 'Extra');
        $secret = random_bytes(32);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        $this->expectException(ValidationException::class);
        $service->finishRegistration(
            $credential->customer,
            $company,
            $start['challenge'],
            'cred-extra',
            $this->b64($secret),
            $this->b64($signature),
            'Extra',
        );
    }

    public function test_start_authentication_fails_for_users_without_active_passkeys(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        $this->expectException(ValidationException::class);
        $service->startAuthentication($company, $credential->username);
    }

    public function test_start_authentication_rejects_unknown_identifiers(): void
    {
        [$company] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        $this->expectException(ValidationException::class);
        $service->startAuthentication($company, 'no-existe');
    }

    public function test_replay_of_a_used_authentication_challenge_is_rejected(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        [$passkey, $secret] = $this->register($service, $credential->customer, $company, 'A');
        $start = $service->startAuthentication($company, $credential->username);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);

        $service->finishAuthentication($company, $credential->username, $passkey->credential_id, $start['challenge'], $this->b64($signature));

        $this->expectException(ValidationException::class);
        $service->finishAuthentication($company, $credential->username, $passkey->credential_id, $start['challenge'], $this->b64($signature));
    }

    public function test_passkey_secret_is_never_returned_in_the_list_endpoint(): void
    {
        [$company, $credential] = $this->bootstrap();
        $service = app(LoyaltyPortalPasskeyService::class);
        $this->register($service, $credential->customer, $company, 'A');

        $listed = $service->list($credential->customer, $company);
        $this->assertCount(1, $listed);
        $this->assertSame('A', $listed->first()->name);
        $this->assertArrayNotHasKey('secret', $listed->first()->public_key_jwk ?? []);
    }

    private function bootstrap(): array
    {
        Cache::flush();
        $company = Company::create(['trade_name' => 'Empresa '.uniqid(), 'legal_name' => 'Empresa', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'name' => 'Cliente Passkey', 'is_active' => true]);
        $credential = LoyaltyPortalCredential::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'username' => 'cliente_passkey_'.uniqid(),
            'email' => 'passkey@example.com',
            'password' => 'ClaveSegura1',
        ]);

        return [$company, $credential];
    }

    private function register(LoyaltyPortalPasskeyService $service, Customer $customer, Company $company, string $name): array
    {
        $start = $service->startRegistration($customer, $company, $name);
        $secret = random_bytes(32);
        $signature = hash_hmac('sha256', $this->rawChallenge($start['challenge']), $secret, true);
        $result = $service->finishRegistration(
            $customer,
            $company,
            $start['challenge'],
            'cred-'.bin2hex(random_bytes(8)),
            $this->b64($secret),
            $this->b64($signature),
            $name,
        );

        return [$result['passkey'], $secret];
    }

    private function extractSecret(LoyaltyPortalPasskey $passkey): string
    {
        $stored = $passkey->public_key_jwk['secret'] ?? null;
        if (! is_string($stored)) {
            $this->fail('La passkey no tiene secret cifrado.');
        }

        return Crypt::decryptString($stored);
    }

    private function rawChallenge(string $b64): string
    {
        $remainder = strlen($b64) % 4;
        if ($remainder !== 0) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($b64, '-_', '+/'));
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
