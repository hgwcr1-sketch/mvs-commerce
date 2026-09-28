<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRewardRedemptionService
{
    private const SCALE = 4;

    public function __construct(
        private readonly LoyaltyAccountService $accounts,
        private readonly LoyaltyRewardAvailabilityService $availability,
        private readonly InventoryPostingService $inventory,
    ) {}

    /**
     * Canje atómico de un premio: historial + puntos + cupo/inventario.
     * Idempotente por (empresa, event_key).
     */
    public function redeem(Customer $customer, LoyaltyReward $reward, Company $company, Branch $branch, User $user, array $context = []): LoyaltyRewardRedemption
    {
        return DB::transaction(function () use ($customer, $reward, $company, $branch, $user, $context): LoyaltyRewardRedemption {
            $this->validateContext($customer, $reward, $company, $branch);

            $eventKey = trim((string) ($context['event_key'] ?? ''));
            if ($eventKey === '') {
                $eventKey = 'reward:'.$reward->id.':customer:'.$customer->id.':'.uniqid();
            }

            $existing = LoyaltyRewardRedemption::query()
                ->where('company_id', $company->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $availability = $this->availability->evaluate($reward, $company, $branch);
            if (! $availability['available']) {
                throw ValidationException::withMessages([
                    'reward' => $this->availabilityMessage($availability['reason']),
                ]);
            }

            $cost = (string) $reward->points_cost;
            $account = $this->accounts->getOrCreateAccount($customer, $company);

            if ($reward->availability_mode === LoyaltyReward::MODE_LIMITED) {
                $this->consumeLimitedQuota($reward);
            }

            try {
                $redemption = LoyaltyRewardRedemption::create([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                    'reward_id' => $reward->id,
                    'product_id' => $reward->product_id,
                    'loyalty_movement_id' => null,
                    'event_key' => $eventKey,
                    'reward_name' => $reward->name,
                    'reward_type' => $reward->type,
                    'availability_mode' => $reward->availability_mode,
                    'product_name' => $reward->product?->name,
                    'points_cost' => $cost,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                return LoyaltyRewardRedemption::query()
                    ->where('company_id', $company->id)
                    ->where('event_key', $eventKey)
                    ->firstOrFail();
            }

            if ($reward->availability_mode === LoyaltyReward::MODE_PRODUCT) {
                $this->inventory->postRewardRedemption($redemption, $user->id);
            }

            $movement = $this->accounts->subtractPoints($account, $cost, LoyaltyMovement::TYPE_REWARD, [
                'branch' => $branch,
                'user' => $user,
                'source_type' => LoyaltyRewardRedemption::class,
                'source_id' => $redemption->id,
                'event_key' => $eventKey,
                'description' => 'Canje de premio: '.$redemption->reward_name,
                'effective_at' => $context['effective_at'] ?? now(),
                'metadata' => [
                    'redemption_id' => $redemption->id,
                    'reward_type' => $redemption->reward_type,
                    'availability_mode' => $redemption->availability_mode,
                ],
            ]);

            $redemption->forceFill(['loyalty_movement_id' => $movement->id])->save();

            return $redemption->fresh();
        });
    }

    private function consumeLimitedQuota(LoyaltyReward $reward): void
    {
        $locked = LoyaltyReward::query()->lockForUpdate()->findOrFail($reward->id);
        $quota = (string) ($locked->stock_quantity ?? '0');

        if (bccomp($quota, '1', self::SCALE) < 0) {
            throw ValidationException::withMessages([
                'reward' => 'El premio no tiene cupo disponible.',
            ]);
        }

        $locked->update(['stock_quantity' => bcsub($quota, '1', self::SCALE)]);
        $reward->setRawAttributes($locked->fresh()->getAttributes());
    }

    private function validateContext(Customer $customer, LoyaltyReward $reward, Company $company, Branch $branch): void
    {
        if ((int) $reward->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['reward' => 'El premio no pertenece a la empresa actual.']);
        }

        if ((int) $branch->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['branch' => 'La sucursal no pertenece a la empresa actual.']);
        }

        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa actual.']);
        }
    }

    private function availabilityMessage(?string $reason): string
    {
        return match ($reason) {
            'inactive' => 'El premio está inactivo.',
            'insufficient_quota' => 'El premio no tiene cupo disponible.',
            'out_of_stock' => 'El premio no tiene existencias disponibles en esta sucursal.',
            default => 'El premio no está disponible.',
        };
    }
}
