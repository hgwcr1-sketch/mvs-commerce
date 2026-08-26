<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Exports\DataExportService;
use App\Services\Reports\EssentialReportQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportCenterController extends Controller
{
    public function show(Request $request, EssentialReportQuery $reports, string $category): View
    {
        abort_unless(isset(EssentialReportQuery::CATEGORIES[$category]), 404);
        $company = Company::query()->findOrFail((int) session('active_company_id'));
        $definition = EssentialReportQuery::CATEGORIES[$category];
        abort_unless($request->user()->hasPermission('reportes.ver', $company), 403);
        abort_unless($request->user()->hasPermission($definition['permission'], $company), 403);

        $filters = $this->filters($request, $company);
        $filters['can_view_receivables'] = $request->user()->hasPermission('cuentas_cobrar.ver', $company);
        $filters['can_view_payables'] = $request->user()->hasPermission('cuentas_pagar.ver', $company);
        $report = $reports->run($category, $company->id, $filters);
        $canExport = $request->user()->hasPermission('reportes.exportar', $company);
        $exportDatasets = collect([$report['export_dataset'] ?? null, $report['secondary_export_dataset'] ?? null])
            ->filter()
            ->filter(fn (string $dataset) => $canExport
                && $request->user()->hasPermission(DataExportService::DATASETS[$dataset]['permission'], $company))
            ->mapWithKeys(fn (string $dataset) => [$dataset => DataExportService::DATASETS[$dataset]['label']]);

        return view('data-center.report', [
            'category' => $category,
            'definition' => $definition,
            'report' => $report,
            'filters' => $filters,
            'options' => $this->options($request, $company),
            'exportDatasets' => $exportDatasets,
        ]);
    }

    private function filters(Request $request, Company $company): array
    {
        $companyId = $company->id;
        $assignedBranches = $request->user()->branches()->where('company_id', $companyId)->pluck('branches.id');
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::in($assignedBranches->all())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'user_id' => ['nullable', 'integer', Rule::exists('company_user', 'user_id')->where('company_id', $companyId)],
        ]);

        return [
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : (int) session('active_branch_id'),
            'from' => $data['from'] ?? now()->startOfMonth()->toDateString(),
            'to' => $data['to'] ?? now()->toDateString(),
            'product_id' => isset($data['product_id']) ? (int) $data['product_id'] : null,
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
        ];
    }

    private function options(Request $request, Company $company): array
    {
        return [
            'branches' => $request->user()->branches()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['branches.id', 'branches.name']),
            'products' => Product::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => Customer::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'users' => User::query()->whereHas('companies', fn ($query) => $query->where('companies.id', $company->id))->orderBy('name')->get(['id', 'name']),
        ];
    }
}
