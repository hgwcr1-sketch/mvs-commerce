<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyRegistrationIncentive;
use App\Models\LoyaltySetting;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyRedemptionService
{
    private const SCALE = 4;

    public function __construct(
        private readonly LoyaltyAccountService $accounts,
        private readonly LoyaltyPointValueService $pointValues,
        private readonly LoyaltyRedemptionEligibilityService $eligibility,
        private readonly LoyaltyRedemptionLimitService $limits,
        private readonly LoyaltyRegistrationIncentiveService $registrationIncentives,
    ) {}

    /** @return array{movement:LoyaltyMovement,account:LoyaltyAccount,requested_points:string,redeemed_points:string,redeemed_amount:string,balance_after:string,point_value:string} */
    public function redeem(
        Customer $customer,
        Company $company,
        string|int $requestedPoints,
        string|int $eligibleAmount,
        array $context = [],
    ): array {
        return DB::transaction(function () use ($customer, $company, $requestedPoints, $eligibleAmount, $context): array {
            $this->validateCompanyAndCustomer($company, $customer);
            $requestedPoints = $this->positiveDecimal($requestedPoints, 'requested_points');
            $eligibleAmount = $this->positiveDecimal($eligibleAmount, 'eligible_amount');
            $setting = $this->activeSetting($company);
            $account = $this->accounts->getOrCreateAccount($customer, $company);

            if (! $account->is_active) {
                throw ValidationException::withMessages(['account' => 'La cuenta de fidelización está desactivada.']);
            }

            $existing = $this->existingMovement($account, $company, $context['event_key'] ?? null);
            if ($existing !== null) {
                return $this->result($existing, $account->fresh(), ltrim((string) $existing->points, '-'));
            }

            if (($context['is_offer'] ?? false) && ! $setting->redeem_on_offers) {
                throw ValidationException::withMessages(['is_offer' => 'El canje de puntos no está permitido en ofertas.']);
            }

            $incentive = $this->registrationIncentives->evaluateForPurchase(
                $customer,
                $company,
                $eligibleAmount,
                $context['effective_at'] ?? null,
                ($context['source_type'] ?? null) === Sale::class ? ($context['source_id'] ?? null) : null,
                [
                    'branch_id' => $context['branch_id'] ?? (is_object($context['branch'] ?? null) ? $context['branch']->id : ($context['branch'] ?? null)),
                    'has_offers' => (bool) ($context['is_offer'] ?? false),
                    'existing_discount_amount' => $context['existing_discount_amount'] ?? '0',
                ],
            );
            $bypassMinimum = $incentive['eligible']
                && $incentive['benefit_type'] === LoyaltyRegistrationIncentive::TYPE_POINTS
                && $incentive['bypass_redemption_minimum'];

            $eligibility = $this->eligibility->evaluate($account, $company);
            if (! $bypassMinimum && ! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'redemption' => $this->eligibilityMessage($eligibility['reason']),
                ]);
            }

            if (bccomp($requestedPoints, $eligibility['available_points'], self::SCALE) > 0) {
                throw ValidationException::withMessages(['requested_points' => 'Saldo de puntos insuficiente.']);
            }

            $limit = $this->limits->calculate($account, $company, $eligibleAmount, $bypassMinimum);
            if (! $limit['eligible']) {
                throw ValidationException::withMessages(['redemption' => 'El monto de la operación no permite realizar un canje.']);
            }
            if (bccomp($requestedPoints, $limit['max_redeemable_points'], self::SCALE) > 0) {
                throw ValidationException::withMessages(['requested_points' => 'Los puntos solicitados exceden el límite máximo permitido.']);
            }

            $pointValue = $this->pointValues->pointValue($company);
            $redeemedAmount = $this->pointValues->moneyFromPoints($requestedPoints, $company);
            $metadata = array_merge($context['metadata'] ?? [], [
                'requested_points' => $requestedPoints,
                'redeemed_points' => $requestedPoints,
                'redeemed_amount' => $redeemedAmount,
                'eligible_amount' => $eligibleAmount,
                'maximum_redemption_percent' => $limit['percentage'],
                'is_offer' => (bool) ($context['is_offer'] ?? false),
            ]);

            $movement = $this->accounts->subtractPoints($account, $requestedPoints, LoyaltyMovement::TYPE_REDEMPTION, [
                'branch' => $context['branch'] ?? $context['branch_id'] ?? null,
                'user' => $context['user'] ?? $context['user_id'] ?? null,
                'base_amount' => $redeemedAmount,
                'point_value' => $pointValue,
                'description' => $context['description'] ?? 'Canje de puntos',
                'source_type' => $context['source_type'] ?? null,
                'source_id' => $context['source_id'] ?? null,
                'event_key' => $context['event_key'] ?? null,
                'effective_at' => $context['effective_at'] ?? now(),
                'metadata' => $metadata,
            ]);

            if ($bypassMinimum && $incentive['claim_id'] !== null) {
                $this->registrationIncentives->consume(
                    $incentive['claim_id'],
                    $customer,
                    $company,
                    ($context['source_type'] ?? null) === Sale::class ? ($context['source_id'] ?? null) : null,
                    $context['effective_at'] ?? null,
                    null,
                    [
                        'purchase_amount' => $eligibleAmount,
                        'branch_id' => $context['branch_id'] ?? (is_object($context['branch'] ?? null) ? $context['branch']->id : ($context['branch'] ?? null)),
                        'has_offers' => (bool) ($context['is_offer'] ?? false),
                        'existing_discount_amount' => $context['existing_discount_amount'] ?? '0',
                    ],
                );
            }

            return $this->result($movement, $account->fresh(), $requestedPoints);
        });
    }

    private function validateCompanyAndCustomer(Company $company, Customer $customer): void
    {
        if (! Company::query()->whereKey($company->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'La empresa no existe.']);
        }

        $persistedCustomer = Customer::withTrashed()->find($customer->id);
        if ($persistedCustomer === null) {
            throw ValidationException::withMessages(['customer' => 'El cliente no existe.']);
        }
        if ((int) $persistedCustomer->company_id !== (int) $company->id) {
            throw ValidationException::withMessages(['customer' => 'El cliente no pertenece a la empresa.']);
        }
    }

    private function activeSetting(Company $company): LoyaltySetting
    {
        $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
        if ($setting === null) {
            throw ValidationException::withMessages(['loyalty' => 'La empresa no tiene configuración de Fidelización.']);
        }
        if (! $setting->is_active) {
            throw ValidationException::withMessages(['loyalty' => 'Fidelización está desactivada para la empresa.']);
        }

        return $setting;
    }

    private function existingMovement(LoyaltyAccount $account, Company $company, mixed $eventKey): ?LoyaltyMovement
    {
        if ($eventKey === null) {
            return null;
        }

        $movement = LoyaltyMovement::query()
            ->where('company_id', $company->id)
            ->where('event_key', $eventKey)
            ->first();

        if ($movement !== null
            && ($movement->type !== LoyaltyMovement::TYPE_REDEMPTION
                || (int) $movement->loyalty_account_id !== (int) $account->id)) {
            throw ValidationException::withMessages(['event_key' => 'El evento ya fue utilizado por otra operación de fidelización.']);
        }

        return $movement;
    }

    private function positiveDecimal(string|int $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw ValidationException::withMessages([$field => 'El valor debe tener como máximo cuatro decimales.']);
        }

        $decimal = bcadd($value, '0', self::SCALE);
        if (bccomp($decimal, '0', self::SCALE) <= 0) {
            throw ValidationException::withMessages([$field => 'El valor debe ser mayor que cero.']);
        }

        return $decimal;
    }

    private function eligibilityMessage(?string $reason): string
    {
        return match ($reason) {
            'minimum_not_reached' => 'El saldo disponible no alcanza el mínimo requerido para canjear.',
            'insufficient_points' => 'Saldo de puntos insuficiente.',
            'invalid_minimum_configuration' => 'La configuración del mínimo de canje no es válida.',
            default => 'La cuenta no es elegible para realizar un canje.',
        };
    }

    /** @return array{movement:LoyaltyMovement,account:LoyaltyAccount,requested_points:string,redeemed_points:string,redeemed_amount:string,balance_after:string,point_value:string} */
    private function result(LoyaltyMovement $movement, LoyaltyAccount $account, string $requestedPoints): array
    {
        return [
            'movement' => $movement,
            'account' => $account,
            'requested_points' => $requestedPoints,
            'redeemed_points' => ltrim((string) $movement->points, '-'),
            'redeemed_amount' => (string) $movement->base_amount,
            'balance_after' => (string) $movement->balance_after,
            'point_value' => (string) $movement->point_value,
        ];
    }
}
