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

            // Obtener el último customer_code usado para esta empresa
            $lastCode = Customer::where('company_id', $company->id)
                ->whereNotNull('customer_code')
                ->orderByRaw('CAST(customer_code AS UNSIGNED) DESC')
                ->value('customer_code');

            $nextNumber = $lastCode ? (int) $lastCode + 1 : 1;

            $this->info("  Último código: " . ($lastCode ?? 'ninguno') . " → Próximo: " . str_pad($nextNumber, 6, '0', STR_PAD_LEFT));

            // Buscar clientes sin customer_code ordenados por ID
            $customers = Customer::where('company_id', $company->id)
                ->whereNull('customer_code')
                ->orderBy('id')
                ->get();

            $count = $customers->count();
            $this->info("  Clientes sin código: {$count}");

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
                        $exists = Customer::where('company_id', $company->id)
                            ->where('customer_code', $code)
                            ->exists();

                        if ($exists) {
                            // Si colisiona, buscar el siguiente disponible
                            while ($exists) {
                                $nextNumber++;
                                $code = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
                                $exists = Customer::where('company_id', $company->id)
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
}
