<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySequence;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PosCheckoutInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_sequence_is_incremental_and_independent_per_company(): void
    {
        $firstCompany = $this->company('Empresa uno');
        $secondCompany = $this->company('Empresa dos');

        $this->assertSame('POS-00000001', CompanySequence::nextPosNumber($firstCompany->id));
        $this->assertSame('POS-00000002', CompanySequence::nextPosNumber($firstCompany->id));
        $this->assertSame('POS-00000001', CompanySequence::nextPosNumber($secondCompany->id));

        $this->assertDatabaseHas('company_sequences', [
            'company_id' => $firstCompany->id,
            'name' => CompanySequence::POS,
            'current_value' => 2,
        ]);
    }

    public function test_checkout_token_is_unique_per_company_and_fingerprint_is_persisted(): void
    {
        [$firstCompany, $firstBranch, $firstUser] = $this->context('Empresa uno');
        [$secondCompany, $secondBranch, $secondUser] = $this->context('Empresa dos');
        $token = (string) Str::uuid();
        $fingerprint = hash('sha256', 'solicitud-normalizada');

        $sale = $this->sale($firstCompany, $firstBranch, $firstUser, 'POS-00000001', $token, $fingerprint);
        $this->sale($secondCompany, $secondBranch, $secondUser, 'POS-00000001', $token, $fingerprint);

        $this->assertSame($token, $sale->fresh()->checkout_token);
        $this->assertSame($fingerprint, $sale->fresh()->request_fingerprint);

        $this->expectException(QueryException::class);
        $this->sale($firstCompany, $firstBranch, $firstUser, 'POS-00000002', $token, $fingerprint);
    }

    public function test_inventory_movements_preserve_four_decimal_places(): void
    {
        [$company, $branch, $user] = $this->context('Empresa precisión');
        $product = $this->product($company);

        $movement = InventoryMovement::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'sale',
            'quantity' => '1.2345',
            'previous_stock' => '10.9876',
            'new_stock' => '9.7531',
        ])->fresh();

        $this->assertSame('1.2345', $movement->quantity);
        $this->assertSame('10.9876', $movement->previous_stock);
        $this->assertSame('9.7531', $movement->new_stock);
    }

    public function test_sequence_and_sale_are_rolled_back_together_when_transaction_fails(): void
    {
        [$company, $branch, $user] = $this->context('Empresa rollback');
        $token = (string) Str::uuid();

        try {
            DB::transaction(function () use ($company, $branch, $user, $token): void {
                $number = CompanySequence::nextPosNumber($company->id);
                $this->sale(
                    $company,
                    $branch,
                    $user,
                    $number,
                    $token,
                    hash('sha256', 'rollback'),
                );

                throw new RuntimeException('Fallo simulado');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado', $exception->getMessage());
        }

        $this->assertDatabaseMissing('sales', ['checkout_token' => $token]);
        $this->assertDatabaseMissing('company_sequences', [
            'company_id' => $company->id,
            'name' => CompanySequence::POS,
        ]);
        $this->assertSame('POS-00000001', CompanySequence::nextPosNumber($company->id));
    }

    private function company(string $name): Company
    {
        return Company::create([
            'trade_name' => $name,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);
    }

    private function context(string $name): array
    {
        $company = $this->company($name);
        $branch = Branch::create([
            'company_id' => $company->id,
            'name' => 'Principal',
            'code' => 'P-'.$company->id,
            'is_active' => true,
        ]);
        $user = User::factory()->create();

        return [$company, $branch, $user];
    }

    private function sale(
        Company $company,
        Branch $branch,
        User $user,
        string $number,
        string $token,
        string $fingerprint,
    ): Sale {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'checkout_token' => $token,
            'request_fingerprint' => $fingerprint,
            'sale_number' => $number,
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => 0,
            'paid_total' => 0,
            'balance_due' => 0,
            'completed_at' => now(),
        ]);
    }

    private function product(Company $company): Product
    {
        $category = ProductCategory::create([
            'company_id' => $company->id,
            'name' => 'Categoría '.$company->id,
            'slug' => 'categoria-'.$company->id,
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'company_id' => $company->id,
            'name' => 'Kilogramo',
            'abbreviation' => 'kg',
            'slug' => 'kg-'.$company->id,
            'allows_decimals' => true,
            'is_active' => true,
        ]);

        return Product::create([
            'company_id' => $company->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'name' => 'Producto precisión',
            'internal_code' => 'PREC-'.$company->id,
            'sale_price' => 1000,
            'track_inventory' => true,
            'is_active' => true,
        ]);
    }
}
