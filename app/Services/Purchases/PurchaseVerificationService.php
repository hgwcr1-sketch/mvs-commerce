<?php

namespace App\Services\Purchases;

use App\Models\Alert;
use App\Models\Purchase;
use App\Models\PurchaseVerification;
use App\Models\User;
use App\Services\Notifications\AlertDispatcher;
use App\Services\Notifications\AlertTypeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseVerificationService
{
    public function __construct(
        private AlertDispatcher $alertDispatcher,
    ) {}

    public function assign(Purchase $purchase, User $assigner, User $assignee): PurchaseVerification
    {
        return DB::transaction(function () use ($purchase, $assigner, $assignee) {
            $purchase = Purchase::query()->with('items')->lockForUpdate()->findOrFail($purchase->id);
            if ($purchase->status !== 'posted' || $purchase->verification()->exists()) {
                throw ValidationException::withMessages(['purchase' => 'La compra no puede asignarse o ya tiene una verificación.']);
            }
            $verification = PurchaseVerification::create([
                'company_id' => $purchase->company_id, 'branch_id' => $purchase->branch_id, 'purchase_id' => $purchase->id,
                'created_by' => $purchase->user_id, 'assigned_by' => $assigner->id, 'assigned_to' => $assignee->id,
                'status' => 'pending', 'assigned_at' => now(),
            ]);
            foreach ($purchase->items as $item) {
                $verification->items()->create(['purchase_item_id' => $item->id, 'product_id' => $item->product_id, 'expected_quantity' => $item->quantity]);
            }

            $this->dispatchPurchaseVerificationAlert($verification, $purchase, $assigner, $assignee);

            return $verification;
        });
    }

    public function start(PurchaseVerification $verification, User $user): void
    {
        DB::transaction(function () use ($verification, $user) {
            $locked = PurchaseVerification::lockForUpdate()->findOrFail($verification->id);
            if ($locked->assigned_to !== $user->id || $locked->status !== 'pending') {
                throw ValidationException::withMessages(['verification' => 'La tarea no puede iniciarse.']);
            }
            $locked->update(['status' => 'in_review', 'started_at' => now()]);
        });
    }

    public function verify(PurchaseVerification $verification, User $user, array $lines): PurchaseVerification
    {
        return DB::transaction(function () use ($verification, $user, $lines) {
            $locked = PurchaseVerification::with('items')->lockForUpdate()->findOrFail($verification->id);
            if ($locked->assigned_to !== $user->id || ! in_array($locked->status, ['pending', 'in_review'], true)) {
                throw ValidationException::withMessages(['verification' => 'La tarea no está disponible para este usuario.']);
            }
            $submittedIds = collect($lines)->keys()->map(fn ($id) => (int) $id)->sort()->values();
            if ($submittedIds->all() !== $locked->items->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['lines' => 'Debe revisar todas las líneas de la recepción.']);
            }
            $hasDifferences = false;
            foreach ($locked->items as $item) {
                $line = $lines[$item->id];
                $difference = round((float) $line['received_quantity'] - (float) $item->expected_quantity, 4);
                $hasDifferences = $hasDifferences || abs($difference) > 0.00005;
                $item->update(['received_quantity' => $line['received_quantity'], 'difference' => number_format($difference, 4, '.', ''), 'is_checked' => true, 'observation' => $line['observation'] ?? null, 'verified_by' => $user->id, 'verified_at' => now()]);
            }
            $locked->update(['status' => $hasDifferences ? 'differences' : 'conform', 'verified_by' => $user->id, 'verified_at' => now(), 'started_at' => $locked->started_at ?: now()]);

            return $locked->fresh('items');
        });
    }

    public function close(PurchaseVerification $verification, User $resolver, ?string $notes): void
    {
        DB::transaction(function () use ($verification, $resolver, $notes) {
            $locked = PurchaseVerification::lockForUpdate()->findOrFail($verification->id);
            if (! in_array($locked->status, ['conform', 'differences'], true)) {
                throw ValidationException::withMessages(['verification' => 'Solo una verificación concluida puede cerrarse.']);
            }
            if ($locked->status === 'differences' && blank($notes)) {
                throw ValidationException::withMessages(['resolution_notes' => 'Indique cómo se resolvió la diferencia.']);
            }
            $locked->update(['status' => 'closed', 'resolved_by' => $resolver->id, 'resolved_at' => now(), 'resolution_notes' => $notes]);
        });
    }

    private function dispatchPurchaseVerificationAlert(PurchaseVerification $verification, Purchase $purchase, User $assigner, User $assignee): void
    {
        try {
            $this->alertDispatcher->dispatch(AlertTypeRegistry::TYPE_PURCHASE_VERIFICATION, [
                'company_id' => $verification->company_id,
                'branch_id' => $verification->branch_id,
                'severity' => Alert::SEVERITY_ATTENTION,
                'actor_id' => $assigner->id,
                'responsible_user_id' => $assignee->id,
                'entity_type' => PurchaseVerification::class,
                'entity_id' => $verification->id,
                'link' => route('purchase-verifications.show', $verification),
                'dedupe_key' => (string) $verification->id,
                'metadata' => [
                    'purchase_id' => $purchase->id,
                    'purchase_identifier' => $purchase->invoice_number ?? $purchase->id,
                    'verification_id' => $verification->id,
                    'assigner_name' => $assigner->name,
                    'assignee_name' => $assignee->name,
                ],
                'notes' => "Se asignó una nueva verificación de compra a {$assignee->name}.",
            ]);
        } catch (\Throwable $e) {
            Log::error('Error enviando alerta de verificación de compra', [
                'verification_id' => $verification->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
