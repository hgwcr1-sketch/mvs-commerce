<?php

namespace App\Services\Imports;

use App\Jobs\ProcessCustomerImport;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerImportRow;
use App\Models\CustomerImportRun;
use App\Models\User;
use App\Services\Loyalty\LoyaltyAccountService;
use App\Services\CustomerPublicCodeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Persistence/orchestration for P32; normalization remains in CustomerImportService. */
class CustomerImportRunService
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly CustomerImportService $import,
        private readonly CustomerImportIdentityMatcher $identities,
        private readonly LoyaltyAccountService $accounts,
    ) {}

    public function upload(UploadedFile $file, int $companyId, int $userId): CustomerImportRun
    {
        $this->authorize($companyId, $userId);
        $path = $file->store('customer-imports/'.$companyId, 'local');
        if (! $path) {
            throw new RuntimeException('No se pudo guardar el archivo privado.');
        }
        $run = CustomerImportRun::create([
            'company_id' => $companyId, 'user_id' => $userId,
            'fingerprint' => hash_file('sha256', $file->getRealPath()),
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'private_file_path' => $path, 'status' => 'uploaded',
        ]);
        ProcessCustomerImport::dispatch($run->id, $companyId)->afterCommit();

        return $run->refresh();
    }

    public function confirm(int $runId, int $companyId, int $userId): CustomerImportRun
    {
        $this->authorize($companyId, $userId);
        $run = DB::transaction(function () use ($runId, $companyId, $userId) {
            $run = $this->run($runId, $companyId)->lockForUpdate()->firstOrFail();
            if ($run->confirmed_at) {
                return $run;
            }
            if ($run->status !== 'ready' || $run->purged_at) {
                throw ValidationException::withMessages(['customer_file' => 'El análisis todavía no está listo para confirmar.']);
            }
            $run->update(['status' => 'importing', 'confirmed_at' => now(), 'user_id' => $userId]);

            return $run;
        });
        if ($run->status === 'importing') {
            ProcessCustomerImport::dispatch($run->id, $companyId)->afterCommit();
        }

        return $run->refresh();
    }

    public function retry(int $runId, int $companyId, int $userId): void
    {
        $this->authorize($companyId, $userId);
        DB::transaction(function () use ($runId, $companyId) {
            $run = $this->run($runId, $companyId)->lockForUpdate()->firstOrFail();
            if ($run->purged_at || in_array($run->status, [...CustomerImportRun::TERMINAL, 'ready'], true)) {
                throw ValidationException::withMessages(['customer_file' => 'Esta ejecución no necesita reanudarse o sus detalles expiraron.']);
            }
            // Safe even after a worker died without calling failed(): checkpoints are transactional.
            $run->update(['status' => $run->confirmed_at ? 'importing' : 'analyzing', 'last_error' => null]);
        });
        ProcessCustomerImport::dispatch($runId, $companyId)->afterCommit();
    }

    public function step(int $runId, int $companyId): bool
    {
        return DB::transaction(function () use ($runId, $companyId) {
            $company = Company::findOrFail($companyId);
            $run = $this->run($runId, $companyId)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, ['uploaded', 'analyzing', 'importing'], true)) {
                return false;
            }
            $this->authorize($companyId, (int) $run->user_id);
            $run->attempts++;
            $run->started_at ??= now();
            if ($run->confirmed_at) {
                $this->importChunk($run, $company);
            } else {
                $this->analyzeChunk($run, $company);
            }
            $this->refreshCounts($run);
            $run->save();

            return in_array($run->status, ['analyzing', 'importing'], true);
        }, 3);
    }

    private function analyzeChunk(CustomerImportRun $run, Company $company): void
    {
        $run->status = 'analyzing';
        $path = Storage::disk('local')->path($run->private_file_path);
        if (! file_exists($path)) {
            throw ValidationException::withMessages(['customer_file' => 'El archivo temporal no existe. Vuelva a cargar el archivo.']);
        }
        $chunk = $this->import->readChunk($path, $company, max(2, $run->current_row + 1), self::CHUNK_SIZE);
        $code = $company->default_phone_country_code;
        $existing = $this->identities->customers($chunk['rows'], $company->id, $code);
        $fileIndex = $this->fileIndex($run, $chunk['rows'], $code);
        $prior = $this->priorCreatedRows($run, array_column($chunk['rows'], 'row_number'));
        $inserts = [];
        foreach ($chunk['rows'] as $data) {
            $keys = $this->identities->keys($data, $code);
            $matches = $this->identities->matches($existing, $keys);
            if (isset($prior[$data['row_number']])) {
                $matches[] = $prior[$data['row_number']];
                $matches = array_values(array_unique($matches));
            }
            $classification = $this->classify($data, $matches, $this->identities->matches($fileIndex, $keys));
            if ($classification['kind'] === 'new') {
                $this->identities->add($fileIndex, $keys, $data['row_number']);
            }
            $inserts[] = [
                'run_id' => $run->id, 'company_id' => $company->id, 'source_row' => $data['row_number'],
                'data' => json_encode($data, JSON_THROW_ON_ERROR), ...$keys, ...$classification,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($inserts, 50) as $rows) {
            CustomerImportRow::insert($rows);
        }
        $run->current_row = $chunk['end'];
        $run->last_source_row = $chunk['last_row'];
        if ($chunk['end'] >= $chunk['last_row']) {
            if (! $run->rows()->exists()) {
                throw ValidationException::withMessages(['customer_file' => 'El archivo no contiene filas de clientes.']);
            }
            $run->status = 'ready';
        }
    }

    private function classify(array $data, array $matches, array $fileMatches = []): array
    {
        $result = ['kind' => 'new', 'reason' => null, 'matched_customer_id' => null, 'duplicate_of_row' => null];
        if (count($matches) > 1) {
            return array_replace($result, ['kind' => 'conflict', 'reason' => 'Conflicto: los datos de esta fila coinciden con clientes existentes diferentes. Revise identificación, teléfono y correo.']);
        }
        if (count($matches) === 1) {
            return array_replace($result, ['kind' => 'existing', 'matched_customer_id' => $matches[0], 'reason' => 'Existente — será ignorado. No se modificarán sus datos ni sus puntos.']);
        }
        if (count($fileMatches) > 1) {
            return array_replace($result, ['kind' => 'conflict', 'reason' => 'Los identificadores apuntan a filas diferentes del archivo: '.implode(', ', $fileMatches).'. No se fusionarán.']);
        }
        if (count($fileMatches) === 1) {
            return array_replace($result, ['kind' => 'duplicate_file', 'duplicate_of_row' => $fileMatches[0], 'reason' => 'Duplicado de la fila '.$fileMatches[0].'; esta fila se omitirá sin fusionar sus datos.']);
        }
        $errors = $this->import->errors($data);
        if ($errors !== []) {
            return array_replace($result, ['kind' => 'error', 'reason' => collect($errors)->map(fn ($error) => $error['field'].': '.$error['message'])->implode(' ')]);
        }

        return $result;
    }

    private function fileIndex(CustomerImportRun $run, array $dataRows, ?string $code): array
    {
        $keys = [];
        foreach ($dataRows as $data) {
            foreach ($this->identities->keys($data, $code) as $value) {
                if ($value !== null) {
                    $keys[$value] = $value;
                }
            }
        }
        if ($keys === []) {
            return [];
        }
        $index = [];
        // Only valid first candidates participate; ignored rows never enrich them.
        $rows = $run->rows()->where('kind', 'new')->where(function ($query) use ($keys) {
            foreach (['identification_key', 'phone_key', 'mobile_key', 'email_key'] as $key) {
                $query->orWhereIn($key, array_values($keys));
            }
        })->get(['source_row', 'identification_key', 'phone_key', 'mobile_key', 'email_key']);
        foreach ($rows as $row) {
            $this->identities->add($index, $row->only(['identification_key', 'phone_key', 'mobile_key', 'email_key']), $row->source_row);
        }

        return $index;
    }

    private function priorCreatedRows(CustomerImportRun $run, array $sourceRows): array
    {
        // The minimal ledger survives detail purging, including rows without identifiers.
        return DB::table('customer_import_rows as r')
            ->join('customer_import_runs as i', 'i.id', '=', 'r.run_id')
            ->where('i.company_id', $run->company_id)->where('r.company_id', $run->company_id)
            ->where('i.fingerprint', $run->fingerprint)->where('i.id', '!=', $run->id)
            ->whereIn('r.source_row', $sourceRows)->whereNotNull('r.created_customer_id')
            ->pluck('r.created_customer_id', 'r.source_row')->all();
    }

    private function importChunk(CustomerImportRun $run, Company $company): void
    {
        $rows = $run->rows()->whereNull('processed_at')->orderBy('source_row')->limit(self::CHUNK_SIZE)->lockForUpdate()->get();
        $candidates = $rows->where('kind', 'new');
        $existing = $this->identities->customers($candidates->pluck('data')->all(), $company->id, $company->default_phone_country_code);
        $prior = $this->priorCreatedRows($run, $candidates->pluck('source_row')->all());
        $codes = $this->publicCodes($company->id, $candidates->count());
        $pointEntries = [];
        foreach ($rows as $row) {
            if ($row->kind === 'new') {
                $keys = $this->identities->keys($row->data, $company->default_phone_country_code);
                $matches = $this->identities->matches($existing, $keys);
                if (isset($prior[$row->source_row])) {
                    $matches = array_values(array_unique([...$matches, $prior[$row->source_row]]));
                }
                $row->fill($this->classify($row->data, $matches));
                if ($row->kind === 'new') {
                    $customer = Customer::create(['company_id' => $company->id, 'public_code' => array_pop($codes), ...$this->import->attributes($row->data)]);
                    $points = $row->data['initial_points'];
                    if (bccomp($points, '0', 4) > 0) {
                        $pointEntries[] = [
                            'customer_id' => $customer->id, 'points' => $points, 'source_row' => $row->source_row,
                            'event_key' => 'p32:'.hash('sha256', $company->id.':'.$run->fingerprint.':'.$row->source_row),
                        ];
                    }
                    $row->kind = 'created';
                    $row->created_customer_id = $customer->id;
                    $this->identities->add($existing, $keys, $customer->id);
                }
            }
        }
        $this->accounts->initializeImportPoints($company, User::findOrFail($run->user_id), $pointEntries, CustomerImportRun::class, $run->id);
        foreach ($rows as $row) {
            $row->processed_at = now();
            $row->save();
        }
        if (! $run->rows()->whereNull('processed_at')->exists()) {
            $hasIssues = $run->rows()->whereIn('kind', ['error', 'conflict'])->exists();
            $run->status = $hasIssues ? 'completed_with_issues' : 'completed';
            $run->finished_at = now();
        }
    }

    private function publicCodes(int $companyId, int $count): array
    {
        $codes = [];
        $generator = app(CustomerPublicCodeService::class);
        for ($attempt = 0; count($codes) < $count && $attempt < CustomerPublicCodeService::MAX_ATTEMPTS; $attempt++) {
            $needed = $count - count($codes);
            for ($i = 0; $i < $needed; $i++) {
                $code = $generator->randomCode();
                $codes[$code] = $code;
            }
            $existing = Customer::withTrashed()->where('company_id', $companyId)->whereIn('public_code', array_values($codes))->pluck('public_code');
            foreach ($existing as $code) {
                unset($codes[$code]);
            }
        }
        if (count($codes) !== $count) {
            throw new RuntimeException('No fue posible reservar códigos públicos únicos para el lote.');
        }

        return array_values($codes);
    }

    private function refreshCounts(CustomerImportRun $run): void
    {
        $counts = $run->rows()->selectRaw('kind, COUNT(*) AS aggregate')->groupBy('kind')->pluck('aggregate', 'kind');
        $run->total_rows = $run->analyzed_rows = $counts->sum();
        foreach (['new' => 'new_count', 'existing' => 'existing_count', 'duplicate_file' => 'duplicate_count', 'conflict' => 'conflict_count', 'error' => 'error_count', 'created' => 'created_count'] as $kind => $counter) {
            $run->$counter = (int) ($counts[$kind] ?? 0);
        }
        $run->ignored_count = $run->existing_count + $run->duplicate_count;
        $run->rejected_count = $run->conflict_count + $run->error_count;
    }

    private function authorize(int $companyId, int $userId): void
    {
        $company = Company::findOrFail($companyId);
        $user = User::findOrFail($userId);
        abort_unless($company->is_active && $user->is_active && $user->hasPermission('clientes.crear', $company), 403);
    }

    private function run(int $runId, int $companyId)
    {
        return CustomerImportRun::where('company_id', $companyId)->whereKey($runId);
    }

    public function purge(int $runId, int $companyId): bool
    {
        return DB::transaction(function () use ($runId, $companyId) {
            $run = $this->run($runId, $companyId)->lockForUpdate()->firstOrFail();
            if (! in_array($run->status, [...CustomerImportRun::TERMINAL, 'ready', 'failed'], true)
                || $run->updated_at->greaterThan(now()->subDays(30)) || $run->purged_at) {
                return false;
            }
            if ($run->private_file_path) {
                if (Storage::disk('local')->exists($run->private_file_path)) {
                    Storage::disk('local')->delete($run->private_file_path);
                } else {
                    Log::warning('Archivo de importación ya no existe', ['path' => $run->private_file_path, 'run_id' => $run->id]);
                }
            }
            $run->rows()->update(['data' => null, 'reason' => null, 'identification_key' => null, 'phone_key' => null, 'mobile_key' => null, 'email_key' => null]);
            $run->update(['private_file_path' => null, 'original_filename' => null, 'last_error' => null, 'purged_at' => now()]);

            return true;
        });
    }
}
