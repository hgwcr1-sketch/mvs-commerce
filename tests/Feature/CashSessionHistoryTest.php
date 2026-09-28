<?php

namespace Tests\Feature;

use App\Jobs\SendCashSessionMailNotification;
use App\Models\Branch;
use App\Models\CashCountDetail;
use App\Models\CashDenomination;
use App\Models\CashPaymentReconciliation;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\CashSessionMailNotification;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashSessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_is_paginated_ordered_and_preserves_filters(): void
    {
        [$company, $branch, $register] = $this->context('Empresa');
        $viewer = $this->user($company, $branch, ['caja.ver']);

        for ($i = 1; $i <= 26; $i++) {
            $this->cashSession($company, $branch, $register, $viewer, [
                'session_number' => sprintf('CAJA-%08d', $i),
                'status' => 'closed',
                'open_guard' => null,
                'opened_at' => now()->subMinutes($i),
                'closed_at' => now()->subMinutes($i)->addMinute(),
            ]);
        }

        $response = $this->getAs(
            $viewer,
            $company,
            $branch,
            route('cash.history.index', ['status' => 'closed'])
        );

        $response
            ->assertOk()
            ->assertSee('CAJA-00000001')
            ->assertDontSee('CAJA-00000026')
            ->assertSee('status=closed', false);

        $this->assertCount(25, $response->viewData('sessions'));
    }

    public function test_scope_permissions_and_sensitive_detail_are_separated(): void
    {
        [$company, $branch, $register] = $this->context('Empresa Uno');

        $otherBranch = $this->branch($company, 'Histórica', false);
        $otherRegister = $this->register(
            $company,
            $otherBranch,
            'Caja inactiva',
            false
        );

        $viewer = $this->user($company, $branch, ['caja.ver']);

        $all = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.ver_todas']
        );

        $admin = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.ver_todas', 'caja.administrar']
        );

        $local = $this->cashSession(
            $company,
            $branch,
            $register,
            $viewer,
            [
                'session_number' => 'LOCAL',
                'expected_cash' => 7000,
                'counted_cash' => 6500,
                'difference_amount' => -500,
                'closing_submitted_at' => now(),
            ]
        );

        $remote = $this->cashSession(
            $company,
            $otherBranch,
            $otherRegister,
            $viewer,
            ['session_number' => 'REMOTA']
        );

        [$foreign, $foreignBranch, $foreignRegister] =
            $this->context('Empresa Ajena');

        $foreignUser = $this->user(
            $foreign,
            $foreignBranch,
            []
        );

        $foreignSession = $this->cashSession(
            $foreign,
            $foreignBranch,
            $foreignRegister,
            $foreignUser,
            ['session_number' => 'AJENA']
        );

        $this->getAs(
            $viewer,
            $company,
            $branch,
            route('cash.history.index')
        )
            ->assertSee('LOCAL')
            ->assertDontSee('REMOTA')
            ->assertDontSee('AJENA');

        $this->getAs(
            $all,
            $company,
            $branch,
            route('cash.history.index')
        )
            ->assertSee('LOCAL')
            ->assertSee('REMOTA')
            ->assertDontSee('AJENA');

        $this->getAs(
            $viewer,
            $company,
            $branch,
            route('cash.history.show', $remote)
        )->assertNotFound();

        $this->getAs(
            $all,
            $company,
            $branch,
            route('cash.history.show', $local)
        )
            ->assertOk()
            ->assertDontSee('7.000')
            ->assertDontSee('Diferencia')
            ->assertDontSee('Último error');

        $this->getAs(
            $admin,
            $company,
            $branch,
            route('cash.history.show', $local)
        )
            ->assertOk()
            ->assertSee('7.000')
            ->assertSee('Diferencia');

        $this->getAs(
            $admin,
            $company,
            $branch,
            route('cash.history.show', $foreignSession)
        )->assertNotFound();
            }

    public function test_filters_timezone_difference_and_historical_inactive_entities(): void
    {
        CarbonImmutable::setTestNow('2026-08-14 18:00:00 UTC');

        [$company, $branch, $register] = $this->context('Empresa');

        $company->update([
            'timezone' => 'America/Costa_Rica',
        ]);

        $viewer = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.ver_todas', 'caja.administrar']
        );

        $historicalBranch = $this->branch(
            $company,
            'Histórica',
            false
        );

        $historicalRegister = $this->register(
            $company,
            $historicalBranch,
            'Caja histórica',
            false
        );

        $session = $this->cashSession(
            $company,
            $historicalBranch,
            $historicalRegister,
            $viewer,
            [
                'session_number' => 'FIL_TRO%_',
                'status' => 'closed',
                'open_guard' => null,
                'opened_at' => CarbonImmutable::parse(
                    '2026-08-14 05:30:00',
                    'UTC'
                ),
                'closing_submitted_at' => CarbonImmutable::parse(
                    '2026-08-14 05:45:00',
                    'UTC'
                ),
                'closed_at' => CarbonImmutable::parse(
                    '2026-08-14 05:50:00',
                    'UTC'
                ),
                'difference_amount' => 0,
            ]
        );

        $paymentMethod = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'card-historical',
            'name' => 'Tarjeta histórica',
            'type' => PaymentMethod::TYPE_CARD,
            'is_system' => false,
            'is_active' => false,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 1,
        ]);

        CashPaymentReconciliation::create([
            'payment_method_id' => $paymentMethod->id,
            'cash_session_id' => $session->id,
            'payment_method_code_snapshot' => 'card',
            'payment_method_name_snapshot' => 'Tarjeta',
            'payment_method_type_snapshot' => 'card',
            'expected_amount' => 100,
            'reported_amount' => 100,
            'difference_amount' => 0,
            'reconciled_by' => $viewer->id,
            'reconciled_at' => now(),
        ]);

        CashSessionMailNotification::create([
            'company_id' => $company->id,
            'cash_session_id' => $session->id,
            'notification_type' => 'closed',
            'recipients' => [],
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        $viewer->update([
            'is_active' => false,
        ]);

        $url = route('cash.history.index', [
            'date_from' => '2026-08-13',
            'date_to' => '2026-08-13',
            'branch_id' => $historicalBranch->id,
            'cash_register_id' => $historicalRegister->id,
            'cashier_id' => $viewer->id,
            'status' => 'closed',
            'difference' => 'without',
            'session_number' => '%_',
            'mail_status' => 'sent',
            'mail_type' => 'closed',
        ]);

        $this->getAs(
            $viewer,
            $company,
            $branch,
            $url
        )
            ->assertOk()
            ->assertSee('FIL_TRO%_')
            ->assertSee('Caja histórica')
            ->assertSee('Histórica');

        CarbonImmutable::setTestNow();
    }

    public function test_admin_detail_uses_snapshots_and_open_session_is_pending(): void
    {
        [$company, $branch, $register] = $this->context('Empresa');

        $admin = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.administrar']
        );

        $open = $this->cashSession(
            $company,
            $branch,
            $register,
            $admin,
            [
                'expected_cash' => 999999,
                'difference_amount' => 999999,
            ]
        );

        $this->getAs(
            $admin,
            $company,
            $branch,
            route('cash.history.show', $open)
        )
            ->assertOk()
            ->assertSee('Pendiente')
            ->assertDontSee('999.999');

        $closed = $this->cashSession(
            $company,
            $branch,
            $register,
            $admin,
            [
                'status' => 'closed',
                'open_guard' => null,
                'closing_submitted_at' => now(),
                'closed_at' => now(),
                'expected_cash' => 50000,
                'counted_cash' => 50000,
                'difference_amount' => 0,
                'closing_notes' => 'Nota histórica',
            ]
        );

        $denomination = CashDenomination::create([
            'company_id' => $company->id,
            'currency_code' => 'CRC',
            'type' => 'bill',
            'value' => 20000,
            'label' => 'Nombre actual',
            'sort_order' => 1,
            'is_active' => false,
        ]);

        CashCountDetail::create([
            'cash_session_id' => $closed->id,
            'cash_denomination_id' => $denomination->id,
            'count_type' => 'closing',
            'quantity' => 2,
            'denomination_value' => 12345,
            'total_amount' => 24690,
            'counted_by' => $admin->id,
            'counted_at' => now(),
        ]);

        $paymentMethod = PaymentMethod::create([
            'company_id' => $company->id,
            'code' => 'old',
            'name' => 'Método actual',
            'type' => PaymentMethod::TYPE_OTHER,
            'is_system' => false,
            'is_active' => false,
            'affects_cash' => false,
            'requires_reference' => false,
            'allows_change' => false,
            'sort_order' => 1,
        ]);

        CashPaymentReconciliation::create([
            'payment_method_id' => $paymentMethod->id,
            'cash_session_id' => $closed->id,
            'payment_method_code_snapshot' => 'old',
            'payment_method_name_snapshot' => 'Método snapshot',
            'payment_method_type_snapshot' => 'other',
            'expected_amount' => 100,
            'reported_amount' => 90,
            'difference_amount' => -10,
            'reconciled_by' => $admin->id,
            'reconciled_at' => now(),
        ]);

        $this->getAs(
            $admin,
            $company,
            $branch,
            route('cash.history.show', $closed)
        )
            ->assertOk()
            ->assertSee('12.345')
            ->assertSee('24.690')
            ->assertSee('Método snapshot')
            ->assertSee('Nota histórica')
            ->assertDontSee('USD');
                }

    public function test_retry_eligibility_audit_and_after_commit_dispatch(): void
    {
        Queue::fake();

        [$company, $branch, $register] = $this->context('Empresa');

        $admin = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.administrar']
        );

        $session = $this->cashSession(
            $company,
            $branch,
            $register,
            $admin
        );

        $failed = $this->notification(
            $company,
            $session,
            'failed',
            2,
            now()->subMinute()
        );

        $this->postAs(
            $admin,
            $company,
            $branch,
            route('cash.history.mail.retry', [$session, $failed])
        )->assertRedirect();

        $this->assertSame(
            'pending',
            $failed->fresh()->status
        );

        $this->assertDatabaseHas('cash_session_events', [
            'cash_session_id' => $session->id,
            'event_type' => CashSessionEvent::TYPE_MAIL_RETRY_REQUESTED,
            'user_id' => $admin->id,
        ]);

        $payload = CashSessionEvent::latest('id')
            ->firstOrFail()
            ->payload;

        $this->assertSame(
            [
                'notification_id',
                'notification_type',
                'previous_status',
                'attempts',
            ],
            array_keys($payload)
        );

        $this->assertArrayNotHasKey(
            'recipients',
            $payload
        );

        $this->assertArrayNotHasKey(
            'last_error',
            $payload
        );
    }

    public function test_recent_pending_terminal_states_and_max_attempts_cannot_retry(): void
    {
        Queue::fake();

        [$company, $branch, $register] = $this->context('Empresa');

        $admin = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.administrar']
        );

        $cases = [
            ['pending', 1, now()],
            ['sent', 1, now()->subHour()],
            ['skipped', 0, null],
            ['processing', 1, now()->subHour()],
            ['failed', 5, now()->subHour()],
        ];

        foreach ($cases as $index => [$status, $attempts, $available]) {
            $candidateRegister = $this->register(
                $company,
                $branch,
                'Caja retry '.$index
            );

            $candidateSession = $this->cashSession(
                $company,
                $branch,
                $candidateRegister,
                $admin
            );

            $notification = $this->notification(
                $company,
                $candidateSession,
                $status,
                $attempts,
                $available
            );

            $this->postAs(
                $admin,
                $company,
                $branch,
                route(
                    'cash.history.mail.retry',
                    [$candidateSession, $notification]
                )
            )->assertSessionHasErrors('notification');
        }

        Queue::assertNothingPushed();
    }

    public function test_stale_pending_is_claimed_once_and_delivered_recipients_are_preserved(): void
    {
        Queue::fake();

        [$company, $branch, $register] = $this->context('Empresa');

        $admin = $this->user(
            $company,
            $branch,
            ['caja.ver', 'caja.administrar']
        );

        $session = $this->cashSession(
                $company,
            $branch,
            $register,
            $admin
        );

        $pending = $this->notification(
            $company,
            $session,
            'pending',
            1,
            now()->subHour()
        );

        $pending->update([
    'delivered_recipients' => ['one@example.com'],
]);

CashSessionMailNotification::query()
    ->whereKey($pending->id)
    ->update([
        'updated_at' => now()->subMinutes(6),
    ]);

$pending->refresh();

        $this->postAs(
            $admin,
            $company,
            $branch,
            route('cash.history.mail.retry', [$session, $pending])
        )
    ->assertRedirect()
    ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cash_session_events', [
    'cash_session_id' => $session->id,
    'event_type' => CashSessionEvent::TYPE_MAIL_RETRY_REQUESTED,
    'user_id' => $admin->id,
]);

        $this->postAs(
            $admin,
            $company,
            $branch,
            route('cash.history.mail.retry', [$session, $pending])
        )->assertSessionHasErrors('notification');

        $this->assertSame(
            ['one@example.com'],
            $pending->fresh()->delivered_recipients
        );

        $this->assertSame(
            1,
            CashSessionEvent::where(
                'event_type',
                CashSessionEvent::TYPE_MAIL_RETRY_REQUESTED
            )->count()
        );
    }
        private function context(string $name): array
    {
        $company = Company::create([
            'trade_name' => $name,
            'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica',
            'is_active' => true,
        ]);

        $branch = $this->branch(
            $company,
            'Principal'
        );

        $register = $this->register(
            $company,
            $branch,
            'Caja principal'
        );

        return [$company, $branch, $register];
    }

    private function branch(
        Company $company,
        string $name,
        bool $active = true
    ): Branch {
        return Branch::create([
            'company_id' => $company->id,
            'name' => $name,
            'code' => 'B'.Str::random(6),
            'is_active' => $active,
        ]);
    }

    private function register(
        Company $company,
        Branch $branch,
        string $name,
        bool $active = true
    ): CashRegister {
        return CashRegister::create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'code' => 'C'.Str::random(6),
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function user(
        Company $company,
        Branch $branch,
        array $permissions
    ): User {
        $user = User::factory()->create();

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'R'.Str::random(6),
            'is_active' => true,
        ]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'label' => $name,
                    'module' => 'Caja',
                    'is_active' => true,
                ]
            );

            $role->permissions()->syncWithoutDetaching([
                $permission->id,
            ]);
        }

        $user->companies()->attach(
            $company->id,
            ['role_id' => $role->id]
        );

        $user->branches()->attach($branch->id);

        return $user;
    }

    private function cashSession(
        Company $company,
        Branch $branch,
        CashRegister $register,
        User $user,
        array $overrides = []
    ): CashSession {
        return CashSession::create(
            array_merge(
                [
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'cash_register_id' => $register->id,
                    'session_number' => 'CAJA-'.Str::random(8),
                    'opened_by' => $user->id,
                    'status' => 'open',
                    'open_guard' => 'OPEN',
                    'currency_code' => 'CRC',
                    'opening_amount' => 50000,
                    'blind_closing_snapshot' => true,
                    'accepts_usd_snapshot' => false,
                    'opened_at' => now(),
                ],
                $overrides
            )
        );
    }

    private function notification(
        Company $company,
        CashSession $session,
        string $status,
        int $attempts,
        $available,
        string $recipient = 'admin@example.com'
    ): CashSessionMailNotification {
        return CashSessionMailNotification::create([
            'company_id' => $company->id,
            'cash_session_id' => $session->id,
            'notification_type' => Str::contains($recipient, 'closed')
                ? 'closed'
                : 'opened',
            'recipients' => [$recipient],
            'delivered_recipients' => [],
            'status' => $status,
            'attempts' => $attempts,
            'available_at' => $available,
        ]);
    }

    private function getAs(
        User $user,
        Company $company,
        Branch $branch,
        string $url
    ) {
        return $this
            ->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->get($url);
    }

    private function postAs(
        User $user,
        Company $company,
        Branch $branch,
        string $url
    ) {
        return $this
            ->actingAs($user)
            ->withSession([
                'active_company_id' => $company->id,
                'active_branch_id' => $branch->id,
            ])
            ->post($url);
    }
}