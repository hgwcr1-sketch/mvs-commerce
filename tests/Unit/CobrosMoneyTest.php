<?php

namespace Tests\Unit;

use App\Support\Cobros\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CobrosMoneyTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_display_rounding_preserves_decimal_precision(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::format($input));
    }

    public static function amounts(): array
    {
        return [
            ['0', '0.00'], ['0.0000', '0.00'], ['1234', '1,234.00'],
            ['1234.5649', '1,234.56'], ['1234.5650', '1,234.57'],
            ['-1234.5650', '-1,234.57'], ['-0.0049', '0.00'], ['-0.0050', '-0.01'],
            ['999.9950', '1,000.00'], ['999999999999999.9999', '1,000,000,000,000,000.00'],
            ['9007199254740993.1250', '9,007,199,254,740,993.13'], ['+0001.2', '1.20'],
        ];
    }

    public function test_invalid_amount_is_not_silently_rendered_as_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::format('invalid');
    }
}
