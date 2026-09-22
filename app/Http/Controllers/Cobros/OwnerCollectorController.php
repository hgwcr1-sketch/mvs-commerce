<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\CollectorManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerCollectorController extends Controller
{
    public function index(Request $request, CollectorManagementService $service): View
    {
        $company = Company::findOrFail((int) session('active_company_id'));
        $contract = $service->getContract($company);
        $collectors = $service->listCollectors($company);
        $branches = Branch::where('company_id', $company->id)->orderBy('name')->get();

        return view('cobros.owner.collectors', [
            'company' => $company,
            'contract' => $contract,
            'collectors' => $collectors,
            'branches' => $branches,
            'collectorCount' => $service->getCollectorCount($company),
            'canCreate' => $service->canCreateCollector($company),
            'remainingSlots' => $service->getRemainingSlots($company),
            'isReadOnly' => $service->isOwnerReadOnly($company),
        ]);
    }

    public function store(Request $request, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede crear cobradores en modalidad de licencias individuales.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $result = $service->createCollector($company, $data, $request->user());

        return back()
            ->with('success', "Cobrador {$result['user']->name} registrado exitosamente.")
            ->with('collector_plain_password', $result['plain_password'])
            ->with('collector_email', $result['user']->email)
            ->with('collector_name', $result['user']->name)
            ->with('collector_phone', $result['user']->phone);
    }

    public function regenerateAccess(User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $plainPassword = $service->regenerateAccess($user, $company);

        return back()
            ->with('success', "Nuevo acceso generado para {$user->name}.")
            ->with('collector_plain_password', $plainPassword)
            ->with('collector_email', $user->email)
            ->with('collector_name', $user->name)
            ->with('collector_phone', $user->phone);
    }

    public function toggle(User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $service->toggleCollector($user, $company, ! $user->is_active);

        $status = $user->fresh()->is_active ? 'activado' : 'desactivado';

        return back()->with('success', "Cobrador {$user->name} {$status}.");
    }

    public function updateBranches(Request $request, User $user, CollectorManagementService $service): RedirectResponse
    {
        $company = Company::findOrFail((int) session('active_company_id'));

        if ($service->isOwnerReadOnly($company)) {
            abort(403, 'No puede modificar cobradores en modalidad de licencias individuales.');
        }

        $data = $request->validate([
            'branch_ids' => ['required', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
        ]);

        $service->assignBranches($user, $company, $data['branch_ids']);

        return back()->with('success', "Sucursales de {$user->name} actualizadas.");
    }
}
