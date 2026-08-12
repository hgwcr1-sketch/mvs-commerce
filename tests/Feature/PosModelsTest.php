<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_methods_are_isolated_by_company(): void
    {
        [$firstCompany] = $this->companyContext('Empresa uno');
        [$secondCompany] = $this->companyContext('Empresa dos');
        $firstMethod = $this->paymentMethod($firstCompany, 'cash', 2);
        $secondMethod = $this->paymentMethod($secondCompany, 'card', 1);

        $methods = PaymentMethod::forCompany($firstCompany->id)
            ->active()
            ->ordered()
            ->get();

        $this->assertTrue($methods->contains($firstMethod));
        $this->assertFalse($methods->contains($secondMethod));
    }

    public function test_sales_are_isolated_by_company_and_branch(): void
    {
        [$firstCompany, $firstBranch, $user] = $this->companyContext('Empresa uno');
        $secondBranch = Branch::create([
            'company_id' => $firstCompany->id,
            'name' => 'Secundaria',
            'code' => 'S-'.$firstCompany->id,
            'is_active' => true,
        ]);
        [$secondCompany, $thirdBranch, $secondUser] = $this->companyContext('Empresa dos');

        $firstSale = $this->sale($firstCompany, $firstBranch, $user, 'V-1');
        $secondSale = $this->sale($firstCompany, $secondBranch, $user, 'V-2');
        $thirdSale = $this->sale($secondCompany, $thirdBranch, $secondUser, 'V-3');

        $sales = Sale::forCompany($firstCompany->id)
            ->forBranch($firstBranch->id)
            ->get();

        $this->assertTrue($sales->contains($firstSale));
        $this->assertFalse($sales->contains($secondSale));
        $this->assertFalse($sales->contains($thirdSale));
    }

    public function test_sale_can_have_multiple_items(): void
    {
        [$company, $branch, $user] = $this->companyContext('Empresa uno');
        $sale = $this->sale($company, $branch, $user, 'V-1');

        $sale->items()->createMany([
            $this->itemData('Producto uno', '10.0000'),
            $this->itemData('Producto dos', '20.0000'),
        ]);

        $this->assertCount(2, $sale->fresh()->items);
        $this->assertInstanceOf(SaleItem::class, $sale->items->first());
    }

    public function test_sale_can_have_multiple_payments_with_different_methods(): void
    {
        [$company, $branch, $user] = $this->companyContext('Empresa uno');
        $sale = $this->sale($company, $branch, $user, 'V-1');
        $cash = $this->paymentMethod($company, 'cash', 1);
        $card = $this->paymentMethod($company, 'card', 2);

        $sale->payments()->createMany([
            $this->paymentData($cash, $user, '5.0000'),
            $this->paymentData($card, $user, '5.0000'),
        ]);

        $payments = $sale->fresh()->payments;

        $this->assertCount(2, $payments);
        $this->assertEqualsCanonicalizing(
            [$cash->id, $card->id],
            $payments->pluck('payment_method_id')->all()
        );
    }

    public function test_null_customer_represents_final_consumer(): void
    {
        [$company, $branch, $user] = $this->companyContext('Empresa uno');
        $sale = $this->sale($company, $branch, $user, 'V-1');

        $this->assertNull($sale->customer_id);
        $this->assertNull($sale->customer);
    }

    public function test_monetary_casts_preserve_four_decimal_places(): void
    {
        [$company, $branch, $user] = $this->companyContext('Empresa uno');
        $sale = $this->sale($company, $branch, $user, 'V-1', [
            'exchange_rate' => '1.2345',
            'subtotal' => '10.1234',
            'discount_total' => '1.1000',
            'tax_total' => '1.1722',
            'total' => '10.1956',
            'paid_total' => '5.0000',
            'balance_due' => '5.1956',
        ]);
        $item = $sale->items()->create($this->itemData('Producto', '10.1234'));
        $method = $this->paymentMethod($company, 'cash', 1);
        $payment = $sale->payments()->create(
            $this->paymentData($method, $user, '5.0000')
        );

        $sale = $sale->fresh();
        $item = $item->fresh();
        $payment = $payment->fresh();

        $this->assertSame('1.2345', $sale->exchange_rate);
        $this->assertSame('10.1234', $sale->subtotal);
        $this->assertSame('10.1234', $item->unit_price);
        $this->assertSame('1.0000', $item->quantity);
        $this->assertSame('5.0000', $payment->amount);
        $this->assertSame('0.0000', $payment->change_amount);
    }

    public function test_completed_and_suspended_scopes_filter_sales(): void
    {
        [$company, $branch, $user] = $this->companyContext('Empresa uno');
        $completed = $this->sale($company, $branch, $user, 'V-1', [
            'status' => Sale::STATUS_COMPLETED,
        ]);
        $suspended = $this->sale($company, $branch, $user, 'V-2', [
            'status' => Sale::STATUS_SUSPENDED,
        ]);
        $this->sale($company, $branch, $user, 'V-3', [
            'status' => Sale::STATUS_DRAFT,
        ]);

        $this->assertSame([$completed->id], Sale::completed()->pluck('id')->all());
        $this->assertSame([$suspended->id], Sale::suspended()->pluck('id')->all());
    }

    public function test_payment_created_by_and_voided_by_relations_work(): void
    {
        [$company, $branch, $creator] = $this->companyContext('Empresa uno');
        $voider = User::factory()->create();
        $sale = $this->sale($company, $branch, $creator, 'V-1');
        $method = $this->paymentMethod($company, 'cash', 1);
        $payment = $sale->payments()->create([
            ...$this->paymentData($method, $creator, '10.0000'),
            'status' => SalePayment::STATUS_VOIDED,
            'voided_by' => $voider->id,
            'voided_at' => now(),
            'void_reason' => 'Prueba',
        ]);

        $this->assertTrue($payment->createdBy->is($creator));
        $this->assertTrue($payment->voidedBy->is($voider));
        $this->assertTrue($payment->paymentMethod->is($method));
        $this->assertTrue($payment->sale->is($sale));
    }

    private function companyContext(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'is_active' => true,
        ]);
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
        array $attributes = []
    ): Sale {
        return Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'customer_id' => null,
            'sale_number' => $number,
            ...$attributes,
        ]);
    }

    private function paymentMethod(
        Company $company,
        string $code,
        int $sortOrder
    ): PaymentMethod {
        return PaymentMethod::create([
            'company_id' => $company->id,
            'code' => $code,
            'name' => ucfirst($code),
            'type' => $code,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private function itemData(string $description, string $unitPrice): array
    {
        return [
            'product_id' => null,
            'description' => $description,
            'quantity' => '1.0000',
            'unit_price' => $unitPrice,
            'gross_total' => $unitPrice,
            'discount_total' => '0.0000',
            'subtotal' => $unitPrice,
            'tax_rate' => '0.0000',
            'tax_total' => '0.0000',
            'total' => $unitPrice,
            'unit_cost' => '4.5678',
        ];
    }

    private function paymentData(
        PaymentMethod $method,
        User $user,
        string $amount
    ): array {
        return [
            'payment_method_id' => $method->id,
            'created_by' => $user->id,
            'amount' => $amount,
            'received_amount' => $amount,
            'change_amount' => '0.0000',
            'status' => SalePayment::STATUS_COMPLETED,
        ];
    }
}
