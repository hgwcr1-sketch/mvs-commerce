<?php

namespace App\Http\Controllers\Cobros;

use App\Models\CobrosMigrationNotice;
use App\Models\CobrosRoute;
use Illuminate\Http\Request;

/**
 * 13-1A — importación directa de créditos por el COBRADOR, reutilizando el
 * controlador del prestamista (12B-2) con dos restricciones: ruta obligatoria
 * por fila y solo rutas donde el usuario es miembro activo (applyRoute()).
 * Idempotencia/conflictos y errores por fila intactos; tras confirmar deja
 * el aviso «Migración pendiente de revisar» para el prestamista.
 */
class CollectorCreditImportController extends CreditImportController
{
    protected function routeRequired(): bool
    {
        return true;
    }

    protected function validateRoutesInPreview(): bool
    {
        return true;
    }

    protected function afterConfirm(Request $request, array $results): void
    {
        $company = $this->activeCompany();
        $collector = $request->user();
        $routeNames = collect($results)
            ->filter(fn (array $row) => in_array($row['status'], ['imported', 'duplicate'], true))
            ->map(fn (array $row) => trim((string) ($row['data']['route_name'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        $importedRows = collect($results)->where('status', 'imported');
        $imported = $importedRows->count();
        $summary = [
            'imported' => $imported,
            'duplicates' => collect($results)->where('status', 'duplicate')->count(),
            'conflicts' => collect($results)->where('status', 'conflict')->count(),
            'invalid' => collect($results)->where('status', 'invalid')->count(),
            'original_total' => $importedRows->sum(fn (array $r) => (string) ($r['data']['original_amount'] ?? '0')),
            'balance_total' => $importedRows->sum(fn (array $r) => (string) ($r['data']['balance'] ?? '0')),
        ];
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
                'source' => CobrosMigrationNotice::SOURCE_CREDIT_IMPORT,
                'details' => $imported.' crédito(s) importados por el cobrador.',
                'summary' => $summary,
                'status' => CobrosMigrationNotice::STATUS_PENDING,
            ]);
        }
    }
}
