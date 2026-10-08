<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\CobrosMigrationNotice;
use App\Models\CobrosRoute;
use App\Models\Company;
use App\Models\CustomerImportRun;
use App\Services\Imports\CustomerImportRunService;
use App\Services\Imports\MigrationTemplateService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 13-1A — importación directa de clientes por el COBRADOR (variante cobros_ruta:
 * ruta obligatoria, solo rutas donde es miembro activo). Reutiliza el motor
 * CustomerImportRunService (modo seguro, idempotente) sin permiso de dueño.
 */
class CollectorCustomerImportController extends Controller
{
    public function index()
    {
        return view('cobros.collector.customer-import', ['company' => $this->activeCompany()]);
    }

    public function template(MigrationTemplateService $templates)
    {
        $spreadsheet = $templates->make('customers', $this->activeCompany()->id, 'cobros');
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer, $spreadsheet): void {
                try {
                    $writer->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            },
            'plantilla_importacion_clientes.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function preview(Request $request, CustomerImportRunService $import)
    {
        $request->validate([
            'customer_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);
        $companyId = $this->activeCompany()->id;
        $run = $import->upload($request->file('customer_file'), $companyId, (int) $request->user()->id, 'cobros_ruta');
        session(['cobros_customer_import_preview' => ['company_id' => $companyId, 'run_id' => $run->id]]);

        return $this->status($request, $run->id);
    }

    public function status(Request $request, int $run)
    {
        $company = $this->activeCompany();
        $run = CustomerImportRun::where('company_id', $company->id)->findOrFail($run);
        $rows = $run->rows()->orderBy('source_row')->paginate(50);

        return view('cobros.owner.customer-import-status', [
            'company' => $company,
            'run' => $run,
            'rows' => $rows,
        ]);
    }

    public function confirm(Request $request, CustomerImportRunService $import)
    {
        $preview = session('cobros_customer_import_preview');
        $runId = $request->integer('run_id') ?: (int) ($preview['run_id'] ?? 0);
        if (! $runId) {
            return redirect()->route('cobros.collector.customers.import')
                ->withErrors(['customer_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }

        $company = $this->activeCompany();
        $run = $import->confirm($runId, $company->id, (int) $request->user()->id);
        session()->forget('cobros_customer_import_preview');

        $this->notice($company, $request->user(), $run);

        return redirect()->route('cobros.collector.customers.import.status', $run->id);
    }

    /** Aviso «Migración pendiente de revisar» por cada ruta usada en el archivo. */
    private function notice(Company $company, $collector, CustomerImportRun $run): void
    {
        $routeNames = $run->rows()->where('kind', 'created')->get(['data'])
            ->map(fn ($row) => trim((string) (data_get($row->data, 'route_name') ?? '')))
            ->filter()->unique()->values();
        $created = (int) $run->created_count;
        foreach ($routeNames as $name) {
            $route = CobrosRoute::forCompany($company->id)->where('active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if ($route === null) {
                continue;
            }
            CobrosMigrationNotice::create([
                'company_id' => $company->id,
                'cobros_route_id' => $route->id,
                'collector_user_id' => $collector->id,
                'source' => CobrosMigrationNotice::SOURCE_CUSTOMER_IMPORT,
                'source_id' => $run->id,
                'details' => $created.' cliente(s) importados por el cobrador.',
                'status' => CobrosMigrationNotice::STATUS_PENDING,
            ]);
        }
    }

    private function activeCompany(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }
}
