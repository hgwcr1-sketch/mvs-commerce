<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyAllowance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CompanyProvisioner
{
    public function __construct(
        private readonly PaymentMethodProvisioner $paymentMethodProvisioner,
        private readonly CompanyCashSettingsProvisioner $companyCashSettingsProvisioner,
        private readonly CashDenominationProvisioner $cashDenominationProvisioner,
    ) {
    }

    /**
     * Crea la cuenta administradora y su primera empresa en una transacción.
     */
    public function install(
        array $administratorData,
        array $companyData,
        string $branchName = 'Principal',
        string $branchCode = 'PRINCIPAL',
    ): Company {
        return DB::transaction(function () use (
            $administratorData,
            $companyData,
            $branchName,
            $branchCode,
        ) {
            $owner = User::create([
                'name' => $administratorData['name'],
                'email' => $administratorData['email'],
                'password' => Hash::make($administratorData['password']),
                'is_active' => true,
            ]);

            return $this->provision(
                $owner,
                $companyData,
                $branchName,
                $branchCode,
            );
        });
    }

    /**
     * Provisiona una empresa con sus relaciones mínimas de operación.
     *
     * Los permisos son globales; el rol Administrador se crea por empresa.
     */
    public function provision(
        User $owner,
        array $companyData,
        string $branchName = 'Principal',
        string $branchCode = 'PRINCIPAL',
        int $initialCompanyAllowance = 1,
    ): Company {
        return DB::transaction(function () use (
            $owner,
            $companyData,
            $branchName,
            $branchCode,
            $initialCompanyAllowance,
        ) {
            $permissionIds = Permission::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            if ($permissionIds === []) {
                throw ValidationException::withMessages([
                    'permissions' => 'No hay permisos globales activos para asignar al administrador.',
                ]);
            }

            $allowance = CompanyAllowance::firstOrCreate(
                ['user_id' => $owner->id],
                ['allowed_companies' => $initialCompanyAllowance],
            );

            if ($owner->ownedCompanies()->count() >= $allowance->allowed_companies) {
                throw ValidationException::withMessages([
                    'company' => 'No tiene cupos disponibles para crear una nueva empresa.',
                ]);
            }

            $company = Company::create([
                ...$companyData,
                'owner_user_id' => $owner->id,
                'is_active' => true,
            ]);

            $this->paymentMethodProvisioner->provision($company);
            $this->companyCashSettingsProvisioner->provision($company);
            $this->cashDenominationProvisioner->provision($company);

            $administratorRole = Role::create([
                'company_id' => $company->id,
                'name' => 'Administrador',
                'description' => 'Administrador inicial de la empresa.',
                'is_active' => true,
            ]);

            $administratorRole->permissions()->sync($permissionIds);

            $branch = Branch::create([
                'company_id' => $company->id,
                'name' => $branchName,
                'code' => $branchCode,
                'is_active' => true,
            ]);

            $company->users()->attach($owner->id, [
                'role_id' => $administratorRole->id,
            ]);

            $owner->branches()->attach($branch->id);

            return $company;
        });
    }
}
