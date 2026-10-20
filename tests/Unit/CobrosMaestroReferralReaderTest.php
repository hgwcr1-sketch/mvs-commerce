<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\MaestroReferralReader;
use App\Contracts\Cobros\MaestroReferralCredentialProvider;
use Mvs\MaestroClient\MaestroPlatformClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CobrosMaestroReferralReaderTest extends TestCase
{
    public function test_reader_is_disabled_and_returns_empty_verified_shape_by_default(): void
    {
        config(['maestro.referrals.enabled' => false]);
        $data = app(MaestroReferralReader::class)->read(new User, new Company);

        $this->assertFalse($data['available']);
        $this->assertNull($data['link']);
        $this->assertSame([], $data['referrals']);
        $this->assertNull($data['credit']);
    }

    public function test_authenticated_user_without_authorized_credential_fails_closed(): void
    {
        config(['maestro.referrals.enabled' => true]);
        $this->app->bind(MaestroReferralCredentialProvider::class, fn () => new class implements MaestroReferralCredentialProvider {
            public function for(User $user, Company $company): ?string { return null; }
        });

        $data = app(MaestroReferralReader::class)->read(new User(['id' => 10]), new Company(['id' => 20]));

        $this->assertFalse($data['available']);
        $this->assertSame([], $data['referrals']);
        $this->assertNull($data['link']);
    }

    #[DataProvider('maestroFailureStatuses')]
    public function test_maestro_http_failures_are_not_exposed(int $status): void
    {
        config(['maestro.referrals.enabled' => true]);
        $this->app->bind(MaestroReferralCredentialProvider::class, fn () => new class implements MaestroReferralCredentialProvider {
            public function for(User $user, Company $company): ?string { return 'jws-test'; }
        });
        $this->app->instance(MaestroPlatformClient::class, new MaestroPlatformClient('https://maestro.test', 'app-token'));
        Http::fake(['https://maestro.test/api/v1/referrals/me/binding' => Http::response(['data' => ['link' => 'must-not-leak']], $status)]);

        $data = app(MaestroReferralReader::class)->read(new User(['id' => 10]), new Company(['id' => 20]));

        $this->assertFalse($data['available']);
        $this->assertNull($data['link']);
        $this->assertSame([], $data['referrals']);
        $this->assertNull($data['credit']);
    }

    public static function maestroFailureStatuses(): array
    {
        return [[401], [403], [500]];
    }

    public function test_valid_binding_preserves_only_official_response_shape(): void
    {
        config(['maestro.referrals.enabled' => true]);
        $this->app->bind(MaestroReferralCredentialProvider::class, fn () => new class implements MaestroReferralCredentialProvider {
            public function for(User $user, Company $company): ?string { return 'jws-test'; }
        });
        $this->app->instance(MaestroPlatformClient::class, new MaestroPlatformClient('https://maestro.test', 'app-token'));
        Http::fake(['https://maestro.test/api/v1/referrals/me/binding' => Http::response(['data' => [
            'maestro_referral_attribution_id' => 'attr-1', 'referred_client_id' => 'client-1', 'maestro_installation_id' => 'install-1',
        ]], 200)]);

        $data = app(MaestroReferralReader::class)->read(new User(['id' => 10]), new Company(['id' => 20]));

        $this->assertTrue($data['available']);
        $this->assertNull($data['link']);
        $this->assertSame([], $data['referrals']);
        $this->assertNull($data['credit']);
        $this->assertArrayNotHasKey('company_id', $data);
    }
}
