<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosMigrationNotice;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 13-1A/13-1B — bandeja del prestamista de migraciones realizadas por cobradores.
 * Filtros por cobrador, ruta y estado; resumen numérico para cotejo con el sistema
 * anterior; «Marcar como revisada» idempotente con trazabilidad (usuario + fecha).
 * Solo lectura + revisión: no bloquea clientes, créditos, rutas ni cobros.
 */
class MigrationNoticeController extends Controller
{
    public function index(Request $request)
    {
        $company = $this->activeCompany();

        $query = CobrosMigrationNotice::query()
            ->where('company_id', $company->id)
            ->with(['route', 'collector', 'reviewedBy']);

        $filters = [
            'collector' => $request->integer('collector') ?: null,
            'route' => $request->integer('route') ?: null,
            'status' => in_array($request->string('status')->toString(), [CobrosMigrationNotice::STATUS_PENDING, CobrosMigrationNotice::STATUS_REVIEWED], true)
                ? $request->string('status')->toString()
                : null,
        ];
        if ($filters['collector'] !== null) {
            $query->where('collector_user_id', $filters['collector']);
        }
        if ($filters['route'] !== null) {
            $query->where('cobros_route_id', $filters['route']);
        }
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        $notices = $query
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $collectors = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->whereIn('id', CobrosMigrationNotice::where('company_id', $company->id)->select('collector_user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
        $routes = CobrosRoute::query()
            ->whereIn('id', CobrosMigrationNotice::where('company_id', $company->id)->select('cobros_route_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('cobros.owner.migration-notices', [
            'company' => $company,
            'notices' => $notices,
            'collectors' => $collectors,
            'routes' => $routes,
            'filters' => $filters,
        ]);
    }

    /** Idempotente: una revisada no se reescribe (conserva la primera trazabilidad). */
    public function review(int $notice)
    {
        $company = $this->activeCompany();
        $row = CobrosMigrationNotice::where('company_id', $company->id)->findOrFail($notice);
        if ($row->status === CobrosMigrationNotice::STATUS_PENDING) {
            $row->update([
                'status' => CobrosMigrationNotice::STATUS_REVIEWED,
                'reviewed_by' => (int) auth()->id(),
                'reviewed_at' => now(),
            ]);
        }

        return redirect()->route('cobros.owner.migrations.notices')->with('status', 'Marcada como revisada.');
    }

    private function activeCompany(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }
}
