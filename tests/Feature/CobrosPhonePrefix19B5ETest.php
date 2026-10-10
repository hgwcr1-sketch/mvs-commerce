<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPhonePrefix19B5ETest extends TestCase
{
    use RefreshDatabase;

    private function firstStep(string $iso = 'CR'): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => $iso,
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));
    }

    public function test_step_two_shows_country_dial_code_for_costa_rica_and_colombia(): void
    {
        $this->firstStep('CR');
        $this->get(route('cobros.register.step', ['paso' => 2]))
            ->assertOk()
            ->assertSee('+506')
            ->assertDontSee('+57');

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CO',
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->get(route('cobros.register.step', ['paso' => 2]))
            ->assertOk()
            ->assertSee('+57')
            ->assertDontSee('+506');
    }

    public function test_phone_is_validated_and_normalized_per_country(): void
    {
        // Costa Rica: 8 dígitos, con o sin prefijo escrito.
        $this->firstStep('CR');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Tica Número',
            'email' => 'tica5e@test.com',
            'phone' => '+506 8888-1111',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->assertSame('8888-1111', session('cobros.registration')['personal']['phone']);

        // Longitud incorrecta para Costa Rica.
        $this->firstStep('CR');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Tica Corta',
            'email' => 'ticacorta5e@test.com',
            'phone' => '1234567',
        ])->assertSessionHasErrors('phone');

        // Colombia: 10 dígitos.
        $this->firstStep('CO');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Colombiano Número',
            'email' => 'col5e@test.com',
            'phone' => '+57 3001234567',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->assertSame('300 123 4567', session('cobros.registration')['personal']['phone']);

        // Longitud de Costa Rica no válida para Colombia.
        $this->firstStep('CO');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Colombiano Corto',
            'email' => 'colcorto5e@test.com',
            'phone' => '3001234',
        ])->assertSessionHasErrors('phone');
    }

    public function test_empty_phone_is_rejected_for_both_countries(): void
    {
        foreach (['CR', 'CO'] as $iso) {
            $this->firstStep($iso);
            $this->post(route('cobros.register.personal'), [
                'name' => 'Sin Teléfono '.$iso,
                'email' => "sinphone5e{$iso}@test.com",
            ])->assertSessionHasErrors('phone');
        }
    }

    public function test_phone_is_preserved_when_returning_between_steps(): void
    {
        $this->firstStep('CR');
        $this->post(route('cobros.register.personal'), [
            'name' => 'Vuelve Al Paso',
            'email' => 'vuelve5e@test.com',
            'phone' => '8888-7777',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->get(route('cobros.register.step', ['paso' => 2]))
            ->assertOk()
            ->assertSee('value="8888-7777"', false)
            ->assertSee('+506');
    }
}
