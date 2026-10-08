<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerImportRun;
use App\Services\Imports\CustomerImportRunService;
use App\Services\Imports\MigrationTemplateService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importación de clientes dentro del shell CobrOS.
 * Reutiliza el motor existente (CustomerImportRunService + IdentityMatcher +
 * MigrationTemplateService) sin duplicar reglas ni tocar el flujo de Commerce.
 */
class CustomerImportController extends Controller
{
    public function index()
    {
        $company = $this->activeCompany();
        $runs = CustomerImportRun::where('company_id', $company->id)->latest()->paginate(10);

        return view('cobros.owner.customer-import', ['company' => $company, 'runs' => $runs]);
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
        $run = $import->upload($request->file('customer_file'), $companyId, (int) $request->user()->id, 'cobros');
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
            return redirect()->route('cobros.owner.customers.import')
                ->withErrors(['customer_file' => 'La vista previa expiró. Cargue nuevamente el archivo.']);
        }

        $company = $this->activeCompany();
        $run = $import->confirm($runId, $company->id, (int) $request->user()->id);
        session()->forget('cobros_customer_import_preview');

        return redirect()->route('cobros.owner.customers.import.status', $run->id);
    }

    public function retry(Request $request, int $run, CustomerImportRunService $import)
    {
        $company = $this->activeCompany();
        $import->retry($run, $company->id, (int) $request->user()->id);

        return redirect()->route('cobros.owner.customers.import.status', $run);
    }

    public function report(Request $request, int $run)
    {
        $company = $this->activeCompany();
        $run = CustomerImportRun::where('company_id', $company->id)->findOrFail($run);

        return response()->streamDownload(function () use ($run) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['fila', 'estado', 'motivo', 'fila_original', 'cliente_creado'], ',', '"', '');
            foreach ($run->rows()->orderBy('source_row')->cursor() as $row) {
                $reason = $row->reason ?? ($run->purged_at ? 'Detalle temporal purgado' : '');
                if (preg_match('/^[=+@\-\t\r]/', $reason)) {
                    $reason = "'".$reason;
                }
                fputcsv($output, [$row->source_row, $row->kind, $reason, $row->duplicate_of_row, $row->created_customer_id], ',', '"', '');
            }
            fclose($output);
        }, 'resultado-clientes-'.$run->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function activeCompany(): Company
    {
        return Company::query()->findOrFail((int) session('active_company_id'));
    }
}
