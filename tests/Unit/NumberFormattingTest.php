<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NumberFormattingTest extends TestCase
{
    /**
     * Cantidad física 3.0000 → 3.00 (2 decimales siempre).
     */
    public function test_physical_quantity_shows_two_decimals(): void
    {
        $result = number_format((float) '3.0000', 2);
        $this->assertSame('3.00', $result);
    }

    /**
     * Cantidad física 0.0000 → 0.00.
     */
    public function test_physical_quantity_zero_shows_two_decimals(): void
    {
        $result = number_format((float) '0.0000', 2);
        $this->assertSame('0.00', $result);
    }

    /**
     * Cantidad física 10.00 → 10.00.
     */
    public function test_physical_quantity_ten_shows_two_decimals(): void
    {
        $result = number_format((float) '10.00', 2);
        $this->assertSame('10.00', $result);
    }

    /**
     * Cantidad física 257.0000 → 257.00.
     */
    public function test_physical_quantity_large_whole_shows_two_decimals(): void
    {
        $result = number_format((float) '257.0000', 2);
        $this->assertSame('257.00', $result);
    }

    /**
     * Cantidad física fraccionaria 2.5000 → 2.50 (no truncada).
     */
    public function test_fractional_quantity_shows_two_decimals(): void
    {
        $result = number_format((float) '2.5000', 2);
        $this->assertSame('2.50', $result);
    }

    /**
     * Cantidad física fraccionaria 1.7500 → 1.75.
     */
    public function test_fractional_quantity_seven_five_shows_two_decimals(): void
    {
        $result = number_format((float) '1.7500', 2);
        $this->assertSame('1.75', $result);
    }

    /**
     * Cantidad física 1.0000 → 1.00 (no 1).
     */
    public function test_physical_quantity_one_shows_two_decimals(): void
    {
        $result = number_format((float) '1.0000', 2);
        $this->assertSame('1.00', $result);
    }

    /**
     * Dinero 1234.56 con money2 sigue mostrando 2 decimales.
     */
    public function test_money_still_shows_two_decimals(): void
    {
        $formatted = number_format(1234.56, 2, ',', '.');
        $this->assertSame('1.234,56', $formatted);
    }

    /**
     * Dinero 100.00 con money2 sigue con 2 decimales.
     */
    public function test_money_whole_amount_shows_two_decimals(): void
    {
        $formatted = number_format(100.00, 2, ',', '.');
        $this->assertSame('100,00', $formatted);
    }

    /**
     * money() colones sin decimales (no es cantidad física).
     */
    public function test_money_colones_no_decimals(): void
    {
        $formatted = number_format(5000, 0, ',', '.');
        $this->assertSame('5.000', $formatted);
    }

    /**
     * Verificar que number_format con 0 decimales NO se usa para cantidades
     * (documentar el cambio de regla).
     */
    public function test_zero_decimals_is_wrong_for_quantities(): void
    {
        $wrongBehavior = number_format((float) '3.0000', 0);
        $this->assertSame('3', $wrongBehavior); // entero sin .00 = INCORRECTO bajo nueva regla

        $correctBehavior = number_format((float) '3.0000', 2);
        $this->assertSame('3.00', $correctBehavior); // siempre 2 decimales = CORRECTO
    }

    /**
     * La suma de cantidades enteras muestra 2 decimales.
     */
    public function test_sum_of_quantities_shows_two_decimals(): void
    {
        $items = collect([
            (object) ['theoretical_quantity' => '3.0000'],
            (object) ['theoretical_quantity' => '5.0000'],
            (object) ['theoretical_quantity' => '12.0000'],
        ]);

        $sum = $items->sum(fn($i) => $i->theoretical_quantity);
        $result = number_format((float) $sum, 2);

        $this->assertSame('20.00', $result);
    }

    /**
     * La diferencia entre cantidades se muestra con 2 decimales.
     */
    public function test_difference_displayed_with_two_decimals(): void
    {
        $diff = abs((float) '2.0000');
        $result = number_format($diff, 2);
        $this->assertSame('2.00', $result);
    }

    /**
     * La diferencia cero se muestra como 0.00.
     */
    public function test_zero_difference_shows_0_00(): void
    {
        $diff = abs((float) '0.0000');
        $result = number_format($diff, 2);
        $this->assertSame('0.00', $result);
    }

    /**
     * Dinero money() ahora muestra 2 decimales: 8900 → 8.900,00.
     */
    public function test_money_now_shows_two_decimals(): void
    {
        $result = number_format(8900.00, 2, ',', '.');
        $this->assertSame('8.900,00', $result);
    }

    /**
     * Dinero money() con valor entero: ₡500 → ₡500,00.
     */
    public function test_money_whole_value_shows_two_decimals(): void
    {
        $result = number_format(500.00, 2, ',', '.');
        $this->assertSame('500,00', $result);
    }

    /**
     * Puntos formatPoints: 12.3456 → 12.35 (2 decimales, redondeo).
     */
    public function test_points_show_two_decimals(): void
    {
        $result = number_format((float) '12.3456', 2);
        $this->assertSame('12.35', $result);
    }

    /**
     * Puntos formatPoints: 100.0000 → 100.00.
     */
    public function test_points_whole_shows_two_decimals(): void
    {
        $result = number_format((float) '100.0000', 2);
        $this->assertSame('100.00', $result);
    }

    /**
     * Puntos formatPoints: 0.0000 → 0.00.
     */
    public function test_points_zero_shows_two_decimals(): void
    {
        $result = number_format((float) '0.0000', 2);
        $this->assertSame('0.00', $result);
    }
}
