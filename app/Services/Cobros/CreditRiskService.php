<?php

namespace App\Services\Cobros;

use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Models\CobrosInitialPortfolioRecord;
use App\Models\Company;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * BLOQUE 11A — motor de clasificación crediticia (semáforo).
 *
 * Reglas:
 * - VERDE: ninguna cuota vencida pendiente.
 * - AMARILLO: exactamente una cuota vencida pendiente.
 * - ROJO: dos o más cuotas vencidas pendientes.
 * - Clientes nuevos (sin cuotas ni histórico): VERDE.
 * - Migrados sin cuotas detalladas: declared_behavior
 *   (malo=ROJO, regular=AMARILLO, bueno/sin_informacion=VERDE),
 *   salvo deuda antigua grave declarada (nota histórica) => ROJO.
 * - Abono parcial no cambia el color por sí solo: solo cuenta la cuota
 *   vencida con pendiente BCMath > 0.
 * - Solo lectura: nunca escribe saldos, cuotas, mora ni recibos.
 */
final class CreditRiskService
{
    public const GREEN = 'verde';

    public const YELLOW = 'amarillo';

    public const RED = 'rojo';

    /**
     * @return array{level: string, overdue_count: int, source: string}
     */
    public function classify(Company $company, Customer $customer, ?CarbonImmutable $today = null): array
    {
        $date = ($today ?? CarbonImmutable::today($company->timezone ?: config('app.timezone')))->toDateString();

        $overdue = $this->overdueInstallmentCount($company, $customer, $date);

        if ($overdue > 0) {
            return [
                'level' => $overdue === 1 ? self::YELLOW : self::RED,
                'overdue_count' => $overdue,
                'source' => 'cuotas',
            ];
        }

        $hasCredits = CobrosCredit::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->exists();

        if ($hasCredits) {
            return ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'cuotas'];
        }

        $record = CobrosInitialPortfolioRecord::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->first();

        if ($record === null) {
            return ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'nuevo'];
        }

        $behavior = strtolower(trim((string) $record->declared_behavior));

        if ($this->hasGraveHistoricalDebt($record)) {
            return ['level' => self::RED, 'overdue_count' => 0, 'source' => 'historico'];
        }

        return match ($behavior) {
            'malo' => ['level' => self::RED, 'overdue_count' => 0, 'source' => 'historico'],
            'regular' => ['level' => self::YELLOW, 'overdue_count' => 0, 'source' => 'historico'],
            default => ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'historico'],
        };
    }

    /**
     * 11B/12A — clasificación por lote SIN N+1: consulta 1 vez los créditos de
     * la empresa, 1 vez sus cuotas vencidas y 1 vez el histórico migrado
     * (3 consultas fijas, independientes del número de clientes). Resultados
     * idénticos a classify() cliente por cliente. Solo lectura.
     *
     * @param  iterable<Customer>  $customers
     * @return array<int, array{level: string, overdue_count: int, source: string}>
     */
    public function classifyMany(Company $company, iterable $customers, ?CarbonImmutable $today = null): array
    {
        $date = ($today ?? CarbonImmutable::today($company->timezone ?: config('app.timezone')))->toDateString();

        $items = [];
        foreach ($customers as $customer) {
            $items[(int) $customer->getKey()] = $customer;
        }

        if ($items === []) {
            return [];
        }

        // Q1: créditos de los clientes en pantalla (una sola consulta).
        $creditOwner = CobrosCredit::query()
            ->forCompany($company->id)
            ->whereIn('customer_id', array_keys($items))
            ->get(['id', 'customer_id'])
            ->mapWithKeys(fn (CobrosCredit $credit): array => [(int) $credit->id => (int) $credit->customer_id]);

        // Q2: cuotas vencidas no pagadas de esos créditos (una sola consulta).
        $overdue = array_fill_keys(array_keys($items), 0);

        if ($creditOwner->isNotEmpty()) {
            $installments = CobrosCreditInstallment::query()
                ->whereIn('credit_id', $creditOwner->keys()->all())
                ->whereDate('due_date', '<', $date)
                ->whereNotIn('status', [CobrosCreditInstallment::STATUS_PAID])
                ->get(['credit_id', 'contractual_amount', 'applied_amount']);

            foreach ($installments as $installment) {
                $pending = bcsub((string) $installment->contractual_amount, (string) $installment->applied_amount, 4);
                if (bccomp($pending, '0', 4) > 0) {
                    $owner = $creditOwner[(int) $installment->credit_id] ?? null;
                    if ($owner !== null) {
                        $overdue[$owner]++;
                    }
                }
            }
        }

        // Q3: histórico migrado, registro más reciente por cliente.
        $records = [];
        CobrosInitialPortfolioRecord::query()
            ->forCompany($company->id)
            ->whereIn('customer_id', array_keys($items))
            ->orderByDesc('id')
            ->get(['id', 'customer_id', 'declared_behavior', 'historical_note'])
            ->each(function (CobrosInitialPortfolioRecord $record) use (&$records): void {
                $id = (int) $record->customer_id;
                if (! array_key_exists($id, $records)) {
                    $records[$id] = $record;
                }
            });

        $withCredits = array_fill_keys($creditOwner->values()->unique()->all(), true);

        $levels = [];
        foreach (array_keys($items) as $id) {
            $count = $overdue[$id] ?? 0;

            if ($count > 0) {
                $levels[$id] = [
                    'level' => $count === 1 ? self::YELLOW : self::RED,
                    'overdue_count' => $count,
                    'source' => 'cuotas',
                ];

                continue;
            }

            if (isset($withCredits[$id])) {
                $levels[$id] = ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'cuotas'];

                continue;
            }

            $record = $records[$id] ?? null;

            if ($record === null) {
                $levels[$id] = ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'nuevo'];

                continue;
            }

            if ($this->hasGraveHistoricalDebt($record)) {
                $levels[$id] = ['level' => self::RED, 'overdue_count' => 0, 'source' => 'historico'];

                continue;
            }

            $levels[$id] = match (strtolower(trim((string) $record->declared_behavior))) {
                'malo' => ['level' => self::RED, 'overdue_count' => 0, 'source' => 'historico'],
                'regular' => ['level' => self::YELLOW, 'overdue_count' => 0, 'source' => 'historico'],
                default => ['level' => self::GREEN, 'overdue_count' => 0, 'source' => 'historico'],
            };
        }

        return $levels;
    }

    private function overdueInstallmentCount(Company $company, Customer $customer, string $date): int
    {
        $creditIds = CobrosCredit::query()
            ->forCompany($company->id)
            ->where('customer_id', $customer->id)
            ->pluck('id');

        if ($creditIds->isEmpty()) {
            return 0;
        }

        $count = 0;
        $installments = CobrosCreditInstallment::query()
            ->whereIn('credit_id', $creditIds)
            ->whereDate('due_date', '<', $date)
            ->whereNotIn('status', [CobrosCreditInstallment::STATUS_PAID])
            ->get(['contractual_amount', 'applied_amount']);

        foreach ($installments as $installment) {
            $pending = bcsub((string) $installment->contractual_amount, (string) $installment->applied_amount, 4);
            if (bccomp($pending, '0', 4) > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function hasGraveHistoricalDebt(CobrosInitialPortfolioRecord $record): bool
    {
        $note = mb_strtolower(trim((string) $record->historical_note));

        if ($note === '') {
            return false;
        }

        foreach (['grave', 'incobrable', 'judicial', 'demanda', 'embargo'] as $marker) {
            if (str_contains($note, $marker)) {
                return true;
            }
        }

        return false;
    }
}
