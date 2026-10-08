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
     * 11B — clasificación por lote (solo lectura, reutiliza classify; evita N+1
     * en listados al compartir la fecha y no repetir consultas de fecha).
     *
     * @param  iterable<Customer>  $customers
     * @return array<int, array{level: string, overdue_count: int, source: string}>
     */
    public function classifyMany(Company $company, iterable $customers, ?CarbonImmutable $today = null): array
    {
        $date = $today ?? CarbonImmutable::today($company->timezone ?: config('app.timezone'));
        $levels = [];

        foreach ($customers as $customer) {
            $levels[(int) $customer->getKey()] = $this->classify($company, $customer, $date);
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
