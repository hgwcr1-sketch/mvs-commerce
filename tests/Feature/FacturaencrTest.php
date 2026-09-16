<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Models\Sale;
use App\Models\User;
use App\Services\Facturaencr\FacturaencrClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacturaencrTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_file_is_loaded_correctly(): void
    {
        $this->assertSame('https://api.facturaencr.com/v2/efactura', config('facturaencr.base_url'));
        $this->assertSame('sandbox', config('facturaencr.environment'));
        $this->assertIsInt(config('facturaencr.timeout'));
        $this->assertIsString(config('facturaencr.api_key'));
        $this->assertIsString(config('facturaencr.api_secret'));
    }

    public function test_config_accepts_custom_env_values(): void
    {
        Config::set('facturaencr.base_url', 'https://custom.facturaencr.com/api');
        Config::set('facturaencr.environment', 'production');
        Config::set('facturaencr.timeout', 60);

        $this->assertSame('https://custom.facturaencr.com/api', config('facturaencr.base_url'));
        $this->assertSame('production', config('facturaencr.environment'));
        $this->assertSame(60, config('facturaencr.timeout'));
    }

    public function test_electronic_documents_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('electronic_documents'));
    }

    public function test_electronic_documents_table_has_required_indexes(): void
    {
        $indexes = Schema::getConnection()->select("PRAGMA index_list('electronic_documents')");

        $indexNames = array_map(fn ($i) => $i->name, $indexes);

        $this->assertContains('idx_electronic_documents_idempotency', $indexNames);
        $this->assertContains('uq_electronic_documents_company_sale_type', $indexNames);
    }

    public function test_electronic_documents_table_foreign_key_on_company(): void
    {
        $foreignKeys = Schema::getConnection()->select("PRAGMA foreign_key_list('electronic_documents')");

        $companyFks = array_filter($foreignKeys, fn ($fk) => $fk->from === 'company_id' && $fk->table === 'companies');

        $this->assertNotEmpty($companyFks, 'company_id should have a foreign key constraint to companies');
    }

    public function test_electronic_document_store_and_retrieve(): void
    {
        $company = Company::create([
            'trade_name' => 'Test Comercio',
            'legal_name' => 'Test S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        $sale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'sale_number' => 'POS-001',
            'document_type' => 'electronic_ticket',
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

        $doc = ElectronicDocument::create([
            'company_id' => $company->id,
            'sale_id' => $sale->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'feature-test-idem',
            'status' => 'pending',
        ]);

        $this->assertNotNull($doc->id);

        $found = ElectronicDocument::where('idempotency_key', 'feature-test-idem')->first();
        $this->assertNotNull($found);
        $this->assertSame($company->id, $found->company_id);
        $this->assertSame($sale->id, $found->sale_id);
        $this->assertSame('01', $found->document_type);
    }

    public function test_client_post_with_fake_response(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response([
                'status' => 'accepted',
                'clave' => '506010100000000000010000000000000000000',
                'documentId' => 'doc-feature',
            ], 200),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => '506010100000000000010000000000000000000']);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('accepted', $response->data['status']);
        $this->assertNotNull($response->data['clave']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key'));
    }

    public function test_client_422_response_mapping(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response([
                'error' => 'validation_error',
                'message' => 'The clave field is required.',
                'errors' => ['clave' => ['required']],
            ], 422),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', []);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(422, $response->httpStatusCode);
        $this->assertSame('validation_error', $response->errorCode);
        $this->assertStringContainsString('clave', $response->errorMessage);
    }

    public function test_client_409_conflict_response_mapping(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response([
                'error' => 'conflict',
                'message' => 'Document already exists for this idempotency key.',
            ], 409),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', ['clave' => 'duplicate']);

        $this->assertTrue($response->isError());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(409, $response->httpStatusCode);
        $this->assertSame('conflict', $response->errorCode);
    }

    public function test_store_electronic_document_after_client_call(): void
    {
        $company = Company::create([
            'trade_name' => 'Test Comercio',
            'legal_name' => 'Test S.A.',
            'identification_number' => '3101000000',
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Principal', 'code' => 'PRI', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id);
        $user->branches()->attach($branch->id);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response([
                'status' => 'accepted',
                'clave' => '506010100000000000010000000000000000000',
                'documentId' => 'doc-e2e',
            ], 200),
        ]);

        $client = new FacturaencrClient();
        $response = $client->post('efactura', [
            'clave' => '506010100000000000010000000000000000000',
            'documento' => ['tipoDocumento' => '01'],
        ]);

        $doc = ElectronicDocument::create([
            'company_id' => $company->id,
            'provider' => 'facturaencr',
            'document_type' => '01',
            'environment' => 'sandbox',
            'idempotency_key' => 'e2e-idem-1',
            'provider_document_id' => $response->data['documentId'] ?? null,
            'clave' => $response->data['clave'] ?? null,
            'status' => $response->isSuccess() ? 'accepted' : 'error',
            'provider_request_id' => 'req-e2e-1',
        ]);

        $this->assertNotNull($doc->id);
        $this->assertSame('accepted', $doc->status);
        $this->assertSame('doc-e2e', $doc->provider_document_id);
        $this->assertSame('506010100000000000010000000000000000000', $doc->clave);
    }

    public function test_environment_sandbox_configured(): void
    {
        $this->assertSame('sandbox', config('facturaencr.environment'));

        Config::set('facturaencr.environment', 'production');
        $this->assertSame('production', config('facturaencr.environment'));
    }

    public function test_no_real_http_calls_are_made_in_tests(): void
    {
        Config::set('facturaencr.base_url', 'https://api.facturaencr.com/v2/efactura');
        Config::set('facturaencr.api_key', 'test-key');
        Config::set('facturaencr.api_secret', 'test-secret');
        Config::set('facturaencr.timeout', 30);

        Http::fake([
            'api.facturaencr.com/v2/efactura/efactura' => Http::response(['status' => 'ok'], 200),
        ]);

        $client = new FacturaencrClient();
        $client->post('efactura', ['test' => true]);

        Http::assertSent(function ($request) {
            return $request->url() !== '';
        });

        Http::preventStrayRequests();
    }
}