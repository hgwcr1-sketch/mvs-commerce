<?php

namespace Tests\Feature;

use App\Models\CobrosRegistrationDraft;
use App\Notifications\RegistrationDraftRecovery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CobrosRegistrationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function completeDraft(string $email = 'borrador@test.com'): void
    {
        $this->get('/bienvenida-cobros?ref=REF-abc_12345')->assertOk();

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Draft Owner',
            'email' => $email,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Persistida',
            'code' => 'RP',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));
    }

    public function test_progress_is_persisted_in_a_single_upserted_draft(): void
    {
        $this->completeDraft();

        $this->assertSame(1, CobrosRegistrationDraft::count());
        $this->assertNotNull(session('cobros.registration')['_draft_id']);

        $draft = CobrosRegistrationDraft::firstOrFail();
        $payload = $draft->payload;

        $this->assertSame('CR', $payload['country_iso']);
        $this->assertSame('prestamista', $payload['modality']);
        $this->assertSame('Draft Owner', $payload['personal']['name']);
        $this->assertSame('borrador@test.com', $payload['personal']['email']);
        $this->assertSame('manual', $payload['portfolio_plan']);
        $this->assertSame('Ruta Persistida', $payload['routes'][0]['name']);
        $this->assertSame('REF-abc_12345', $payload['referral']['code']);
        $this->assertNotNull($draft->last_saved_at);

        $this->post(route('cobros.register.routes.save'), [
            'route_id' => $payload['routes'][0]['id'],
            'name' => 'Ruta Persistida Editada',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->assertSame(1, CobrosRegistrationDraft::count(), 'El upsert no debe duplicar borradores');
        $this->assertCount(1, CobrosRegistrationDraft::firstOrFail()->payload['routes']);
        $this->assertSame('Ruta Persistida Editada', CobrosRegistrationDraft::firstOrFail()->payload['routes'][0]['name']);
        $this->assertGuest();
    }

    public function test_back_and_correction_keep_one_draft_and_stable_routes(): void
    {
        $this->completeDraft();

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CO',
            'modality' => 'cobrador_independiente',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Draft Owner Corregido',
            'email' => 'borrador@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->assertSame(1, CobrosRegistrationDraft::count());
        $payload = CobrosRegistrationDraft::firstOrFail()->payload;
        $this->assertSame('CO', $payload['country_iso']);
        $this->assertSame('Draft Owner Corregido', $payload['personal']['name']);
        $this->assertCount(1, $payload['routes'], 'Corregir no debe perder ni duplicar rutas');
        $this->assertSame('REF-abc_12345', $payload['referral']['code']);
        $this->assertSame(1, CobrosRegistrationDraft::count());
    }

    public function test_recovery_link_restores_the_draft_after_session_loss(): void
    {
        Notification::fake();
        $this->completeDraft();

        $this->flushSession();
        $this->assertNull(session('cobros.registration'));

        $this->post(route('cobros.register.recover.store'), ['email' => 'borrador@test.com'])
            ->assertRedirect(route('cobros.register.recover'));

        $token = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertIsString($token);

        $this->get(route('cobros.register.recover.token', ['token' => $token]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $registration = session('cobros.registration');
        $this->assertSame('Draft Owner', $registration['personal']['name']);
        $this->assertSame('borrador@test.com', $registration['personal']['email']);
        $this->assertSame('manual', $registration['portfolio_plan']);
        $this->assertCount(1, $registration['routes']);
        $this->assertSame('REF-abc_12345', session('cobros.referral')['code']);
        $this->assertGuest();
    }

    public function test_recovery_token_is_single_use_and_expires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:00:00'));
        Notification::fake();
        $this->completeDraft();

        $this->post(route('cobros.register.recover.store'), ['email' => 'borrador@test.com']);
        $first = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$first): bool {
            $first = $notification->token;

            return true;
        });

        $this->get(route('cobros.register.recover.token', ['token' => $first]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $this->flushSession();
        $this->get(route('cobros.register.recover.token', ['token' => $first]))
            ->assertRedirect(route('cobros.register.recover'));
        $this->assertNotNull(session('recover_error'));
        $this->assertNull(session('cobros.registration'), 'La reutilización no debe restaurar nada');

        $this->post(route('cobros.register.recover.store'), ['email' => 'borrador@test.com']);
        $second = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$second): bool {
            $second = $notification->token;

            return true;
        });

        Carbon::setTestNow(Carbon::parse('2026-10-09 11:01:00'));
        $this->get(route('cobros.register.recover.token', ['token' => $second]))
            ->assertRedirect(route('cobros.register.recover'));
        $this->assertNotNull(session('recover_error'));
        $this->assertNull(session('cobros.registration'), 'El token caducado no debe restaurar nada');
    }

    public function test_recovery_request_is_generic_and_link_contains_no_personal_data(): void
    {
        Notification::fake();

        $this->post(route('cobros.register.recover.store'), ['email' => 'nadie@test.com'])
            ->assertRedirect(route('cobros.register.recover'));
        $generic = session('recover_status');
        $this->assertNotNull($generic);
        Notification::assertNothingSent();

        $this->completeDraft();

        $this->post(route('cobros.register.recover.store'), ['email' => 'borrador@test.com'])
            ->assertRedirect(route('cobros.register.recover'));
        $this->assertSame($generic, session('recover_status'), 'La respuesta debe ser idéntica exista o no el borrador');

        $token = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/', $token);
        $url = route('cobros.register.recover.token', ['token' => $token]);
        $this->assertStringNotContainsString('borrador@test.com', $url);
        $this->assertStringNotContainsString('Draft Owner', $url);

        $draft = CobrosRegistrationDraft::firstOrFail();
        $this->assertSame(hash('sha256', $token), $draft->token_hash);
        $this->assertStringNotContainsString($token, json_encode($draft->payload));
        $this->assertArrayNotHasKey('password', $draft->payload);
        $this->assertArrayNotHasKey('password', $draft->payload['personal'] ?? []);
    }

    public function test_drafts_create_no_operational_entities_or_access(): void
    {
        $this->completeDraft();

        $this->assertDatabaseCount('cobros_registration_drafts', 1);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_licenses', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('cobros_credits', 0);
        $this->assertDatabaseCount('accounts_receivable', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertGuest();

        $this->get(route('cobros.register.step', ['paso' => 4]))->assertOk();
        $this->get(route('cobros.register.recover'))->assertOk();
    }
}
