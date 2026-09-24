<?php

namespace Tests\Feature;

use App\Models\{Branch, Company, Customer, Permission, Role, RouteosAuditLog, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R01 MVS RouteOS — Fundamentos: permisos, seguridad de crédito,
 * auditoría, geolocalización de clientes y aislamiento multiempresa.
 */
class RouteosR01Test extends TestCase
{
    use RefreshDatabase;

    public function test_routeos_home_requires_permission(): void
    {
        [$c, $b, $u] = $this->context(['routeos.acceder']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('routeos.index'))
            ->assertOk()
            ->assertSee('MVS RouteOS')
            ->assertSee('Ventas, Rutas y Cobros')
            ->assertSee('Próximamente');

        [$c2, $b2, $u2] = $this->context(['clientes.ver'], 'Otra');

        $this->actingAs($u2)->withSession($this->ctx($c2, $b2))
            ->get(route('routeos.index'))
            ->assertForbidden();
    }

    public function test_credit_update_without_permission_is_rejected_and_not_audited(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar']);
        $customer = $this->customer($c, 5000, 15);

        // Cambiar el límite de crédito sin el permiso específico → rechazado.
        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['credit_limit' => 90000]))
            ->assertSessionHasErrors('credit_limit');

        $customer->refresh();
        $this->assertSame('5000.00', (string) $customer->credit_limit);
        $this->assertSame(15, (int) $customer->credit_days);
        $this->assertSame(0, RouteosAuditLog::query()->where('company_id', $c->id)->count());

        // El plazo de crédito también queda protegido.
        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['credit_days' => 60]))
            ->assertSessionHasErrors('credit_days');

        $customer->refresh();
        $this->assertSame(15, (int) $customer->credit_days);
        $this->assertSame(0, RouteosAuditLog::query()->where('company_id', $c->id)->count());
    }

    public function test_editor_without_credit_permission_can_still_edit_normal_fields(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar']);
        $customer = $this->customer($c, 5000, 15);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['name' => 'Cliente Editado']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('Cliente Editado', $customer->name);
        // Crédito intacto.
        $this->assertSame('5000.00', (string) $customer->credit_limit);
        $this->assertSame(15, (int) $customer->credit_days);
    }

    public function test_credit_update_with_permission_succeeds_and_is_audited(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar', 'routeos.credito.administrar']);
        $customer = $this->customer($c, 5000, 15);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['credit_limit' => 12000, 'credit_days' => 30]))
            ->assertRedirect(route('clientes.index'));

        $customer->refresh();
        $this->assertSame('12000.00', (string) $customer->credit_limit);
        $this->assertSame(30, (int) $customer->credit_days);

        $log = RouteosAuditLog::query()
            ->where('company_id', $c->id)
            ->where('action', 'routeos.credito.actualizado')
            ->where('entity_type', Customer::class)
            ->where('entity_id', $customer->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($u->id, $log->actor_id);
        $this->assertSame('5000.00', (string) $log->old_values['credit_limit']);
        $this->assertSame(15, (int) $log->old_values['credit_days']);
        $this->assertSame('12000.00', (string) $log->new_values['credit_limit']);
        $this->assertSame(30, (int) $log->new_values['credit_days']);
        $this->assertNotNull($log->occurred_at);
    }

    public function test_credit_change_with_same_values_does_not_create_audit(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar', 'routeos.credito.administrar']);
        $customer = $this->customer($c, 5000, 15);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer))
            ->assertRedirect(route('clientes.index'));

        $this->assertSame(
            0,
            RouteosAuditLog::query()->where('company_id', $c->id)
                ->where('action', 'routeos.credito.actualizado')->count()
        );
    }

    public function test_geo_coordinates_are_stored_with_validation_stamp(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar']);
        $customer = $this->customer($c);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, [
                'latitude' => 9.9281345,
                'longitude' => -84.0907251,
                'location_reference' => '200 m oeste del parque',
            ]))
            ->assertRedirect(route('clientes.index'));

        $customer->refresh();
        $this->assertSame('9.92813450', (string) $customer->latitude);
        $this->assertSame('-84.09072510', (string) $customer->longitude);
        $this->assertSame('200 m oeste del parque', $customer->location_reference);
        $this->assertTrue($customer->isLocationValidated());
        $this->assertNotNull($customer->location_validated_at);
        $this->assertSame($u->id, $customer->location_validated_by);

        $this->assertSame(
            1,
            RouteosAuditLog::query()->where('company_id', $c->id)
                ->where('action', 'routeos.ubicacion.actualizada')->count()
        );
    }

    public function test_geo_coordinates_validation_ranges_and_pairs(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar']);
        $customer = $this->customer($c);

        // Latitud fuera de rango.
        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['latitude' => 95, 'longitude' => -84]))
            ->assertSessionHasErrors('latitude');

        // Longitud fuera de rango.
        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['latitude' => 9.9, 'longitude' => 200]))
            ->assertSessionHasErrors('longitude');

        // Latitud sin longitud → rechazado.
        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->put(route('clientes.update', $customer), $this->payload($customer, ['latitude' => 9.9]))
            ->assertSessionHasErrors('latitude');

        $customer->refresh();
        $this->assertNull($customer->latitude);
        $this->assertNull($customer->longitude);
    }

    public function test_location_edit_is_blocked_across_companies(): void
    {
        [$c, $b, $u] = $this->context(['clientes.editar', 'routeos.credito.administrar']);
        [$c2, $b2, $u2] = $this->context(['clientes.editar', 'routeos.credito.administrar'], 'Ajena');

        $customer = $this->customer($c, 1000, 15);

        // Empresa ajena no puede ver ni modificar el cliente.
        $this->actingAs($u2)->withSession($this->ctx($c2, $b2))
            ->get(route('clientes.show', $customer))
            ->assertNotFound();

        $this->actingAs($u2)->withSession($this->ctx($c2, $b2))
            ->put(route('clientes.update', $customer), $this->payload($customer, [
                'latitude' => 9.9,
                'longitude' => -84.0,
                'credit_limit' => 99999,
            ]))
            ->assertNotFound();

        $customer->refresh();
        $this->assertNull($customer->latitude);
        $this->assertSame('1000.00', (string) $customer->credit_limit);
        $this->assertSame(0, RouteosAuditLog::query()->where('company_id', $c2->id)->count());
    }

    public function test_maps_and_waze_urls_depend_on_coordinates(): void
    {
        [$c] = $this->context(['routeos.acceder']);
        $customer = $this->customer($c);

        $this->assertNull($customer->google_maps_url);
        $this->assertNull($customer->waze_url);
        $this->assertFalse($customer->hasLocation());

        $customer->update(['latitude' => 9.9281345, 'longitude' => -84.0907251]);
        $customer->refresh();

        $this->assertTrue($customer->hasLocation());
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=9.92813450,-84.09072510',
            $customer->google_maps_url
        );
        $this->assertSame(
            'https://waze.com/ul?ll=9.92813450,-84.09072510&navigate=yes',
            $customer->waze_url
        );
    }

    public function test_customer_show_displays_location_section_with_links(): void
    {
        [$c, $b, $u] = $this->context(['clientes.ver']);
        $customer = $this->customer($c);
        $customer->update(['latitude' => 9.9281345, 'longitude' => -84.0907251, 'location_reference' => 'Local azul']);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('clientes.show', $customer))
            ->assertOk()
            ->assertSee('Ubicación')
            ->assertSee('Local azul')
            ->assertSee('google.com/maps')
            ->assertSee('waze.com');
    }

    public function test_customer_show_without_location_has_no_map_links(): void
    {
        [$c, $b, $u] = $this->context(['clientes.ver']);
        $customer = $this->customer($c);

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->get(route('clientes.show', $customer))
            ->assertOk()
            ->assertSee('aún no tiene ubicación registrada')
            ->assertDontSee('waze.com');
    }

    public function test_creating_customer_with_credit_requires_permission(): void
    {
        [$c, $b, $u] = $this->context(['clientes.crear']);

        $payload = [
            'customer_type' => 'individual',
            'name' => 'Cliente Nuevo',
            'credit_limit' => 5000,
            'credit_days' => 30,
            'price_level' => 'normal',
        ];

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->post(route('clientes.store'), $payload)
            ->assertSessionHasErrors('credit_limit');

        $this->assertSame(0, Customer::query()->where('company_id', $c->id)->where('name', 'Cliente Nuevo')->count());

        // Sin crédito la creación es normal.
        $payload['credit_limit'] = 0;
        $payload['credit_days'] = 0;

        $this->actingAs($u)->withSession($this->ctx($c, $b))
            ->post(route('clientes.store'), $payload)
            ->assertRedirect(route('clientes.index'));

        $customer = Customer::query()->where('company_id', $c->id)->where('name', 'Cliente Nuevo')->firstOrFail();
        $this->assertSame('0.00', (string) $customer->credit_limit);
    }

    public function test_routeos_module_registered_in_registry(): void
    {
        $this->assertSame('routeos', app(\App\Services\Modules\ModuleRegistry::class)->forPermission('routeos.credito.administrar'));
        $this->assertSame('routeos', app(\App\Services\Modules\ModuleRegistry::class)->forPermission('routeos.acceder'));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | --------------------------------------------------------------------- */

    private function context(array $permissions, string $companyName = 'Empresa'): array
    {
        $c = Company::create([
            'trade_name' => $companyName.uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $b = Branch::create(['company_id' => $c->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true]);

        $u = User::factory()->create();
        $r = Role::create(['company_id' => $c->id, 'name' => 'R'.uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Test', 'is_active' => true]);
            $r->permissions()->attach($permission);
        }

        $u->companies()->attach($c->id, ['role_id' => $r->id]);
        $u->branches()->attach($b->id);

        return [$c, $b, $u];
    }

    private function customer(Company $c, float $limit = 0, int $days = 0): Customer
    {
        return Customer::create([
            'company_id' => $c->id,
            'name' => 'Cliente Prueba',
            'customer_type' => 'individual',
            'credit_limit' => $limit,
            'credit_days' => $days,
            'price_level' => 'normal',
            'is_active' => true,
        ]);
    }

    private function payload(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_type' => $customer->customer_type,
            'name' => $customer->name,
            'price_level' => $customer->price_level ?? 'normal',
            'credit_limit' => (float) $customer->credit_limit,
            'credit_days' => (int) ($customer->credit_days ?? 0),
        ], $overrides);
    }

    private function ctx(Company $c, Branch $b): array
    {
        return ['active_company_id' => $c->id, 'active_branch_id' => $b->id];
    }
}
