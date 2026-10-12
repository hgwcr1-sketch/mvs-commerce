<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosLogout01Test extends TestCase
{
    use RefreshDatabase;

    public function test_logout_redirects_to_public_inicio_and_blocks_protected_pages(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)
            ->post(route('cobros.logout'))
            ->assertRedirect(route('cobros.pwa.inicio'));
        $this->assertGuest();

        $this->get(route('cobros.index'))->assertRedirect(route('cobros.login'));
        $this->get(route('cobros.pwa.inicio'))->assertOk();
    }
}
