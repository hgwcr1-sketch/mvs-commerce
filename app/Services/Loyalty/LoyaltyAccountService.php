<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyAccountService
{
    private const SCALE = 4;

    private const EARNING_TYPES = [
        LoyaltyMovement::TYPE_PURCHASE,
        LoyaltyMovement::TYPE_NEW_CUSTOMER,
        LoyaltyMovement::TYPE_BIRTHDAY,
        LoyaltyMovement::TYPE_RETURN_CUSTOMER,
        LoyaltyMovement::TYPE_PROMOTION,
    ];

    public function getOrCreateAccount(Customer $customer, Company $company, ?User $user = null): LoyaltyAccount
    {
        $this->validateContext($company, $customer, null, $user);

        return LoyaltyAccount::query()->firstOrCreate(
            ['company_id' => $company->id, 'customer_id' => $customer->id],
            ['balance' => '0.0000', 'total_earned' => '0.0000', 'total_redeemed' => '0.0000', 'total_expired' => '0.0000']
        );
    }

    /** @return array{balance: string, total_earned: string, total_redeemed: string, total_expired: string, last_qualifying_purchase_at: mixed, last_activity_at: mixed} */
    public function getBalance(LoyaltyAccount $account): array
    {
        $account = LoyaltyAccount::query()->find($account->id);

        if ($account === null) {
            throw ValidationException::withMessages(['account' => 'La cuenta de fidelización no existe.']);
        }

        return $account->only([
            'balance', 'total_earned', 'total_redeemed', 'total_expired',
            'last_qualifying_purchase_at', 'last_activity_at',
        ]);
    }

    public function addPoints(LoyaltyAccount $account, string|int $points, string $type, array $context = []): LoyaltyMovement
    {
        $points = $this->positiveDecimal($points);

        return $this->record($account, $points, $type, $context);
    }

    public function subtractPoints(LoyaltyAccount $account, string|int $points, string $type, array $context = []): LoyaltyMovement
    {
        $points = $this->positiveDecimal($points);

        return $this->record($account, bcsub('0', $points, self::SCALE), $type, $context);
    }

    public function adjustPoints(LoyaltyAccount $account, string|int $points, array $context = []): LoyaltyMovement
    {
        $points = $this->decimal($points);
        if (bccomp($points, '0', self::SCALE) === 0) {
            throw ValidationException::withMessages(['points' => 'El ajuste de puntos no puede ser cero.']);
        }

        return $this->record($account, $points, LoyaltyMovement::TYPE_ADJUSTMENT, $context);
    }

    /** Registra Kardex legado sin volver a aplicar sus puntos al saldo consolidado. */
    public function recordHistoricalMigrationMovement(LoyaltyAccount $account, string $signedPoints, string $type, array $context = []): LoyaltyMovement
    {
        $this->validateType($type);
        $account = LoyaltyAccount::query()->lockForUpdate()->findOrFail($account->id);
        $eventKey = $context['event_key'] ?? null;

        if ($eventKey !== null && ($existing = LoyaltyMovement::query()->where('company_id', $account->company_id)->where('event_key', $eventKey)->first())) {
            return $existing;
        }

        return LoyaltyMovement::query()->create([
            'company_id' => $account->company_id,
            'loyalty_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'user_id' => $context['user_id'] ?? null,
            'type' => $type,
            'points' => $this->decimal($signedPoints),
            'balance_before' => $this->optionalDecimal($context['balance_before'] ?? null) ?? $this->decimal($account->balance),
            'balance_after' => $this->optionalDecimal($context['balance_after'] ?? null) ?? $this->decimal($account->balance),
            'description' => $context['description'] ?? $this->description($type),
            'source_type' => $context['source_type'] ?? 'LoyaltyMigration',
            'source_id' => $context['source_id'] ?? null,
            'event_key' => $eventKey,
            'effective_at' => $context['effective_at'] ?? now(),
            'metadata' => $context['metadata'] ?? null,
        ]);
    }

    public function reverseMovement(
        LoyaltyMovement $original,
        string $type,
        array $context = [],
        string|int|null $pointsOverride = null,
    ): LoyaltyMovement {
        if (! in_array($type, [LoyaltyMovement::TYPE_RETURN, LoyaltyMovement::TYPE_VOID], true)) {
            throw ValidationException::withMessages(['type' => 'La reversión debe ser de tipo return o void.']);
        }

        $original = LoyaltyMovement::query()->find($original->id);
        if ($original === null) {
            throw ValidationException::withMessages(['related_movement_id' => 'El movimiento original no existe.']);
        }

        $originalAmount = ltrim($this->decimal($original->points), '-');
        if ($pointsOverride === null) {
            $amount = $originalAmount;
        } else {
            $amount = $this->positiveDecimal($pointsOverride);
            if (bccomp($amount, $originalAmount, self::SCALE) > 0) {
                throw ValidationException::withMessages(['points' => 'La reversión no puede superar los puntos del movimiento original.']);
            }
        }

        $context['related_movement_id'] = $original->id;

        // La reversión aplica siempre el signo opuesto al movimiento original.
        if (bccomp($original->points, '0', self::SCALE) >= 0) {
            $signedPoints = bcsub('0', $amount, self::SCALE);
        } else {
            $signedPoints = $amount;
        }

        return $this->record(
            $original->loyaltyAccount,
            $signedPoints,
            $type,
            $context,
            $original
        );
    }

    private function record(
        LoyaltyAccount $account,
        string $signedPoints,
        string $type,
        array $context,
        ?LoyaltyMovement $reversedMovement = null
    ): LoyaltyMovement {
        $this->validateType($type);

        return DB::transaction(function () use ($account, $signedPoints, $type, $context, $reversedMovement) {
            $locked = LoyaltyAccount::query()->lockForUpdate()->find($account->id);
            if ($locked === null) {
                throw ValidationException::withMessages(['account' => 'La cuenta de fidelización no existe.']);
            }

            $company = Company::query()->find($locked->company_id);
            $customer = Customer::withTrashed()->find($locked->customer_id);
            $branch = $this->resolveBranch($context['branch'] ?? $context['branch_id'] ?? null);
            $user = $this->resolveUser($context['user'] ?? $context['user_id'] ?? null);
            $this->validateContext($company, $customer, $branch, $user, $locked);

            $eventKey = $context['event_key'] ?? null;
            if ($eventKey !== null) {
                $existing = LoyaltyMovement::query()
                    ->where('company_id', $locked->company_id)
                    ->where('event_key', $eventKey)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            if ($reversedMovement !== null
                && ($reversedMovement->company_id !== $locked->company_id
                    || $reversedMovement->loyalty_account_id !== $locked->id)) {
                throw ValidationException::withMessages(['related_movement_id' => 'El movimiento original no pertenece a esta cuenta y empresa.']);
            }

            $before = $this->decimal($locked->balance);
            $after = bcadd($before, $signedPoints, self::SCALE);
            if (bccomp($after, '0', self::SCALE) < 0) {
                throw ValidationException::withMessages(['points' => 'Saldo de puntos insuficiente.']);
            }

            $now = now();
            $movement = LoyaltyMovement::query()->create([
                'company_id' => $locked->company_id,
                'branch_id' => $branch?->id,
                'loyalty_account_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'user_id' => $user?->id,
                'type' => $type,
                'points' => $signedPoints,
                'balance_before' => $before,
                'balance_after' => $after,
                'base_amount' => $this->optionalDecimal($context['base_amount'] ?? null),
                'earning_percentage' => $this->optionalDecimal($context['earning_percentage'] ?? null),
                'point_value' => $this->optionalDecimal($context['point_value'] ?? null),
                'description' => $context['description'] ?? $this->description($type),
                'source_type' => $context['source_type'] ?? null,
                'source_id' => $context['source_id'] ?? null,
                'related_movement_id' => $context['related_movement_id'] ?? null,
                'event_key' => $eventKey,
                'effective_at' => $context['effective_at'] ?? $now,
                'metadata' => $context['metadata'] ?? null,
            ]);

            foreach ($context['lines'] ?? [] as $line) {
                $movement->lines()->create($line);
            }

            $totals = $this->updatedTotals($locked, $type, $signedPoints, $reversedMovement);
            $accountUpdates = $totals + ['balance' => $after, 'last_activity_at' => $now];
            if ($type === LoyaltyMovement::TYPE_PURCHASE && isset($context['qualifying_purchase_at'])) {
                $accountUpdates['last_qualifying_purchase_at'] = $context['qualifying_purchase_at'];
            }
            $locked->update($accountUpdates);

            return $movement;
        });
    }

    private function updatedTotals(LoyaltyAccount $account, string $type, string $points, ?LoyaltyMovement $original): array
    {
        $totals = [
            'total_earned' => $this->decimal($account->total_earned),
            'total_redeemed' => $this->decimal($account->total_redeemed),
            'total_expired' => $this->decimal($account->total_expired),
        ];

        if ($original !== null) {
            $amount = ltrim($this->decimal($points), '-');
            if (in_array($original->type, self::EARNING_TYPES, true)) {
                $totals['total_earned'] = bcsub($totals['total_earned'], $amount, self::SCALE);
            } elseif (in_array($original->type, [LoyaltyMovement::TYPE_REDEMPTION, LoyaltyMovement::TYPE_REWARD], true)) {
                $totals['total_redeemed'] = bcsub($totals['total_redeemed'], $amount, self::SCALE);
            } elseif ($original->type === LoyaltyMovement::TYPE_EXPIRATION) {
                $totals['total_expired'] = bcsub($totals['total_expired'], $amount, self::SCALE);
            }

            return $totals;
        }

        $amount = ltrim($points, '-');
        if (in_array($type, self::EARNING_TYPES, true) && bccomp($points, '0', self::SCALE) > 0) {
            $totals['total_earned'] = bcadd($totals['total_earned'], $amount, self::SCALE);
        } elseif (in_array($type, [LoyaltyMovement::TYPE_REDEMPTION, LoyaltyMovement::TYPE_REWARD], true) && bccomp($points, '0', self::SCALE) < 0) {
            $totals['total_redeemed'] = bcadd($totals['total_redeemed'], $amount, self::SCALE);
        } elseif ($type === LoyaltyMovement::TYPE_EXPIRATION && bccomp($points, '0', self::SCALE) < 0) {
            $totals['total_expired'] = bcadd($totals['total_expired'], $amount, self::SCALE);
        }

        return $totals;
    }

    private function validateContext(?Company $company, ?Customer $customer, ?Branch $branch, ?User $user, ?LoyaltyAccount $account = null): void
    {
        if ($company === null || ! Company::query()->whereKey($company->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'La empresa no existe.']);
        }
        if ($customer === null || ! Customer::withTrashed()->whereKey($customer->id)->exists()) {
            throw ValidationException::withMessages(['customer' => 'El cliente no existe.']);
        }
        if ((int) $customer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }
        if ($account !== null && ((int) $account->company_id !== (int) $company->id || (int) $account->customer_id !== (int) $customer->id)) {
            throw ValidationException::withMessages(['account' => 'La cuenta no pertenece a la empresa y cliente indicados.']);
        }
        if ($branch !== null && (int) $branch->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['branch' => 'La sucursal no pertenece a la empresa.']);
        }
        if ($user !== null && ! User::query()->whereKey($user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'El usuario no existe.']);
        }
    }

    private function resolveBranch(Branch|int|string|null $branch): ?Branch
    {
        if ($branch === null) {
            return null;
        }

        $resolved = $branch instanceof Branch ? Branch::query()->find($branch->id) : Branch::query()->find($branch);
        if ($resolved === null) {
            throw ValidationException::withMessages(['branch' => 'La sucursal no existe.']);
        }

        return $resolved;
    }

    private function resolveUser(User|int|string|null $user): ?User
    {
        if ($user === null) {
            return null;
        }

        $resolved = $user instanceof User ? User::query()->find($user->id) : User::query()->find($user);
        if ($resolved === null) {
            throw ValidationException::withMessages(['user' => 'El usuario no existe.']);
        }

        return $resolved;
    }

    private function validateType(string $type): void
    {
        if (! in_array($type, LoyaltyMovement::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'El tipo de movimiento de fidelización no es válido.']);
        }
    }

    private function positiveDecimal(string|int $value): string
    {
        $value = $this->decimal($value);
        if (bccomp($value, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages(['points' => 'Los puntos deben ser mayores que cero.']);
        }

        return $value;
    }

    private function optionalDecimal(string|int|null $value): ?string
    {
        return $value === null ? null : $this->decimal($value);
    }

    private function decimal(string|int $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages(['points' => 'El valor decimal debe tener como máximo cuatro decimales.']);
        }

        return bcadd($value, '0', self::SCALE);
    }

    private function description(string $type): string
    {
        return match ($type) {
            LoyaltyMovement::TYPE_PURCHASE => 'Puntos por compra',
            LoyaltyMovement::TYPE_NEW_CUSTOMER => 'Puntos por cliente nuevo',
            LoyaltyMovement::TYPE_BIRTHDAY => 'Puntos por cumpleaños',
            LoyaltyMovement::TYPE_RETURN_CUSTOMER => 'Puntos por retorno de cliente',
            LoyaltyMovement::TYPE_PROMOTION => 'Puntos por promoción',
            LoyaltyMovement::TYPE_REDEMPTION => 'Canje de puntos',
            LoyaltyMovement::TYPE_REWARD => 'Premio de fidelización',
            LoyaltyMovement::TYPE_RETURN => 'Reversión por devolución',
            LoyaltyMovement::TYPE_VOID => 'Reversión por anulación',
            LoyaltyMovement::TYPE_EXPIRATION => 'Vencimiento de puntos',
            LoyaltyMovement::TYPE_ADJUSTMENT => 'Ajuste de puntos',
        };
    }
}
