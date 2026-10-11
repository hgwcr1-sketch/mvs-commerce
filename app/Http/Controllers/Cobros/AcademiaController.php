<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Cobros\AcademiaCatalog;
use App\Services\Cobros\CobrosModalityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * BLOQUE 21A-2 — Academia MVS CobrOS (solo lectura).
 *
 * Muestra únicamente las tres rutas de capacitación declaradas en
 * AcademiaCatalog, resolviendo la modalidad de la membresía activa con
 * CobrosModalityService (misma fuente de verdad que el resto de CobrOS).
 *
 * No escribe en base de datos, no expone videos inexistentes y no mezcla datos
 * de otras empresas: el único dato de tenant es el nombre de la empresa activa.
 */
class AcademiaController extends Controller
{
    /** Listado de las tres rutas de capacitación. */
    public function index(Request $request): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $currentModality = app(CobrosModalityService::class)
            ->currentModality($company, $request->user());

        return view('cobros.academia.index', [
            'company' => $company,
            'tracks' => AcademiaCatalog::tracks(),
            'currentModality' => $currentModality,
            'currentTrack' => AcademiaCatalog::trackForModality($currentModality),
        ]);
    }

    /** Temario de una ruta de capacitación; 404 para claves internas o ajenas. */
    public function show(Request $request, string $track): View
    {
        if (! AcademiaCatalog::has($track)) {
            abort(404);
        }

        $company = Company::findOrFail((int) session('active_company_id'));
        $currentModality = app(CobrosModalityService::class)
            ->currentModality($company, $request->user());
        $trackData = AcademiaCatalog::track($track);

        return view('cobros.academia.track', [
            'company' => $company,
            'track' => $trackData,
            'tracks' => AcademiaCatalog::tracks(),
            'currentModality' => $currentModality,
            'currentTrack' => AcademiaCatalog::trackForModality($currentModality),
        ]);
    }
}
