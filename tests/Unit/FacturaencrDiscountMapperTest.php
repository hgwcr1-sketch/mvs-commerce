<?php

namespace Tests\Unit;

use App\Services\Facturaencr\FacturaencrDiscountMapper;
use PHPUnit\Framework\TestCase;

class FacturaencrDiscountMapperTest extends TestCase
{
    private FacturaencrDiscountMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new FacturaencrDiscountMapper;
    }

    public function test_line_without_discount_produces_no_field(): void
    {
        $this->assertSame([], $this->mapper->map('0.0000'));
        $this->assertSame([], $this->mapper->map(0));
        $this->assertSame([], $this->mapper->map(null));
        $this->assertSame([], $this->mapper->map(''));
    }

    public function test_zero_discount_is_not_serialized(): void
    {
        $this->assertSame([], $this->mapper->map('0.0000'));
        $this->assertSame([], $this->mapper->map('0.00'));
    }

    public function test_micro_discount_above_zero_is_serialized(): void
    {
        $this->assertSame([['montoDescuento' => 0.0001, 'codigoDescuento' => '07']], $this->mapper->map('0.0001'));
    }

    public function test_real_discount_follows_provider_contract(): void
    {
        $mapped = $this->mapper->map('700.0000');

        $this->assertSame([['montoDescuento' => 700.0, 'codigoDescuento' => '07']], $mapped);
        $this->assertTrue($this->mapper->isValidCode($mapped[0]['codigoDescuento']));
    }

    public function test_negative_discount_is_not_serialized(): void
    {
        $this->assertSame([], $this->mapper->map('-10.0000'));
    }

    public function test_scalar_discount_is_rejected_by_preflight(): void
    {
        // Regresión del incidente: el formato anterior (escalar) debe
        // detectarse ANTES del POST con la misma ruta que el proveedor.
        $errors = $this->mapper->contractErrors([['descuento' => 700]]);

        $this->assertArrayHasKey('detalle.0.descuento', $errors);
    }

    public function test_discount_without_catalog_code_is_rejected_by_preflight(): void
    {
        $errors = $this->mapper->contractErrors([
            ['descuento' => [['montoDescuento' => 400]]],
            ['descuento' => [['montoDescuento' => 400, 'codigoDescuento' => '77']]],
            ['descuento' => [['montoDescuento' => 0, 'codigoDescuento' => '07']]],
        ]);

        $this->assertArrayHasKey('detalle.0.descuento.0.codigoDescuento', $errors);
        $this->assertArrayHasKey('detalle.1.descuento.0.codigoDescuento', $errors);
        $this->assertArrayHasKey('detalle.2.descuento.0.montoDescuento', $errors);
    }

    public function test_valid_contract_payload_has_no_errors(): void
    {
        $detalle = [
            ['precioUnitario' => 2900],
            ['precioUnitario' => 2800, 'descuento' => $this->mapper->map('700.0000')],
            ['precioUnitario' => 2200, 'descuento' => $this->mapper->map('400.0000')],
        ];

        $this->assertSame([], $this->mapper->contractErrors($detalle));
    }

    public function test_catalog_codes_are_the_official_ones(): void
    {
        $this->assertSame(
            ['01', '02', '03', '04', '05', '06', '07', '08', '09', '99'],
            FacturaencrDiscountMapper::CODES
        );
        $this->assertTrue($this->mapper->isValidCode('07'));
        $this->assertFalse($this->mapper->isValidCode('10'));
    }
}
