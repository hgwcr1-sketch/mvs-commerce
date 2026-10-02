<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyLicenseService;
use App\Services\Fiscal\CompanyFiscalConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FiscalWizardIdentificationTest extends TestCase
{
    use RefreshDatabase;

    private function context(array $permissions = ['fiscal.ver', 'fiscal.editar']): array
    {
        $company = Company::create([
            'trade_name' => 'FE' . uniqid(),
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P' . uniqid(),
            'is_active' => true,
        ]);

        app(CompanyLicenseService::class)->ensure($company);
        app(CompanyFiscalConfigService::class)->ensure($company);
        \App\Models\CompanyLicense::query()->where('company_id', $company->id)
            ->update(['fiscal_enabled' => true, 'fiscal_monthly_quota' => 50]);

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'R' . uniqid(), 'is_active' => true]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => $name, 'module' => 'Facturación Electrónica', 'is_active' => true],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);

        $this->actingAs($user)->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ]);

        return [$company, $branch];
    }

    private function hacienda(string $name, array $activities, string $status = 'ok'): void
    {
        Http::fake([
            'api.hacienda.go.cr/*' => Http::response([
                'nombre' => $name,
                'tipo_persona' => 'Juridica',
                'regimen' => 'Simplificado',
                'situacion' => 'Activo',
                'actividades_economicas' => array_map(
                    fn (string $code, string $description) => [
                        'codigo' => $code,
                        'descripcion' => $description,
                    ],
                    array_column($activities, 0),
                    array_column($activities, 1),
                ),
                'estado' => $status,
            ], 200),
        ]);
    }

    public function test_lookup_returns_real_activities_from_hacienda(): void
    {
        $this->context();
        $this->hacienda('COMERCIAL MORA S.A.', [['1071.9', 'Venta al por menor']]);

        $response = $this->getJson(route('fiscal.contribuyente', [
            'tipo' => '02',
            'identificacion' => '3012345678',
        ]));

        $response->assertOk();
        $response->assertJsonPath('status', 'found');
        $response->assertJsonPath('name', 'COMERCIAL MORA S.A.');
        $response->assertJsonPath('activities_queried', true);
        $this->assertSame(
            [['code' => '1071.9', 'description' => 'Venta al por menor']],
            $response->json('activities')
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), 'identificacion=3012345678'));
    }

    public function test_lookup_requires_the_fiscal_permission(): void
    {
        $this->context(['dashboard.ver']);
        $this->hacienda('NO DEBE RESPONDER', []);

        $this->getJson(route('fiscal.contribuyente', ['tipo' => '02', 'identificacion' => '3012345678']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_lookup_rejects_incomplete_identification_without_calling_hacienda(): void
    {
        $this->context();
        $this->hacienda('NO DEBE RESPONDER', []);

        $this->getJson(route('fiscal.contribuyente', ['tipo' => '02', 'identificacion' => '3012']))
            ->assertStatus(422)
            ->assertJsonPath('status', 'invalid');

        Http::assertNothingSent();
    }

    public function test_wizard_paso_uno_expone_el_selector_de_actividades_reales(): void
    {
        $this->context();

        $this->get(route('fiscal.setup', ['step' => 'datos']))
            ->assertOk()
            ->assertSee('fiscal_activity_select')
            ->assertSee('fiscal_identification_status')
            ->assertSee('Consultar en Hacienda')
            ->assertSee('const endpoint =', false)
            ->assertSee('DOMContentLoaded', false)
            ->assertSee('nunca se bloquea el paso 1', false);
    }
}