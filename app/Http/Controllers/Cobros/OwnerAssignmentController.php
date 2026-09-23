<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosAssignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Cobros\PortfolioAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerAssignmentController extends Controller
{
    public function index(Request $request, PortfolioAssignmentService $service): View
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $filters = $request->validate([
            'collector_user_id' => ['nullable', 'integer', 'min:1'],
            'assignment_status' => ['nullable', 'in:assigned,unassigned'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $collectorFilter = isset($filters['collector_user_id']) ? (int) $filters['collector_user_id'] : null;
        $statusFilter = $filters['assignment_status'] ?? null;
        $branchFilter = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;

        $assignments = CobrosAssignment::query()
            ->forCompany($company->id)
            ->get()
            ->keyBy('customer_id');

        $query = Customer::query()
            ->forCompany($company->id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($statusFilter === 'assigned') {
            $query->whereIn('id', $assignments->keys());
        } elseif ($statusFilter === 'unassigned') {
            $query->whereNotIn('id', $assignments->keys());
        }

        if ($collectorFilter !== null) {
            $query->whereIn('id', $assignments->where('collector_user_id', $collectorFilter)->keys());
        }

        if ($branchFilter !== null) {
            $query->whereHas('accountsReceivable', function ($q) use ($company, $branchFilter) {
                $q->where('company_id', $company->id)
                    ->where('branch_id', $branchFilter)
                    ->where('balance_due', '>', 0)
                    ->whereNotIn('status', ['paid', 'cancelled']);
            });
        }

        $customers = $query->paginate(20)->withQueryString();

        $balances = [];
        $openAccounts = \App\Models\AccountReceivable::query()
            ->forCompany($company->id)
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->whereIn('customer_id', $customers->pluck('id'))
            ->get(['customer_id', 'balance_due', 'branch_id']);

        foreach ($openAccounts as $account) {
            $id = (int) $account->customer_id;
            $balances[$id] = bcadd($balances[$id] ?? '0.0000', $account->balance_due, 4);
        }

        $collectors = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
            ->where('is_active', true)
            ->whereHas('roles', function ($q) use ($company) {
                $q->where('roles.company_id', $company->id)
                    ->where('roles.is_active', true)
                    ->where('roles.is_super_admin', false)
                    ->whereHas('permissions', fn ($p) => $p->where('permissions.name', 'cobros.cobrar'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $rows = $customers->getCollection()->map(function (Customer $customer) use ($assignments, $balances, $collectors) {
            $assignment = $assignments->get($customer->id);
            $collector = $assignment
                ? $collectors->firstWhere('id', $assignment->collector_user_id)
                    ?? User::query()->whereKey($assignment->collector_user_id)->first(['id', 'name', 'email'])
                : null;

            return [
                'customer' => $customer,
                'balance' => $balances[$customer->id] ?? '0.0000',
                'collector' => $collector,
                'assignment' => $assignment,
            ];
        });

        $branches = \App\Models\Branch::query()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('cobros.owner.assignments', [
            'company' => $company,
            'rows' => $rows,
            'customers' => $customers,
            'collectors' => $collectors,
            'branches' => $branches,
            'filters' => [
                'collector_user_id' => $collectorFilter,
                'assignment_status' => $statusFilter,
                'branch_id' => $branchFilter,
            ],
        ]);
    }

    public function store(Request $request, PortfolioAssignmentService $service): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'collector_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = Customer::query()
            ->forCompany($company->id)
            ->findOrFail((int) $data['customer_id']);

        $collector = User::query()->findOrFail((int) $data['collector_user_id']);

        $service->assign($company, $customer, $collector, $request->user(), $data['reason'] ?? null);

        return back()->with('success', "Cliente {$customer->name} asignado correctamente.");
    }

    public function destroy(Request $request, Customer $customer, PortfolioAssignmentService $service): RedirectResponse
    {
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $customer = Customer::query()
            ->forCompany($company->id)
            ->findOrFail($customer->id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $service->unassign($company, $customer, $request->user(), $data['reason'] ?? null);

        return back()->with('success', "Cliente {$customer->name} quitado de la cartera.");
    }
}
