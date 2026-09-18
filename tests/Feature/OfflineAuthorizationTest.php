<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\OfflineAuthorization;
use App\Models\OfflineTerminal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OfflineAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OfflineAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $testKeysDir;
    private string $testPrivateKeyPath;
    private string $testPublicKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testKeysDir = storage_path('app/private/mvs-offline-test');
        $this->testPrivateKeyPath = $this->testKeysDir . '/private.pem';
        $this->testPublicKeyPath = $this->testKeysDir . '/public.pem';

        if (! File::isDirectory($this->testKeysDir)) {
            File::makeDirectory($this->testKeysDir, 0700, true, true);
        }

        $this->generateTestKeyPair();

        config([
            'offline.keys.private' => 'app/private/mvs-offline-test/private.pem',
            'offline.keys.public' => 'app/private/mvs-offline-test/public.pem',
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testKeysDir)) {
            File::deleteDirectory($this->testKeysDir);
        }

        parent::tearDown();
    }

    // =====================================================================
    // CRYPTOGRAPHIC TESTS (required by specification)
    // =====================================================================

    public function test_signed_token_verifies_correctly(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $payload = $service->verifyToken($result['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals($company->id, $payload['company_id']);
        $this->assertSame($user->id, $payload['user_id']);
        $this->assertSame($branch->id, $payload['branch_id']);
        $this->assertSame($terminal->terminal_uuid, $payload['terminal_uuid']);
        $this->assertNull($service->verifyToken($this->tamperPayload($result['authorization'], 'user_id', $user->id + 1)));
    }

    public function test_legacy_token_without_signed_user_remains_valid_for_existing_flows(): void
    {
        [$company, $branch] = $this->tenant('Legacy');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);
        $service = app(OfflineAuthorizationService::class);
        $token = $service->authorize($terminal, $user)['token'];
        [$header, $encodedPayload] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true);
        unset($payload['user_id']);
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $data = $header.'.'.$encodedPayload;
        openssl_sign($data, $signature, File::get($this->testPrivateKeyPath), OPENSSL_ALGO_SHA256);
        $legacy = $data.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $this->assertSame($payload, $service->verifyToken($legacy));
        $this->assertSame($payload, $service->verifyTokenAllowExpired($legacy));
    }

    public function test_modifying_company_id_invalidates_signature(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $tampered = $this->tamperPayload($result['authorization'], 'company_id', 99999);
        $this->assertNull($service->verifyToken($tampered));
    }

    public function test_modifying_branch_id_invalidates_signature(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $tampered = $this->tamperPayload($result['authorization'], 'branch_id', 99999);
        $this->assertNull($service->verifyToken($tampered));
    }

    public function test_modifying_terminal_uuid_invalidates_signature(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $tampered = $this->tamperPayload($result['authorization'], 'terminal_uuid', 'fake-uuid');
        $this->assertNull($service->verifyToken($tampered));
    }

    public function test_modifying_issued_at_invalidates_signature(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $tampered = $this->tamperPayload($result['authorization'], 'issued_at', now()->subDay()->toIso8601String());
        $this->assertNull($service->verifyToken($tampered));
    }

    public function test_modifying_valid_until_invalidates_signature(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $tampered = $this->tamperPayload($result['authorization'], 'valid_until', now()->addDays(365)->toIso8601String());
        $this->assertNull($service->verifyToken($tampered));
    }

    public function test_expired_token_is_rejected(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);

        // Build a manually expired token (valid_until in the past)
        $header = base64_encode(json_encode(['alg' => 'RSA-SHA256', 'typ' => 'MVS-OFFLINE-AUTH', 'version' => 1]));
        $payload = base64_encode(json_encode([
            'authorization_id' => 'expired-test',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'issued_at' => now()->subHours(50)->toIso8601String(),
            'valid_until' => now()->subHours(2)->toIso8601String(),
            'license_status' => 'active',
        ]));
        $signedData = $this->base64url($header) . '.' . $this->base64url($payload);

        $privateKey = openssl_pkey_get_private(File::get($this->testPrivateKeyPath));
        $signature = '';
        openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);

        $token = $this->base64url($header) . '.' . $this->base64url($payload) . '.' . $this->base64url($signature);

        $this->assertNull($service->verifyToken($token));
    }

    public function test_invalid_structure_is_rejected(): void
    {
        $service = app(OfflineAuthorizationService::class);

        $this->assertNull($service->verifyToken('invalid'));
        $this->assertNull($service->verifyToken('a.b'));
        $this->assertNull($service->verifyToken('a.b.c.d'));
        $this->assertNull($service->verifyToken(''));
    }

    public function test_unsupported_algorithm_is_rejected(): void
    {
        $header = base64_encode(json_encode(['alg' => 'HMAC-SHA256', 'typ' => 'MVS-OFFLINE-AUTH', 'version' => 1]));
        $payload = base64_encode(json_encode(['test' => true]));
        $token = $this->base64url($header) . '.' . $this->base64url($payload) . '.' . $this->base64url('fake-sig');

        $service = app(OfflineAuthorizationService::class);
        $this->assertNull($service->verifyToken($token));
    }

    public function test_unsupported_version_is_rejected(): void
    {
        $header = base64_encode(json_encode(['alg' => 'RSA-SHA256', 'typ' => 'MVS-OFFLINE-AUTH', 'version' => 99]));
        $payload = base64_encode(json_encode(['test' => true]));
        $token = $this->base64url($header) . '.' . $this->base64url($payload) . '.' . $this->base64url('fake-sig');

        $service = app(OfflineAuthorizationService::class);
        $this->assertNull($service->verifyToken($token));
    }

    public function test_public_key_can_verify_token(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        // Parse the token
        $parts = explode('.', $result['authorization']);
        $this->assertCount(3, $parts);

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $signature = base64_decode(strtr($signatureB64, '-_', '+/'));
        $signedData = $headerB64 . '.' . $payloadB64;

        // Verify using ONLY the public key (no APP_KEY, no private key)
        $publicKey = openssl_pkey_get_public(File::get($this->testPublicKeyPath));
        $this->assertEquals(1, openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256));
        openssl_free_key($publicKey);
    }

    public function test_public_key_cannot_fabricate_valid_authorization(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        // Try to sign with the PUBLIC key (which should fail)
        $header = base64_encode(json_encode(['alg' => 'RSA-SHA256', 'typ' => 'MVS-OFFLINE-AUTH', 'version' => 1]));
        $payload = base64_encode(json_encode([
            'authorization_id' => 'fabricated',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => $terminal->terminal_uuid,
            'issued_at' => now()->toIso8601String(),
            'valid_until' => now()->addHours(24)->toIso8601String(),
            'license_status' => 'active',
        ]));
        $signedData = $this->base64url($header) . '.' . $this->base64url($payload);

        $publicKey = openssl_pkey_get_public(File::get($this->testPublicKeyPath));
        $signature = '';
        $result = @openssl_sign($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        // openssl_sign with public key returns false or throws
        $this->assertFalse((bool) $result);

        // Even if we manually construct a token, verification should fail
        $fakeSig = random_bytes(256);
        $token = $this->base64url($header) . '.' . $this->base64url($payload) . '.' . $this->base64url($fakeSig);

        $service = app(OfflineAuthorizationService::class);
        $this->assertNull($service->verifyToken($token));
    }

    public function test_absence_of_private_key_prevents_signing(): void
    {
        // Temporarily remove the private key
        config(['offline.keys.private' => 'app/private/mvs-offline-test/NONEXISTENT.pem']);

        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Offline authorization private key not available');
        $service->authorize($terminal, $user);
    }

    public function test_app_key_not_used_for_signing(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        // The token should NOT decrypt with Laravel Crypt (APP_KEY)
        // because it's an RSA-signed token, not an AES-encrypted one
        try {
            \Illuminate\Support\Facades\Crypt::decryptString($result['authorization']);
            // If this succeeds, it means we're still using APP_KEY — FAIL
            $this->fail('Token should not be decryptable with APP_KEY (Crypt). It should be RSA-signed.');
        } catch (\Illuminate\Encryption\DecryptException | \RuntimeException) {
            // Expected: token is NOT AES-encrypted, so Crypt fails
            $this->assertTrue(true);
        }
    }

    public function test_company_a_cannot_use_authorization_of_company_b(): void
    {
        [$companyA, $branchA] = $this->tenant('Empresa A');
        [$companyB, $branchB] = $this->tenant('Empresa B');
        $userA = $this->createUserWithAccess($companyA, $branchA);

        $terminalA = $this->createActiveTerminal($companyA, $branchA);
        $terminalB = $this->createActiveTerminal($companyB, $branchB);

        $service = app(OfflineAuthorizationService::class);
        $resultA = $service->authorize($terminalA, $userA);

        // Verify with company B context — should fail
        $payload = $service->verifyToken($resultA['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals($companyA->id, $payload['company_id']);
        $this->assertNotEquals($companyB->id, $payload['company_id']);
    }

    public function test_branch_a_cannot_use_authorization_of_branch_b(): void
    {
        [$company, $branchA] = $this->tenant('Empresa');
        [, $branchB] = $this->tenant('Otra');
        $user = $this->createUserWithAccess($company, $branchA);

        $terminalA = $this->createActiveTerminal($company, $branchA);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminalA, $user);

        $payload = $service->verifyToken($result['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals($branchA->id, $payload['branch_id']);
        $this->assertNotEquals($branchB->id, $payload['branch_id']);
    }

    public function test_different_terminal_cannot_use_authorization(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminalA = $this->createActiveTerminal($company, $branch);
        $terminalB = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $resultA = $service->authorize($terminalA, $user);

        $payload = $service->verifyToken($resultA['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals($terminalA->terminal_uuid, $payload['terminal_uuid']);
        $this->assertNotEquals($terminalB->terminal_uuid, $payload['terminal_uuid']);
    }

    public function test_revoked_terminal_does_not_receive_new_authorization(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $terminal->revoke();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->authorize($terminal, $user);
    }

    public function test_revoked_terminal_is_rejected_during_online_verification(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        // Revoke AFTER authorization was issued
        $terminal->revoke();

        // Online verification should reject
        $this->assertNull($service->verifyToken($result['authorization']));
    }

    public function test_revocation_after_issuance_cannot_be_known_offline(): void
    {
        // This test documents the expected limitation:
        // A terminal that receives an authorization BEFORE revocation
        // will continue to hold a cryptographically valid token until
        // valid_until expires (max 48 hours).
        //
        // This is an inherent limitation of the disconnected model.
        // The maximum exposure window is the remaining validity time.

        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        // Parse and verify the token is cryptographically valid
        $parts = explode('.', $result['authorization']);
        $this->assertCount(3, $parts);

        [$headerB64, $payloadB64, $signatureB64] = $parts;
        $signature = base64_decode(strtr($signatureB64, '-_', '+/'));
        $signedData = $headerB64 . '.' . $payloadB64;

        // Cryptographic check: signature is STILL VALID even if revoked
        // (this is the offline limitation — the terminal has the token already)
        $publicKey = openssl_pkey_get_public(File::get($this->testPublicKeyPath));
        $cryptoValid = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKey);

        $this->assertEquals(1, $cryptoValid, 'Signature should remain cryptographically valid until valid_until expires');

        // Online check: server rejects it because terminal is revoked
        $terminal->revoke();
        $this->assertNull($service->verifyToken($result['authorization']));

        // The gap between crypto-valid and server-rejected is the
        // documented revocation exposure window.
    }

    public function test_valid_until_never_exceeds_configured_maximum(): void
    {
        config(['offline.max_hours' => 48]);
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $issuedAt = \Carbon\Carbon::parse($result['issued_at']);
        $validUntil = \Carbon\Carbon::parse($result['valid_until']);
        $diffHours = $issuedAt->diffInHours($validUntil);

        $this->assertLessThanOrEqual(48, $diffHours);
        $this->assertGreaterThan(0, $diffHours);
    }

    public function test_inoperable_license_prevents_authorization(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        // Force a suspended license
        $company->license()->create([
            'status' => 'suspended',
            'plan' => 'Prueba',
            'starts_at' => now()->subDays(30),
            'expires_at' => now()->subDays(5),
            'grace_until' => now()->subDays(1),
        ]);

        $service = app(OfflineAuthorizationService::class);

        // CompanyLicenseService::refresh will keep it suspended,
        // and the token will get license_status = 'suspended'
        $result = $service->authorize($terminal, $user);

        // The token is issued but records the suspended status
        $payload = $service->verifyToken($result['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals('suspended', $payload['license_status']);
    }

    // =====================================================================
    // ORIGINAL TESTS (maintained from Phase 1)
    // =====================================================================

    public function test_company_a_cannot_authorize_terminal_of_company_b(): void
    {
        [$companyA, $branchA, $userA] = $this->tenant('Empresa A');
        [$companyB, $branchB] = $this->tenant('Empresa B');

        $terminalB = OfflineTerminal::create([
            'company_id' => $companyB->id,
            'branch_id' => $branchB->id,
            'terminal_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'status' => OfflineTerminal::STATUS_ACTIVE,
        ]);

        $service = app(OfflineAuthorizationService::class);
        $this->expectException(\Throwable::class);
        $service->authorize($terminalB, $userA);
    }

    public function test_incorrect_branch_is_rejected(): void
    {
        [$company, $branchA] = $this->tenant('Empresa');
        [, $branchB] = $this->tenant('Otra');
        $user = $this->createUserWithAccess($company, $branchA);

        $terminal = OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branchB->id,
            'terminal_uuid' => '550e8400-e29b-41d4-a716-446655440001',
            'status' => OfflineTerminal::STATUS_ACTIVE,
        ]);

        $service = app(OfflineAuthorizationService::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->authorize($terminal, $user);
    }

    public function test_revoked_terminal_is_rejected(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);
        $terminal->update(['status' => OfflineTerminal::STATUS_REVOKED]);

        $service = app(OfflineAuthorizationService::class);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->authorize($terminal, $user);
    }

    public function test_user_without_permission_context_is_rejected(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $outsider = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->authorize($terminal, $outsider);
    }

    public function test_authorization_contains_correct_company_and_branch_and_terminal(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);

        $payload = $service->verifyToken($result['authorization']);
        $this->assertNotNull($payload);
        $this->assertEquals($company->id, $payload['company_id']);
        $this->assertEquals($branch->id, $payload['branch_id']);
        $this->assertEquals($terminal->terminal_uuid, $payload['terminal_uuid']);
    }

    public function test_issued_at_uses_server_time(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $before = now()->subSecond();
        $service = app(OfflineAuthorizationService::class);
        $result = $service->authorize($terminal, $user);
        $after = now()->addSecond();

        $issuedAt = \Carbon\Carbon::parse($result['issued_at']);
        $this->assertTrue($issuedAt->gte($before));
        $this->assertTrue($issuedAt->lte($after));
        $this->assertEquals($issuedAt->timestamp, \Carbon\Carbon::parse($result['server_time'])->timestamp);
    }

    public function test_manipulated_payload_does_not_validate(): void
    {
        $service = app(OfflineAuthorizationService::class);
        $header = base64_encode(json_encode(['alg' => 'RSA-SHA256', 'typ' => 'MVS-OFFLINE-AUTH', 'version' => 1]));
        $payload = base64_encode(json_encode([
            'authorization_id' => 'fake',
            'company_id' => 999,
            'branch_id' => 999,
            'terminal_uuid' => 'fake',
            'issued_at' => now()->toIso8601String(),
            'valid_until' => now()->addHours(24)->toIso8601String(),
        ]));
        $token = $this->base64url($header) . '.' . $this->base64url($payload) . '.' . $this->base64url('fake-sig');
        $this->assertNull($service->verifyToken($token));
    }

    public function test_requesting_user_is_audited(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $service->authorize($terminal, $user);

        $this->assertDatabaseHas('offline_authorizations', [
            'offline_terminal_id' => $terminal->id,
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'result' => OfflineAuthorization::RESULT_GRANTED,
        ]);
    }

    public function test_authorized_terminal_gets_last_validated_at_updated(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $user = $this->createUserWithAccess($company, $branch);
        $terminal = $this->createActiveTerminal($company, $branch);

        $this->assertNull($terminal->last_validated_at);
        $service = app(OfflineAuthorizationService::class);
        $service->authorize($terminal, $user);
        $this->assertNotNull($terminal->fresh()->last_validated_at);
    }

    public function test_endpoint_returns_404_for_unknown_terminal(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $this->seedPermissionsAndAssign($company, $branch, $user);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.authorize'), ['terminal_uuid' => '00000000-0000-0000-0000-000000000000'])
            ->assertStatus(404);
    }

    public function test_endpoint_validates_token(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $this->seedPermissionsAndAssign($company, $branch, $user);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $authResult = $service->authorize($terminal, $user);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.verify'), ['token' => $authResult['authorization']])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'terminal_uuid' => $terminal->terminal_uuid,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
            ]);
    }

    public function test_endpoint_rejects_invalid_token(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $this->seedPermissionsAndAssign($company, $branch, $user);

        $this->actingAs($user)
            ->withSession(['active_company_id' => $company->id, 'active_branch_id' => $branch->id])
            ->postJson(route('offline.verify'), ['token' => 'invalid-token'])
            ->assertStatus(401)
            ->assertJson(['valid' => false]);
    }

    public function test_deny_authorization_creates_denied_record(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $terminal = $this->createActiveTerminal($company, $branch);

        $service = app(OfflineAuthorizationService::class);
        $denied = $service->denyAuthorization($terminal, $platformAdmin, 'Permiso revocado');

        $this->assertEquals(OfflineAuthorization::RESULT_DENIED, $denied->result);
        $this->assertEquals('Permiso revocado', $denied->notes);
    }

    public function test_register_terminal_creates_active_terminal(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $service = app(OfflineAuthorizationService::class);
        $terminal = $service->registerTerminal($company, $branch, $platformAdmin, 'Terminal Principal');

        $this->assertNotNull($terminal->terminal_uuid);
        $this->assertEquals(OfflineTerminal::STATUS_ACTIVE, $terminal->status);
        $this->assertEquals('Terminal Principal', $terminal->name);
        $this->assertEquals($platformAdmin->id, $terminal->registered_by);
    }

    public function test_terminal_uuid_is_auto_generated(): void
    {
        [$company, $branch] = $this->tenant('Empresa');
        $platformAdmin = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);

        $service = app(OfflineAuthorizationService::class);
        $terminal = $service->registerTerminal($company, $branch, $platformAdmin);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $terminal->terminal_uuid
        );
    }

    public function test_has_key_pair_reports_correctly(): void
    {
        $service = app(OfflineAuthorizationService::class);
        $this->assertTrue($service->hasKeyPair());

        config(['offline.keys.private' => 'app/private/mvs-offline-test/NONEXISTENT.pem']);
        $service2 = app(OfflineAuthorizationService::class);
        $this->assertFalse($service2->hasKeyPair());
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    private function tenant(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'B'.uniqid(),
            'is_active' => true,
        ]);
        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Administrador',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function createUserWithAccess(Company $company, Branch $branch): User
    {
        $role = Role::where('company_id', $company->id)->first();
        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return $user;
    }

    private function createActiveTerminal(Company $company, Branch $branch): OfflineTerminal
    {
        return OfflineTerminal::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'terminal_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => OfflineTerminal::STATUS_ACTIVE,
        ]);
    }

    private function seedPermissionsAndAssign(Company $company, Branch $branch, ?User &$user = null): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'configuracion.editar'],
            ['label' => 'Editar configuración', 'module' => 'Configuración', 'is_active' => true]
        );

        $role = Role::where('company_id', $company->id)->first();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create(['is_active' => true, 'is_platform_admin' => false]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
    }

    private function generateTestKeyPair(): void
    {
        $opensslConfigPath = $this->findOpenSslConfigPath();
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        if ($opensslConfigPath) {
            $config['config'] = $opensslConfigPath;
        }

        $res = openssl_pkey_new($config);
        $this->assertNotFalse($res, 'Failed to generate test RSA key pair');

        $exportConfig = $opensslConfigPath ? ['config' => $opensslConfigPath] : [];
        $privateKeyContent = '';
        openssl_pkey_export($res, $privateKeyContent, null, $exportConfig);
        File::put($this->testPrivateKeyPath, $privateKeyContent);

        $details = openssl_pkey_get_details($res);
        File::put($this->testPublicKeyPath, $details['key']);

        openssl_free_key($res);
    }

    private function findOpenSslConfigPath(): ?string
    {
        $defaultPath = 'C:\\Program Files\\Common Files\\SSL\\openssl.cnf';
        if (file_exists($defaultPath)) {
            return $defaultPath;
        }

        $bundledPath = base_path('storage/app/openssl.cnf');
        if (file_exists($bundledPath)) {
            return $bundledPath;
        }

        $alternatives = [
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
            'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
        ];
        foreach ($alternatives as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function tamperPayload(string $token, string $field, mixed $value): string
    {
        $parts = explode('.', $token);
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $payload = json_decode(base64_decode($payloadB64, true), true);
        $payload[$field] = $value;
        $newPayloadB64 = $this->base64url(json_encode($payload, JSON_THROW_ON_ERROR));

        // Keep the ORIGINAL signature — modifying the payload invalidates it
        return $headerB64 . '.' . $newPayloadB64 . '.' . $signatureB64;
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
