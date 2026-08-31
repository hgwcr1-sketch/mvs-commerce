<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Services\Exports\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DataExportController extends Controller
{
    public function download(Request $request, DataExportService $exports, string $dataset, string $format)
    {
        abort_unless(isset(DataExportService::DATASETS[$dataset]), 404);
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $companyId = (int) session('active_company_id');
        $company = Company::query()->findOrFail($companyId);
        $definition = DataExportService::DATASETS[$dataset];
        abort_unless($request->user()->hasPermission($definition['permission'], $company), 403);

        $branchId = $definition['branch'] ? $this->resolveBranchId($request, $company, $dataset) : null;
        [$headers, $rows] = $exports->dataset($dataset, $companyId, $branchId);
        $fileName = Str::slug($definition['label']).'-'.now()->format('Ymd-His').'.'.$format;

        return $format === 'csv'
            ? $this->csv($headers, $rows, $fileName)
            : $this->xlsx($headers, $rows, $definition['label'], $fileName);
    }

    private function resolveBranchId(Request $request, Company $company, string $dataset): int
    {
        $branchId = $request->integer('branch_id') ?: (int) session('active_branch_id');
        $branch = Branch::query()->where('company_id', $company->id)->where('is_active', true)->findOrFail($branchId);
        $isAssigned = $request->user()->branches()->whereKey($branch->id)->exists();
        abort_unless($isAssigned, 403);

        if (in_array($dataset, ['inventory', 'inventory-migration'], true) && $branch->id !== (int) session('active_branch_id')) {
            abort_unless($request->user()->hasPermission('inventario.ver_otras_sucursales', $company), 403);
        }

        return $branch->id;
    }

    private function csv(array $headers, array $rows, string $fileName)
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $headers, array $rows, string $title, string $fileName)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->fromArray(array_merge([$headers], $rows), null, 'A1');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $fileName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
