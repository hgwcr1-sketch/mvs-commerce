<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3C — consulta de auditoría de cobros para dueño y supervisor.
 *
 * Solo lectura sobre los registros de auditoría de CobrOS
 * (`cobros.payment.registered` y `cobros.payment.annulled`), siempre
 * acotados a la empresa activa de la sesión. Nunca muta saldo, custodia
 * ni recibos.
 */
class CobrosAuditController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $user = $request->user();

        $logs = DB::table('routeos_audit_logs')
            ->where('company_id', $company->id)
            ->whereIn('action', ['cobros.payment.registered', 'cobros.payment.annulled'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(20);

        return view('cobros.owner.audit', [
            'company' => $company,
            'logs' => $logs,
            'actorNames' => DB::table('users')
                ->whereIn('id', collect($logs->items())->pluck('actor_id')->filter()->unique())
                ->pluck('name', 'id'),
        ]);
    }
}
