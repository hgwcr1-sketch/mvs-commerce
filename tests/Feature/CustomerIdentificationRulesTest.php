<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\IdentificationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerIdentificationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_identification_rules_follow_official_lengths_per_type(): void
    {
        $this->assertTrue(IdentificationRules::isValid('01', '1-0987-0988'));
        $this->assertTrue(IdentificationRules::isValid('01', '109870988'));
        $this->assertFalse(IdentificationRules::isValid('01', '1-0987-098'), 'Cédula física requiere 9 dígitos.');
        $this->assertFalse(IdentificationRules::isValid('01', '1-0987-09881'), 'Cédula física no admite 10 dígitos.');

        $this->assertTrue(IdentificationRules::isValid('02', '3-101-000000'));
        $this->assertFalse(IdentificationRules::isValid('02', '3-101-00000'), 'Cédula jurídica requiere 10 dígitos.');
        $this->assertFalse(IdentificationRules::isValid('02', '3-101-0000000'), 'Cédula jurídica no admite 11 dígitos.');

        $this->assertTrue(IdentificationRules::isValid('03', '20000000000'), 'DIMEX de 11 dígitos.');
        $this->assertTrue(IdentificationRules::isValid('03', '200000000001'), 'DIMEX de 12 dígitos.');
        $this->assertFalse(IdentificationRules::isValid('03', '2000000000'), 'DIMEX no admite 10 dígitos.');
        $this->assertFalse(IdentificationRules::isValid('03', '2000000000012'), 'DIMEX no admite 13 dígitos.');

        $this->assertTrue(IdentificationRules::isValid('04', '3130000000'), 'NITE de 10 dígitos sin guiones.');
        $this->assertFalse(IdentificationRules::isValid('04', '3-130-000000'), 'NITE no admite guiones.');
        $this->assertFalse(IdentificationRules::isValid('04', '313000000'), 'NITE requiere 10 dígitos.');

        $this->assertTrue(IdentificationRules::isValid('05', str_repeat('X', 20)), 'Extranjero admite 20 caracteres.');
        $this->assertFalse(IdentificationRules::isValid('05', str_repeat('X', 21)), 'Extranjero no admite 21 caracteres.');
    }

    public function test_identification_limits_placeholders_and_masks_are_centralized(): void
    {
        $this->assertSame(11, IdentificationRules::maxLength('01'));
        $this->assertSame(12, IdentificationRules::maxLength('02'));
        $this->assertSame(12, IdentificationRules::maxLength('03'));
        $this->assertSame(10, IdentificationRules::maxLength('04'));
        $this->assertSame(20, IdentificationRules::maxLength('05'));

        $this->assertSame('1-0987-0988', IdentificationRules::example('01'));
        $this->assertSame('3-101-000000', IdentificationRules::example('02'));
        $this->assertSame('10 dígitos', IdentificationRules::example('04'));

        $this->assertSame('1-0987-0988', IdentificationRules::format('01', '109870988'));
        $this->assertSame('3-101-000000', IdentificationRules::format('02', '3101000000'));
        $this->assertSame('3130000000', IdentificationRules::format('04', '3-130-000000'), 'NITE se normaliza sin guiones.');
        $this->assertSame('200000000001', IdentificationRules::format('03', '20000000000123'), 'DIMEX se limita a 12 dígitos sin guiones.');
        $this->assertSame('1-098', IdentificationRules::format('01', '1098'), 'La máscara se aplica de forma progresiva.');
        $this->assertSame('ID-LEGACY', IdentificationRules::format('01', 'ID-LEGACY'), 'Los datos heredados no se reformatean.');
    }

    public function test_identification_boundary_last_allowed_length_enters_and_one_more_is_rejected(): void
    {
        [$company, $branch, $user] = $this->context();

        $cases = [
            ['01', '1-0987-0988', '1-0987-09881'],
            ['02', '3-101-000000', '3-101-0000000'],
            ['03', '200000000001', '2000000000012'],
            ['04', '3130000000', '31300000000'],
            ['05', 'PAS'.str_repeat('9', 17), 'PAS'.str_repeat('9', 18)],
        ];

        foreach ($cases as [$type, $allowed, $rejected]) {
            $this->assertSame(
                IdentificationRules::maxLength($type),
                mb_strlen($allowed),
                "El valor permitido ocupa exactamente el máximo del tipo {$type}."
            );
            $this->assertTrue(IdentificationRules::isValid($type, $allowed));

            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('clientes.store'), $this->payload([
                    'identification_type' => $type,
                    'identification' => $allowed,
                    'email' => uniqid('bd-').'@example.test',
                ]))
                ->assertRedirect(route('clientes.index'))
                ->assertSessionHasNoErrors();

            $this->assertFalse(IdentificationRules::isValid($type, $rejected));

            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('clientes.store'), $this->payload([
                    'identification_type' => $type,
                    'identification' => $rejected,
                    'email' => uniqid('bd-').'@example.test',
                ]))
                ->assertRedirect()
                ->assertSessionHasErrors('identification');
        }
    }

    public function test_clientes_store_rejects_identification_that_does_not_match_the_type(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification_type' => '01',
                'identification' => '1-0987-098',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification_type' => '03',
                'identification' => '2000000000',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->post(route('clientes.store'), $this->payload([
                'identification_type' => '04',
                'identification' => '313000000',
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('identification');

        $this->assertSame(0, Customer::where('company_id', $company->id)->count());
    }

    public function test_clientes_store_accepts_official_formats_per_type(): void
    {
        [$company, $branch, $user] = $this->context();

        $cases = [
            ['01', '1-0987-0988'],
            ['02', '3-101-000000'],
            ['03', '20000000000'],
            ['04', '3130000000'],
            ['05', 'PAS-123456'],
        ];

        foreach ($cases as [$type, $identification]) {
            $this->actingAs($user)->withSession($this->activeSession($company, $branch))
                ->post(route('clientes.store'), $this->payload([
                    'identification_type' => $type,
                    'identification' => $identification,
                    'email' => uniqid('id-').'@example.test',
                ]))
                ->assertRedirect(route('clientes.index'))
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('customers', [
                'company_id' => $company->id,
                'identification_type' => $type,
                'identification' => $identification,
            ]);
        }
    }

    public function test_clientes_update_keeps_existing_legacy_identification_editable(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => 'ID-LEGACY',
            'name' => 'Cliente Heredado',
            'credit_limit' => 0,
            'credit_days' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->put(route('clientes.update', $customer), $this->payload([
                'identification_type' => '01',
                'identification' => 'ID-LEGACY',
                'name' => 'Cliente Heredado Editado',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('Cliente Heredado Editado', $customer->name);
        $this->assertSame('ID-LEGACY', $customer->identification);
        $this->assertSame($company->id, $customer->company_id);
    }

    public function test_pos_quick_customer_applies_identification_rules(): void
    {
        [$company, $branch, $user] = $this->context();

        $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente Rápido',
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '1-0987-0988',
        ])->assertCreated()->assertJsonPath('customer.identification', '1-0987-0988');

        $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente DIMEX corto',
            'customer_type' => 'individual',
            'identification_type' => '03',
            'identification' => '2000000000',
        ])->assertStatus(422)->assertJsonValidationErrors(['identification']);

        $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente NITE corto',
            'customer_type' => 'individual',
            'identification_type' => '04',
            'identification' => '313000000',
        ])->assertStatus(422)->assertJsonValidationErrors(['identification']);

        $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente NITE con guiones',
            'customer_type' => 'individual',
            'identification_type' => '04',
            'identification' => '3-130-000000',
        ])->assertStatus(422)->assertJsonValidationErrors(['identification']);

        $this->quickStoreCustomer($user, $company, $branch, [
            'name' => 'Cliente extranjero largo',
            'customer_type' => 'individual',
            'identification_type' => '05',
            'identification' => str_repeat('X', 21),
        ])->assertStatus(422)->assertJsonValidationErrors(['identification']);

        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'name' => 'Cliente DIMEX corto']);
        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'name' => 'Cliente NITE corto']);
        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'name' => 'Cliente NITE con guiones']);
        $this->assertDatabaseMissing('customers', ['company_id' => $company->id, 'name' => 'Cliente extranjero largo']);
    }

    public function test_hacienda_lookup_returns_the_contributor_name(): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => Http::response([
                'nombre' => 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA',
                'tipoIdentificacion' => '02',
            ], 200),
        ]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '02', 'identificacion' => '3-101-000000']))
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('name', 'COMERCIAL EJEMPLO SOCIEDAD ANONIMA');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'identificacion=3101000000'));
    }

    public function test_hacienda_lookup_receives_only_digits_even_when_the_masked_value_is_shown(): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => Http::response(['nombre' => 'EMPRESA CON MASCARA S.A.'], 200),
        ]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '1-0987-0988']))
            ->assertOk()
            ->assertJsonPath('status', 'found');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'identificacion=109870988'));
        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'identificacion=1-'));
    }

    public function test_hacienda_lookup_reports_unknown_taxpayer(): void
    {
        Http::fake(['api.hacienda.go.cr/*' => Http::response(['code' => 404], 404)]);

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '101010101']))
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('name', null);
    }

    public function test_hacienda_lookup_never_blocks_when_the_service_does_not_respond(): void
    {
        Http::fake(fn () => throw new ConnectionException('Hacienda no responde'));

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '123456789']))
            ->assertOk()
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('name', null);
    }

    public function test_hacienda_lookup_requires_a_complete_identification(): void
    {
        Http::fake();

        [$company, $branch, $user] = $this->context();

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '1-0987-098']))
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');

        Http::assertNothingSent();
    }

    public function test_hacienda_lookup_requires_the_customer_creation_permission(): void
    {
        Http::fake();

        [$company, $branch, $user] = $this->context(false);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))
            ->getJson(route('clientes.contribuyente', ['tipo' => '01', 'identificacion' => '109870988']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    private function context(bool $withPermissions = true): array
    {
        $company = Company::create([
            'trade_name' => 'Empresa '.uniqid(), 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'default_phone_country_code' => '+506', 'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id, 'name' => 'Principal', 'code' => 'P'.uniqid(), 'is_active' => true,
        ]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Clientes '.uniqid(), 'is_active' => true]);

        if ($withPermissions) {
            foreach (['clientes.crear', 'clientes.ver', 'clientes.editar', 'pos.acceder'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], [
                    'label' => $name, 'module' => 'Clientes', 'is_active' => true,
                ]);
                $role->permissions()->attach($permission);
            }
        }

        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        return [$company, $branch, $user];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'customer_type' => 'individual', 'identification_type' => '01',
            'identification' => '1-0987-0988', 'name' => 'María Núñez',
            'phone_country_code' => '+506', 'phone' => '88881111',
            'credit_limit' => 0, 'credit_days' => 0, 'price_level' => 'normal', 'is_active' => '1',
        ], $overrides);
    }

    private function quickStoreCustomer(User $user, Company $company, Branch $branch, array $payload)
    {
        return $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->postJson(route('pos.customers.quick-store'), $payload);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }
}
