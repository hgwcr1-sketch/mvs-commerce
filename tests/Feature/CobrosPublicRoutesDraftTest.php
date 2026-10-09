<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPublicRoutesDraftTest extends TestCase
{
    use RefreshDatabase;

    private function reachRoutesStep(): void
    {
        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CR',
            'modality' => 'prestamista',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));

        $this->post(route('cobros.register.personal'), [
            'name' => 'Draft Owner',
            'email' => 'draft.owner@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
    }

    private function addRoute(string $name, array $extra = []): void
    {
        $this->post(route('cobros.register.routes.save'), array_merge([
            'name' => $name,
            'code' => '',
            'lender' => '',
        ], $extra))->assertRedirect(route('cobros.register.step', ['paso' => 3]));
    }

    public function test_multiple_draft_routes_persist_survive_back_navigation_and_edit_idempotently(): void
    {
        $this->reachRoutesStep();

        $this->addRoute('Ruta Norte');
        $this->addRoute('Ruta Sur', ['code' => 'RSUR']);
        $this->addRoute('Ruta Centro');

        $routes = session('cobros.registration')['routes'];
        $this->assertCount(3, $routes);

        $this->get(route('cobros.register.step', ['paso' => 1]))->assertOk();
        $resume = $this->get(route('cobros.register.step', ['paso' => 3]));
        $resume->assertOk()
            ->assertSee('Ruta Norte')
            ->assertSee('Ruta Sur')
            ->assertSee('Ruta Centro');
        $this->assertCount(3, session('cobros.registration')['routes']);

        $routeId = $routes[1]['id'];

        $this->post(route('cobros.register.routes.save'), [
            'route_id' => $routeId,
            'name' => 'Ruta Sur Editada',
            'code' => 'RSUR2',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.routes.save'), [
            'route_id' => $routeId,
            'name' => 'Ruta Sur Editada',
            'code' => 'RSUR2',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $routes = session('cobros.registration')['routes'];
        $this->assertCount(3, $routes, 'La edición idempotente no debe duplicar rutas');
        $this->assertSame('Ruta Sur Editada', $routes[1]['name']);

        $this->post(route('cobros.register.routes.remove'), ['route_id' => $routeId])
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $routes = session('cobros.registration')['routes'];
        $this->assertCount(2, $routes);
        $this->get(route('cobros.register.step', ['paso' => 3]))->assertDontSee('Ruta Sur Editada');

        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertGuest();
    }

    public function test_route_and_portfolio_endpoints_require_prior_steps_and_stay_anonymous(): void
    {
        $this->post(route('cobros.register.routes.save'), ['name' => 'Prematura'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->post(route('cobros.register.routes.remove'), ['route_id' => 'x'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));
        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 1]));

        $this->assertNull(session('cobros.registration'));
        $this->assertGuest();

        $this->reachRoutesStep();

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_portfolio_plan_validates_and_gates_the_review_step(): void
    {
        $this->reachRoutesStep();

        $this->get(route('cobros.register.step', ['paso' => 4]))
            ->assertRedirect(route('cobros.register.step', ['paso' => 3]));

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'hack'])
            ->assertSessionHasErrors('portfolio_plan');

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'none'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $this->assertSame('none', session('cobros.registration')['portfolio_plan']);

        $review = $this->get(route('cobros.register.step', ['paso' => 4]));
        $review->assertOk()
            ->assertSee('Comenzar sin cartera por ahora')
            ->assertSee('Sin rutas en borrador');
    }

    public function test_import_templates_are_not_offered_without_authorized_context(): void
    {
        $templateUrls = [
            route('cobros.collector.customers.import.template'),
            route('cobros.collector.credits.import.template'),
            route('cobros.owner.customers.import.template'),
            route('cobros.owner.credits.import.template'),
        ];

        $this->reachRoutesStep();

        $step3 = $this->get(route('cobros.register.step', ['paso' => 3]));
        $step3->assertOk()->assertSee('plantillas de clientes y créditos');

        foreach ($templateUrls as $url) {
            $step3->assertDontSee($url, false);
        }
        $step3->assertDontSee('/importar/plantilla', false);

        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'import'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $step4 = $this->get(route('cobros.register.step', ['paso' => 4]));
        foreach ($templateUrls as $url) {
            $step4->assertDontSee($url, false);
        }
        $step4->assertDontSee('/importar/plantilla', false);
    }

    public function test_review_shows_reference_pricing_per_country_without_charging_or_rewards(): void
    {
        $this->reachRoutesStep();
        $this->addRoute('Ruta A');
        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $cr = $this->get(route('cobros.register.step', ['paso' => 4]));
        $cr->assertOk()
            ->assertSee('₡10.000,00')
            ->assertSee('₡20.000,00')
            ->assertSee('Precios de referencia')
            ->assertSee('no se realiza ningún cobro')
            ->assertDontSee('10%')
            ->assertDontSee('beneficio')
            ->assertSee('aria-disabled="true"', false);

        $this->post(route('cobros.register.country'), [
            'country_iso' => 'CO',
            'modality' => 'cobrador_independiente',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 2]));
        $this->post(route('cobros.register.personal'), [
            'name' => 'Colombia Owner',
            'email' => 'col.owner@test.com',
        ])->assertRedirect(route('cobros.register.step', ['paso' => 3]));
        $this->addRoute('Ruta Bogotá');
        $this->addRoute('Ruta Medellín');
        $this->post(route('cobros.register.portfolio'), ['portfolio_plan' => 'manual'])
            ->assertRedirect(route('cobros.register.step', ['paso' => 4]));

        $co = $this->get(route('cobros.register.step', ['paso' => 4]));
        $co->assertOk()
            ->assertSee('$60.000 COP')
            ->assertSee('$240.000 COP');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('cobros_routes', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertGuest();
    }
}
