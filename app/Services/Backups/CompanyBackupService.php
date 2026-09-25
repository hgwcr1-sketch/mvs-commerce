<?php

namespace App\Services\Backups;

use App\Models\Company;
use App\Models\CompanyBackupRecord;
use App\Models\CompanyBackupSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CompanyBackupService
{
    public function __construct(private CompanyBackupRunner $runner)
    {
    }

    public function settings(Company $company): CompanyBackupSetting
    {
        return CompanyBackupSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'is_enabled' => false,
                'plan' => 'basico',
                'frequency' => 'diario',
                'retention_days' => 30,
                'manual_backup_allowed' => true,
                'external_copy' => 'preparada',
                'encryption_required' => false,
            ],
        );
    }

    public function updateSettings(Company $company, array $data, ?User $actor = null): CompanyBackupSetting
    {
        $settings = $this->settings($company);

        $plan = $data['plan'] ?? $settings->plan;
        $frequency = $data['frequency'] ?? $settings->frequency;
        $externalCopy = $data['external_copy'] ?? $settings->external_copy;
        $retentionDays = (int) ($data['retention_days'] ?? $settings->retention_days);
        if ($plan === 'fe_5_anios') {
            $retentionDays = max($retentionDays, CompanyBackupSetting::PLAN_FE_RETENTION_DAYS);
        }

        $enabled = (bool) ($data['is_enabled'] ?? $settings->is_enabled);
        $settings->forceFill([
            'is_enabled' => $enabled,
            'plan' => $plan,
            'frequency' => $frequency,
            'retention_days' => $retentionDays,
            'manual_backup_allowed' => (bool) ($data['manual_backup_allowed'] ?? $settings->manual_backup_allowed),
            'external_copy' => $externalCopy,
            'encryption_required' => $externalCopy === 'activada',
            'next_backup_at' => $enabled
                ? ($settings->next_backup_at ?? $this->nextRunAt($frequency))
                : null,
            'updated_by' => $actor?->id,
        ])->save();

        return $settings;
    }

    public function nextRunAt(string $frequency, CarbonImmutable|Carbon|null $from = null): CarbonImmutable
    {
        $base = $from ? CarbonImmutable::instance($from) : CarbonImmutable::now();

        return match ($frequency) {
            'semanal' => $base->addWeek(),
            'mensual' => $base->addMonth(),
            default => $base->addDay(),
        };
    }

    public function dueSettings()
    {
        return CompanyBackupSetting::query()
            ->where('is_enabled', true)
            ->whereHas('company', fn ($query) => $query->where('is_active', true))
            ->where(fn ($query) => $query->whereNull('next_backup_at')->orWhere('next_backup_at', '<=', now()))
            ->with('company')
            ->get();
    }

    public function runBackup(Company $company, string $kind = 'manual', ?User $actor = null): CompanyBackupRecord
    {
        $settings = $this->settings($company);
        $record = CompanyBackupRecord::query()->create([
            'company_id' => $company->id,
            'kind' => $kind,
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $actor?->id,
        ]);

        try {
            if (! $settings->is_enabled) {
                throw new RuntimeException('El servicio de backups está desactivado para esta empresa.');
            }
            if ($kind === 'manual' && ! $settings->manual_backup_allowed) {
                throw new RuntimeException('El backup manual no está permitido para esta empresa.');
            }

            $directory = $this->backupDirectory($company);
            File::ensureDirectoryExists($directory);

            $export = $this->runner->run($this->buildExportCommand($company, $directory), $this->connectionEnv());
            if ($export['exit_code'] !== 0) {
                throw new RuntimeException('Fallo export-company.sh: '.mb_substr($export['output'], -600));
            }

            $validate = $this->runner->run($this->buildValidateCommand($directory), $this->connectionEnv());
            if ($validate['exit_code'] !== 0) {
                throw new RuntimeException('Fallo validate-company-export.sh: '.mb_substr($validate['output'], -600));
            }

            $size = collect(File::glob($directory.'/*'))->sum(fn ($file) => (int) @filesize($file));

            $record->forceFill([
                'status' => 'success',
                'path' => $directory,
                'size_bytes' => (int) $size,
                'finished_at' => now(),
                'message' => 'Export y validación verificados.',
            ])->save();

            $settings->forceFill([
                'last_backup_at' => now(),
                'last_status' => 'success',
                'last_size_bytes' => (int) $size,
                'last_message' => 'Export y validación verificados.',
            ])->save();
        } catch (\Throwable $exception) {
            $record->forceFill([
                'status' => 'error',
                'finished_at' => now(),
                'message' => $exception->getMessage(),
            ])->save();

            $settings->forceFill([
                'last_backup_at' => now(),
                'last_status' => 'error',
                'last_size_bytes' => null,
                'last_message' => $exception->getMessage(),
            ])->save();
        }

        $settings->forceFill([
            'next_backup_at' => $settings->is_enabled ? $this->nextRunAt($settings->frequency) : null,
        ])->save();

        return $record->refresh();
    }

    public function runRestoreTest(Company $company, ?User $actor = null): CompanyBackupRecord
    {
        $record = CompanyBackupRecord::query()->create([
            'company_id' => $company->id,
            'kind' => 'restore_test',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by' => $actor?->id,
        ]);

        try {
            $last = CompanyBackupRecord::query()
                ->where('company_id', $company->id)
                ->where('status', 'success')
                ->where('kind', 'manual')
                ->whereNotNull('path')
                ->latest('finished_at')
                ->first();

            if (! $last) {
                throw new RuntimeException('Sin backup manual previo exitoso para probar la restauración.');
            }
            if (! is_dir($last->path)) {
                throw new RuntimeException('El directorio del backup ya no existe: '.$last->path);
            }

            $targetDatabase = $this->restoreDatabaseName($company);
            $report = $last->path.'/restore-report.json';

            $run = $this->runner->run(
                $this->buildRestoreCommand($last->path, $targetDatabase, $report),
                $this->connectionEnv(),
            );
            if ($run['exit_code'] !== 0) {
                throw new RuntimeException('Fallo restore-company.sh: '.mb_substr($run['output'], -600));
            }

            $message = 'Restauración de prueba verificada en '.$targetDatabase.' (BD conservada).';
            $record->forceFill([
                'status' => 'success',
                'path' => $last->path,
                'finished_at' => now(),
                'message' => $message,
            ])->save();
        } catch (\Throwable $exception) {
            $record->forceFill([
                'status' => 'error',
                'finished_at' => now(),
                'message' => $exception->getMessage(),
            ])->save();
        }

        return $record->refresh();
    }

    public function buildExportCommand(Company $company, string $directory): array
    {
        $connection = $this->connection();

        return [
            (string) config('company_backups.bash'),
            (string) config('company_backups.export_script'),
            '--company-id', (string) $company->id,
            '--db', (string) ($connection['database'] ?? ''),
            '--host', (string) ($connection['host'] ?? '127.0.0.1'),
            '--port', (string) ($connection['port'] ?? '5432'),
            '--user', (string) ($connection['username'] ?? ''),
            '--out', $directory,
            '--allow-live-db',
        ];
    }

    public function buildValidateCommand(string $directory): array
    {
        $connection = $this->connection();

        return [
            (string) config('company_backups.bash'),
            (string) config('company_backups.validate_script'),
            '--dir', $directory,
            '--db', (string) ($connection['database'] ?? ''),
            '--host', (string) ($connection['host'] ?? '127.0.0.1'),
            '--port', (string) ($connection['port'] ?? '5432'),
            '--user', (string) ($connection['username'] ?? ''),
        ];
    }

    public function buildRestoreCommand(string $directory, string $targetDatabase, string $report): array
    {
        $connection = $this->connection();

        return [
            (string) config('company_backups.bash'),
            (string) config('company_backups.restore_script'),
            '--dir', $directory,
            '--db', $targetDatabase,
            '--host', (string) ($connection['host'] ?? '127.0.0.1'),
            '--port', (string) ($connection['port'] ?? '5432'),
            '--user', (string) ($connection['username'] ?? ''),
            '--report', $report,
        ];
    }

    public function restoreDatabaseName(Company $company): string
    {
        return sprintf((string) config('company_backups.restore_db_template'), $company->id);
    }

    public function backupDirectory(Company $company): string
    {
        return storage_path(
            config('company_backups.storage_dir').'/'.$company->id.'/'.now()->format('Ymd-His')
        );
    }

    private function connection(): array
    {
        return (array) config('database.connections.'.config('database.default'), []);
    }

    private function connectionEnv(): array
    {
        $password = (string) ($this->connection()['password'] ?? '');

        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }
}
