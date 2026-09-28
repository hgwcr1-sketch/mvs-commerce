<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CompanySequence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCustomerCodes extends Command
{
    protected $signature = 'customers:backfill-codes
                            {--company= : ID de la empresa (opcional, por defecto todas)}
                            {--dry-run : Simular sin guardar cambios}
                            {--chunk=100 : Tamaño del lote para procesar}';

    protected $description = 'Asigna códigos comerciales secuenciales a clientes existentes sin customer_code. Ordenados por ID ascendente por empresa.';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        if ($chunkSize < 1) {
            $this->error('El tamaño del lote debe ser positivo.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: No se guardarán cambios.');
        }

        $companies = Company::query()->when($companyId, fn ($q) => $q->whereKey($companyId))->get();

        if ($companies->isEmpty()) {
            $this->error('No se encontraron empresas.');
            return self::FAILURE;
        }

        $totalAssigned = 0;

        foreach ($companies as $company) {
            $this->info("Procesando empresa: {$company->name} (ID: {$company->id})");

            // Comparar en PHP: los códigos manuales no tienen que ser numéricos.
            $lastCode = $this->lastNumericCode($company->id, $chunkSize);
            $sequenceValue = CompanySequence::where('company_id', $company->id)
                ->where('name', CompanySequence::CUSTOMER_CODE)->value('current_value') ?? 0;
            $maximum = bccomp($lastCode ?? '0', (string) $sequenceValue, 0) > 0
                ? $lastCode : (string) $sequenceValue;
            if (bccomp($maximum, (string) PHP_INT_MAX, 0) >= 0) {
                $this->error('El código numérico excede la capacidad de la secuencia.');
                return self::FAILURE;
            }
            $nextNumber = (int) $maximum + 1;

            $this->info("  Último código: " . ($lastCode ?? 'ninguno') . " → Próximo: " . str_pad($nextNumber, 6, '0', STR_PAD_LEFT));

            // Buscar clientes sin customer_code ordenados por ID
            $customers = Customer::where('company_id', $company->id)
                ->whereNull('customer_code')
                ->orderBy('id')
                ->get();

            $count = $customers->count();
            $this->info("  Clientes sin código: {$count}");

            if (bccomp(bcadd($maximum, (string) $count, 0), (string) (PHP_INT_MAX - 1), 0) > 0) {
                $this->error('No hay capacidad suficiente en la secuencia para este lote.');
                return self::FAILURE;
            }

            if ($count === 0) {
                $this->info("  Sin clientes para procesar.");
                continue;
            }

            if ($dryRun) {
                foreach ($customers as $customer) {
                    $code = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
                    $this->line("  ID {$customer->id} → {$code} ({$customer->name})");
                    $nextNumber++;
                }
                $totalAssigned += $count;
                continue;
            }

            // Procesar en chunks para no bloquear la tabla mucho tiempo
            $processed = 0;
            foreach ($customers->chunk($chunkSize) as $chunk) {
                DB::transaction(function () use ($chunk, &$nextNumber, &$processed, $company, $dryRun) {
                    foreach ($chunk as $customer) {
                        $code = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

                        // Verificar que no exista (doble check por concurrencia)
                        $exists = Customer::withTrashed()->where('company_id', $company->id)
                            ->where('customer_code', $code)
                            ->exists();

                        if ($exists) {
                            // Si colisiona, buscar el siguiente disponible
                            while ($exists) {
                                $nextNumber++;
                                $code = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
                                $exists = Customer::withTrashed()->where('company_id', $company->id)
                                    ->where('customer_code', $code)
                                    ->exists();
                            }
                        }

                        $customer->update(['customer_code' => $code]);
                        $nextNumber++;
                        $processed++;
                    }
                });
            }

            // Actualizar la secuencia CompanySequence para que futuras generaciones continúen correctamente
            if (! $dryRun) {
                CompanySequence::query()->updateOrCreate(
                    ['company_id' => $company->id, 'name' => CompanySequence::CUSTOMER_CODE],
                    ['current_value' => $nextNumber - 1]
                );
            }

            $this->info("  Asignados: {$processed}");
            $totalAssigned += $processed;
        }

        $this->info("Total códigos asignados: {$totalAssigned}");

        if ($dryRun) {
            $this->warn('DRY-RUN completado. Ejecute sin --dry-run para aplicar cambios.');
        }

        return self::SUCCESS;
    }

    private function lastNumericCode(int $companyId, int $chunkSize): ?string
    {
        $maximum = null;
        // Incluye eliminados: el índice único también reserva sus códigos.
        Customer::withTrashed()->where('company_id', $companyId)
            ->whereNotNull('customer_code')->select(['id', 'customer_code'])
            ->chunkById($chunkSize, function ($customers) use (&$maximum) {
                foreach ($customers as $customer) {
                    $code = $customer->customer_code;
                    if (preg_match('/^[0-9]+$/D', $code)
                        && ($maximum === null || bccomp($code, $maximum, 0) > 0)) {
                        $maximum = $code;
                    }
                }
            });

        return $maximum;
    }
}
