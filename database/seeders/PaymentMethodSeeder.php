<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\PaymentMethodProvisioner;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Aprovisiona los métodos de pago iniciales de todas las empresas.
     */
    public function run(PaymentMethodProvisioner $provisioner): void
    {
        Company::query()->eachById(function (Company $company) use ($provisioner) {
            $provisioner->provision($company);
        });
    }
}
