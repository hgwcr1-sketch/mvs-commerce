<?php

namespace Tests\Feature;

use App\Models\AccountReceivable;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentMethodProvisioner;
use App\Services\Sales\AccountsReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_100_percent_nc_sale(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000);

        $response->assertOk()->assertJsonPath('duplicate', false);
        $sale = Sale::latest('id')->first();
        $this->assertSame('10000.0000', $sale->paid_total);
        $this->assertSame(0, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
        $this->assertDatabaseHas('credit_note_applications', ['credit_note_id' => $nc->id, 'sale_id' => $sale->id, 'amount' => '10000.0000', 'status' => CreditNoteApplication::STATUS_APPLIED]);
    }

    public function test_nc_less_than_total_with_cash(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 6300, 'received_amount' => 6300],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame('11300.0000', $sale->total);
        $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertSame('6300.0000', $payment->amount);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_nc_less_than_total_with_card(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $card->id, 'amount' => 6300, 'reference' => 'CARD-TEST'],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertSame('6300.0000', $payment->amount);
        $this->assertSame('CARD-TEST', $payment->reference);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_nc_less_than_total_with_sinpe(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $sinpe = PaymentMethod::forCompany($company->id)->where('type', 'sinpe')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $sinpe->id, 'amount' => 6300, 'reference' => 'SINPE-TEST'],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $payment = SalePayment::where('sale_id', $sale->id)->first();
        $this->assertSame('6300.0000', $payment->amount);
        $this->assertSame('SINPE-TEST', $payment->reference);
    }

    public function test_multiple_nc(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc1 = $this->createCreditNote($company, $branch, $customer, $user, 5000);
        $nc2 = $this->createCreditNote($company, $branch, $customer, $user, 3000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc1->id => 5000, $nc2->id => 3000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 3300, 'received_amount' => 3300],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame(1, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('credit_notes', ['id' => $nc1->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc2->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
        $this->assertSame(2, CreditNoteApplication::where('sale_id', $sale->id)->count());
    }

    public function test_partial_nc_application(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 8000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 8000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 3300, 'received_amount' => 3300],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);
    }

    public function test_nc_amount_exceeds_total_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 15000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 15000], 11300)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_nc_from_other_customer_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $otherCustomer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $otherCustomer, $user, 10000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_nc_without_customer_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => null,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 10000]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_nc_from_other_company_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany, $otherBranch] = $this->companyAndBranch('Otra');
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($otherCompany, $otherBranch, $customer, $user, 10000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_cross_branch_nc_allowed(): void
    {
        [$company, $branch, $user] = $this->context();
        $branchB = $this->branch($company, 'Sucursal B');
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branchB, $customer, $user, 10000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame($branch->id, $sale->branch_id);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000']);
    }

    public function test_voided_nc_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);
        $nc->update(['status' => CreditNote::STATUS_VOIDED, 'balance' => '0.0000']);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_zero_balance_nc_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);
        $nc->update(['balance' => '0.0000', 'status' => CreditNote::STATUS_APPLIED]);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_amount_exceeds_nc_balance_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 8000], 10000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_amount_exceeds_pending_sale_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc1 = $this->createCreditNote($company, $branch, $customer, $user, 8000);
        $nc2 = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc1->id => 8000, $nc2->id => 5000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 100, 'received_amount' => 100],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 2);
    }

    public function test_precision_0001(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, true, ['sale_price' => 10000, 'tax_rate' => 0]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 3333);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 3333], 10000, [
            ['payment_method_id' => $cash->id, 'amount' => 6667, 'received_amount' => 6667],
        ]);

        $response->assertOk();
        $saleId = $response->json('sale_id');
        $sale = Sale::findOrFail($saleId);
        $this->assertSame('10000.0000', $sale->subtotal);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000']);
    }

    public function test_duplicate_nc_ids_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail()->id, 'amount' => 10000, 'received_amount' => 10000]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [
                ['credit_note_id' => $nc->id, 'amount' => 3000],
                ['credit_note_id' => $nc->id, 'amount' => 2000],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_permission_required(): void
    {
        [$company, $branch] = $this->companyAndBranch('Empresa');
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear']);
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 10000]],
        ])->assertUnprocessable();
    }

    public function test_search_endpoint_returns_ncs(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 7500);
        $this->createCreditNote($company, $branch, $customer, $user, 2500);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.credit-notes.available', ['customer_id' => $customer->id]));

        $response->assertOk();
        $data = $response->json();
        $this->assertCount(2, $data);
        $this->assertSame($nc->credit_note_number, $data[0]['number']);
    }

    public function test_search_endpoint_filters_by_company(): void
    {
        [$company, $branch, $user] = $this->context();
        [$otherCompany, $otherBranch] = $this->companyAndBranch('Ajena');
        $customer = $this->customer($company);
        $otherCustomer = $this->customer($otherCompany);
        $this->createCreditNote($company, $branch, $customer, $user, 5000);
        $this->createCreditNote($otherCompany, $otherBranch, $otherCustomer, $user, 5000);

        $response = $this->actingAs($user)
            ->withSession($this->activeSession($company, $branch))
            ->getJson(route('pos.credit-notes.available', ['customer_id' => $customer->id]));

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_100_percent_nc_creates_zero_sale_payments(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)->assertOk();

        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_100_percent_nc_creates_zero_cash_movements(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)->assertOk();

        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_nc_plus_cash_only_monetary_counted(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 6300, 'received_amount' => 6300],
        ])->assertOk();

        $session = $this->ensureCashSession($company, $branch, $user);
        $cashEffectTotal = (float) SalePayment::where('cash_session_id', $session->id)->where('affects_cash_snapshot', true)->sum('cash_effect_amount');
        $this->assertSame(6300.0, $cashEffectTotal);
    }

    public function test_nc_plus_multiple_payments(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $card = PaymentMethod::forCompany($company->id)->where('type', 'card')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 3000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 3000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 5000, 'received_amount' => 5000],
            ['payment_method_id' => $card->id, 'amount' => 3300, 'reference' => 'CARD-MIX'],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame(2, SalePayment::where('sale_id', $sale->id)->count());
        $this->assertSame('0.0000', (string) $nc->fresh()->balance);
    }

    public function test_nc_plus_credit_ar_amount(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithCredit($company, 40000);
        $product = $this->product($company, false, false, ['sale_price' => 50000, 'tax_rate' => 0]);
        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 15000);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $credit->id, 'amount' => 50000, 'received_amount' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 15000]],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $ar = AccountReceivable::where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame('35000.0000', $ar->original_amount);
        $this->assertSame('35000.0000', $ar->balance_due);
    }

    public function test_credit_limit_validates_reduced_amount(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithCredit($company, 40000);
        $product = $this->product($company, false, false, ['sale_price' => 50000, 'tax_rate' => 0]);
        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 15000);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $credit->id, 'amount' => 50000, 'received_amount' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 15000]],
        ]);

        $response->assertOk();
        $ar = AccountReceivable::where('sale_id', Sale::latest('id')->first()->id)->firstOrFail();
        $this->assertSame('35000.0000', $ar->original_amount);
    }

    public function test_checkout_idempotent_with_nc(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);
        $token = (string) Str::uuid();

        $first = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000, [], null, $token);
        $second = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000, [], null, $token);

        $first->assertOk()->assertJsonPath('duplicate', false);
        $second->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_fingerprint_changes_with_nc(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc1 = $this->createCreditNote($company, $branch, $customer, $user, 5000);
        $nc2 = $this->createCreditNote($company, $branch, $customer, $user, 8000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc1->id => 5000], 10000, [
            ['payment_method_id' => PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail()->id, 'amount' => 5000, 'received_amount' => 5000],
        ])->assertOk();

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc2->id => 8000], 10000, [
            ['payment_method_id' => PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail()->id, 'amount' => 2000, 'received_amount' => 2000],
        ])->assertOk();

        $this->assertSame(4, Sale::count());
    }

    public function test_concurrent_nc_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)->assertOk();
        $nc->refresh();
        $this->assertSame('0.0000', (string) $nc->balance);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000, [], null, (string) Str::uuid())
            ->assertUnprocessable();
    }

    public function test_rollback_restores_nc_balance(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', 'loyalty_points')->firstOrFail();
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 10000]],
            'requested_points' => 1000,
        ])->assertUnprocessable();

        $nc->refresh();
        $this->assertSame('10000.0000', (string) $nc->balance);
        $this->assertSame(CreditNote::STATUS_ISSUED, $nc->status);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_loyalty_plus_nc_coexistence(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '9000.0000');
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 3300, 'received_amount' => 3300],
        ], null, (string) Str::uuid(), 3000);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertSame(2, SalePayment::where('sale_id', $sale->id)->count());
        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', 'loyalty_points')->firstOrFail();
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale->id,
            'payment_method_id' => $loyaltyMethod->id,
            'amount' => '3000.0000',
        ]);
        $this->assertDatabaseHas('credit_notes', ['id' => $nc->id, 'balance' => '0.0000']);
    }

    public function test_salevoid_reverses_active_nc_and_restores_balance(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $saleId = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 10000], 10000)->json('sale_id');
        $sale = Sale::findOrFail($saleId);

        // La NC está totalmente aplicada (balance = 0)
        $nc->refresh();
        $this->assertSame('0.0000', (string) $nc->balance);
        $this->assertSame(CreditNote::STATUS_APPLIED, $nc->status);

        // Anular la venta debe revertir la aplicación de NC
        app(\App\Services\Sales\SaleVoidService::class)->void($sale, $user, 'Anulación con NC aplicada');

        // Verificar que la venta quedó anulada
        $sale->refresh();
        $this->assertSame(Sale::STATUS_VOIDED, $sale->status);

        // Verificar que la aplicación de NC fue revertida
        $app = CreditNoteApplication::where('sale_id', $sale->id)
            ->where('status', CreditNoteApplication::STATUS_VOIDED)
            ->firstOrFail();
        $this->assertSame(CreditNoteApplication::STATUS_VOIDED, $app->status);
        $this->assertNotNull($app->voided_at);
        $this->assertSame('Anulación de venta '.$sale->sale_number.': Anulación con NC aplicada', $app->void_reason);

        // Verificar que la NC recuperó su balance original
        $nc->refresh();
        $this->assertSame('10000.0000', (string) $nc->balance);
        $this->assertSame('0.0000', (string) $nc->applied_amount);
        $this->assertSame(CreditNote::STATUS_ISSUED, $nc->status);

        // La NC debe estar disponible nuevamente
        $available = app(\App\Services\Sales\CreditNoteService::class)->availableForCustomer((int) $company->id, (int) $customer->id);
        $this->assertCount(1, $available);
        $this->assertSame($nc->id, $available->first()->id);
    }

    public function test_100_percent_nc_no_cash_session(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => null,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 10000]],
        ]);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $this->assertNull($sale->cash_session_id);
    }

    public function test_partial_nc_no_cash_session_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => null,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 5000]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_invalid_nc_no_cash_session_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);

        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => null,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => 999999, 'amount' => 10000]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_nc_order_independent_fingerprint(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc1 = $this->createCreditNote($company, $branch, $customer, $user, 10000);
        $nc2 = $this->createCreditNote($company, $branch, $customer, $user, 6000);

        $token1 = (string) Str::uuid();
        $response1 = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc2->id => 3000, $nc1->id => 5000], 10000, [
            ['payment_method_id' => $cash->id, 'amount' => 2000, 'received_amount' => 2000],
        ], null, $token1);
        $response1->assertOk()->assertJsonPath('duplicate', false);

        $token2 = (string) Str::uuid();
        $response2 = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc1->id => 5000, $nc2->id => 3000], 10000, [
            ['payment_method_id' => $cash->id, 'amount' => 2000, 'received_amount' => 2000],
        ], null, $token2);

        $this->assertSame(4, Sale::count());
        $fp1 = Sale::where('checkout_token', $token1)->value('request_fingerprint');
        $fp2 = Sale::where('checkout_token', $token2)->value('request_fingerprint');
        $this->assertSame($fp1, $fp2);
    }

    public function test_multiple_nc_locked_in_id_asc(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customer($company);
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $ncHigh = $this->createCreditNote($company, $branch, $customer, $user, 5000);
        $ncLow = $this->createCreditNote($company, $branch, $customer, $user, 3000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$ncHigh->id => 5000, $ncLow->id => 3000], 10000, [
            ['payment_method_id' => $cash->id, 'amount' => 2000, 'received_amount' => 2000],
        ]);

        $response->assertOk();
        $saleId = $response->json('sale_id');
        $sale = Sale::findOrFail($saleId);
        $apps = CreditNoteApplication::where('sale_id', $sale->id)->orderBy('id')->get();
        $this->assertSame(2, $apps->count());
        $this->assertSame($ncHigh->id, $apps[0]->credit_note_id);
        $this->assertSame($ncLow->id, $apps[1]->credit_note_id);
    }

    public function test_nc_loyalty_cash_exact_coverage(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '9000.0000');
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $cash = PaymentMethod::forCompany($company->id)->where('type', 'cash')->firstOrFail();
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 5000);

        $response = $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 5000], 11300, [
            ['payment_method_id' => $cash->id, 'amount' => 3300, 'received_amount' => 3300],
        ], null, (string) Str::uuid(), 3000);

        $response->assertOk();
        $sale = Sale::latest('id')->first();
        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', 'loyalty_points')->firstOrFail();
        $cashPaid = SalePayment::where('sale_id', $sale->id)->where('payment_method_id', $cash->id)->sum('amount');
        $loyaltyPaid = SalePayment::where('sale_id', $sale->id)->where('payment_method_id', $loyaltyMethod->id)->sum('amount');
        $this->assertSame(3300.0, (float) $cashPaid);
        $this->assertSame(3000.0, (float) $loyaltyPaid);
        $this->assertSame('0.0000', (string) $nc->fresh()->balance);
    }

    public function test_nc_loyalty_exceeds_total_rejected(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '9000.0000');
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 13]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 8000);

        $this->checkoutNc($user, $company, $branch, $customer, $product, [$nc->id => 8000], 11300, [], null, (string) Str::uuid(), 5000)
            ->assertUnprocessable();

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_rollback_after_nc_application(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithBalance($company, '9000.0000');
        $product = $this->product($company, false, false, ['sale_price' => 10000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 10000);

        $loyaltyMethod = PaymentMethod::forCompany($company->id)->where('type', 'loyalty_points')->firstOrFail();
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 10000]],
            'requested_points' => 99999,
        ])->assertUnprocessable();

        $nc->refresh();
        $this->assertSame('10000.0000', (string) $nc->balance);
        $this->assertSame(CreditNote::STATUS_ISSUED, $nc->status);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('credit_note_applications', 0);
    }

    public function test_ar_create_legacy_compatible(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithCredit($company, 50000);
        $product = $this->product($company, false, false, ['sale_price' => 20000, 'tax_rate' => 0]);

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();

        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $credit->id, 'amount' => 20000, 'received_amount' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);

        $response->assertOk();
        $ar = AccountReceivable::where('sale_id', Sale::latest('id')->first()->id)->firstOrFail();
        $this->assertSame('20000.0000', $ar->original_amount);
        $this->assertSame('20000.0000', $ar->balance_due);
    }

    public function test_ar_create_with_credit_amount(): void
    {
        [$company, $branch, $user] = $this->context();
        $customer = $this->customerWithCredit($company, 40000);
        $product = $this->product($company, false, false, ['sale_price' => 50000, 'tax_rate' => 0]);
        $nc = $this->createCreditNote($company, $branch, $customer, $user, 15000);
        $credit = PaymentMethod::forCompany($company->id)->where('type', 'credit')->firstOrFail();

        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $response = $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'payments' => [['payment_method_id' => $credit->id, 'amount' => 50000, 'received_amount' => null]],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => [['credit_note_id' => $nc->id, 'amount' => 15000]],
        ]);

        $response->assertOk();
        $ar = AccountReceivable::where('sale_id', Sale::latest('id')->first()->id)->firstOrFail();
        $this->assertSame('35000.0000', $ar->original_amount);
        $this->assertSame('35000.0000', $ar->balance_due);
    }

    // ── helpers ──

    private function context(string $name = 'Empresa'): array
    {
        $company = $this->company($name);
        app(PaymentMethodProvisioner::class)->provision($company);
        $branch = $this->branch($company, 'Principal');
        $user = $this->user($company, $branch, ['pos.acceder', 'ventas.crear', 'notas_credito.aplicar']);
        $product = $this->product($company);
        $this->stock($branch, $product, 50);

        return [$company, $branch, $user];
    }

    private function company(string $name): Company
    {
        return Company::create(['trade_name' => $name.uniqid(), 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
    }

    private function companyAndBranch(string $name): array
    {
        $company = $this->company($name);
        $branch = $this->branch($company, 'Principal');
        return [$company, $branch];
    }

    private function branch(Company $company, string $name): Branch
    {
        return Branch::create(['company_id' => $company->id, 'name' => $name, 'code' => $name.'-'.$company->id, 'is_active' => true]);
    }

    private function user(Company $company, Branch $branch, array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.uniqid(), 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'POS', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission);
        }
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        $user->branches()->attach($branch->id);
        return $user;
    }

    private function product(Company $company, bool $tracked = false, bool $decimals = false, array $attributes = []): Product
    {
        $suffix = uniqid();
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'Cat '.$suffix, 'slug' => 'cat-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad', 'abbreviation' => 'U', 'slug' => 'u-'.$suffix, 'allows_decimals' => $decimals, 'is_active' => true]);
        return Product::create(array_merge(['company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id, 'name' => 'Producto '.$suffix, 'internal_code' => 'P-'.$suffix, 'cost' => 500, 'sale_price' => 1000, 'stock' => 123, 'tax_rate' => 13, 'track_inventory' => $tracked, 'is_active' => true], $attributes));
    }

    private function customer(Company $company, array $attributes = []): Customer
    {
        return Customer::create(array_merge(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true], $attributes));
    }

    private function customerWithCredit(Company $company, float $creditLimit): Customer
    {
        return Customer::create([
            'company_id' => $company->id,
            'name' => 'Cliente '.uniqid(),
            'customer_type' => 'individual',
            'is_active' => true,
            'credit_limit' => $creditLimit,
            'credit_days' => 30,
        ]);
    }

    private function customerWithBalance(Company $company, string $balance): Customer
    {
        LoyaltySetting::firstOrCreate(['company_id' => $company->id], [
            'is_active' => true,
            'earning_percentage' => 1,
            'point_value' => 1,
            'minimum_redemption_points' => 1,
            'redemption_minimum_enabled' => false,
            'redemption_minimum_amount' => 0,
            'maximum_redemption_percent' => 100,
            'earn_on_offers' => false,
            'birthday_enabled' => false,
            'birthday_points' => 0,
            'returning_customer_enabled' => false,
            'returning_customer_days' => 0,
            'returning_customer_points' => 0,
            'redeem_on_offers' => false,
            'expiration_enabled' => false,
            'expiration_months' => 0,
        ]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Cliente '.uniqid(), 'customer_type' => 'individual', 'is_active' => true]);
        LoyaltyAccount::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'balance' => $balance]);
        return $customer;
    }

    private function stock(Branch $branch, Product $product, float $stock): void
    {
        DB::table('branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function createCreditNote(Company $company, Branch $branch, Customer $customer, User $user, float $amount): CreditNote
    {
        $cashSession = $this->ensureCashSession($company, $branch, $user);

        $originSale = Sale::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customer->id,
            'checkout_token' => (string) Str::uuid(),
            'request_fingerprint' => 'test-'.uniqid(),
            'sale_number' => 'V-'.uniqid(),
            'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
            'sale_condition' => Sale::CONDITION_CASH,
            'status' => Sale::STATUS_COMPLETED,
            'currency_code' => 'CRC',
            'subtotal' => $amount,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $amount,
            'paid_total' => $amount,
            'balance_due' => 0,
        ]);

        $saleReturn = SaleReturn::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'sale_id' => $originSale->id,
            'user_id' => $user->id,
            'return_number' => 'DV-'.uniqid(),
            'reason' => 'Test return',
            'status' => SaleReturn::STATUS_COMPLETED,
            'returned_at' => now(),
        ]);

        return CreditNote::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'sale_id' => $originSale->id,
            'sale_return_id' => $saleReturn->id,
            'credit_note_number' => 'NC-TEST-'.uniqid(),
            'currency_code' => 'CRC',
            'issued_amount' => $amount,
            'offset_amount' => '0',
            'applied_amount' => '0',
            'balance' => $amount,
            'status' => CreditNote::STATUS_ISSUED,
            'reason' => 'Test',
            'issued_by' => $user->id,
            'issued_at' => now(),
            'requires_ar_review' => false,
        ]);
    }

    private function ensureCashSession(Company $company, Branch $branch, User $user): CashSession
    {
        $session = CashSession::query()->forCompany($company->id)->forBranch($branch->id)->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->first();
        if ($session) return $session;
        $register = CashRegister::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'CAJA-'.uniqid(), 'name' => 'Caja', 'is_active' => true]);
        return CashSession::create(['company_id' => $company->id, 'branch_id' => $branch->id, 'cash_register_id' => $register->id, 'session_number' => 'S-'.uniqid(), 'opened_by' => $user->id, 'status' => CashSession::STATUS_OPEN, 'open_guard' => CashSession::OPEN_GUARD, 'opening_amount' => 0, 'opened_at' => now()]);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function checkoutNc(
        User $user,
        Company $company,
        Branch $branch,
        Customer $customer,
        Product $product,
        array $ncMap,
        int $expectedTotal,
        array $extraPayments = [],
        ?int $customerId = null,
        ?string $token = null,
        ?string $requestedPoints = null,
    ) {
        $cashSession = $this->ensureCashSession($company, $branch, $user);
        $ncApplications = [];
        foreach ($ncMap as $ncId => $amount) {
            $ncApplications[] = ['credit_note_id' => is_object($ncId) ? $ncId->id : $ncId, 'amount' => $amount];
        }

        $payload = array_filter([
            'checkout_token' => $token ?? (string) Str::uuid(),
            'cash_session_id' => $cashSession->id,
            'customer_id' => $customerId ?? $customer->id,
            'payments' => $extraPayments,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'credit_note_applications' => $ncApplications,
            'requested_points' => $requestedPoints,
        ], fn ($value) => $value !== null);

        return $this->actingAs($user)->withSession($this->activeSession($company, $branch))->postJson(route('pos.checkout'), $payload);
    }
}
