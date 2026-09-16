<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

class ElectronicDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_includes_expected_fields(): void
    {
        $model = new ElectronicDocument();
        $fillable = $model->getFillable();

        $expected = [
            'company_id',
            'sale_id',
            'provider',
            'document_type',
            'environment',
            'idempotency_key',
            'provider_document_id',
            'clave',
            'consecutivo',
            'status',
            'last_error_code',
            'last_error_message',
            'provider_request_id',
        ];

        foreach ($expected as $field) {
            $this->assertContains($field, $fillable, "Field {$field} should be fillable");
        }
    }

    public function test_fillable_does_not_include_sensitive_fields(): void
    {
        $model = new ElectronicDocument();
        $fillable = $model->getFillable();

        $sensitive = [
            'api_key',
            'api_secret',
            'password',
            'secret',
            'certificate',
            'private_key',
            'pin',
            'xml',
            'pdf',
            'qr',
            'credentials',
        ];

        foreach ($sensitive as $field) {
            $this->assertNotContains($field, $fillable, "Sensitive field {$field} must not be fillable");
        }
    }

    public function test_default_values_are_correct(): void
    {
        $model = new ElectronicDocument([
            'company_id' => 1,
            'sale_id' => 10,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-123',
            'status' => 'pending',
        ]);

        $this->assertSame('facturaencr', $model->provider);
        $this->assertSame('sandbox', $model->environment);
        $this->assertSame('pending', $model->status);
        $this->assertNull($model->provider_document_id);
        $this->assertNull($model->clave);
        $this->assertNull($model->consecutivo);
        $this->assertNull($model->last_error_code);
        $this->assertNull($model->last_error_message);
        $this->assertNull($model->provider_request_id);
    }

    public function test_casts_are_correct(): void
    {
        $model = new ElectronicDocument();
        $casts = $model->getCasts();

        $this->assertArrayHasKey('sale_id', $casts);
        $this->assertArrayHasKey('company_id', $casts);
        $this->assertSame('integer', $casts['sale_id']);
        $this->assertSame('integer', $casts['company_id']);
    }

    public function test_relationship_company(): void
    {
        $model = new ElectronicDocument();
        $relation = $model->company();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relation);
        $this->assertSame('company_id', $relation->getForeignKeyName());
    }

    public function test_relationship_sale(): void
    {
        $model = new ElectronicDocument();
        $relation = $model->sale();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relation);
        $this->assertSame('sale_id', $relation->getForeignKeyName());
    }

    public function test_is_final_returns_true_for_accepted(): void
    {
        $model = new ElectronicDocument(['status' => 'accepted']);
        $this->assertTrue($model->isFinal());
        $this->assertFalse($model->isPending());
    }

    public function test_is_final_returns_true_for_rejected(): void
    {
        $model = new ElectronicDocument(['status' => 'rejected']);
        $this->assertTrue($model->isFinal());
        $this->assertFalse($model->isPending());
    }

    public function test_is_pending_returns_true_for_non_final(): void
    {
        foreach (['pending', 'queued', 'signing', 'sent', 'polling', 'error'] as $status) {
            $model = new ElectronicDocument(['status' => $status]);
            $this->assertTrue($model->isPending(), "Status {$status} should be pending");
            $this->assertFalse($model->isFinal(), "Status {$status} should not be final");
        }
    }

    public function test_scope_for_company(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forCompany(42);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_for_sale(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forSale(99);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_for_provider(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forProvider('facturaencr');

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_for_document_type(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forDocumentType('01');

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_for_environment(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forEnvironment('sandbox');

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_for_status(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->forStatus('accepted');

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_accepted(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->accepted();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_rejected(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->rejected();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_scope_pending(): void
    {
        $query = ElectronicDocument::query();
        $result = $query->pending();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_create_and_read_from_database(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        $doc = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-test-1',
            'status' => 'pending',
        ]);

        $this->assertNotNull($doc->id);
        $this->assertSame($company->id, $doc->company_id);
        $this->assertNull($doc->sale_id);
        $this->assertSame('facturaencr', $doc->provider);
        $this->assertSame('01', $doc->document_type);
        $this->assertSame('sandbox', $doc->environment);
        $this->assertSame('idem-test-1', $doc->idempotency_key);
        $this->assertSame('pending', $doc->status);
        $this->assertNotNull($doc->created_at);
        $this->assertNotNull($doc->updated_at);

        $found = ElectronicDocument::find($doc->id);
        $this->assertNotNull($found);
        $this->assertSame('idem-test-1', $found->idempotency_key);
    }

public function test_update_status(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        $doc = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-test-2',
            'status' => 'pending',
        ]);

        $doc->update([
            'status' => 'accepted',
            'provider_document_id' => 'doc-123',
            'clave' => '506010100000000000010000000000000000000',
            'consecutivo' => '001-001-000001',
        ]);

        $refreshed = ElectronicDocument::find($doc->id);
        $this->assertSame('accepted', $refreshed->status);
        $this->assertSame('doc-123', $refreshed->provider_document_id);
        $this->assertSame('506010100000000000010000000000000000000', $refreshed->clave);
        $this->assertSame('001-001-000001', $refreshed->consecutivo);
    }

    public function test_unique_constraint_on_company_sale_document_type(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $user = User::factory()->create();
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $sale = Sale::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $user->id, 'sale_number' => 'POS-003', 'document_type' => 'electronic_ticket', 'sale_condition' => 'cash', 'status' => 'completed', 'currency_code' => 'CRC', 'exchange_rate' => 1, 'subtotal' => 1000, 'discount_total' => 100, 'tax_total' => 117, 'rounding_total' => 0, 'total' => 1017, 'paid_total' => 1017, 'balance_due' => 0, 'completed_at' => now()]);

        ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-unique-1',
            'status' => 'pending',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-unique-2',
            'status' => 'pending',
        ]);
    }

    public function test_different_company_can_have_same_sale_id_and_document_type(): void
    {
        $company1 = Company::create(['trade_name' => 'Test1', 'legal_name' => 'Test1 S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $company2 = Company::create(['trade_name' => 'Test2', 'legal_name' => 'Test2 S.A.', 'identification_number' => '3101000001', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        ElectronicDocument::create([
            'company_id' => $company1->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-company-1',
            'status' => 'pending',
        ]);

        $doc2 = ElectronicDocument::create([
            'company_id' => $company2->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-company-2',
            'status' => 'pending',
        ]);

        $this->assertNotNull($doc2->id);
    }

    public function test_different_document_type_does_not_violate_constraint(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-type-1',
            'status' => 'pending',
        ]);

        $doc2 = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '04',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-type-2',
            'status' => 'pending',
        ]);

        $this->assertNotNull($doc2->id);
    }

    public function test_nullable_fields_can_be_null(): void
    {
        $company = Company::create(['trade_name' => 'Test', 'legal_name' => 'Test S.A.', 'identification_number' => '3101000000', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);

        $doc = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'idem-null',
            'status' => 'pending',
        ]);

        $this->assertNull($doc->sale_id);
        $this->assertNull($doc->provider_document_id);
        $this->assertNull($doc->clave);
        $this->assertNull($doc->consecutivo);
        $this->assertNull($doc->last_error_code);
        $this->assertNull($doc->last_error_message);
        $this->assertNull($doc->provider_request_id);
    }

    public function test_table_has_correct_columns(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('electronic_documents');

        $expected = [
            'id',
            'company_id',
            'sale_id',
            'provider',
            'document_type',
            'environment',
            'idempotency_key',
            'provider_document_id',
            'clave',
            'consecutivo',
            'status',
            'last_error_code',
            'last_error_message',
            'provider_request_id',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertContains($column, $columns, "Column {$column} should exist in electronic_documents table");
        }
    }
}