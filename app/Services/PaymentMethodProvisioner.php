<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PaymentMethod;

class PaymentMethodProvisioner
{
    /**
     * Provisiona los métodos de pago iniciales de una empresa.
     */
    public function provision(Company $company): void
    {
        $methods = [
            [
                'code' => 'cash',
                'name' => 'Efectivo',
                'type' => PaymentMethod::TYPE_CASH,
                'is_system' => true,
                'is_active' => true,
                'affects_cash' => true,
                'requires_reference' => false,
                'allows_change' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'card',
                'name' => 'Tarjeta',
                'type' => PaymentMethod::TYPE_CARD,
                'is_system' => true,
                'is_active' => true,
                'affects_cash' => false,
                'requires_reference' => true,
                'allows_change' => false,
                'sort_order' => 20,
            ],
            [
                'code' => 'sinpe',
                'name' => 'SINPE',
                'type' => PaymentMethod::TYPE_SINPE,
                'is_system' => true,
                'is_active' => true,
                'affects_cash' => false,
                'requires_reference' => true,
                'allows_change' => false,
                'sort_order' => 30,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $method['code'],
                ],
                $method,
            );
        }
    }
}
