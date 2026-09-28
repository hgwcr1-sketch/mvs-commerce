<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerTaxpayerActivity;
use Illuminate\Support\Facades\DB;

/**
 * Persistencia de las actividades económicas del contribuyente.
 *
 * Separa el dato CONSULTADO (propuesta de TaxpayerLookupService) del dato
 * PERSISTIDO (esta tabla). Solo se guarda lo que el usuario aplicó
 * explícitamente: una consulta a Hacienda nunca escribe por sí sola.
 *
 * Tabla normalizada: una fila por actividad, `company_id` en cada fila para
 * aislamiento por empresa y `code` único por cliente.
 */
class CustomerTaxpayerActivityService
{
    /**
     * Reemplaza el set completo de actividades del cliente dentro de la
     * empresa a la que pertenece. Sin actividades válidas no borra nada:
     * solo actúa cuando se envían códigos.
     *
     * `is_primary` NO se toma del payload: la fuente oficial `/fe/ae` no marca
     * una actividad principal, y marcarla sería inventar un dato tributario.
     * La bandera solo se acepta si la fuente la declara (`primary` /
     * `es_principal`) dentro de la fila normalizada.
     *
     * @param  list<array<string, mixed>>  $activities
     */
    public function syncForCustomer(Customer $customer, array $activities): void
    {
        $rows = [];

        foreach ($activities as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $code = isset($entry['code']) ? mb_substr(trim((string) $entry['code']), 0, 20) : '';

            if ($code === '' || preg_match('/^\d{1,20}$/', $code) !== 1) {
                continue;
            }

            $description = isset($entry['description']) ? trim((string) $entry['description']) : '';
            $description = $description !== '' ? mb_substr($description, 0, 255) : null;

            $rows[$code] = [
                'code' => $code,
                'description' => $description,
                'is_primary' => $this->isPrimaryMarkedBySource($entry),
            ];
        }

        if ($rows === []) {
            return;
        }

        $companyId = (int) $customer->company_id;

        DB::transaction(function () use ($customer, $companyId, $rows) {
            CustomerTaxpayerActivity::query()
                ->where('company_id', $companyId)
                ->where('customer_id', $customer->id)
                ->delete();

            foreach (array_values($rows) as $row) {
                CustomerTaxpayerActivity::query()->create($row + [
                    'company_id' => $companyId,
                    'customer_id' => $customer->id,
                ]);
            }
        });
    }

    /**
     * `is_primary` solo es true si la FUENTE lo declara de forma explícita.
     *
     * @param  array<string, mixed>  $entry
     */
    private function isPrimaryMarkedBySource(array $entry): bool
    {
        if (array_key_exists('primary', $entry)) {
            return (bool) $entry['primary'];
        }

        if (array_key_exists('es_principal', $entry)) {
            return (bool) $entry['es_principal'];
        }

        return false;
    }

    /**
     * Propuesta (solo lectura) lista para la UI, desde la respuesta ya
     * normalizada del lookup.
     *
     * @param  array<string, mixed>  $lookup
     * @return array{regime: ?string, situation: ?string, activities: list<array{code: string, description: ?string}>}
     */
    public function proposalFromLookup(array $lookup): array
    {
        $activities = [];

        foreach (is_array($lookup['activities'] ?? null) ? $lookup['activities'] : [] as $activity) {
            if (! is_array($activity)) {
                continue;
            }

            $code = trim((string) ($activity['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $activities[] = [
                'code' => $code,
                'description' => isset($activity['description']) && $activity['description'] !== null
                    ? (string) $activity['description']
                    : null,
            ];
        }

        return [
            'regime' => isset($lookup['regime']) && is_string($lookup['regime']) ? $lookup['regime'] : null,
            'situation' => isset($lookup['situation']) && is_string($lookup['situation']) ? $lookup['situation'] : null,
            'activities' => $activities,
        ];
    }
}
