<?php

namespace Tests\Feature;

use App\Models\CobrosLaborInvitation;
use App\Models\Company;
use App\Models\User;
use App\Services\Cobros\LaborInvitationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CobrosLaborInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $lender = User::factory()->create(['is_active' => true]);
        $company = Company::create([
            'trade_name' => 'Prestamista', 'currency' => 'CRC',
            'timezone' => 'America/Costa_Rica', 'is_active' => true,
            'owner_user_id' => $lender->id,
        ]);

        return [$company, $lender, app(LaborInvitationService::class)];
    }

    private function rejected(callable $action): void
    {
        try {
            $action();
            $this->fail('La operación debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_secure_hash_one_use_and_no_provisioning(): void
    {
        [$company, $lender, $service] = $this->context();
        $tables = ['users', 'company_user', 'cobros_routes', 'cobros_route_user',
            'cobros_collector_licenses', 'cobros_route_licenses'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
        $issued = $service->issue($company, $lender, ' Worker@Example.com ', 30);
        $record = $issued['invitation'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued['plain']);
        $this->assertSame(hash('sha256', $issued['plain']), $record->fresh()->token_hash);
        $this->assertSame('worker@example.com', $record->recipient_email);
        $this->assertArrayNotHasKey('token_hash', $record->toArray());
        $this->assertStringNotContainsString($issued['plain'], json_encode(DB::table('cobros_labor_invitations')->first()));
        $validated = $service->validate($company, $lender, 'WORKER@example.com', $issued['plain']);
        $this->assertNotNull($validated->used_at);
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', $issued['plain']));
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_configurable_expiry_rejects_exact_deadline_and_allows_new_token(): void
    {
        [$company, $lender, $service] = $this->context();
        $this->travelTo(now()->startOfSecond());
        $issued = $service->issue($company, $lender, 'worker@example.com', 2);
        $this->assertTrue($issued['invitation']->expires_at->equalTo(now()->addMinutes(2)));
        $this->travel(2)->minutes();
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', $issued['plain']));
        $next = $service->issue($company, $lender, 'worker@example.com');
        $this->assertNotSame($issued['plain'], $next['plain']);
        $this->assertNotNull($service->validate($company, $lender, 'worker@example.com', $next['plain'])->used_at);
    }

    public function test_revocation_is_idempotent_and_old_token_stays_invalid_after_reissue(): void
    {
        [$company, $lender, $service] = $this->context();
        $issued = $service->issue($company, $lender, 'worker@example.com');
        $service->revoke($company, $lender, $issued['invitation']->id);
        $revoked = $issued['invitation']->fresh()->revoked_at;
        $this->travel(1)->minutes();
        $service->revoke($company, $lender, $issued['invitation']->id);
        $this->assertNotNull($revoked);
        $this->assertTrue($revoked->equalTo($issued['invitation']->fresh()->revoked_at));
        $service->issue($company, $lender, 'worker@example.com');
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', $issued['plain']));
    }

    public function test_duplicates_invalid_recipient_and_invalid_ttl_are_rejected(): void
    {
        [$company, $lender, $service] = $this->context();
        $service->issue($company, $lender, 'worker@example.com');
        $this->rejected(fn () => $service->issue($company, $lender, ' WORKER@example.com '));
        $this->rejected(fn () => $service->issue($company, $lender, 'invalid'));
        $this->rejected(fn () => $service->issue($company, $lender, 'new@example.com', 0));
        $this->assertDatabaseCount('cobros_labor_invitations', 1);
    }

    public function test_company_lender_recipient_and_token_are_isolated(): void
    {
        [$company, $lender, $service] = $this->context();
        [$otherCompany, $otherLender] = $this->context();
        $issued = $service->issue($company, $lender, 'worker@example.com');
        $service->issue($otherCompany, $otherLender, 'worker@example.com');
        $this->rejected(fn () => $service->issue($company, $otherLender, 'new@example.com'));
        $this->rejected(fn () => $service->validate($otherCompany, $otherLender, 'worker@example.com', $issued['plain']));
        $this->rejected(fn () => $service->validate($company, $otherLender, 'worker@example.com', $issued['plain']));
        $this->rejected(fn () => $service->validate($company, $lender, 'other@example.com', $issued['plain']));
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', 'tampered'));
        try {
            $service->revoke($otherCompany, $otherLender, $issued['invitation']->id);
            $this->fail('Revocación cruzada permitida.');
        } catch (ModelNotFoundException) {
            $this->assertNull($issued['invitation']->fresh()->revoked_at);
        }
        $this->assertNull($issued['invitation']->fresh()->used_at);
        $this->assertNotNull($service->validate($company, $lender, 'worker@example.com', $issued['plain'])->used_at);
    }

    public function test_lender_is_checked_against_database_not_mutable_models(): void
    {
        [$company, $lender, $service] = $this->context();
        [, $outsider] = $this->context();
        $company->owner_user_id = $outsider->id;
        $this->rejected(fn () => $service->issue($company, $outsider, 'worker@example.com'));
        $lender->update(['is_active' => false]);
        $this->rejected(fn () => $service->issue($company, $lender, 'worker@example.com'));
        $this->assertSame(0, CobrosLaborInvitation::count());
    }

    public function test_one_existing_account_can_receive_independent_invitations_from_two_lenders(): void
    {
        [$company, $lender, $service] = $this->context();
        [$otherCompany, $otherLender] = $this->context();
        $collector = User::factory()->create(['email' => 'collector@example.com']);
        $collector->companies()->attach([$company->id, $otherCompany->id]);
        foreach ([[$company, $lender], [$otherCompany, $otherLender]] as [$tenant, $owner]) {
            $route = \App\Models\CobrosRoute::create(['company_id' => $tenant->id, 'name' => 'Cartera']);
            $route->users()->attach($collector->id, ['active' => true]);
            $customer = \App\Models\Customer::create([
                'company_id' => $tenant->id, 'name' => 'Cliente',
                'customer_type' => 'individual', 'is_active' => true,
            ]);
            \App\Models\CobrosAssignment::create([
                'company_id' => $tenant->id, 'cobros_route_id' => $route->id,
                'customer_id' => $customer->id, 'collector_user_id' => $collector->id,
                'assigned_by' => $owner->id, 'assigned_at' => now(),
            ]);
        }
        $tables = ['users', 'company_user', 'cobros_assignments', 'cobros_routes',
            'cobros_route_user', 'cobros_collector_licenses', 'cobros_route_licenses'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->get()->toJson(),
        ])->all();

        $first = $service->issue($company, $lender, $collector->email);
        $second = $service->issue($otherCompany, $otherLender, $collector->email);
        $this->assertNotSame($first['plain'], $second['plain']);
        $this->assertNotSame($first['invitation']->id, $second['invitation']->id);
        $service->revoke($company, $lender, $first['invitation']->id);
        $this->assertNull($second['invitation']->fresh()->revoked_at);
        $validated = $service->validate($otherCompany, $otherLender, $collector->email, $second['plain']);
        $this->assertSame($second['invitation']->id, $validated->id);
        $this->assertSame($otherCompany->id, $validated->company_id);
        $this->assertSame($otherLender->id, $validated->lender_user_id);
        $this->rejected(fn () => $service->validate($company, $lender, $collector->email, $first['plain']));
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame(1, User::where('email', $collector->email)->count());
        $this->assertSame(2, $collector->companies()->count());
    }

    public function test_lender_change_and_company_deactivation_invalidate_outstanding_invitation(): void
    {
        [$company, $lender, $service] = $this->context();
        [, $replacement] = $this->context();
        $issued = $service->issue($company, $lender, 'worker@example.com');
        $company->update(['is_active' => false]);
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', $issued['plain']));
        $company->update(['is_active' => true, 'owner_user_id' => $replacement->id]);
        $this->rejected(fn () => $service->validate($company, $lender, 'worker@example.com', $issued['plain']));
        $this->rejected(fn () => $service->validate($company, $replacement, 'worker@example.com', $issued['plain']));
        $this->assertNull($issued['invitation']->fresh()->used_at);
    }
}
