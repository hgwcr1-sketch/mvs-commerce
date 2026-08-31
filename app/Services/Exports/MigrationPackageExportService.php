<?php

namespace App\Services\Exports;

use Illuminate\Support\Str;
use ZipArchive;

class MigrationPackageExportService
{
    public const DATASETS = ['customers', 'products', 'sales', 'inventory-migration', 'loyalty-migration'];

    public function __construct(private readonly DataExportService $exports) {}

    public function build(int $companyId, int $branchId): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mvs-migration-');
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::OVERWRITE) === true, 500);
        $manifest = ['version' => 1, 'company_id' => $companyId, 'generated_at' => now()->toIso8601String(), 'files' => []];

        foreach (self::DATASETS as $dataset) {
            [$headers, $rows] = $this->exports->dataset($dataset, $companyId, $branchId);
            $content = $this->csv($headers, $rows);
            $name = Str::slug(DataExportService::DATASETS[$dataset]['label']).'.csv';
            $zip->addFromString($name, $content);
            $manifest['files'][] = ['dataset' => $dataset, 'file' => $name, 'rows' => count($rows), 'sha256' => hash('sha256', $content)];
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->close();

        return $path;
    }

    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
