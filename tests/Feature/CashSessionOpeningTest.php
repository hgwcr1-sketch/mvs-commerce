<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashDenomination;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashSessionEvent;
use App\Models\Company;
use App\Models\CompanyCashSetting;
use App\Models\CompanySequence;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\CashDenominationProvisioner;
use App\Services\CompanyCashSettingsProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashSessionOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_counts_are_empty_and_server_calculates_and_saves_each_denomination(): void
    {
        [$c,$b] = $this->context();
        $u = $this->user($c, $b, ['caja.abrir']);
        $r = $this->register($c, $b);
        $response = $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('cash.open.create'))->assertOk();
        $response->assertSee('Billetes')->assertSee('Monedas')->assertDontSee('name="opening_amount"', false);
        $this->assertSame(11, substr_count($response->getContent(), 'name="denominations['));
        $payload = $this->payload($r, ['opening_amount' => 999999]);
        $denominations = CashDenomination::forCompany($c->id)->active()->get();
        foreach ($denominations as $d) {
            $payload['denominations'][$d->id] = match ((int) $d->value) {
                20000 => 2,1000 => 3,25 => 4,default => 0
            };
        }
        $this->post(route('cash.open.store'), $payload)->assertSessionHasNoErrors();
        $session = CashSession::firstOrFail();
        $this->assertSame('43100.0000', $session->opening_amount);
        $this->assertSame(11, $session->countDetails()->where('count_type', 'opening')->count());
        foreach ($session->countDetails as $detail) {
            $this->assertSame($u->id, $detail->counted_by);
            $this->assertSame($payload['denominations'][$detail->cash_denomination_id], $detail->quantity);
            $this->assertSame($session->opened_at->toDateTimeString(), $detail->counted_at->toDateTimeString());
        }
        $this->assertSame($b->id, $session->branch_id);
        $this->assertSame($r->id, $session->cash_register_id);
    }

    public function test_missing_foreign_negative_and_incomplete_opening_counts_are_rejected(): void
    {
        [$c,$b] = $this->context();
        $u = $this->user($c, $b, ['caja.abrir']);
        $r = $this->register($c, $b);
        $this->actingAs($u)->withSession($this->ctx($c, $b));
        $payload = $this->payload($r);
        unset($payload['denominations']);
        $this->post(route('cash.open.store'), $payload)->assertSessionHasErrors('denominations');
        $payload = $this->payload($r);
        array_pop($payload['denominations']);
        $this->post(route('cash.open.store'), $payload)->assertSessionHasErrors('denominations');
        $payload = $this->payload($r);
        $payload['denominations'][999999] = 1;
        $this->post(route('cash.open.store'), $payload)->assertSessionHasErrors('denominations');
        $payload = $this->payload($r);
        $id = array_key_first($payload['denominations']);
        $payload['denominations'][$id] = -1;
        $this->post(route('cash.open.store'), $payload)->assertSessionHasErrors('denominations.'.$id);
        $this->assertDatabaseCount('cash_sessions', 0);
        $this->assertDatabaseCount('cash_count_details', 0);
    }

    public function test_authorized_user_opens_active_register_with_sequence_fund_snapshots_and_event(): void
    {
        [$c,$b] = $this->context();
        $s = $this->settings($c);
        $s->update(['difference_tolerance' => 25, 'blind_closing' => false]);
        $u = $this->user($c, $b, ['caja.abrir']);
        $r = $this->register($c, $b);
        $this->open($u, $c, $b, $r)->assertRedirect(route('cash.index'))->assertSessionHas('success');
        $cash = CashSession::firstOrFail();
        $this->assertSame('CAJA-00000001', $cash->session_number);
        $this->assertSame('1000.0000', $cash->opening_amount);
        $this->assertSame('25.0000', $cash->tolerance_snapshot);
        $this->assertFalse($cash->blind_closing_snapshot);
        $this->assertSame($u->id, $cash->opened_by);
        $this->assertSame(1, $cash->events()->where('event_type', 'opened')->count());
    }

    public function test_permissions_register_context_and_currency_are_enforced(): void
    {
        [$c,$b] = $this->context();
        [, $other] = $this->context('Otra');
        $none = $this->user($c, $b, []);
        $u = $this->user($c, $b, ['caja.abrir']);
        $own = $this->register($c, $b);
        $foreign = $this->register($other->company, $other);
        $inactive = $this->register($c, $b, ['code' => 'inactive', 'is_active' => false]);
        $this->actingAs($none)->withSession($this->ctx($c, $b))->post(route('cash.open.store'), $this->payload($own))->assertForbidden();
        $this->open($u, $c, $b, $foreign)->assertSessionHasErrors('cash_register_id');
        $this->open($u, $c, $b, $inactive)->assertSessionHasErrors('cash_register_id');
        $c->update(['currency' => 'USD']);
        $this->open($u, $c, $b, $own)->assertSessionHasErrors('currency');
        $this->assertSame(0, CashSession::count());
    }

    public function test_sequences_are_independent_per_company(): void
    {
        [$c1,$b1] = $this->context('Uno');
        [$c2,$b2] = $this->context('Dos');
        $this->open($this->user($c1, $b1, ['caja.abrir']), $c1, $b1, $this->register($c1, $b1));
        $this->open($this->user($c2, $b2, ['caja.abrir']), $c2, $b2, $this->register($c2, $b2));
        $this->assertEqualsCanonicalizing(['CAJA-00000001', 'CAJA-00000001'], CashSession::pluck('session_number')->all());
        $this->assertSame(2, CompanySequence::where('name', 'cash_session')->count());
    }

    public function test_register_guard_and_individual_user_rules_are_enforced(): void
    {
        [$c,$b] = $this->context();
        $u1 = $this->user($c, $b, ['caja.abrir']);
        $u2 = $this->user($c, $b, ['caja.abrir']);
        $r1 = $this->register($c, $b);
        $r2 = $this->register($c, $b, ['code' => 'two']);
        $this->open($u1, $c, $b, $r1);
        $this->open($u2, $c, $b, $r1)->assertSessionHasErrors('cash_register_id');
        $this->open($u1, $c, $b, $r2)->assertSessionHasErrors('cash_register_id');
        $this->open($u2, $c, $b, $r2)->assertSessionHasNoErrors();
        $this->assertSame(2, CashSession::count());
        $this->expectException(QueryException::class);
        $this->raw($c, $b, $r1, $u2, 'DUP');
    }

    public function test_same_user_can_open_in_different_branch(): void
    {
        [$c,$b] = $this->context();
        $other = $this->branch($c, 'Otra');
        $u = $this->user($c, $b, ['caja.abrir']);
        $u->branches()->attach($other->id);
        $this->open($u, $c, $b, $this->register($c, $b));
        $this->open($u, $c, $other, $this->register($c, $other, ['code' => 'other']))->assertSessionHasNoErrors();
        $this->assertSame(2, CashSession::count());
    }

    public function test_usd_disabled_is_sanitized_and_enabled_rate_is_validated_and_audited(): void
    {
        [$c,$b] = $this->context();
        $u = $this->user($c, $b, ['caja.abrir']);
        $r = $this->register($c, $b);
        $this->open($u, $c, $b, $r, ['usd_exchange_rate' => 999, 'opening_amount_usd' => 50]);
        $cash = CashSession::first();
        $this->assertNull($cash->usd_exchange_rate);
        $this->assertSame('0.0000', $cash->opening_amount_usd);
        [$c2,$b2] = $this->context('USD');
        $this->settings($c2)->update(['accepts_usd' => true, 'usd_exchange_rate_min' => 500, 'usd_exchange_rate_max' => 550]);
        $u2 = $this->user($c2, $b2, ['caja.abrir']);
        $r2 = $this->register($c2, $b2);
        $this->open($u2, $c2, $b2, $r2, ['usd_exchange_rate' => null])->assertSessionHasErrors('usd_exchange_rate');
        $this->open($u2, $c2, $b2, $r2, ['usd_exchange_rate' => 499])->assertSessionHasErrors('usd_exchange_rate');
        $this->open($u2, $c2, $b2, $r2, ['usd_exchange_rate' => 525, 'opening_amount_usd' => 20.5])->assertSessionHasNoErrors();
        $usd = CashSession::where('company_id', $c2->id)->first();
        $this->assertSame('525.0000', $usd->usd_exchange_rate);
        $this->assertSame($u2->id, $usd->exchange_rate_entered_by);
    }

    public function test_event_failure_rolls_back_session_and_sequence(): void
    {
        [$c,$b] = $this->context();
        $u = $this->user($c, $b, ['caja.abrir']);
        $r = $this->register($c, $b);
        CashSessionEvent::creating(fn () => throw new \RuntimeException('event failure'));
        try {
            app(CashSessionService::class)->open($this->payload($r), $u, $c->id, $b->id);
        } catch (\RuntimeException $e) {
            $this->assertSame('event failure', $e->getMessage());
        }
        $this->assertSame(0, CashSession::count());
        $this->assertDatabaseMissing('company_sequences', ['company_id' => $c->id, 'name' => 'cash_session']);
    }

    public function test_index_isolates_company_branch_and_history_permissions(): void
    {
        [$c,$b] = $this->context();
        $other = $this->branch($c, 'Otra');
        $mine = $this->user($c, $b, ['caja.abrir']);
        $alien = $this->user($c, $b, ['caja.abrir']);
        $this->raw($c, $b, $this->register($c, $b), $mine, 'MIA', CashSession::STATUS_CLOSED);
        $this->raw($c, $b, $this->register($c, $b, ['code' => 'alien']), $alien, 'AJENA', CashSession::STATUS_CLOSED);
        $this->raw($c, $other, $this->register($c, $other, ['code' => 'other']), $mine, 'OTRA', CashSession::STATUS_CLOSED);
        $this->actingAs($mine)->withSession($this->ctx($c, $b))->get(route('cash.index'))->assertSee('MIA')->assertDontSee('AJENA')->assertDontSee('OTRA');
        $viewer = $this->user($c, $b, ['caja.ver', 'caja.ver_todas']);
        $this->actingAs($viewer)->withSession($this->ctx($c, $b))->get(route('cash.index'))->assertSee('MIA')->assertSee('AJENA')->assertDontSee('OTRA');
    }

    public function test_pos_requires_opening_and_cash_open_view_remains_available(): void
    {
        [$c,$b] = $this->context();
        $u = $this->user($c, $b, ['pos.acceder', 'ventas.crear', 'caja.abrir']);
        $r = $this->register($c, $b);
        $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('pos.index'))->assertRedirect(route('cash.open.create'));
        $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('cash.open.create'))->assertSee('Volver')->assertSee('@submit="processing=true"', false)->assertSee(':disabled="processing"', false);
        $this->open($u, $c, $b, $r);
        $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('pos.index'))->assertSee('Caja abierta: CAJA-00000001');
    }

    public function test_open_session_and_history_use_company_timezone_without_changing_stored_utc_value(): void
    {
        [$c,$b] = $this->context();
        $c->update(['timezone' => 'America/Costa_Rica']);
        $u = $this->user($c, $b, ['caja.abrir']);
        $openedAt = CarbonImmutable::parse('2026-08-13 23:29:12', 'UTC');
        $session = $this->raw($c, $b, $this->register($c, $b), $u, 'CAJA-00000001', CashSession::STATUS_OPEN, $openedAt);
        $storedBefore = $session->getRawOriginal('opened_at');

        $response = $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('cash.index'))->assertOk();

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), '13/08/2026 17:29'));
        $this->assertSame($storedBefore, $session->fresh()->getRawOriginal('opened_at'));
        $this->assertSame('2026-08-13 23:29:12', $session->fresh()->opened_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_each_company_sees_cash_dates_in_its_own_timezone(): void
    {
        [$c,$b] = $this->context();
        $c->update(['timezone' => 'America/New_York']);
        $u = $this->user($c, $b, ['caja.abrir']);
        $this->raw($c, $b, $this->register($c, $b), $u, 'NY-TIME', CashSession::STATUS_CLOSED, CarbonImmutable::parse('2026-08-13 23:29:12', 'UTC'));

        $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('cash.index'))
            ->assertOk()->assertSee('13/08/2026 19:29')->assertDontSee('13/08/2026 17:29');
    }

    public function test_empty_or_invalid_company_timezone_uses_application_fallback(): void
    {
        foreach (['', 'Invalid/Timezone'] as $index => $timezone) {
            [$c,$b] = $this->context('Fallback'.$index);
            $c->update(['timezone' => $timezone]);
            $u = $this->user($c, $b, ['caja.abrir']);
            $this->raw($c, $b, $this->register($c, $b), $u, 'FALLBACK-'.$index, CashSession::STATUS_CLOSED, CarbonImmutable::parse('2026-08-13 23:29:12', 'UTC'));
            $this->actingAs($u)->withSession($this->ctx($c, $b))->get(route('cash.index'))->assertOk()->assertSee('13/08/2026 23:29');
        }
    }

    private function context(string $n = 'Empresa'): array
    {
        $c = Company::create(['trade_name' => $n, 'currency' => 'CRC', 'is_active' => true]);
        $b = $this->branch($c, 'Principal');
        $this->settings($c);

        return [$c, $b];
    }

    private function branch(Company $c, string $n): Branch
    {
        return Branch::create(['company_id' => $c->id, 'name' => $n, 'code' => strtoupper(substr($n, 0, 3)).uniqid(), 'is_active' => true]);
    }

    private function settings(Company $c): CompanyCashSetting
    {
        return app(CompanyCashSettingsProvisioner::class)->provision($c);
    }

    private function user(Company $c, Branch $b, array $names): User
    {
        $u = User::factory()->create();
        $role = Role::create(['company_id' => $c->id, 'name' => 'R'.uniqid(), 'is_active' => true]);
        foreach ($names as $n) {
            $p = Permission::firstOrCreate(['name' => $n], ['label' => $n, 'module' => 'Caja', 'is_active' => true]);
            $role->permissions()->attach($p);
        }$u->companies()->attach($c->id, ['role_id' => $role->id]);
        $u->branches()->attach($b->id);

        return $u;
    }

    private function register(Company $c, Branch $b, array $a = []): CashRegister
    {
        return CashRegister::create(array_merge(['company_id' => $c->id, 'branch_id' => $b->id, 'code' => 'cash'.uniqid(), 'name' => 'Caja', 'is_active' => true, 'is_default' => true], $a));
    }

    private function payload(CashRegister $r, array $a = []): array
    {
        app(CashDenominationProvisioner::class)->provision($r->company);
        $counts = CashDenomination::forCompany($r->company_id)->forCurrency('CRC')->active()->get()->mapWithKeys(fn ($d) => [$d->id => (int) $d->value === 1000 ? 1 : 0])->all();

        return array_merge(['cash_register_id' => $r->id, 'opening_amount' => 1000, 'denominations' => $counts, 'opening_amount_usd' => 0, 'confirmation' => '1'], $a);
    }

    private function open(User $u, Company $c, Branch $b, CashRegister $r, array $a = [])
    {
        return $this->actingAs($u)->withSession($this->ctx($c,$b))->post(route('cash.open.store'),$this->payload($r,$a));
    }

    private function ctx(Company $c,Branch $b): array
    {
        return ['active_company_id' => $c->id, 'active_branch_id' => $b->id];
    }

    private function raw(Company $c,Branch $b,CashRegister $r,User $u,string $number,string $status = CashSession::STATUS_OPEN, ?CarbonImmutable $openedAt = null): CashSession
    {
        $openedAt ??= CarbonImmutable::now();

        return CashSession::create(['company_id' => $c->id, 'branch_id' => $b->id, 'cash_register_id' => $r->id, 'session_number' => $number, 'opened_by' => $u->id, 'status' => $status, 'open_guard' => $status === CashSession::STATUS_OPEN ? CashSession::OPEN_GUARD : null, 'opening_amount' => 0, 'opened_at' => $openedAt, 'closed_at' => $status === CashSession::STATUS_CLOSED ? $openedAt : null]);
    }
}
