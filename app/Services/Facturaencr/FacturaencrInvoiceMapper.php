<?php

namespace App\Services\Facturaencr;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Exceptions\Facturaencr\FacturaencrValidationException;
use App\Services\Fiscal\FiscalTaxService;
use Throwable;

class FacturaencrInvoiceMapper
{
    private const CONDICION_VENTA_MAP = [
        'cash' => '01',
        'credit' => '02',
    ];

    private const MEDIO_PAGO_MAP = [
        'cash' => '01',
        'card' => '02',
        'bank_transfer' => '04',
        'sinpe' => '06',
    ];

    public function __construct(private readonly FiscalTaxService $fiscalTaxService = new FiscalTaxService())
    {
    }

    public function map(
        Sale $sale,
        array $saleItems,
        Customer $customer,
        Company $company,
        ?SalePayment $salePayment = null,
        ?string $idempotencyKey = null
    ): array {
        $errors = $this->validate($sale, $saleItems, $customer, $company, $salePayment);

        if (!empty($errors)) {
            throw new FacturaencrValidationException($errors);
        }

        $payload = [
            'emisorLegalId' => $this->emisorLegalId($company),
            'condicionVenta' => $this->mapCondicionVenta($sale->sale_condition),
            'currency' => $sale->currency_code,
            'exchangeRate' => (float) $sale->exchange_rate,
            'receptor' => $this->mapReceptor($customer),
            'detalle' => $this->mapDetalle($saleItems),
        ];

        $medioPago = $this->mapMedioPago($sale, $salePayment);
        if ($medioPago !== []) {
            $payload['medioPago'] = $medioPago;
        }

        if ($sale->sale_condition === 'credit') {
            $plazoCredito = $this->calculatePlazoCredito($sale);
            if ($plazoCredito !== null) {
                $payload['plazoCredito'] = $plazoCredito;
            }
        }

        return $payload;
    }

    public function idempotencyKey(Sale $sale, string $documentType): string
    {
        return md5("{$sale->company_id}-{$sale->id}-{$documentType}");
    }

    public function canUseSandboxEmisor(): bool
    {
        return config('facturaencr.environment') === 'sandbox';
    }

    public function getSandboxEmisor(): string
    {
        return config('facturaencr.sandbox_emisor', 'EMISORPRUEBA');
    }

    private function calculatePlazoCredito(Sale $sale): ?string
    {
        if (empty($sale->due_date)) {
            return null;
        }

        if (empty($sale->created_at)) {
            return null;
        }

        $days = $sale->created_at->copy()->startOfDay()->diffInDays($sale->due_date->copy()->startOfDay());

        return (string) round($days);
    }

    private function validate(
        Sale $sale,
        array $saleItems,
        Customer $customer,
        Company $company,
        ?SalePayment $salePayment = null
    ): array {
        $errors = [];

        if (config('facturaencr.environment', 'sandbox') !== 'sandbox'
            && config('facturaencr.sandbox_emisor', 'EMISORPRUEBA') === $company->identification_number) {
            $errors['emisor'] = 'EMISORPRUEBA solo puede utilizarse en ambiente sandbox';
        }

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

        if ($sale->sale_condition === 'credit') {
            $plazoCredito = $this->calculatePlazoCredito($sale);
            if ($plazoCredito === null) {
                if (empty($sale->due_date)) {
                    $errors['plazo_credito'] = 'Crédito requiere due_date en la venta para calcular plazoCredito';
                } elseif (empty($sale->created_at)) {
                    $errors['plazo_credito'] = 'Crédito requiere fecha de venta (created_at) para calcular plazoCredito';
                } else {
                    $errors['plazo_credito'] = 'No se puede derivar plazoCredito de forma segura';
                }
            }
        }

        $paymentMethod = $salePayment?->paymentMethod;
        if ($paymentMethod) {
            if (!isset(self::MEDIO_PAGO_MAP[$paymentMethod->code])) {
                $errors['medio_pago'] = 'Medio de pago no soportado por Facturaencr: ' . $paymentMethod->code;
            }
        }

        if (empty($sale->currency_code)) {
            $errors['moneda'] = 'La moneda no está especificada (sales.currency_code vacío)';
        } elseif ($sale->currency_code !== 'CRC' && $sale->exchange_rate <= 0) {
            $errors['tipo_cambio'] = 'Moneda extranjera sin tipo de cambio válido (sales.exchange_rate debe ser > 0)';
        }

        if (empty($saleItems)) {
            $errors['detalle'] = 'No hay líneas de detalle en la venta';
        }

        $unitMapper = new FacturaencrUnitMapper();

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
            } elseif (!$unitMapper->isSupported($item->unit_code)) {
                $errors["{$prefix}_unidad"] = 'Unidad de medida no soportada por Facturaencr: ' . $item->unit_code;
            }

            try {
                $this->fiscalTaxService->snapshotForSaleItem($item);
            } catch (Throwable $exception) {
                $errors["{$prefix}_impuesto"] = $exception->getMessage();
            }
        }

        return $errors;
    }

    private function emisorLegalId(Company $company): string
    {
        if (config('facturaencr.environment', 'sandbox') === 'sandbox') {
            return config('facturaencr.sandbox_emisor', 'EMISORPRUEBA');
        }

        return (string) $company->identification_number;
    }

    private function mapMedioPago(Sale $sale, ?SalePayment $salePayment): array
    {
        if ($sale->sale_condition === 'credit') {
            return [];
        }

        $paymentMethod = $salePayment?->paymentMethod;

        if (!$paymentMethod) {
            return [];
        }

        if (!isset(self::MEDIO_PAGO_MAP[$paymentMethod->code])) {
            return [];
        }

        return [self::MEDIO_PAGO_MAP[$paymentMethod->code]];
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
        $unitMapper = new FacturaencrUnitMapper();
        $detalle = [];

        foreach ($saleItems as $item) {
            $detalle[] = [
                'codigoCabys' => $item->cabys_code,
                'detalle' => $item->description,
                'cantidad' => (float) $item->quantity,
                'unidadMedida' => $unitMapper->map($item->unit_code),
                'precioUnitario' => (float) $item->unit_price,
                'impuesto' => $this->fiscalTaxService->serializeSnapshot(
                    $this->fiscalTaxService->snapshotForSaleItem($item),
                ),
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