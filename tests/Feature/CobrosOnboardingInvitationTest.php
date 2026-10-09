<?php

namespace Tests\Feature;

use App\Models\CobrosLaborInvitation;
use App\Models\CobrosRegistrationDraft;
use App\Models\Company;
use App\Models\User;
use App\Notifications\RegistrationDraftRecovery;
use App\Services\Cobros\CobrosRegistrationActivationService;
use App\Services\Cobros\LaborInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BLOQUE 16C-2B f1 — invitaciones laborales dentro del onboarding público.
 *
 * Cubre: enlace válido que entra al registro sin consumir la invitación,
 * vigencia (vencida/revocada), destinatario, prestamista, reanudación del
 * borrador, separación respecto de la referencia comercial ?ref= y aislamiento
 * de una sola cuenta de cobrador con varios prestamistas (carteras sin mezclar).
 */
class CobrosOnboardingInvitationTest extends TestCase
{
    use RefreshDatabase;

    private LaborInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LaborInvitationService::class);
    }

    /** Empresa + prestamista reales, igual que el motor 16C-2A. */
    private function lender(string $email): array
    {
        $lender = User::factory()->create(['is_active' => true, 'email' => $email]);
        $company = Company::create([
            'trade_name' => 'Prestamista '.explode('@', $email)[0],
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        return [$company, $lender];
    }

    private function url(string $plain): string
    {
        return route('cobros.register.invitation', ['token' => $plain]);
    }

    /** Registro completo hasta la revisión (paso 4). */
    private function completeDraft(string $email, string $modality, array $route = []): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => $modality,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Cobrador Invitado',
            'email' => $email,
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), array_merge([
            'name' => 'Ruta Norte',
            'code' => 'RN',
        ], $route))->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));
    }

    public function test_valid_link_enters_registration_shows_lender_and_does_not_consume(): void
    {
        [$company, $lender] = $this->lender('presta@invitacion.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@invitacion.test');
        $invitation = $issued['invitation'];

        $this->get($this->url($issued['plain']))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $registration = session('cobros.registration');
        $this->assertSame($invitation->id, $registration['labor_invitation']['id']);
        $this->assertSame($invitation->token_hash, $registration['labor_invitation']['token_hash']);
        $this->assertSame($lender->email, $registration['labor_invitation']['invited_by']);
        $this->assertSame('cobrador_vinculado', $registration['modality']);

        // Nunca se guarda el token en claro ni se marca la invitación como usada.
        $this->assertStringNotContainsString($issued['plain'], json_encode($registration));
        $this->assertNull($invitation->fresh()->used_at);

        $this->get(route('cobros.register.step', ['paso' => 1]))
            ->assertOk()
            ->assertSee($lender->name)
            ->assertSee($company->trade_name)
            ->assertDontSee($issued['plain'])
            ->assertDontSee($invitation->token_hash)
            ->assertDontSee($lender->email);

        $this->assertNull(CobrosRegistrationDraft::first());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_expired_link_is_rejected_without_storing_any_reference(): void
    {
        [$company, $lender] = $this->lender('presta@vencida.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@vencida.test', 30);

        $this->travel(31)->minutes();

        $this->get($this->url($issued['plain']))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->assertNotNull(session('invitation_error'));
        $this->assertNull(session('cobros.registration'));
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }

    public function test_revoked_link_is_rejected(): void
    {
        [$company, $lender] = $this->lender('presta@revocada.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@revocada.test');
        $this->service->revoke($company, $lender, $issued['invitation']->id);

        $this->get($this->url($issued['plain']))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->assertNotNull(session('invitation_error'));
        $this->assertNull(session('cobros.registration'));
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }

    public function test_wrong_recipient_blocks_activation_preparation(): void
    {
        [$company, $lender] = $this->lender('presta@destinatario.test');
        $issued = $this->service->issue($company, $lender, 'invitado@destinatario.test');

        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('otro@destinatario.test', 'cobrador_vinculado');

        $errors = app(CobrosRegistrationActivationService::class)
            ->validate(CobrosRegistrationDraft::firstOrFail());

        $this->assertArrayHasKey('labor_invitation', $errors);
        $this->assertStringContainsString('otro correo electrónico', $errors['labor_invitation']);
        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_revoked_invitation_blocks_activation_preparation(): void
    {
        [$company, $lender] = $this->lender('presta@revoca-activacion.test');
        $issued = $this->service->issue($company, $lender, 'invitado@revoca-activacion.test');

        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('invitado@revoca-activacion.test', 'cobrador_vinculado');

        $this->service->revoke($company, $lender, $issued['invitation']->id);

        $errors = app(CobrosRegistrationActivationService::class)
            ->validate(CobrosRegistrationDraft::firstOrFail());

        $this->assertArrayHasKey('labor_invitation', $errors);
        $this->assertStringContainsString('revocada', $errors['labor_invitation']);

        try {
            app(CobrosRegistrationActivationService::class)
                ->assertReady(CobrosRegistrationDraft::firstOrFail());
            $this->fail('La invitación revocada debió impedir la preparación de activación.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('labor_invitation', $exception->errors());
        }

        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('cobros_routes', 0);
    }

    public function test_missing_lender_of_record_blocks_activation_preparation(): void
    {
        [$company, $lender] = $this->lender('presta@prestamista.test');
        $issued = $this->service->issue($company, $lender, 'invitado@prestamista.test');

        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('invitado@prestamista.test', 'cobrador_vinculado');

        // El correo del prestamista en el borrador deja de coincidir con el emisor real.
        $draft = CobrosRegistrationDraft::firstOrFail();
        $payload = $draft->payload;
        $payload['labor_invitation']['invited_by'] = 'otro@prestamista.test';
        $draft->update(['payload' => $payload]);

        $errors = app(CobrosRegistrationActivationService::class)->validate($draft->refresh());

        $this->assertArrayHasKey('labor_invitation', $errors);
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }

    public function test_reference_survives_steps_and_resume_while_referral_stays_separate(): void
    {
        Notification::fake();
        [$company, $lender] = $this->lender('presta@reanudacion.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@reanudacion.test');

        $this->get('/bienvenida-cobros?ref=REF-abc_12345')->assertOk();
        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('cobrador@reanudacion.test', 'cobrador_vinculado');

        // Las dos referencias viven en claves distintas: no se pisan entre sí.
        $this->assertSame('REF-abc_12345', session('cobros.referral')['code']);
        $this->assertSame($issued['invitation']->id, session('cobros.registration')['labor_invitation']['id']);

        $payload = CobrosRegistrationDraft::firstOrFail()->payload;
        $this->assertSame($issued['invitation']->id, $payload['labor_invitation']['id']);
        $this->assertSame('REF-abc_12345', $payload['referral']['code']);
        $this->assertStringNotContainsString($issued['plain'], json_encode($payload));

        $this->flushSession();
        $this->assertNull(session('cobros.registration'));

        $this->post(route('cobros.register.recover.store'), ['email' => 'cobrador@reanudacion.test'])
            ->assertRedirect(route('cobros.register.recover'));

        $token = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->get(route('cobros.register.recover.token', ['token' => $token]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $registration = session('cobros.registration');
        $this->assertSame($issued['invitation']->id, $registration['labor_invitation']['id']);
        $this->assertSame($issued['invitation']->token_hash, $registration['labor_invitation']['token_hash']);
        $this->assertSame('REF-abc_12345', session('cobros.referral')['code']);
        $this->assertNull(session('invitation_warning'), 'La invitación sigue vigente tras reanudar.');

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertOk()
            ->assertSee($lender->name)
            ->assertDontSee($issued['plain']);

        $this->assertSame([], app(CobrosRegistrationActivationService::class)
            ->validate(CobrosRegistrationDraft::firstOrFail()));
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }

    public function test_revoked_invitation_is_announced_on_resume_without_losing_the_reference(): void
    {
        Notification::fake();
        [$company, $lender] = $this->lender('presta@aviso.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@aviso.test');

        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('cobrador@aviso.test', 'cobrador_vinculado');

        $this->service->revoke($company, $lender, $issued['invitation']->id);
        $this->flushSession();

        $this->post(route('cobros.register.recover.store'), ['email' => 'cobrador@aviso.test']);
        $token = null;
        Notification::assertSentOnDemand(RegistrationDraftRecovery::class, function ($notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->get(route('cobros.register.recover.token', ['token' => $token]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        // Se conserva la referencia y se avisa que dejó de estar vigente.
        $this->assertSame($issued['invitation']->id, session('cobros.registration')['labor_invitation']['id']);
        $this->assertNotNull(session('invitation_warning'));
        $this->assertStringContainsString('revocada', session('invitation_warning'));

        $errors = app(CobrosRegistrationActivationService::class)
            ->validate(CobrosRegistrationDraft::firstOrFail());
        $this->assertArrayHasKey('labor_invitation', $errors);
    }

    public function test_collector_with_two_lenders_keeps_one_account_and_separate_portfolios(): void
    {
        [$companyA, $lenderA] = $this->lender('presta.a@multi.test');
        [$companyB, $lenderB] = $this->lender('presta.b@multi.test');
        $issuedA = $this->service->issue($companyA, $lenderA, 'cobrador@multi.test');
        $issuedB = $this->service->issue($companyB, $lenderB, 'cobrador@multi.test');

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'cobrador_multiprestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));
        $this->post(route('cobros.register.personal'), [
            'name' => 'Cobrador Multiprestamista',
            'email' => 'cobrador@multi.test',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Norte', 'lender' => 'Prestamista Norte',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->post(route('cobros.register.routes.save'), [
            'name' => 'Ruta Sur', 'lender' => 'Prestamista Sur',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        // Primera invitación: se conserva sin tocar carteras ni modalidad.
        $this->get($this->url($issuedA['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 4]));
        $registration = session('cobros.registration');
        $this->assertSame($issuedA['invitation']->id, $registration['labor_invitation']['id']);
        $this->assertSame('cobrador_multiprestamista', $registration['modality']);

        // Segunda invitación: cambia la referencia del mismo borrador; nada más.
        $this->get($this->url($issuedB['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 4]));
        $registration = session('cobros.registration');

        $this->assertSame($issuedB['invitation']->id, $registration['labor_invitation']['id']);
        $this->assertSame('cobrador_multiprestamista', $registration['modality']);
        $this->assertCount(2, $registration['routes']);
        $this->assertSame('Prestamista Norte', $registration['routes'][0]['lender']);
        $this->assertSame('Prestamista Sur', $registration['routes'][1]['lender']);

        // Una sola cuenta/borrador; ninguna entidad operativa ni cartera mezclada.
        $this->assertSame(1, CobrosRegistrationDraft::count());
        $this->assertSame(0, User::where('email', 'cobrador@multi.test')->count());
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('cobros_assignments', 0);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('companies', 2);

        // Ninguna de las dos invitaciones se consume durante el registro.
        $this->assertNull($issuedA['invitation']->fresh()->used_at);
        $this->assertNull($issuedB['invitation']->fresh()->used_at);

        // Cada prestamista sigue aislado: revocar la de A no afecta la de B.
        $this->service->revoke($companyA, $lenderA, $issuedA['invitation']->id);
        $this->assertNull($issuedB['invitation']->fresh()->revoked_at);
        $this->assertSame([], app(CobrosRegistrationActivationService::class)
            ->validate(CobrosRegistrationDraft::firstOrFail()));
    }

    public function test_registration_leaves_the_invitation_available_for_final_consumption(): void
    {
        [$company, $lender] = $this->lender('presta@consumo.test');
        $issued = $this->service->issue($company, $lender, 'cobrador@consumo.test');

        $this->get($this->url($issued['plain']))->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->completeDraft('cobrador@consumo.test', 'cobrador_vinculado');

        $this->assertNull($issued['invitation']->fresh()->used_at, 'El registro no consume la invitación.');

        // Solo entonces (fase de activación real) puede consumirse un solo uso.
        $validated = $this->service->validate($company, $lender, 'cobrador@consumo.test', $issued['plain']);
        $this->assertSame($issued['invitation']->id, $validated->id);
        $this->assertNotNull($validated->used_at);
    }

    public function test_tampered_or_unknown_tokens_never_create_a_reference(): void
    {
        [, $outsider] = $this->lender('otro@ajeno.test');

        $this->get($this->url(str_repeat('a', 64)))
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->assertNotNull(session('invitation_error'));
        $this->assertNull(session('cobros.registration'));
        $this->assertNotNull($outsider);
        $this->assertSame(0, CobrosLaborInvitation::count());
    }
}
