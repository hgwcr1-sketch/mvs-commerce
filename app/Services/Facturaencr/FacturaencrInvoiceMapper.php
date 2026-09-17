<?php

namespace App\Services\Facturaencr;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\PaymentMethod;
use App\Exceptions\Facturaencr\FacturaencrValidationException;

class FacturaencrInvoiceMapper
{
    private const CONDICION_VENTA_MAP = [
        'cash' => '01',
        'credit' => '02',
    ];

    private const MEDIO_PAGO_MAP = [
        'cash' => '01',
    ];

    public function map(
        Sale $sale,
        array $saleItems,
        Customer $customer,
        Company $company,
        ?SalePayment $salePayment = null
    ): array {
        $errors = $this->validate($sale, $saleItems, $customer, $company, $salePayment);

        if (!empty($errors)) {
            throw new FacturaencrValidationException($errors);
        }

        $paymentMethod = $salePayment?->paymentMethod;
        $medioPago = $paymentMethod && isset(self::MEDIO_PAGO_MAP[$paymentMethod->code])
            ? [self::MEDIO_PAGO_MAP[$paymentMethod->code]]
            : [];

        return [
            'emisorLegalId' => $company->identification_number,
            'condicionVenta' => $this->mapCondicionVenta($sale->sale_condition),
            'medioPago' => $medioPago,
            'currency' => $sale->currency_code,
            'exchangeRate' => (float) $sale->exchange_rate,
            'receptor' => $this->mapReceptor($customer),
            'detalle' => $this->mapDetalle($saleItems),
        ];
    }

    public function idempotencyKey(Sale $sale, string $documentType): string
    {
        return md5("{$sale->company_id}-{$sale->id}-{$documentType}");
    }

    private function validate(
        Sale $sale,
        array $saleItems,
        Customer $customer,
        Company $company,
        ?SalePayment $salePayment = null
    ): array {
        $errors = [];

        if (empty($company->identification_number)) {
            $errors['emisor'] = 'El emisor no tiene identificación fiscal (companies.identification_number vacío)';
        }

        if (empty($company->legal_name)) {
            $errors['emisor_nombre'] = 'El emisor no tiene nombre legal (companies.legal_name vacío)';
        }

        if (empty($customer->identification_type)) {
            $errors['receptor_tipo_identificacion'] = 'El receptor no tiene tipo de identificación (customers.identification_type vacío)';
        }

        if (empty($customer->identification)) {
            $errors['receptor_identificacion'] = 'El receptor no tiene número de identificación (customers.identification vacío)';
        }

        if (empty($sale->sale_condition)) {
            $errors['condicion_venta'] = 'La condición de venta no está especificada (sales.sale_condition vacío)';
        } elseif (!array_key_exists($sale->sale_condition, self::CONDICION_VENTA_MAP)) {
            $errors['condicion_venta'] = 'La condición de venta no es mapeable: ' . $sale->sale_condition;
        }

        $paymentMethod = $salePayment?->paymentMethod;
        if ($paymentMethod && !isset(self::MEDIO_PAGO_MAP[$paymentMethod->code])) {
            $errors['medio_pago'] = 'Medio de pago no soportado por Facturaencr: ' . $paymentMethod->code;
        }

        if (empty($sale->currency_code)) {
            $errors['moneda'] = 'La moneda no está especificada (sales.currency_code vacío)';
        } elseif ($sale->currency_code !== 'CRC' && $sale->exchange_rate <= 0) {
            $errors['tipo_cambio'] = 'Moneda extranjera sin tipo de cambio válido (sales.exchange_rate debe ser > 0)';
        }

        if (empty($saleItems)) {
            $errors['detalle'] = 'No hay líneas de detalle en la venta';
        }

        foreach ($saleItems as $index => $item) {
            $prefix = "detalle[{$index}]";

            if (empty($item->description)) {
                $errors["{$prefix}_descripcion"] = 'La descripción del detalle está vacía';
            }

            if (empty($item->cabys_code)) {
                $errors["{$prefix}_cabys"] = 'CABYS ausente para el producto (sale_items.cabys_code vacío)';
            } elseif (!$this->isValidCabys($item->cabys_code)) {
                $errors["{$prefix}_cabys"] = 'CABYS mal formado: ' . $item->cabys_code . ' (debe tener 13 dígitos)';
            }

            if ($item->quantity <= 0) {
                $errors["{$prefix}_cantidad"] = 'La cantidad debe ser mayor a 0';
            }

            if ($item->unit_price < 0) {
                $errors["{$prefix}_precio"] = 'El precio unitario no puede ser negativo';
            }

            if (empty($item->unit_code)) {
                $errors["{$prefix}_unidad"] = 'La unidad de medida no está especificada';
            }

            if ($item->tax_rate <= 0) {
                $errors["{$prefix}_impuesto"] = 'El impuesto es requerido (sale_items.tax_rate debe ser > 0)';
            }
        }

        return $errors;
    }

    private function mapReceptor(Customer $customer): array
    {
        return [
            'tipoIdentificacion' => $customer->identification_type,
            'numeroIdentificacion' => $customer->identification,
            'nombre' => $customer->name,
        ];
    }

    private function mapDetalle(array $saleItems): array
    {
        $detalle = [];

        foreach ($saleItems as $item) {
            $detalle[] = [
                'codigoCabys' => $item->cabys_code,
                'detalle' => $item->description,
                'cantidad' => (float) $item->quantity,
                'unidadMedida' => $item->unit_code,
                'precioUnitario' => (float) $item->unit_price,
            ];
        }

        return $detalle;
    }

    private function mapCondicionVenta(string $condition): string
    {
        return self::CONDICION_VENTA_MAP[$condition] ?? $condition;
    }

    private function isValidCabys(string $cabys): bool
    {
        return preg_match('/^\d{13}$/', $cabys) === 1;
    }
}