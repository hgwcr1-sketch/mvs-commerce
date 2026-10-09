<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\GeoCatalogLevel;
use App\Models\GeoDivision;
use Illuminate\Http\Request;

class GeoDivisionController extends Controller
{
    /**
     * Lista dependiente de divisiones del nuevo catálogo internacional.
     * Solo datos reales cargados: sin catálogo responde has_catalog=false
     * y división vacía (nunca opciones inventadas).
     */
    public function index(Request $request)
    {
        $iso = strtoupper((string) $request->query('pais', ''));

        abort_unless(in_array($iso, array_keys((array) config('geography.countries', [])), true), 422, 'País no soportado.');

        $country = Country::query()->where('iso2', $iso)->where('is_active', true)->first();

        abort_if(! $country, 422, 'País no configurado o inactivo.');

        $hasCatalog = GeoDivision::query()->forCountry($iso)->active()->exists();

        $parentId = $request->query('padre');
        $query = GeoDivision::query()->forCountry($iso)->active();

        if ($parentId) {
            $parent = GeoDivision::query()->forCountry($iso)->whereKey($parentId)->firstOrFail();
            $divisions = $query->where('parent_id', $parent->id)->orderBy('code')->get();
        } else {
            $divisions = $query->roots()->where('level', 1)->orderBy('code')->get();
        }

        return response()->json([
            'has_catalog' => $hasCatalog,
            'levels' => GeoCatalogLevel::query()->forCountry($iso)->active()->orderBy('level')->get()
                ->filter(fn (GeoCatalogLevel $level) => GeoDivision::query()
                    ->where('country_id', $level->country_id)
                    ->where('level', $level->level)
                    ->active()
                    ->exists())
                ->map(fn (GeoCatalogLevel $level) => [
                    'level' => (int) $level->level,
                    'name' => $level->name,
                ])->values(),
            'divisions' => $divisions->map(fn (GeoDivision $division) => [
                'id' => (int) $division->id,
                'code' => $division->code,
                'name' => $division->name,
                'level' => (int) $division->level,
            ])->values(),
        ]);
    }
}
