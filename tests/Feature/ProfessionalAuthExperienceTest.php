<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ProfessionalAuthExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_preserves_active_user_authentication_and_session_security(): void
    {
        $user = User::factory()->create(['email' => 'active@example.test', 'password' => Hash::make('Secure123'), 'is_active' => true]);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure123', 'remember' => 'on']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_rejects_invalid_credentials_and_inactive_users(): void
    {
        $active = User::factory()->create(['email' => 'active@example.test', 'password' => Hash::make('Secure123'), 'is_active' => true]);
        $inactive = User::factory()->create(['email' => 'inactive@example.test', 'password' => Hash::make('Secure123'), 'is_active' => false]);

        $this->from(route('login'))->post(route('login.store'), ['email' => $active->email, 'password' => 'Wrong123'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->from(route('login'))->post(route('login.store'), ['email' => $inactive->email, 'password' => 'Secure123'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_recovery_sends_the_existing_secure_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'recover@example.test', 'is_active' => true]);

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_password_reset_preserves_the_existing_strength_rules(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test', 'password' => Hash::make('OldSecure1')]);
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecure9',
            'password_confirmation' => 'NewSecure9',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewSecure9', $user->fresh()->password));
    }

    public function test_login_recovery_and_reset_share_professional_mobile_first_shell(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('data-auth-shell', false)
            ->assertSee('Profesional por dentro.')->assertSee('min-h-11', false)
            ->assertSee('sm:px-8', false)->assertSee('lg:grid-cols-', false);
        $this->get(route('password.request'))->assertOk()->assertSee('Recupere su acceso')->assertSee('data-auth-shell', false);
        $this->get(route('password.reset', ['token' => 'test-token', 'email' => 'user@example.test']))
            ->assertOk()->assertSee('Cree una nueva contraseña')->assertSee('data-auth-shell', false);
    }
}
