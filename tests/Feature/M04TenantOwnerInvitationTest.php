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

    private function payload(): array
    {
        return [
            'trade_name' => 'Tenant invitado', 'owner' => ['name' => 'Owner', 'email' => 'owner@invite.test'],
            'plan' => 'Inicial', 'branch_limit' => 1, 'status' => 'trial', 'modules' => ['sales'],
        ];
    }
}
