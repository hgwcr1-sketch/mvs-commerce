<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NumberFormattingViewTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = base_path('resources/views');
    }

    private function readView(string $path): string
    {
        return file_get_contents("{$this->base}/{$path}");
    }

    /**
     * inventory-counts/show usa number_format(..., 2) — 2 decimales.
     */
    public function test_inventory_count_view_uses_2_decimals(): void
    {
        $content = $this->readView('inventory-counts/show.blade.php');

        $this->assertStringContainsString("number_format((float) \$item->theoretical_quantity, 2)", $content);
        $this->assertStringContainsString("number_format((float) \$item->counted_quantity, 2)", $content);
        $this->assertStringContainsString("number_format((float) \$item->recount_quantity, 2)", $content);
        $this->assertStringContainsString("number_format(abs((float) \$item->difference), 2)", $content);
    }

    /**
     * inventory-counts/show NO usa number_format(..., 0) ni (..., 4).
     */
    public function test_inventory_count_view_no_longer_uses_0_or_4_decimals(): void
    {
        $content = $this->readView('inventory-counts/show.blade.php');

        $this->assertStringNotContainsString("number_format((float) \$item->theoretical_quantity, 0)", $content);
        $this->assertStringNotContainsString("number_format((float) \$item->counted_quantity, 0)", $content);
        $this->assertStringNotContainsString("number_format((float) \$item->recount_quantity, 0)", $content);
        $this->assertStringNotContainsString("number_format(abs((float) \$item->difference), 0)", $content);
        $this->assertStringNotContainsString("number_format((float) \$item->theoretical_quantity, 4)", $content);
    }

    /**
     * inventory-counts/show: sin diferencia muestra "0.00".
     */
    public function test_inventory_count_zero_difference_shows_0_00(): void
    {
        $content = $this->readView('inventory-counts/show.blade.php');
        $this->assertStringContainsString('>0.00<', $content);
    }

    /**
     * POS formatQuantity usa min/max 2 decimales.
     */
    public function test_pos_format_quantity_uses_two_decimals(): void
    {
        $content = $this->readView('pos/index.blade.php');

        $this->assertStringContainsString(
            "formatQuantity(value) { return new Intl.NumberFormat('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })",
            $content
        );
        $this->assertStringNotContainsString("maximumFractionDigits: 0 })", $content);
        $this->assertStringNotContainsString("maximumFractionDigits: 4 })", $content);
    }

    /**
     * POS money() ahora usa 2 decimales (antes usaba 0).
     */
    public function test_pos_money_uses_two_decimals(): void
    {
        $content = $this->readView('pos/index.blade.php');

        $this->assertStringContainsString(
            "money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 2, maximumFractionDigits: 2 })",
            $content
        );
        $this->assertStringNotContainsString(
            "money(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 0, maximumFractionDigits: 0 })",
            $content
        );
    }

    /**
     * POS money2() sigue con 2 decimales.
     */
    public function test_pos_money2_unchanged(): void
    {
        $content = $this->readView('pos/index.blade.php');

        $this->assertStringContainsString(
            "money2(value) { return new Intl.NumberFormat('es-CR', { style: 'currency', currency: 'CRC', minimumFractionDigits: 2, maximumFractionDigits: 2 })",
            $content
        );
    }

    /**
     * POS formatPoints() ahora usa 2 decimales (antes usaba 4).
     */
    public function test_pos_format_points_uses_two_decimals(): void
    {
        $content = $this->readView('pos/index.blade.php');

        $this->assertStringContainsString(
            "formatPoints(value) { return new Intl.NumberFormat('es-CR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })",
            $content
        );
        $this->assertStringNotContainsString(
            "formatPoints(value) { return new Intl.NumberFormat('es-CR', { maximumFractionDigits: 4 })",
            $content
        );
    }

    /**
     * Ninguna función de formateo visible usa 4 decimales.
     */
    public function test_no_visible_function_uses_four_decimals(): void
    {
        $content = $this->readView('pos/index.blade.php');

        $this->assertStringNotContainsString("maximumFractionDigits: 4", $content);
    }

    /**
     * purchase-verifications usa .toFixed(2).
     */
    public function test_purchase_verification_uses_to_fixed_2(): void
    {
        $content = $this->readView('purchase-verifications/show.blade.php');

        $this->assertStringContainsString('.toFixed(2)', $content);
        $this->assertStringNotContainsString('.toFixed(4)', $content);
        $this->assertStringNotContainsString('.toFixed(0)', $content);
    }

    /**
     * Los casts decimal:4 en los modelos NO fueron modificados.
     */
    public function test_model_decimal_casts_unchanged(): void
    {
        $modelFile = file_get_contents(app_path('Models/InventoryCountItem.php'));

        $this->assertStringContainsString("'theoretical_quantity' => 'decimal:4'", $modelFile);
        $this->assertStringContainsString("'counted_quantity' => 'decimal:4'", $modelFile);
        $this->assertStringContainsString("'recount_quantity' => 'decimal:4'", $modelFile);
        $this->assertStringContainsString("'difference' => 'decimal:4'", $modelFile);

        $pvModelFile = file_get_contents(app_path('Models/PurchaseVerificationItem.php'));

        $this->assertStringContainsString("'expected_quantity' => 'decimal:4'", $pvModelFile);
        $this->assertStringContainsString("'received_quantity' => 'decimal:4'", $pvModelFile);
        $this->assertStringContainsString("'difference' => 'decimal:4'", $pvModelFile);
    }
}
