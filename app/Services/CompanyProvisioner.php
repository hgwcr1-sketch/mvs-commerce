<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyAllowance;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CompanyProvisioner
{
    public function __construct(
        private readonly PaymentMethodProvisioner $paymentMethodProvisioner,
        private readonly CompanyCashSettingsProvisioner $companyCashSettingsProvisioner,
        private readonly CashDenominationProvisioner $cashDenominationProvisioner,
        private readonly CompanyLicenseService $companyLicenseService,
    ) {}

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
        array $additionalBranches = [],
        ?array $moduleKeys = null,
    ): Company {
        return DB::transaction(function () use (
            $owner,
            $companyData,
            $branchName,
            $branchCode,
            $initialCompanyAllowance,
            $additionalBranches,
            $moduleKeys,
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

            $this->companyLicenseService->ensure($company);

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

            $branches = collect([$branch]);
            foreach ($additionalBranches as $additionalBranch) {
                $branches->push(Branch::create([
                    'company_id' => $company->id,
                    'name' => $additionalBranch['name'],
                    'code' => $additionalBranch['code'],
                    'phone' => $additionalBranch['phone'] ?? null,
                    'address' => $additionalBranch['address'] ?? null,
                    'is_active' => true,
                ]));
            }

            if ($moduleKeys !== null) {
                foreach (array_keys(ModuleRegistry::MODULES) as $moduleKey) {
                    $company->modules()->create([
                        'module_key' => $moduleKey,
                        'is_enabled' => in_array($moduleKey, $moduleKeys, true),
                    ]);
                }
            }

            $company->users()->attach($owner->id, [
                'role_id' => $administratorRole->id,
            ]);

            $owner->branches()->attach($branches->pluck('id')->all());

            return $company;
        });
    }

    public function onboard(array $administratorData, array $companyData, array $branches, array $moduleKeys): Company
    {
        return DB::transaction(function () use ($administratorData, $companyData, $branches, $moduleKeys) {
            $owner = User::create([
                'name' => $administratorData['name'],
                'email' => $administratorData['email'],
                'phone' => $administratorData['phone'] ?? null,
                'password' => Hash::make($administratorData['password']),
                'is_active' => true,
            ]);
            $primary = array_shift($branches);

            $company = $this->provision(
                $owner,
                $companyData,
                $primary['name'],
                $primary['code'],
                1,
                $branches,
                $moduleKeys,
            );
            $company->branches()->where('code', $primary['code'])->update([
                'phone' => $primary['phone'] ?? null,
                'address' => $primary['address'] ?? null,
            ]);

            return $company;
        });
    }
}
