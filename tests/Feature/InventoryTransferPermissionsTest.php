<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryTransfer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\Notifications\AlertDispatcher;
use App\Services\Notifications\AlertRecipientResolver;
use App\Services\Notifications\AlertTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Permisos "Ver transferencias" / "Recibir transferencias" y campana de
 * pendientes para la sucursal destino (P23/P24 — revisión de traslados).
 */
class InventoryTransferPermissionsTest extends TestCase
{
    use RefreshDatabase;

    // ── Permisos ver / recibir ──────────────────────────────────────────

    public function test_destination_user_with_receive_permission_can_view_review_and_receive(): void
    {
        Notification::fake();
        [$company, $from, $to, $origin, $product] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '10.0000');
        $receiver = $this->userWith($company, $to, [
            'inventario.transferencias.ver',
            'inventario.transferencias.recibir',
            'notificaciones.traslados',
            'notificaciones.ver',
        ], [$to]);

        // Origen crea, prepara y despacha el traslado.
        $this->actingAs($origin)->withSession($this->activeSession($company, $from))->post(route('transferencias.store'), [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'products' => [['product_id' => $product->id, 'quantity' => '3']],
        ])->assertRedirect(route('transferencias.index'));
        $transfer = InventoryTransfer::firstOrFail();
        foreach (['prepare', 'dispatch'] as $action) {
            $this->post(route('transferencias.'.$action, $transfer))->assertRedirect(route('transferencias.index'));
        }

        // Destino: puede VER listado y detalle sin inventario.transferir.
        $this->withoutVite();
        $this->actingAs($receiver)->withSession($this->activeSession($company, $to))
            ->get(route('transferencias.index'))->assertOk();
        $this->actingAs($receiver)->withSession($this->activeSession($company, $to))
            ->get(route('transferencias.show', $transfer))->assertOk();

        // Destino: inicia revisión y recibe con el permiso recibir.
        $this->actingAs($receiver)->withSession($this->activeSession($company, $to))
            ->post(route('transferencias.review', $transfer))->assertRedirect(route('transferencias.index'));
        $this->actingAs($receiver)->withSession($this->activeSession($company, $to))
            ->post(route('transferencias.receive', $transfer), ['received_quantity' => '3'])
            ->assertRedirect(route('transferencias.index'));

        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame('7.0000', $this->stockValue($from, $product));
        $this->assertSame('3.0000', $this->stockValue($to, $product));
    }

    public function test_user_without_transfer_permissions_is_denied(): void
    {
        [$company, $from, $to, , $product] = $this->context(['inventario.ver']);
        $outsider = $this->userWith($company, $from, ['inventario.ver'], [$from, $to]);
        $transfer = $this->createTransfer($company, $from, $to, $outsider, $product, 'in_review', '1');

        $session = $this->activeSession($company, $from);
        $this->actingAs($outsider)->withSession($session)->get(route('transferencias.index'))->assertForbidden();
        $this->actingAs($outsider)->withSession($session)->get(route('transferencias.show', $transfer))->assertForbidden();
        $this->actingAs($outsider)->withSession($session)->get(route('transferencias.create'))->assertForbidden();
        $this->actingAs($outsider)->withSession($session)
            ->post(route('transferencias.receive', $transfer), ['received_quantity' => '1'])->assertForbidden();
        $this->assertSame('in_review', $transfer->fresh()->status);
    }

    public function test_view_only_user_can_view_but_not_create_dispatch_or_receive(): void
    {
        [$company, $from, $to, , $product] = $this->context(['inventario.transferir']);
        $viewer = $this->userWith($company, $to, ['inventario.transferencias.ver'], [$from, $to]);
        $transfer = $this->createTransfer($company, $from, $to, $viewer, $product, 'in_review', '1');

        $session = $this->activeSession($company, $to);
        $this->withoutVite();
        $this->actingAs($viewer)->withSession($session)->get(route('transferencias.index'))->assertOk();
        $this->actingAs($viewer)->withSession($session)->get(route('transferencias.show', $transfer))->assertOk();

        // Sin inventario.transferir: no crea, no despacha.
        $this->actingAs($viewer)->withSession($session)->get(route('transferencias.create'))->assertForbidden();
        $this->actingAs($viewer)->withSession($session)
            ->post(route('transferencias.dispatch', $transfer))->assertForbidden();
        // Sin inventario.transferencias.recibir: no recibe.
        $this->actingAs($viewer)->withSession($session)
            ->post(route('transferencias.receive', $transfer), ['received_quantity' => '1'])->assertForbidden();
        $this->assertSame('in_review', $transfer->fresh()->status);
    }

    // ── Aislamiento company / branch ────────────────────────────────────

    public function test_transfer_show_is_isolated_by_company_and_branch(): void
    {
        [$companyA, $fromA, $toA, , $product] = $this->context(['inventario.transferir']);
        $transfer = $this->createTransfer($companyA, $fromA, $toA, $this->userWith($companyA, $fromA, ['inventario.transferir'], [$fromA]), $product, 'pending', '1');

        // Otra empresa con permiso de ver: 404 (no existe para ella).
        [$companyB, $fromB] = $this->context(['inventario.transferencias.ver']);
        $foreign = $this->userWith($companyB, $fromB, ['inventario.transferencias.ver'], [$fromB]);
        $this->actingAs($foreign)->withSession($this->activeSession($companyB, $fromB))
            ->get(route('transferencias.show', $transfer))->assertStatus(404);

        // Misma empresa pero sucursal no asignada y sin ver_otras: 403.
        $suffix = Str::lower(Str::random(8));
        $otherBranch = Branch::create(['company_id' => $companyA->id, 'name' => 'Tercera '.$suffix, 'code' => 'T'.$suffix, 'is_active' => true]);
        $unassigned = $this->userWith($companyA, $otherBranch, ['inventario.transferencias.ver'], [$otherBranch]);
        $this->actingAs($unassigned)->withSession($this->activeSession($companyA, $otherBranch))
            ->get(route('transferencias.show', $transfer))->assertForbidden();
    }

    // ── Campana de pendientes: sucursal destino ─────────────────────────

    public function test_dispatch_alert_reaches_destination_branch_only(): void
    {
        Notification::fake();
        [$company, $from, $to, $origin, $product] = $this->context(['inventario.transferir', 'notificaciones.inventario', 'notificaciones.ver'], false);
        $this->stock($from, $product, '10.0000');

        $suffix = Str::lower(Str::random(8));
        $otherBranch = Branch::create(['company_id' => $company->id, 'name' => 'Otra '.$suffix, 'code' => 'X'.$suffix, 'is_active' => true]);
        $receiver = $this->userWith($company, $to, ['inventario.transferencias.recibir', 'notificaciones.traslados', 'notificaciones.ver'], [$to]);
        $sameCompanyOther = $this->userWith($company, $otherBranch, ['inventario.transferencias.recibir', 'notificaciones.traslados', 'notificaciones.ver'], [$otherBranch]);

        [$otherCompany, $otherFrom] = $this->context(['inventario.transferencias.recibir', 'notificaciones.traslados', 'notificaciones.ver']);
        $foreign = $this->userWith($otherCompany, $otherFrom, ['inventario.transferencias.recibir', 'notificaciones.traslados', 'notificaciones.ver'], [$otherFrom]);

        $this->actingAs($origin)->withSession($this->activeSession($company, $from))->post(route('transferencias.store'), [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'products' => [['product_id' => $product->id, 'quantity' => '3']],
        ])->assertRedirect(route('transferencias.index'));
        $transfer = InventoryTransfer::firstOrFail();
        foreach (['prepare', 'dispatch'] as $action) {
            $this->post(route('transferencias.'.$action, $transfer))->assertRedirect(route('transferencias.index'));
        }

        $alert = Alert::query()->where('type', AlertTypeRegistry::TYPE_TRANSFER_RECEIPT)
            ->where('entity_type', InventoryTransfer::class)->where('entity_id', $transfer->id)->firstOrFail();
        $this->assertSame($to->id, (int) $alert->branch_id);

        $ids = app(AlertRecipientResolver::class)->resolve($alert, $company)->pluck('id');
        $this->assertTrue($ids->contains($receiver->id), 'El destino con permiso de recibir debe ver la alerta');
        $this->assertFalse($ids->contains($origin->id), 'El origen (otra sucursal) no debe ver la alerta');
        $this->assertFalse($ids->contains($sameCompanyOther->id), 'Otra sucursal de la misma empresa no debe ver la alerta');
        $this->assertFalse($ids->contains($foreign->id), 'Otra empresa no debe ver la alerta');

        Notification::assertSentTo($receiver, \App\Notifications\MvsAlertNotification::class);
        Notification::assertNotSentTo($origin, \App\Notifications\MvsAlertNotification::class);
    }

    public function test_pending_alert_shows_in_destination_bell_and_disappears_after_receive(): void
    {
        Notification::fake();
        [$company, $from, $to, $origin, $product] = $this->context(['inventario.transferir', 'notificaciones.inventario'], false);
        $this->stock($from, $product, '10.0000');
        $receiver = $this->userWith($company, $to, [
            'inventario.transferencias.ver',
            'inventario.transferencias.recibir',
            'notificaciones.traslados',
            'notificaciones.ver',
        ], [$to]);

        $this->actingAs($origin)->withSession($this->activeSession($company, $from))->post(route('transferencias.store'), [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'products' => [['product_id' => $product->id, 'quantity' => '3']],
        ])->assertRedirect(route('transferencias.index'));
        $transfer = InventoryTransfer::firstOrFail();
        foreach (['prepare', 'dispatch'] as $action) {
            $this->post(route('transferencias.'.$action, $transfer))->assertRedirect(route('transferencias.index'));
        }

        $session = $this->activeSession($company, $to);

        // Campana: el destino ve la alerta pendiente.
        $this->actingAs($receiver)->withSession($session)
            ->getJson(route('notifications.recent'))->assertJsonCount(1, 'alerts');
        $this->withoutVite();
        $this->actingAs($receiver)->withSession($session)
            ->get(route('notifications.index'))->assertOk()
            ->assertSee('pendiente de recepción');

        // El destino revisa y recibe: la alerta se resuelve y desaparece.
        $this->actingAs($receiver)->withSession($session)
            ->post(route('transferencias.review', $transfer))->assertRedirect(route('transferencias.index'));
        $this->actingAs($receiver)->withSession($session)
            ->post(route('transferencias.receive', $transfer), ['received_quantity' => '3'])
            ->assertRedirect(route('transferencias.index'));

        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(Alert::STATUS_RESOLVED, Alert::query()
            ->where('entity_type', InventoryTransfer::class)->where('entity_id', $transfer->id)->value('status'));

        $this->actingAs($receiver)->withSession($session)
            ->getJson(route('notifications.recent'))->assertJsonCount(0, 'alerts');
        $this->actingAs($receiver)->withSession($session)
            ->getJson(route('notifications.unread-count'))->assertJson(['count' => 0]);
    }

    // ── Stock y transferencias completadas intactos ─────────────────────

    public function test_resolving_alerts_does_not_alter_completed_transfer_stock_or_movements(): void
    {
        [$company, $from, $to, $user, $product] = $this->context(['inventario.transferir']);
        $this->stock($from, $product, '10.0000');

        $done = $this->createTransfer($company, $from, $to, $user, $product, 'received', '2');
        $pending = $this->createTransfer($company, $from, $to, $user, $product, 'in_review', '4');
        $alert = Alert::query()->create([
            'company_id' => $company->id, 'branch_id' => $to->id,
            'type' => AlertTypeRegistry::TYPE_TRANSFER_RECEIPT,
            'severity' => Alert::SEVERITY_ATTENTION, 'status' => Alert::STATUS_NEW,
            'occurred_at' => now(), 'entity_type' => InventoryTransfer::class, 'entity_id' => $pending->id,
        ]);

        $doneBefore = $done->fresh()->getAttributes();
        $stockBefore = $this->stockValue($from, $product).'|'.$this->stockValue($to, $product);
        $movementsBefore = DB::table('inventory_movements')->count();

        app(AlertDispatcher::class)->resolveForEntity($company->id, InventoryTransfer::class, $pending->id, $user->id);

        $this->assertSame(Alert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertSame($doneBefore, $done->fresh()->getAttributes());
        $this->assertSame($stockBefore, $this->stockValue($from, $product).'|'.$this->stockValue($to, $product));
        $this->assertSame($movementsBefore, DB::table('inventory_movements')->count());
        $this->assertSame('received', $done->fresh()->status);
        $this->assertSame('in_review', $pending->fresh()->status);
    }

    private function context(array $permissions, bool $assignDestination = true): array
    {
        $suffix = Str::lower(Str::random(8));
        $company = Company::create(['trade_name' => 'Perm '.$suffix, 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica', 'is_active' => true]);
        $from = Branch::create(['company_id' => $company->id, 'name' => 'Origen '.$suffix, 'code' => 'O'.$suffix, 'is_active' => true]);
        $to = Branch::create(['company_id' => $company->id, 'name' => 'Destino '.$suffix, 'code' => 'D'.$suffix, 'is_active' => true]);
        $user = $this->userWith($company, $from, $permissions, $assignDestination ? [$from, $to] : [$from]);
        $category = ProductCategory::create(['company_id' => $company->id, 'name' => 'General '.$suffix, 'slug' => 'general-'.$suffix, 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Unidad '.$suffix, 'abbreviation' => 'U'.$suffix, 'slug' => 'unidad-'.$suffix, 'allows_decimals' => true, 'is_active' => true]);
        $product = Product::create([
            'company_id' => $company->id, 'category_id' => $category->id, 'unit_id' => $unit->id,
            'name' => 'Producto '.$suffix, 'internal_code' => 'SKU-'.$suffix, 'cost' => 10, 'sale_price' => 20,
            'tax_rate' => 13, 'track_inventory' => true, 'is_active' => true,
        ]);

        return [$company, $from, $to, $user, $product];
    }

    private function userWith(Company $company, Branch $branch, array $permissions, array $branches): User
    {
        $suffix = Str::lower(Str::random(8));
        $role = Role::create(['company_id' => $company->id, 'name' => 'Rol '.$suffix, 'is_active' => true]);
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name, 'module' => 'Inventario', 'is_active' => true]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->companies()->attach($company->id, ['role_id' => $role->id]);
        foreach ($branches as $branchItem) {
            $user->branches()->attach($branchItem->id);
        }

        return $user;
    }

    private function stock(Branch $branch, Product $product, string $stock): void
    {
        DB::table('branch_product')->insert([
            'branch_id' => $branch->id, 'product_id' => $product->id, 'stock' => $stock,
            'minimum_stock' => null, 'maximum_stock' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stockValue(Branch $branch, Product $product): string
    {
        return bcadd((string) DB::table('branch_product')->where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('stock'), '0', 4);
    }

    private function activeSession(Company $company, Branch $branch): array
    {
        return ['active_company_id' => $company->id, 'active_branch_id' => $branch->id];
    }

    private function createTransfer(Company $company, Branch $from, Branch $to, User $user, Product $product, string $status, string $quantity): InventoryTransfer
    {
        $transfer = InventoryTransfer::create([
            'company_id' => $company->id, 'from_branch_id' => $from->id, 'to_branch_id' => $to->id,
            'user_id' => $user->id, 'transfer_number' => 'TP-'.uniqid(), 'status' => $status,
        ]);
        $transfer->items()->create([
            'product_id' => $product->id, 'quantity' => $quantity, 'sent_quantity' => $quantity,
            'from_previous_stock' => '0', 'from_new_stock' => '0', 'to_previous_stock' => '0', 'to_new_stock' => '0',
        ]);

        return $transfer;
    }
}
