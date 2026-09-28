<?php

namespace App\Services\Imports;

use App\Models\LoyaltyMigrationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RepairP37IncompatibleSnapshots
{
    public function inspect(int $companyId, string $sourceKey, int $batchId): array
    {
        [$run, $batch, $candidates] = $this->context($companyId, $sourceKey, $batchId);
        foreach ($candidates as $row) {
            $this->validateCandidate($row, $companyId, $sourceKey, $batchId);
        }

        return [
            'run_id' => $run->id,
            'batch_id' => $batch->id,
            'candidates' => count($candidates),
            'row_numbers' => collect($candidates)->pluck('row_number')->all(),
            'imported_count' => (int) $batch->row_count,
            'pending_count' => DB::table('loyalty_migration_pending_rows')->where('batch_id', $batchId)->count(),
        ];
    }

    public function apply(int $companyId, string $sourceKey, int $batchId): array
    {
        return DB::transaction(function () use ($companyId, $sourceKey, $batchId): array {
            [$run, $batch, $candidates] = $this->context($companyId, $sourceKey, $batchId, true);
            $repaired = 0;

            foreach ($candidates as $row) {
                $pending = DB::table('loyalty_migration_pending_rows')
                    ->where('batch_id', $batchId)->where('row_number', $row['row_number'])->exists();
                $eventKey = "loyalty_migration:{$sourceKey}:{$row['row_number']}:legacy_initial_balance";
                $movement = DB::table('loyalty_movements')
                    ->where('company_id', $companyId)
                    ->where('source_type', 'LoyaltyMigration')
                    ->where('source_id', $batchId)
                    ->where('event_key', $eventKey)
                    ->lockForUpdate()->first();

                if (! $movement) {
                    if ($pending) {
                        continue;
                    }
                    throw ValidationException::withMessages(['repair' => "No se encontró el efecto P37 esperado para la fila {$row['row_number']}."]);
                }
                if ($pending || (int) $movement->customer_id !== (int) $row['customer_id']
                    || $movement->type !== 'adjustment'
                    || bccomp((string) $movement->points, (string) $row['balance'], 4) !== 0) {
                    throw ValidationException::withMessages(['repair' => "El efecto P37 de la fila {$row['row_number']} no coincide exactamente con el preview original."]);
                }

                $laterMovementExists = DB::table('loyalty_movements')
                    ->where('loyalty_account_id', $movement->loyalty_account_id)
                    ->where('id', '<>', $movement->id)
                    ->exists();
                if ($laterMovementExists) {
                    throw ValidationException::withMessages(['repair' => "La fila {$row['row_number']} tiene movimientos posteriores y no puede repararse automáticamente."]);
                }

                DB::table('loyalty_movements')->where('id', $movement->id)->delete();
                if (empty($row['current_account_id'])) {
                    DB::table('loyalty_accounts')->where('id', $movement->loyalty_account_id)
                        ->where('company_id', $companyId)->where('customer_id', $row['customer_id'])->delete();
                } else {
                    DB::table('loyalty_accounts')->where('id', $row['current_account_id'])
                        ->where('company_id', $companyId)->where('customer_id', $row['customer_id'])->update([
                            'balance' => $row['current_balance'] ?? '0.0000',
                            'last_activity_at' => null,
                            'updated_at' => now(),
                        ]);
                }

                DB::table('loyalty_migration_pending_rows')->insert([
                    'batch_id' => $batchId,
                    'company_id' => $companyId,
                    'source_key' => $sourceKey,
                    'row_number' => $row['row_number'],
                    'source_rows' => json_encode($row['source_row_numbers'], JSON_THROW_ON_ERROR),
                    'source_data' => json_encode(collect($row)->only(['name', 'identification', 'phone', 'email', 'awarded_points', 'used_points', 'balance'])->all(), JSON_THROW_ON_ERROR),
                    'reasons' => json_encode($row['errors'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $repaired++;
            }

            $importedCount = max(0, (int) $batch->row_count - $repaired);
            DB::table('loyalty_migration_batches')->where('id', $batchId)->update([
                'row_count' => $importedCount,
                'updated_at' => now(),
            ]);
            $pendingCount = DB::table('loyalty_migration_pending_rows')->where('batch_id', $batchId)->count();
            $run->update([
                'imported_count' => $importedCount,
                'pending_count' => $pendingCount,
            ]);

            return [
                'run_id' => $run->id,
                'batch_id' => $batchId,
                'candidates' => count($candidates),
                'repaired' => $repaired,
                'row_numbers' => collect($candidates)->pluck('row_number')->all(),
                'imported_count' => $importedCount,
                'pending_count' => $pendingCount,
            ];
        });
    }

    private function context(int $companyId, string $sourceKey, int $batchId, bool $lock = false): array
    {
        $runQuery = LoyaltyMigrationRun::query()->where('company_id', $companyId)->where('source_key', $sourceKey);
        $batchQuery = DB::table('loyalty_migration_batches')->where('id', $batchId)
            ->where('company_id', $companyId)->where('source_key', $sourceKey);
        if ($lock) {
            $runQuery->lockForUpdate();
            $batchQuery->lockForUpdate();
        }
        $run = $runQuery->firstOrFail();
        $batch = $batchQuery->firstOrFail();
        $candidates = $this->candidates($run->preview_payload);
        if ($candidates === []) {
            throw ValidationException::withMessages(['repair' => 'El preview original no contiene snapshots legacy incompatibles.']);
        }

        return [$run, $batch, $candidates];
    }

    private function candidates(array $preview): array
    {
        $sourceRows = collect($preview['source_rows'] ?? [])->keyBy('row_number');

        return collect($preview['rows'] ?? [])->filter(function (array $row) use ($sourceRows): bool {
            if (($row['consolidation_method'] ?? null) !== 'incompatible' || empty($row['customer_id'])) {
                return false;
            }
            $members = collect($row['source_row_numbers'] ?? [])->map(fn ($number) => $sourceRows->get($number))->filter();
            if ($members->count() < 2 || $members->contains(fn (array $member) => ! $this->isLegacySnapshot($member))) {
                return false;
            }

            return $members->pluck('balance')->map(fn ($balance) => bcadd((string) $balance, '0', 4))->unique()->count() > 1;
        })->values()->all();
    }

    private function validateCandidate(array $row, int $companyId, string $sourceKey, int $batchId): void
    {
        $pending = DB::table('loyalty_migration_pending_rows')
            ->where('batch_id', $batchId)->where('row_number', $row['row_number'])->exists();
        $eventKey = "loyalty_migration:{$sourceKey}:{$row['row_number']}:legacy_initial_balance";
        $movement = DB::table('loyalty_movements')
            ->where('company_id', $companyId)->where('source_type', 'LoyaltyMigration')
            ->where('source_id', $batchId)->where('event_key', $eventKey)->first();
        if (! $movement) {
            if ($pending) {
                return;
            }
            throw ValidationException::withMessages(['repair' => "No se encontró el efecto P37 esperado para la fila {$row['row_number']}."]);
        }
        if ($pending || (int) $movement->customer_id !== (int) $row['customer_id']
            || $movement->type !== 'adjustment'
            || bccomp((string) $movement->points, (string) $row['balance'], 4) !== 0) {
            throw ValidationException::withMessages(['repair' => "El efecto P37 de la fila {$row['row_number']} no coincide exactamente con el preview original."]);
        }
        if (DB::table('loyalty_movements')->where('loyalty_account_id', $movement->loyalty_account_id)
            ->where('id', '<>', $movement->id)->exists()) {
            throw ValidationException::withMessages(['repair' => "La fila {$row['row_number']} tiene movimientos posteriores y no puede repararse automáticamente."]);
        }
    }

    private function isLegacySnapshot(array $row): bool
    {
        $values = collect(['awarded_points', 'used_points', 'balance'])
            ->map(fn (string $field) => (string) ($row[$field] ?? ''));
        if ($values->contains(fn (string $value) => preg_match('/^\d+(?:\.\d{1,4})?$/', $value) !== 1)) {
            return false;
        }

        return bccomp($values[0], '0', 4) === 0
            && bccomp($values[1], '0', 4) === 0
            && bccomp($values[2], '0', 4) > 0;
    }
}
