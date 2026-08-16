<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Permission;
use App\Models\Role;

class SaleVoidService
{
    public function __construct(
        private readonly InventoryPostingService $inventoryPostingService,
    ) {
    }

    public function void(Sale $sale, User $user, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $user, $reason) {

            $sale = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->status !== Sale::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'sale' => 'Solo se puede anular una venta completada.',
                ]);
            }

            if ((int) $sale->company_id !== (int) session('active_company_id')) {
                abort(404);
            }

            if ((int) $sale->branch_id !== (int) session('active_branch_id')) {
                abort(404);
            }

            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Debe indicar el motivo de la anulación.',
                ]);
            }

            $sale->load([
                'items.product',
                'payments',
            ]);

            foreach ($sale->items as $item) {
                if (
                    $item->product !== null
                    && $item->product->track_inventory
                ) {
                    $this->inventoryPostingService->voidSale(
                        $sale,
                        $item->product,
                        (float) $item->quantity,
                        $user->id,
                    );
                }
            }

            foreach ($sale->payments as $payment) {
                if ($payment->status === SalePayment::STATUS_COMPLETED) {
                    $payment->update([
                        'status' => SalePayment::STATUS_VOIDED,
                        'voided_by' => $user->id,
                        'voided_at' => now(),
                        'void_reason' => $reason,
                    ]);
                }
            }

            $sale->update([
                'status' => Sale::STATUS_VOIDED,
                'voided_by' => $user->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $sale->fresh([
                'items',
                'payments',
            ]);
        });
    }

public function test_void_route_requires_permission_and_voids_sale_with_reason(): void
{
    $company = Company::create([
        'trade_name' => 'Empresa '.uniqid(),
        'currency' => 'CRC',
        'timezone' => 'America/Costa_Rica',
        'is_active' => true,
    ]);

    $branch = Branch::create([
        'company_id' => $company->id,
        'name' => 'Principal',
        'code' => 'PR-'.$company->id.'-'.uniqid(),
        'is_active' => true,
    ]);

    $saleOwner = User::factory()->create();

    $sale = Sale::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'user_id' => $saleOwner->id,
        'customer_id' => null,
        'checkout_token' => (string) Str::uuid(),
        'request_fingerprint' => hash('sha256', 'venta-http-anulacion'),
        'sale_number' => 'POS-VOID-HTTP-001',
        'document_type' => Sale::DOCUMENT_ELECTRONIC_TICKET,
        'sale_condition' => Sale::CONDITION_CASH,
        'status' => Sale::STATUS_COMPLETED,
        'currency_code' => 'CRC',
        'exchange_rate' => 1,
        'subtotal' => 1000,
        'discount_total' => 0,
        'tax_total' => 0,
        'rounding_total' => 0,
        'total' => 1000,
        'paid_total' => 1000,
        'balance_due' => 0,
        'completed_at' => now(),
    ]);

    $withoutPermission = User::factory()->create();

    $roleWithoutPermission = Role::create([
        'company_id' => $company->id,
        'name' => 'Sin anular '.uniqid(),
        'is_active' => true,
    ]);

    $withoutPermission->companies()->attach(
        $company->id,
        ['role_id' => $roleWithoutPermission->id],
    );

    $withoutPermission->branches()->attach($branch->id);

    $this->actingAs($withoutPermission)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->post(route('ventas.void', $sale), [
            'reason' => 'Error de prueba',
        ])
        ->assertForbidden();

    $this->assertSame(
        Sale::STATUS_COMPLETED,
        $sale->fresh()->status,
    );

    $authorized = User::factory()->create();

    $role = Role::create([
        'company_id' => $company->id,
        'name' => 'Puede anular '.uniqid(),
        'is_active' => true,
    ]);

    $permission = Permission::firstOrCreate(
        ['name' => 'ventas.anular'],
        [
            'label' => 'Anular ventas',
            'module' => 'Ventas',
            'is_active' => true,
        ],
    );

    $role->permissions()->syncWithoutDetaching($permission);

    $authorized->companies()->attach(
        $company->id,
        ['role_id' => $role->id],
    );

    $authorized->branches()->attach($branch->id);

    $response = $this->actingAs($authorized)
        ->withSession([
            'active_company_id' => $company->id,
            'active_branch_id' => $branch->id,
        ])
        ->post(route('ventas.void', $sale), [
            'reason' => 'Venta registrada por error',
        ]);

    $response->assertRedirect(route('ventas.show', $sale));

    $sale->refresh();

    $this->assertSame(
        Sale::STATUS_VOIDED,
        $sale->status,
    );

    $this->assertSame(
        $authorized->id,
        $sale->voided_by,
    );

    $this->assertSame(
        'Venta registrada por error',
        $sale->void_reason,
    );
}

}