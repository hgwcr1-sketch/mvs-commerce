<?php

namespace App\Services;

use App\Models\CashDenomination;
use App\Models\Company;

class CashDenominationProvisioner
{
    private const CRC_DENOMINATIONS = [
        [20000, 'Billete de ₡20.000', CashDenomination::TYPE_BILL],
        [10000, 'Billete de ₡10.000', CashDenomination::TYPE_BILL],
        [5000, 'Billete de ₡5.000', CashDenomination::TYPE_BILL],
        [2000, 'Billete de ₡2.000', CashDenomination::TYPE_BILL],
        [1000, 'Billete de ₡1.000', CashDenomination::TYPE_BILL],
        [500, 'Moneda de ₡500', CashDenomination::TYPE_COIN],
        [100, 'Moneda de ₡100', CashDenomination::TYPE_COIN],
        [50, 'Moneda de ₡50', CashDenomination::TYPE_COIN],
        [25, 'Moneda de ₡25', CashDenomination::TYPE_COIN],
        [10, 'Moneda de ₡10', CashDenomination::TYPE_COIN],
        [5, 'Moneda de ₡5', CashDenomination::TYPE_COIN],
    ];

    public function provision(Company $company): void
    {
        CashDenomination::query()
            ->forCompany($company->id)
            ->forCurrency('CRC')
            ->where('value', 50000)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        foreach (self::CRC_DENOMINATIONS as $index => [$value, $label, $type]) {
            CashDenomination::firstOrCreate(
                ['company_id' => $company->id, 'currency_code' => 'CRC', 'value' => $value],
                ['label' => $label, 'type' => $type, 'sort_order' => ($index + 1) * 10, 'is_active' => true],
            );
        }
    }
}
