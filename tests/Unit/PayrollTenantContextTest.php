<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\PayrollTenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_context_when_user_belongs_to_company_and_has_branch(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'SUC', 'is_active' => true]);

        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->actingAs($user);

        $context = PayrollTenantContext::resolve();

        $this->assertSame($company->id, $context->companyId());
        $this->assertSame($branch->id, $context->branchId());
    }

    public function test_throws_when_user_not_authenticated(): void
    {
        session(['active_company_id' => 1, 'active_branch_id' => 1]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Usuario no autenticado.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_company_not_in_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_branch_id' => 1]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Contexto de empresa o sucursal no establecido.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_branch_not_in_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        session(['active_company_id' => 1]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Contexto de empresa o sucursal no establecido.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_user_not_in_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'SUC', 'is_active' => true]);

        $user->branches()->attach($branch->id);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('El usuario no pertenece a la empresa activa.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_branch_not_active(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'SUC', 'is_active' => false]);

        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('La sucursal no existe, no está activa o no pertenece a la empresa.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_branch_belongs_to_different_company(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $otherCompany = Company::create(['trade_name' => 'Otra Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $otherCompany->id, 'name' => 'Sucursal', 'code' => 'SUC', 'is_active' => true]);

        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('La sucursal no existe, no está activa o no pertenece a la empresa.');

        PayrollTenantContext::resolve();
    }

    public function test_throws_when_user_not_assigned_to_branch(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::create(['trade_name' => 'Test Co', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Sucursal', 'code' => 'SUC', 'is_active' => true]);

        $user->companies()->attach($company->id);

        session(['active_company_id' => $company->id, 'active_branch_id' => $branch->id]);
        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('El usuario no tiene asignada la sucursal activa.');

        PayrollTenantContext::resolve();
    }
}
