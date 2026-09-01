<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Notifications\TenantOwnerInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class M04TenantOwnerInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_secure_invitation_and_activation_enables_only_tenant_access(): void
    {
        Notification::fake();
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($platform)->post(route('platform.companies.store'), $this->payload())->assertRedirect();
        $owner = User::where('email', 'owner@invite.test')->firstOrFail();

        Notification::assertSentTo($owner, TenantOwnerInvitation::class);
        $this->assertNotNull($owner->tenant_invited_at);
        $this->assertFalse($owner->is_active);

        $token = Password::broker()->createToken($owner);
        $this->post(route('logout'));
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $owner->email,
            'password' => 'SeguraOwner9', 'password_confirmation' => 'SeguraOwner9',
        ])->assertRedirect(route('login'));

        $owner->refresh();
        $this->assertTrue($owner->is_active);
        $this->assertNotNull($owner->tenant_activated_at);
        $this->assertFalse($owner->is_platform_admin);
        $this->actingAs($owner)->get(route('platform.index'))->assertForbidden();
    }

    public function test_invalid_activation_token_does_not_activate_owner(): void
    {
        Notification::fake();
        Permission::create(['name' => 'dashboard.ver', 'label' => 'Dashboard', 'module' => 'Dashboard', 'is_active' => true]);
        $platform = User::factory()->create(['is_active' => true, 'is_platform_admin' => true]);
        $this->actingAs($platform)->post(route('platform.companies.store'), $this->payload());
        $owner = User::where('email', 'owner@invite.test')->firstOrFail();
        $this->post(route('logout'));

        $this->post(route('password.update'), [
            'token' => 'invalid', 'email' => $owner->email,
            'password' => 'SeguraOwner9', 'password_confirmation' => 'SeguraOwner9',
        ])->assertSessionHasErrors('email');
        $this->assertFalse($owner->fresh()->is_active);
    }

    public function test_invitation_email_renders_the_public_mvs_logo_and_keeps_current_content(): void
    {
        config(['app.url' => 'https://mvs-commerce.test']);
        $owner = User::factory()->create(['name' => 'Owner Invitado', 'email' => 'owner-render@invite.test']);
        $mail = (new TenantOwnerInvitation('secure-render-token'))->toMail($owner);
        $html = (string) $mail->render();

        $this->assertSame('Active su acceso a MVS Commerce', $mail->subject);
        $this->assertMatchesRegularExpression('#https://[^" ]+/images/logo-mvs-email\.png#', $html);
        $this->assertStringContainsString('alt="MVS Commerce"', $html);
        $this->assertStringContainsString('width="180"', $html);
        $this->assertFileExists(public_path('images/logo-mvs-email.png'));
        $this->assertLessThan(100_000, filesize(public_path('images/logo-mvs-email.png')));
        $this->assertStringNotContainsString('Laravel Logo', $html);
        $this->assertStringContainsString('Hola Owner Invitado', $html);
        $this->assertStringContainsString('MVS creó el acceso comercial de su empresa.', $html);
        $this->assertStringContainsString('Defina una contraseña segura para activar su cuenta y completar el onboarding de su tenant.', $html);
        $this->assertStringContainsString('Activar mi acceso', $html);
        $this->assertStringContainsString('secure-render-token', $html);
        $this->assertStringContainsString('Este enlace es personal, expirable y de un solo uso.', $html);
    }

    private function payload(): array
    {
        return [
            'trade_name' => 'Tenant invitado', 'owner' => ['name' => 'Owner', 'email' => 'owner@invite.test'],
            'plan' => 'Inicial', 'branch_limit' => 1, 'status' => 'trial', 'modules' => ['sales'],
        ];
    }
}
