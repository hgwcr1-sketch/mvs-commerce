<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Company;
use App\Services\Cobros\CobrosAlertService;
use App\Services\Notifications\AlertTypeRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Fase 5A — pantalla de alertas CobrOS (dueño y supervisor).
 *
 * Evalúa las condiciones diarias (deduplicadas por el dispatcher) y lista
 * únicamente las alertas del canal CobrOS de la empresa activa: nunca
 * mezcla alertas de otros módulos ni de otra empresa. Solo lectura sobre
 * dinero: no registra cobros, anulaciones ni cambios de cartera.
 */
class CobrosAlertController extends Controller
{
    public function index(Request $request, CobrosAlertService $alerts): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        $summary = $alerts->evaluate($company);

        $openCounts = Alert::query()
            ->where('company_id', $company->id)
            ->whereIn('type', CobrosAlertService::TYPES)
            ->where('status', '!=', Alert::STATUS_RESOLVED)
            ->get(['type'])
            ->countBy('type');

        $alertsList = Alert::query()
            ->where('company_id', $company->id)
            ->whereIn('type', CobrosAlertService::TYPES)
            ->with('branch:id,name')
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('cobros.alerts.index', [
            'company' => $company,
            'summary' => $summary,
            'openCounts' => $openCounts,
            'alerts' => $alertsList,
            'labels' => collect(CobrosAlertService::TYPES)
                ->mapWithKeys(fn (string $type) => [$type => AlertTypeRegistry::label($type)]),
        ]);
    }
}
