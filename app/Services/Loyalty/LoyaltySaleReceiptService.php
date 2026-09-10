<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltyPortalSetting;
use App\Models\LoyaltySetting;
use App\Models\Sale;

class LoyaltySaleReceiptService
{
    private const EARNING_TYPES = [
        LoyaltyMovement::TYPE_PURCHASE,
        LoyaltyMovement::TYPE_NEW_CUSTOMER,
        LoyaltyMovement::TYPE_BIRTHDAY,
        LoyaltyMovement::TYPE_RETURN_CUSTOMER,
        LoyaltyMovement::TYPE_PROMOTION,
    ];

    /** Read-only receipt data: historical movements, current balance, or general portal invitation. */
    public function forSale(Sale $sale): ?array
    {
        $company = Company::query()->find($sale->company_id);
        $setting = LoyaltySetting::query()->where('company_id', $sale->company_id)->first();
        if (! $company?->is_active || ! $company->isModuleEnabled('loyalty') || ! $setting?->is_active) {
            return null;
        }

        if ($sale->customer_id === null) {
            return $this->invitation($company);
        }

        $movements = LoyaltyMovement::query()
            ->where('company_id', $sale->company_id)
            ->where('customer_id', $sale->customer_id)
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return $this->withoutMovements($sale, $company);
        }

        // Returns use SaleReturn as source and link back to the original sale movements.
        $adjustments = LoyaltyMovement::query()
            ->where('company_id', $sale->company_id)
            ->where('customer_id', $sale->customer_id)
            ->whereIn('type', [LoyaltyMovement::TYPE_VOID, LoyaltyMovement::TYPE_RETURN])
            ->whereIn('related_movement_id', $movements->modelKeys())
            ->get();
        $movements = $movements->merge($adjustments)->sortBy('id')->values();

        $earned = '0.0000';
        $redeemed = '0.0000';
        foreach ($movements as $movement) {
            if (in_array($movement->type, self::EARNING_TYPES, true)) {
                $earned = bcadd($earned, (string) $movement->points, 4);
            }
            if ($movement->type === LoyaltyMovement::TYPE_REDEMPTION) {
                $redeemed = bcadd($redeemed, ltrim((string) $movement->points, '-'), 4);
            }
        }

        return [
            'kind' => 'history',
            'earned' => $earned,
            'redeemed' => $redeemed,
            'balance_before' => (string) $movements->first()->balance_before,
            'balance_after' => (string) $movements->last()->balance_after,
            'adjusted' => $movements->contains(fn (LoyaltyMovement $movement) => in_array($movement->type, [LoyaltyMovement::TYPE_VOID, LoyaltyMovement::TYPE_RETURN], true)),
        ];
    }

    private function withoutMovements(Sale $sale, Company $company): ?array
    {
        $customer = Customer::query()->where('company_id', $sale->company_id)->find($sale->customer_id);
        if (! $customer) {
            return null;
        }

        $account = LoyaltyAccount::query()->where('company_id', $sale->company_id)
            ->where('customer_id', $customer->id)->first();
        if ($account) {
            // An inactive account is not a new member; never invite or reactivate it here.
            return $account->is_active ? [
                'kind' => 'balance',
                'earned' => '0.0000',
                'redeemed' => '0.0000',
                'balance_after' => (string) $account->balance,
                'adjusted' => false,
            ] : null;
        }

        return $this->invitation($company);
    }

    private function invitation(Company $company): ?array
    {
        $portal = LoyaltyPortalSetting::query()->where('company_id', $company->id)->first();
        $qr = app(LoyaltyPortalAccessService::class);
        if (! $qr->qrSupported()) {
            return null;
        }

        try {
            $svg = $qr->qrSvg(route('loyalty.customer.login', $company));
        } catch (\Throwable) {
            return null;
        }

        return [
            'kind' => 'invitation',
            'portal_name' => $portal?->displayName($company) ?? $company->trade_name,
            // Embedded SVG works in browsers and DomPDF without remote image requests.
            'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($svg),
        ];
    }
}
