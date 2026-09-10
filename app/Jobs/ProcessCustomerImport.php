<?php

namespace App\Jobs;

use App\Models\CustomerImportRun;
use App\Services\Imports\CustomerImportRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessCustomerImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $runId, public readonly int $companyId) {}

    public function handle(CustomerImportRunService $service): void
    {
        if ($service->step($this->runId, $this->companyId)) {
            self::dispatch($this->runId, $this->companyId)->afterCommit();
        }
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(?Throwable $exception): void
    {
        CustomerImportRun::where('company_id', $this->companyId)->whereKey($this->runId)
            ->whereIn('status', ['uploaded', 'analyzing', 'importing'])->update([
                'status' => 'failed',
                'last_error' => $exception instanceof ValidationException
                    ? collect($exception->errors())->flatten()->implode(' ')
                    : 'El proceso se interrumpió. Puede reanudarlo sin duplicar clientes ni puntos.',
                'updated_at' => now(),
            ]);
    }
}
