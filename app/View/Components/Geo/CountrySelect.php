<?php

namespace App\View\Components\Geo;

use Illuminate\View\Component;

class CountrySelect extends Component
{
    public array $countries;

    public ?string $selected;

    public function __construct(?string $country = null)
    {
        $this->selected = $country ?: null;

        $this->countries = collect((array) config('geography.countries', []))
            ->map(fn (array $data, string $iso) => ['iso' => $iso, 'name' => $data['name']])
            ->values()
            ->all();
    }

    public function render()
    {
        return view('components.geo.country-select');
    }
}
