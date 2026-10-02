<?php

namespace Tests\Feature;

use App\Models\AccountReceivablePayment;
use App\Models\CobrosReceipt;
use App\Services\RouteosAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrosPreparation6BTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $fixture = new CobrosPaymentTest('test_partial_payment_reduces_balance_and_creates_unique_receipt');
        $call = fn (string $method, array $args = []) => (new \ReflectionMethod($fixture, $method))->invokeArgs($fixture, $args);
        [$company, $branch, $user] = $call('context');
        [, $account] = $call('customerWithAccount', [$company, $branch, $user, '3000.0000']);
        $payload = $call('payload', ['1000.00', $call('method', [$company, 'cash'])]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);

        return [$account, $payload];
    }

    public function test_failure_before_commit_never_redirects_to_success_and_rolls_back_payment(): void
    {
        [$account, $payload] = $this->fixture();
        $this->mock(RouteosAuditService::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('6B injected failure before commit'));
        $response = $this->post(route('cobros.collector.payments.store', $account), [...$payload, 'confirm' => '1']);
        $response->assertStatus(500);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $this->assertDatabaseCount('cobros_receipts', 0);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('3000.0000', (string) $account->fresh()->balance_due);
    }

    public function test_lost_response_retry_returns_same_receipt_and_exports_isolated_browser_fixtures(): void
    {
        [$account, $payload] = $this->fixture();
        $form = $this->get(route('cobros.collector.payments.create', $account))->assertOk();
        $preview = $this->post(route('cobros.collector.payments.store', $account), $payload)->assertOk();
        $this->assertDatabaseCount('accounts_receivable_payments', 0);
        $url = route('cobros.collector.payments.store', $account);
        $this->post($url, [...$payload, 'confirm' => '1']); // Application succeeded; client discards response.
        $retry = $this->post($url, [...$payload, 'confirm' => '1']);
        $receipt = CobrosReceipt::sole();
        $retry->assertRedirect(route('cobros.collector.payments.receipt', $receipt->receipt_number));
        $retry->assertSessionHas('cobros_receipt_replay', true);
        $page = $this->get($retry->headers->get('Location'))->assertOk();
        $this->assertDatabaseCount('accounts_receivable_payments', 1);
        $this->assertDatabaseCount('cobros_receipts', 1);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertFalse(AccountReceivablePayment::sole()->affects_cash_snapshot);
        $this->assertSame('2000.0000', (string) $account->fresh()->balance_due);
        if ($directory = getenv('COBROS_6B_HTML')) {
            foreach (['form' => $form, 'preview' => $preview, 'receipt' => $page] as $name => $response) {
                file_put_contents($directory.'/cobros-6b-'.$name.'.html', $response->getContent());
            }
        }
    }
}
