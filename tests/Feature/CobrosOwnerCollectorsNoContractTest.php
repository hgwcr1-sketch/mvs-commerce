<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 21D-FIX-2 — la página Cobradores del dueño NO debe dar 500 cuando la
 * empresa (p. ej. espacio DEMO del visitante) tiene el módulo sin
 * `license_mode` (contrato null). El estado sin contrato se muestra como
 * solo lectura sin inventar licencias ni cupos.
 */
class CobrosOwnerCollectorsNoContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_dueño_ve_cobradores_sin_contrato_sin_error_500(): void
    {
        $company = Company::create([
            'trade_name' => 'DEMO Visitante SinContrato',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
        Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'SC1', 'is_active' => true]);

        $portal = Permission::firstOrCreate(['name' => 'cobros.portal_dueno'], ['label' => 'Portal Dueño', 'module' => 'CobrOS', 'is_active' => true]);
        $acceder = Permission::firstOrCreate(['name' => 'cobros.acceder'], ['label' => 'Acceder', 'module' => 'CobrOS', 'is_active' => true]);
        \App\Models\CompanyModule::firstOrCreate(
            ['company_id' => $company->id, 'module_key' => 'cobros'],
            ['is_enabled' => true]
        );
        $owner = User::factory()->create(['is_active' => true]);
        $role = Role::create(['company_id' => $company->id, 'name' => 'Dueño SC', 'is_active' => true]);
        $role->permissions()->attach([$portal->id, $acceder->id]);
        $owner->companies()->syncWithoutDetaching([$company->id => ['role_id' => $role->id]]);

        $this->actingAs($owner)
            ->withSession(['active_company_id' => $company->id])
            ->get(route('cobros.owner.collectors'))
            ->assertOk()
            ->assertSee('Cobradores');
    }
}
