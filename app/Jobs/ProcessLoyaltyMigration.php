<?php

namespace App\Jobs;

use App\Models\LoyaltyMigrationRun;
use App\Services\Imports\LoyaltyMigrationImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessLoyaltyMigration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(LoyaltyMigrationImportService $import): void
    {
        $claimed = LoyaltyMigrationRun::query()
            ->whereKey($this->runId)
            ->where('status', LoyaltyMigrationRun::STATUS_PENDING)
            ->update([
                'status' => LoyaltyMigrationRun::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
                'started_at' => now(),
                'failed_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $run = LoyaltyMigrationRun::query()->findOrFail($this->runId);

        try {
            $existingBatch = DB::table('loyalty_migration_batches')
                ->where('company_id', $run->company_id)
                ->where('source_key', $run->source_key)
                ->first();
            $count = $existingBatch
                ? (int) $existingBatch->row_count
                : $import->confirm($run->preview_payload, (int) $run->company_id, (int) $run->user_id);

            $run->update([
                'status' => LoyaltyMigrationRun::STATUS_COMPLETED,
                'imported_count' => $count,
                'completed_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->markFailed($exception);
        }
    }

    private function markFailed(Throwable $exception): void
    {
        LoyaltyMigrationRun::query()->whereKey($this->runId)->update([
            'status' => LoyaltyMigrationRun::STATUS_FAILED,
            'last_error' => $this->sanitize($exception),
            'failed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sanitize(Throwable $exception): string
    {
        $details = $exception instanceof ValidationException
            ? collect($exception->errors())->flatten()->implode(' ')
            : $exception->getMessage();
        $message = preg_replace('/\b(password|passwd|token|secret|api[_-]?key)\s*[=:]\s*[^\s,;]+/iu', '$1=[oculto]', $details);
        $message = preg_replace('/\s+/', ' ', (string) $message);

        return Str::limit(class_basename($exception).': '.$message, 1000, '');
    }
}
