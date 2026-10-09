<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\GeoCatalogLevel;
use Illuminate\Database\Seeder;

class GeoCatalogSeeder extends Seeder
{
    /**
     * Configura los países soportados y sus niveles territoriales.
     * No importa divisiones masivas; solo estructura configurable (15A).
     */
    public function run(): void
    {
        $default = (string) config('geography.default_country', 'CR');

        foreach ((array) config('geography.countries', []) as $iso2 => $data) {
            $isDefault = $iso2 === $default;

            $country = Country::updateOrCreate(
                ['iso2' => $iso2],
                [
                    'name' => $data['name'],
                    'iso3' => $data['iso3'],
                    'phone_code' => $data['phone_code'],
                    'currency' => $data['currency'],
                    'currency_symbol' => $data['currency_symbol'],
                    'is_default' => $isDefault,
                    'is_active' => true,
                ]
            );

            foreach ((array) ($data['levels'] ?? []) as $level) {
                GeoCatalogLevel::updateOrCreate(
                    [
                        'country_id' => $country->id,
                        'level' => (int) $level['level'],
                    ],
                    [
                        'name' => $level['name'],
                        'parent_level' => $level['parent_level'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
