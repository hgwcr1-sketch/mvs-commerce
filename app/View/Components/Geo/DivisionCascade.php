<?php

namespace App\View\Components\Geo;

use App\Models\Country;
use App\Models\GeoCatalogLevel;
use App\Models\GeoDivision;
use Illuminate\View\Component;

class DivisionCascade extends Component
{
    public ?string $countryIso;

    public ?int $division = null;

    public bool $hasCatalog = false;

    public string $notice = '';

    public array $levels = [];

    public array $selectedByLevel = [];

    public array $optionsByLevel = [];

    public function __construct(?string $country = null, ?int $division = null)
    {
        $iso = strtoupper((string) ($country ?: config('geography.default_country', 'CR')));
        $this->countryIso = $iso;
        $this->division = $division;

        $countryModel = Country::query()->where('iso2', $iso)->where('is_active', true)->first();

        if (! $countryModel) {
            $this->notice = 'País no configurado.';

            return;
        }

        $levels = GeoCatalogLevel::query()->forCountry($iso)->active()->orderBy('level')->get()
            ->filter(fn (GeoCatalogLevel $level) => GeoDivision::query()
                ->where('country_id', $level->country_id)
                ->where('level', $level->level)
                ->active()
                ->exists())
            ->values();

        if ($levels->isEmpty()) {
            $this->notice = "El catálogo territorial de {$countryModel->name} aún no está cargado. "
                . 'No hay divisiones reales disponibles para seleccionar.';

            return;
        }

        $this->hasCatalog = true;
        $this->levels = $levels->map(fn (GeoCatalogLevel $level) => [
            'level' => (int) $level->level,
            'name' => $level->name,
        ])->all();

        if ($division) {
            $cursor = GeoDivision::query()->find($division);

            while ($cursor) {
                $this->selectedByLevel[(int) $cursor->level] = (int) $cursor->id;
                $cursor = $cursor->parent_id ? GeoDivision::query()->find($cursor->parent_id) : null;
            }
        }

        foreach ($this->levels as $index => $level) {
            $query = GeoDivision::query()->forCountry($iso)->active()->orderBy('code');

            if ($index === 0) {
                $query->roots()->where('level', 1);
            } else {
                $parentId = $this->selectedByLevel[$this->levels[$index - 1]['level']] ?? null;

                if (! $parentId) {
                    $this->optionsByLevel[$level['level']] = [];
                    continue;
                }

                $query->where('parent_id', $parentId);
            }

            $this->optionsByLevel[$level['level']] = $query->get()
                ->map(fn (GeoDivision $option) => [
                    'id' => (int) $option->id,
                    'name' => $option->name,
                    'selected' => ($this->selectedByLevel[$level['level']] ?? null) === (int) $option->id,
                ])->all();
        }
    }

    public function render()
    {
        return view('components.geo.division-cascade');
    }
}
