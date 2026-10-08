<?php

namespace App\Services\Cobros;

use App\Models\CobrosInitialPortfolioEvent;
use App\Models\CobrosInitialPortfolioMigration;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\CobrosInitialPortfolioSnapshot;
use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Cartera inicial POR COBRADOR: company + collector_user_id, ciclos independientes.
 *
 * Estados: disponible -> en_progreso -> finalizada (bloqueada).
 * - Inicia/continúa/finaliza SOLO el cobrador (auth), nunca el prestamista.
 * - Finalizar genera FOTO DE APERTURA inmutable + evento append-only y abre
 *   el portal de inmediato (sin aprobación).
 * - Reapertura (agregar olvidados) SOLO prestamista con motivo; no desbloquea
 *   registros congelados. Revisión posterior es control administrativo.
 */
class InitialPortfolioMigrationService
{
    public const STATE_AVAILABLE = 'disponible';

    public const STATE_IN_PROGRESS = 'en_progreso';

    public const STATE_FINALIZED = 'finalizada';

    public function stateFor(Company $company, User $collector): string
    {
        $migration = $this->migrationFor($company, $collector);

        if (! $migration) {
            // Legacy sin ciclo: la pantalla muestra "Disponible", pero NO está enrolado.
            return self::STATE_AVAILABLE;
        }

        if ($migration->isFinalized()) {
            return self::STATE_FINALIZED;
        }

        return $migration->status === CobrosInitialPortfolioMigration::STATUS_AVAILABLE
            ? self::STATE_AVAILABLE
            : self::STATE_IN_PROGRESS;
    }

    /**
     * Señal persistida de incorporación al flujo: existe ciclo y no está finalizado.
     * Legacy (sin fila) NUNCA queda atrapado en el redirect de /cobros.
     */
    public function hasOpenCycle(Company $company, User $collector): bool
    {
        $migration = $this->migrationFor($company, $collector);

        return $migration !== null && ! $migration->isFinalized();
    }

    /** Incorporar al flujo: fila en estado "disponible" (idempotente). */
    public function enroll(Company $company, User $collector): CobrosInitialPortfolioMigration
    {
        $existing = $this->migrationFor($company, $collector);
        if ($existing) {
            return $existing;
        }

        return CobrosInitialPortfolioMigration::create([
            'company_id' => $company->id,
            'collector_user_id' => $collector->id,
            'status' => CobrosInitialPortfolioMigration::STATUS_AVAILABLE,
        ]);
    }

    public function migrationFor(Company $company, User $collector): ?CobrosInitialPortfolioMigration
    {
        return CobrosInitialPortfolioMigration::query()
            ->forCompany((int) $company->id)
            ->where('collector_user_id', $collector->id)
            ->first();
    }

    /** Iniciar/continuar: solo el COBRADOR de ese ciclo (no el prestamista). */
    public function start(Company $company, User $collector): CobrosInitialPortfolioMigration
    {
        if ($collector->hasPermission('cobros.portal_dueno', $company)) {
            abort(403, 'La carga inicial pertenece al cobrador, no al prestamista.');
        }

        $existing = $this->migrationFor($company, $collector);
        if ($existing?->isFinalized()) {
            abort(403, 'La migración de cartera inicial ya fue finalizada.');
        }
        if ($existing) {
            // AVAILABLE -> IN_PROGRESS una sola vez (idempotente: reintentar no duplica OPEN).
            if ($existing->status === CobrosInitialPortfolioMigration::STATUS_AVAILABLE) {
                $existing->update([
                    'status' => CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
                    'started_at' => now(),
                    'started_by_user_id' => $collector->id,
                ]);
                $existing = $existing->refresh();
                $this->record($existing, CobrosInitialPortfolioEvent::TYPE_OPEN, $collector);
            }

            return $existing;
        }

        $migration = CobrosInitialPortfolioMigration::create([
            'company_id' => $company->id,
            'collector_user_id' => $collector->id,
            'status' => CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'started_by_user_id' => $collector->id,
        ]);
        $this->record($migration, CobrosInitialPortfolioEvent::TYPE_OPEN, $collector);

        return $migration;
    }

    /** Cierre: congela con FOTO DE APERTURA inmutable + evento append-only. */
    public function finalize(Company $company, User $collector): CobrosInitialPortfolioMigration
    {
        $migration = $this->migrationFor($company, $collector);

        abort_unless($migration !== null && ! $migration->isFinalized(), 403, 'No hay una migración abierta para finalizar.');

        $migration->update([
            'status' => CobrosInitialPortfolioMigration::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by_user_id' => $collector->id,
        ]);
        $migration = $migration->refresh();

        $this->record($migration, CobrosInitialPortfolioEvent::TYPE_CLOSE, $collector);
        $this->snapshot($migration);

        return $migration;
    }

    /** Reapertura por cobrador: SOLO prestamista, motivo y evento; no desbloquea. */
    public function reopen(Company $company, User $collector, User $admin, string $reason): CobrosInitialPortfolioMigration
    {
        if (! $admin->isPlatformAdmin() && ! $admin->hasPermission('cobros.portal_dueno', $company)) {
            abort(403, 'Solo el prestamista (o MVS) puede reabrir la carga inicial.');
        }

        $migration = $this->migrationFor($company, $collector);
        abort_unless($migration?->isFinalized(), 409, 'Solo se reabre una migración finalizada.');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Indique el motivo de la reapertura.']);
        }

        $migration->update([
            'status' => CobrosInitialPortfolioMigration::STATUS_IN_PROGRESS,
            'reopened_at' => now(),
            'reopened_by_user_id' => $admin->id,
            'reopen_reason' => mb_substr(trim($reason), 0, 250),
            'reopen_count' => $migration->reopen_count + 1,
        ]);
        $migration = $migration->refresh();

        $this->record($migration, CobrosInitialPortfolioEvent::TYPE_REOPEN, $admin, $reason);

        return $migration;
    }

    /** Revisión administrativa posterior (NO gate operativo): quién/cuándo + evento. */
    public function markReviewed(Company $company, User $collector, User $owner): CobrosInitialPortfolioMigration
    {
        if (! $owner->hasPermission('cobros.portal_dueno', $company)) {
            abort(403, 'Solo el prestamista revisa la cartera inicial.');
        }

        $migration = $this->migrationFor($company, $collector);
        abort_unless($migration?->isFinalized(), 409, 'Solo se revisa una migración finalizada.');

        $migration->update(['reviewed_at' => now(), 'reviewed_by_user_id' => $owner->id]);
        $migration = $migration->refresh();
        $this->record($migration, CobrosInitialPortfolioEvent::TYPE_REVIEW, $owner);

        return $migration;
    }

    // --------------------------------------------------------

    /** Resumen por cobrador para el prestamista (sin alterar datos). */
    public function summaryFor(Company $company): \Illuminate\Support\Collection
    {
        return CobrosInitialPortfolioMigration::query()
            ->forCompany((int) $company->id)
            ->with('collector')
            ->orderBy('id')
            ->get()
            ->map(function (CobrosInitialPortfolioMigration $migration) {
                $records = CobrosInitialPortfolioRecord::query()->where('migration_id', $migration->id)->get();

                return [
                    'migration' => $migration,
                    'collector' => $migration->collector,
                    'clients' => $records->count(),
                    'balance' => $records->reduce(
                        fn (string $carry, $record) => bcadd($carry, (string) $record->balance, 4),
                        '0.0000'
                    ),
                    'finalized_at' => $migration->finalized_at,
                    'reviewed' => $migration->reviewed_at !== null,
                ];
            });
    }

    /** Foto de apertura determinista e inmutable (append-only). */
    private function snapshot(CobrosInitialPortfolioMigration $migration): void
    {
        $records = CobrosInitialPortfolioRecord::query()->where('migration_id', $migration->id)->get();
        $sum = fn (string $column): string => $records->reduce(
            fn (string $carry, CobrosInitialPortfolioRecord $record) => bcadd($carry, (string) $record->{$column}, 4),
            '0.0000'
        );

        CobrosInitialPortfolioSnapshot::create([
            'migration_id' => $migration->id,
            'company_id' => $migration->company_id,
            'collector_user_id' => (int) $migration->collector_user_id,
            'captured_at' => now(),
            'payload' => [
                'collector_user_id' => (int) $migration->collector_user_id,
                'clients_count' => $records->count(),
                'total_original' => $sum('original_amount'),
                'total_balance' => $sum('balance'),
                'total_installment' => $sum('installment'),
                'frequencies' => $records->countBy('frequency')->all(),
                'first_next_charge_date' => $records->min('next_charge_date')?->toDateString(),
                'last_next_charge_date' => $records->max('next_charge_date')?->toDateString(),
            ],
        ]);
    }

    /** Evento append-only (jamás se actualiza ni elimina). */
    private function record(CobrosInitialPortfolioMigration $migration, string $type, User $actor, ?string $reason = null): void
    {
        CobrosInitialPortfolioEvent::create([
            'migration_id' => $migration->id,
            'company_id' => $migration->company_id,
            'type' => $type,
            'actor_user_id' => $actor->id,
            'reason' => $reason !== null ? mb_substr(trim($reason), 0, 250) : null,
            'occurred_at' => now(),
        ]);
    }
}
