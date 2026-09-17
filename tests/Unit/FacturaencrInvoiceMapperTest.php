<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrInvoiceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

class FacturaencrInvoiceMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_minimum_payload(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company, $sale->payments->first());

        $this->assertArrayHasKey('emisorLegalId', $payload);
        $this->assertSame($company->identification_number, $payload['emisorLegalId']);
        $this->assertArrayHasKey('condicionVenta', $payload);
        $this->assertSame('01', $payload['condicionVenta']);
        $this->assertArrayHasKey('medioPago', $payload);
        $this->assertIsArray($payload['medioPago']);
        $this->assertSame(['01'], $payload['medioPago']);
        $this->assertArrayHasKey('currency', $payload);
        $this->assertSame('CRC', $payload['currency']);
        $this->assertArrayHasKey('exchangeRate', $payload);
        $this->assertSame(1.0, $payload['exchangeRate']);
        $this->assertArrayHasKey('receptor', $payload);
        $this->assertArrayHasKey('detalle', $payload);
        $this->assertIsArray($payload['detalle']);
        $this->assertCount(1, $payload['detalle']);
        $this->assertArrayHasKey('codigoCabys', $payload['detalle'][0]);
        $this->assertArrayHasKey('detalle', $payload['detalle'][0]);
        $this->assertArrayHasKey('cantidad', $payload['detalle'][0]);
        $this->assertArrayHasKey('unidadMedida', $payload['detalle'][0]);
        $this->assertArrayHasKey('precioUnitario', $payload['detalle'][0]);
    }

    public function test_receptor_with_full_data(): void
    {
        [$company] = $this->prepareData();
        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '9876543210',
            'name' => 'Juan Pérez',
        ]);
        [, , $sale] = $this->prepareData('Test', 'Test S.A.', '3101000000');
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company);

        $receptor = $payload['receptor'];
        $this->assertSame('01', $receptor['tipoIdentificacion']);
        $this->assertSame('9876543210', $receptor['numeroIdentificacion']);
        $this->assertSame('Juan Pérez', $receptor['nombre']);
    }

    public function test_multiple_lines(): void
    {
        [$company, $customer, $sale] = $this->prepareData();

        $item1 = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto 1', 'unit_code' => 'un', 'quantity' => 2, 'unit_price' => 500, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 250]);
        $item2 = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P02', 'cabys_code' => '5060101000001', 'description' => 'Producto 2', 'unit_code' => 'kg', 'quantity' => 1.5000, 'unit_price' => 200, 'gross_total' => 300, 'subtotal' => 300, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 39, 'total' => 339, 'unit_cost' => 100]);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item1, $item2], $customer, $company);

        $this->assertCount(2, $payload['detalle']);
        $this->assertSame('Producto 1', $payload['detalle'][0]['detalle']);
        $this->assertSame('Producto 2', $payload['detalle'][1]['detalle']);
        $this->assertSame(2.0, $payload['detalle'][0]['cantidad']);
        $this->assertSame(1.5, $payload['detalle'][1]['cantidad']);
        $this->assertSame('un', $payload['detalle'][0]['unidadMedida']);
        $this->assertSame('kg', $payload['detalle'][1]['unidadMedida']);
    }

    public function test_iva_13_percent(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company);

        $this->assertArrayHasKey('codigoCabys', $payload['detalle'][0]);
    }

    public function test_valid_cabys_code(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company);

        $this->assertSame('5060101000000', $payload['detalle'][0]['codigoCabys']);
    }

    public function test_invalid_cabys_format_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => 'invalid', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['detalle[0]_cabys']), 'Missing detalle[0]_cabys. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_missing_cabys_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => null, 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['detalle[0]_cabys']), 'Missing detalle[0]_cabys. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_invalid_quantity_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 0, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 0, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 0, 'total' => 0, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['detalle[0]_cantidad']), 'Missing detalle[0]_cantidad. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_invalid_price_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => -10, 'gross_total' => -10, 'subtotal' => -10, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 0, 'total' => -10, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['detalle[0]_precio']), 'Missing detalle[0]_precio. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_missing_tax_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 0, 'tax_total' => 0, 'total' => 1000, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['detalle[0]_impuesto']), 'Missing detalle[0]_impuesto. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_emisor_without_identification_throws_error(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Juan Pérez']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['emisor']), 'Missing emisor. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_receptor_without_identification_throws_error(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => null, 'identification' => '', 'name' => 'Juan Pérez']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['receptor_tipo_identificacion']), 'Missing receptor_tipo_identificacion. Actual keys: ' . print_r(array_keys($errors), true));
            $this->assertTrue(isset($errors['receptor_identificacion']), 'Missing receptor_identificacion. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_foreign_currency_without_exchange_rate_throws_error(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Juan Pérez']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'USD', 'exchange_rate' => 0, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['tipo_cambio']), 'Missing tipo_cambio. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_unmappable_sale_condition_throws_error(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Juan Pérez']);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'invalid_condition', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $item = SaleItem::create(['sale_id' => $sale->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['condicion_venta']), 'Missing condicion_venta. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_unsupported_payment_method_throws_error(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $cardPaymentMethod = PaymentMethod::create(['company_id' => $company->id, 'code' => 'card', 'name' => 'Tarjeta', 'type' => 'card', 'is_system' => true, 'is_active' => true, 'affects_cash' => false, 'requires_reference' => false, 'allows_change' => true]);
        $cardPayment = SalePayment::create(['sale_id' => $sale->id, 'cash_session_id' => null, 'payment_method_id' => $cardPaymentMethod->id, 'created_by' => $sale->user_id, 'status' => 'completed', 'amount' => 1017, 'received_amount' => 1200, 'change_amount' => 183]);
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();

        try {
            $mapper->map($sale, [$item], $customer, $company, $cardPayment);
            $this->fail('Expected FacturaencrValidationException');
        } catch (\App\Exceptions\Facturaencr\FacturaencrValidationException $e) {
            $errors = $e->getErrors();
            $this->assertTrue(isset($errors['medio_pago']), 'Missing medio_pago. Actual keys: ' . print_r(array_keys($errors), true));
        }
    }

    public function test_crc_currency_maps_correctly(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company);

        $this->assertSame('CRC', $payload['currency']);
        $this->assertSame(1.0, $payload['exchangeRate']);
    }

    public function test_idempotency_key_is_stable(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $key1 = $mapper->idempotencyKey($sale, $sale->document_type);
        $key2 = $mapper->idempotencyKey($sale, $sale->document_type);
        $key3 = $mapper->idempotencyKey($sale, 'electronic_ticket');

        $this->assertSame($key1, $key2, 'Same sale + document type must always produce same key');
        $this->assertNotSame($key1, $key3, 'Different document type must produce different key');
    }

    public function test_idempotency_key_has_no_random_or_timestamp(): void
    {
        [$company, $customer, $sale] = $this->prepareData();

        $mapper = new FacturaencrInvoiceMapper();
        $key = $mapper->idempotencyKey($sale, $sale->document_type);

        $this->assertSame(32, strlen($key), 'md5 produces 32-char hex string');
    }

    public function test_multi_company_isolation(): void
    {
        $company1 = Company::create(['trade_name' => 'Test1', 'legal_name' => 'Test1 S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $company2 = Company::create(['trade_name' => 'Test2', 'legal_name' => 'Test2 S.A.', 'identification_number' => '3101000001', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch1 = Branch::create(['company_id' => $company1->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $branch2 = Branch::create(['company_id' => $company2->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user1 = User::factory()->create(['is_active' => true]);
        $user1->companies()->attach($company1->id);
        $user1->branches()->attach($branch1->id);
        $user2 = User::factory()->create(['is_active' => true]);
        $user2->companies()->attach($company2->id);
        $user2->branches()->attach($branch2->id);
        $customer1 = Customer::create(['company_id' => $company1->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Juan']);
        $customer2 = Customer::create(['company_id' => $company2->id, 'customer_type' => 'individual', 'identification_type' => '01', 'identification' => '1234567890', 'name' => 'Ana']);

        $sale1 = Sale::create(['company_id' => $company1->id, 'branch_id' => $branch1->id, 'user_id' => $user1->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);
        $sale2 = Sale::create(['company_id' => $company2->id, 'branch_id' => $branch2->id, 'user_id' => $user2->id, 'sale_number' => 'POS-001', 'document_type' => 'electronic_invoice', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'total' => 1017, 'completed_at' => now()]);

        $item1 = SaleItem::create(['sale_id' => $sale1->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);
        $item2 = SaleItem::create(['sale_id' => $sale2->id, 'product_code' => 'P01', 'cabys_code' => '5060101000000', 'description' => 'Producto', 'unit_code' => 'un', 'quantity' => 1, 'unit_price' => 1000, 'gross_total' => 1000, 'subtotal' => 1000, 'discount_total' => 0, 'tax_rate' => 13, 'tax_total' => 117, 'total' => 1017, 'unit_cost' => 500]);

        $mapper = new FacturaencrInvoiceMapper();
        $key1 = $mapper->idempotencyKey($sale1, $sale1->document_type);
        $key2 = $mapper->idempotencyKey($sale2, $sale2->document_type);

        $this->assertNotSame($key1, $key2, 'Different companies must produce different keys');

        $payload1 = $mapper->map($sale1, [$item1], $customer1, $company1);
        $payload2 = $mapper->map($sale2, [$item2], $customer2, $company2);

        $this->assertSame($company1->identification_number, $payload1['emisorLegalId']);
        $this->assertSame($company2->identification_number, $payload2['emisorLegalId']);
        $this->assertSame('01', $payload1['condicionVenta']);
        $this->assertSame('01', $payload2['condicionVenta']);
    }

    public function test_document_type_mapping(): void
    {
        [$company, $customer, $sale] = $this->prepareData();
        $sale->update(['document_type' => 'electronic_invoice']);
        $item = $this->createItem($sale);

        $mapper = new FacturaencrInvoiceMapper();
        $payload = $mapper->map($sale, [$item], $customer, $company);

        $this->assertSame('01', $payload['condicionVenta']);
    }

    private function prepareData(
        string $tradeName = 'Test Comercio',
        string $legalName = 'Test S.A.',
        string $identification = '3101000000'
    ): array {
        $company = Company::create(['trade_name' => $tradeName, 'legal_name' => $legalName, 'identification_number' => $identification, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $customer = Customer::create([
            'company_id' => $company->id,
            'customer_type' => 'individual',
            'identification_type' => '01',
            'identification' => '1234567890',
            'name' => 'Juan Pérez',
        ]);

        $paymentMethod = PaymentMethod::create(['company_id' => $company->id, 'code' => 'cash', 'name' => 'Efectivo', 'type' => 'cash', 'is_system' => true, 'is_active' => true, 'affects_cash' => true, 'requires_reference' => false, 'allows_change' => true]);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'sale_number' => 'POS-001',
            'document_type' => 'electronic_invoice',
            'sale_condition' => 'cash',
            'status' => 'completed',
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 1000,
            'discount_total' => 100,
            'tax_total' => 117,
            'rounding_total' => 0,
            'total' => 1017,
            'paid_total' => 1017,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'cash_session_id' => null,
            'payment_method_id' => $paymentMethod->id,
            'created_by' => $user->id,
            'status' => 'completed',
            'amount' => 1017,
            'received_amount' => 1200,
            'change_amount' => 183,
        ]);

        return [$company, $customer, $sale];
    }

    private function createItem(Sale $sale): SaleItem
    {
        return SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => 'P01',
            'cabys_code' => '5060101000000',
            'description' => 'Producto',
            'unit_code' => 'un',
            'quantity' => 1,
            'unit_price' => 1000,
            'gross_total' => 1000,
            'subtotal' => 1000,
            'discount_total' => 0,
            'tax_rate' => 13,
            'tax_total' => 117,
            'total' => 1017,
            'unit_cost' => 500,
        ]);
    }
}