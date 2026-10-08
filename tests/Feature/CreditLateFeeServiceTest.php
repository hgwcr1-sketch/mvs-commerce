<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\CobrosCredit;
use App\Models\CobrosCreditInstallment;
use App\Services\Cobros\CreditLateFeeService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 9B.3 — motor de cálculo de mora + días de gracia (puro).
 * Modelos en memoria: sin BD, sin RefreshDatabase, sin suite de pagos.
 */
class CreditLateFeeServiceTest extends TestCase
{
    private CreditLateFeeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(CreditLateFeeService::class);
    }

    private function credit(string $type = CobrosCredit::LATE_FEE_FIXED, string $value = '500', int $grace = 3): CobrosCredit
    {
        return new CobrosCredit([
            'late_fee_type' => $type,
            'late_fee_value' => $value,
            'late_fee_grace_days' => $grace,
            'terms_snapshot' => [
                'late_fee_type' => $type,
                'late_fee_value' => $value,
                'late_fee_grace_days' => $grace,
            ],
        ]);
    }

    private function installment(string $due = '2026-10-20', string $amount = '500.0000', ?string $paidAt = null): CobrosCreditInstallment
    {
        return new CobrosCreditInstallment([
            'number' => 1,
            'due_date' => $due,
            'contractual_amount' => $amount,
            'applied_amount' => '0.0000',
            'status' => CobrosCreditInstallment::STATUS_PENDING,
            'paid_at' => $paidAt,
        ]);
    }

    private function on(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_installment_due_today_has_no_late_fee(): void
    {
        $result = $this->service->evaluate($this->credit(), $this->installment(), $this->on('2026-10-20'));

        $this->assertFalse($result['is_overdue'], 'vence hoy: no vencida');
        $this->assertSame(0, $result['days_late']);
        $this->assertSame('0.0000', $result['late_fee_due']);
        $this->assertSame(3, $result['grace_days']);
        $this->assertSame('2026-10-24', $result['effective_late_date'], 'gracia 3: mora desde el 24/10');
    }

    public function test_days_within_grace_have_no_late_fee(): void
    {
        foreach (['2026-10-21', '2026-10-22'] as $date) {
            $result = $this->service->evaluate($this->credit(), $this->installment(), $this->on($date));

            $this->assertFalse($result['is_overdue'], "dentro de gracia ($date)");
            $this->assertSame('0.0000', $result['late_fee_due'], "dentro de gracia ($date)");
        }
    }

    public function test_last_grace_day_has_no_late_fee(): void
    {
        $result = $this->service->evaluate($this->credit(), $this->installment(), $this->on('2026-10-23'));

        $this->assertFalse($result['is_overdue'], 'último día de gracia: sin mora');
        $this->assertSame(0, $result['days_late']);
        $this->assertSame('0.0000', $result['late_fee_due']);
    }

    public function test_first_day_after_grace_is_overdue_with_correct_fee(): void
    {
        $result = $this->service->evaluate($this->credit(), $this->installment(), $this->on('2026-10-24'));

        $this->assertTrue($result['is_overdue'], 'primer día posterior a gracia: vencida');
        $this->assertSame(1, $result['days_late']);
        $this->assertSame('500.0000', $result['late_fee_due']);
        $this->assertSame('2026-10-24', $result['effective_late_date']);
    }

    public function test_without_late_fee_configured_fee_is_always_zero(): void
    {
        $credit = $this->credit(CobrosCredit::LATE_FEE_NONE, '0', 3);
        $result = $this->service->evaluate($credit, $this->installment(), $this->on('2026-11-05'));

        $this->assertSame('0.0000', $result['late_fee_due'], 'sin mora configurada: siempre 0');
    }

    public function test_advance_payment_never_generates_late_fee(): void
    {
        $installment = $this->installment(paidAt: '2026-10-15 09:00:00');
        $result = $this->service->evaluate($this->credit(), $installment, $this->on('2026-11-10'));

        $this->assertFalse($result['is_overdue'], 'pago anticipado antes del vencimiento: nunca mora');
        $this->assertSame(0, $result['days_late']);
        $this->assertSame('0.0000', $result['late_fee_due']);
    }

    public function test_payment_within_grace_never_generates_late_fee(): void
    {
        $installment = $this->installment(paidAt: '2026-10-22 15:30:00');
        $result = $this->service->evaluate($this->credit(), $installment, $this->on('2026-11-10'));

        $this->assertFalse($result['is_overdue'], 'pagada dentro de gracia: nunca mora');
        $this->assertSame('0.0000', $result['late_fee_due']);
    }

    public function test_contractual_due_date_never_changes(): void
    {
        $credit = $this->credit();
        $installment = $this->installment();
        $dueBefore = $installment->due_date->toDateString();
        $snapshotBefore = $credit->terms_snapshot;

        $this->service->evaluate($credit, $installment, $this->on('2026-11-01'));

        $this->assertSame($dueBefore, $installment->due_date->toDateString(), 'la fecha contractual no cambia');
        $this->assertSame($snapshotBefore, $credit->terms_snapshot, 'terms_snapshot intacto');
    }

    public function test_fixed_late_fee_is_exact_to_four_decimals(): void
    {
        $result = $this->service->evaluate($this->credit(), $this->installment(), $this->on('2026-10-24'));
        $this->assertSame('500.0000', $result['late_fee_due'], 'monto fijo exacto');

        $result2 = $this->service->evaluate(
            $this->credit(CobrosCredit::LATE_FEE_FIXED, '150.55', 0),
            $this->installment(due: '2026-10-20'),
            $this->on('2026-10-21')
        );
        $this->assertSame('150.5500', $result2['late_fee_due'], 'monto fijo con decimales normalizados a escala 4');
    }

    public function test_percent_fee_uses_overdue_installment_amount_as_base(): void
    {
        $credit = $this->credit(CobrosCredit::LATE_FEE_PERCENT, '2', 3);
        $installment = $this->installment(amount: '500.0000');
        $installment->applied_amount = '99.9999'; // base NO es lo aplicado a la cuota.

        $result = $this->service->evaluate($credit, $installment, $this->on('2026-10-24'));

        $this->assertSame('10.0000', $result['late_fee_due'], '2% sobre el pago atrasado (contractual_amount)');
    }

    public function test_percent_fee_decimals_are_exact_half_up_at_scale_four(): void
    {
        $credit = $this->credit(CobrosCredit::LATE_FEE_PERCENT, '2.5', 3);
        $installment = $this->installment(amount: '281.2500');

        $result = $this->service->evaluate($credit, $installment, $this->on('2026-10-24'));

        $this->assertSame('7.0313', $result['late_fee_due'], '2.5% × 281.2500 = 7.03125 → round4 half-up = 7.0313');
    }

    public function test_calculation_does_not_mutate_credit_installment_or_receivable(): void
    {
        $credit = $this->credit();
        $installment = $this->installment();
        $receivable = new AccountReceivable();
        $receivable->forceFill(['balance_due' => '166.0000', 'original_amount' => '1125.0000']);

        $creditAttrs = $credit->getAttributes();
        $installmentAttrs = $installment->getAttributes();
        $receivableAttrs = $receivable->getAttributes();

        $this->service->evaluate($credit, $installment, $this->on('2026-11-05'));

        $this->assertSame($creditAttrs, $credit->getAttributes(), 'crédito/snapshot intactos');
        $this->assertSame($installmentAttrs, $installment->getAttributes(), 'cuota intacta');
        $this->assertSame($receivableAttrs, $receivable->getAttributes(), 'CxC intacta');
    }

    public function test_late_paid_installment_keeps_overdue_evidence(): void
    {
        $installment = $this->installment(paidAt: '2026-10-26 10:00:00');
        $result = $this->service->evaluate($this->credit(), $installment, $this->on('2026-11-05'));

        $this->assertTrue($result['is_overdue'], 'evidencia: estuvo atrasada aunque después pagada');
        $this->assertSame(3, $result['days_late'], '24,25,26 de octubre → 3 días de atraso objetivos');
        $this->assertSame('500.0000', $result['late_fee_due']);
    }
}
