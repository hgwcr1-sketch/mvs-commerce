<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use Illuminate\Database\Seeder;

class CashInfrastructureSeeder extends Seeder
{
    public function run(CompanyCashSettingsProvisioner $settings, CashDenominationProvisioner $denominations): void
    {
        Company::query()->eachById(function (Company $company) use ($settings, $denominations) {
            $settings->provision($company);
            $denominations->provision($company);
        });
    }
}
