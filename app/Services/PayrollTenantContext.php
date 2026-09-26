<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class PayrollTenantContext
{
    private function __construct(
        private int $companyId,
        private int $branchId,
    ) {}

    public static function resolve(): self
    {
        $user = Auth::user();

        if (! $user) {
            throw new AuthorizationException('Usuario no autenticado.');
        }

        $companyId = session('active_company_id');
        $branchId = session('active_branch_id');

        if (! $companyId || ! $branchId) {
            throw new AuthorizationException('Contexto de empresa o sucursal no establecido.');
        }

        $hasCompanyAccess = $user->companies()
            ->where('companies.id', $companyId)
            ->where('companies.is_active', true)
            ->exists();

        if (! $hasCompanyAccess) {
            throw new AuthorizationException('El usuario no pertenece a la empresa activa.');
        }

        $branch = Branch::query()
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            throw new AuthorizationException('La sucursal no existe, no está activa o no pertenece a la empresa.');
        }

        $hasBranchAccess = $user->branches()
            ->where('branches.id', $branchId)
            ->exists();

        if (! $hasBranchAccess) {
            throw new AuthorizationException('El usuario no tiene asignada la sucursal activa.');
        }

        return new self($companyId, $branchId);
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function branchId(): int
    {
        return $this->branchId;
    }

    public function company(): Company
    {
        return Company::findOrFail($this->companyId);
    }

    public function branch(): Branch
    {
        return Branch::findOrFail($this->branchId);
    }
}
