<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Capa online de fidelización para ventas confirmadas (F36 acumulación / F37 canje).
 *
 * NO crea una segunda lógica de acumulación ni de canje: reutiliza exactamente
 * los pipelines del POS sobre la misma cuenta central por empresa.
 *
 * F36 — earnFromEligibleAmount (F08 porcentaje y monto elegible, F12
 * multiplicadores, F13 earn_on_offers vía LoyaltyOfferEligibilityService,
 * bonos F10/F11).
 *
 * F37 — LoyaltyRedemptionService::redeem (F14 valor del punto, F15 mínimo de
 * saldo, F16 máximo pagable con puntos, F17 redeem_on_offers) más el registro
 * del pago con puntos como SalePayment con el PaymentMethod de puntos, igual
 * que en POS; movimiento y pago quedan coordinados dentro de una transacción.
 *
 * Ambos exigen una venta real confirmada (status completed), resuelven empresa
 * activa y sucursal válida desde la propia venta y usan event_keys
 * deterministas (`online_sale:{canal}:{referencia}:loyalty:earn|redemption`)
 * para que reintentos del mismo pedido no dupliquen nada.
 */
class LoyaltyOnlineSaleService
{
    public const DEFAULT_CHANNEL = 'online';

    public function __construct(
        private readonly LoyaltyEarningService $earningService,
        private readonly LoyaltyOfferEligibilityService $offerEligibility,
        private readonly LoyaltyReturningCustomerService $returningCustomerService,
        private readonly LoyaltyBirthdayService $birthdayService,
        private readonly LoyaltyRedemptionService $redemptionService,
    ) {}

    /**
     * @return array{earned:bool,duplicate:bool,points:string}
     */
    public function accrueForSale(
        Sale $sale,
        ?Customer $customer,
        string $externalReference,
        string $channel = self::DEFAULT_CHANNEL,
    ): array {
        $reference = $this->reference($externalReference);

        if ($sale->status !== Sale::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['sale' => 'Solo se acredita fidelización por ventas confirmadas.']);
        }

        $company = Company::query()->where('is_active', true)->find($sale->company_id);
        if ($company === null) {
            throw ValidationException::withMessages(['company' => 'La empresa de la venta no existe o está inactiva.']);
        }

        $branch = Branch::query()->where('company_id', $company->id)->where('is_active', true)->find($sale->branch_id);
        if ($branch === null) {
            throw ValidationException::withMessages(['branch' => 'La sucursal de origen de la venta no está disponible para esta empresa.']);
        }

        // Sin cliente identificado no hay reglas nuevas: mismo comportamiento
        // actual de las ventas sin cliente (no se acredita nada).
        if ($customer === null) {
            return ['earned' => false, 'duplicate' => false, 'points' => '0.0000'];
        }

        $eventKey = "online_sale:{$channel}:{$reference}:loyalty:earn";
        $existing = LoyaltyMovement::query()
            ->where('company_id', $company->id)
            ->where('event_key', $eventKey)
            ->first();

        try {
            $setting = LoyaltySetting::query()->where('company_id', $company->id)->first();
            $offerEligibility = $this->offerEligibility->forSale($sale, (bool) $setting?->earn_on_offers);

            $movement = $this->earningService->earnFromEligibleAmount(
                $customer,
                $company,
                $offerEligibility['eligible_amount'],
                [
                    'branch' => $branch,
                    'source_type' => Sale::class,
                    'source_id' => $sale->id,
                    'event_key' => $eventKey,
                    'description' => "Puntos por venta {$sale->sale_number} ({$channel})",
                    'effective_at' => $sale->completed_at ?? $sale->created_at,
                    'metadata' => [
                        'channel' => $channel,
                        'external_reference' => $reference,
                        'sale_number' => $sale->sale_number,
                        'document_type' => $sale->document_type,
                        'origin' => 'online',
                        'offer_eligibility' => $offerEligibility,
                    ],
                ],
            );

            // Bonos vigentes con la misma semántica del POS (F10/F11).
            $this->returningCustomerService->awardIfEligible(
                $customer,
                $company,
                $sale->id,
                $sale->completed_at ?? $sale->created_at,
                [
                    'branch' => $branch,
                    'source_type' => Sale::class,
                    'description' => "Bono por retorno en venta {$sale->sale_number} ({$channel})",
                    'metadata' => ['channel' => $channel, 'external_reference' => $reference, 'sale_number' => $sale->sale_number],
                ],
            );

            $this->birthdayService->awardIfEligible(
                $customer,
                $company,
                $sale->completed_at ?? $sale->created_at,
                [
                    'branch' => $branch,
                    'source_type' => Sale::class,
                    'description' => "Bono de cumpleaños por venta {$sale->sale_number} ({$channel})",
                    'metadata' => ['channel' => $channel, 'external_reference' => $reference],
                ],
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('loyalty', $exception->errors())) {
                return ['earned' => false, 'duplicate' => false, 'points' => '0.0000'];
            }

            throw $exception;
        }

        return [
            'earned' => $movement !== null && $existing === null,
            'duplicate' => $existing !== null,
            'points' => (string) ($movement?->points ?? $existing?->points ?? '0.0000'),
        ];
    }

    /**
     * Canje de puntos sobre una venta online confirmada (F37).
     *
     * Reutiliza LoyaltyRedemptionService::redeem (F14-F17) y registra la parte
     * pagada con puntos como SalePayment con el PaymentMethod de puntos de la
     * empresa, igual que en POS. Movimiento y pago se coordinan en una sola
     * transacción: si algo falla, no quedan puntos descontados sin pago ni
     * pago registrado sin movimiento.
     *
     * @return array{redeemed:bool,duplicate:bool,redeemed_points:string,redeemed_amount:string,balance_after:string,payment:?SalePayment}
     */
    public function redeemForSale(
        Sale $sale,
        ?Customer $customer,
        string|int $requestedPoints,
        string $externalReference,
        string $channel = self::DEFAULT_CHANNEL,
    ): array {
        $reference = $this->reference($externalReference);

        if ($sale->status !== Sale::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['sale' => 'Solo se canjea puntos por ventas confirmadas.']);
        }

        $company = Company::query()->where('is_active', true)->find($sale->company_id);
        if ($company === null) {
            throw ValidationException::withMessages(['company' => 'La empresa de la venta no existe o está inactiva.']);
        }

        $branch = Branch::query()->where('company_id', $company->id)->where('is_active', true)->find($sale->branch_id);
        if ($branch === null) {
            throw ValidationException::withMessages(['branch' => 'La sucursal de origen de la venta no está disponible para esta empresa.']);
        }

        // Regla 1: cliente obligatorio para canjear.
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'Debe seleccionar un cliente para canjear puntos.']);
        }

        $eventKey = "online_sale:{$channel}:{$reference}:loyalty:redemption";
        $existing = LoyaltyMovement::query()
            ->where('company_id', $company->id)
            ->where('event_key', $eventKey)
            ->first();

        // Reintento del mismo pedido/canal: devolver el resultado ya registrado
        // sin volver a descontar puntos ni duplicar el pago.
        if ($existing !== null) {
            if ($existing->type !== LoyaltyMovement::TYPE_REDEMPTION) {
                throw ValidationException::withMessages(['event_key' => 'El evento ya fue utilizado por otra operación de fidelización.']);
            }

            return [
                'redeemed' => true,
                'duplicate' => true,
                'redeemed_points' => ltrim((string) $existing->points, '-'),
                'redeemed_amount' => (string) $existing->base_amount,
                'balance_after' => (string) $existing->balance_after,
                'payment' => $this->paymentFor($sale, $company),
            ];
        }

        $applicableAmount = number_format((float) $sale->total, 4, '.', '');
        $hasOffers = $sale->items()->where('is_offer', true)->exists();
        $effectiveAt = $sale->completed_at ?? $sale->created_at;

        // Canje y registro del pago en una sola transacción: las reglas F14-F17
        // se evalúan primero; método de puntos y SalePayment quedan coordinados
        // con el movimiento (si algo falla, rollback completo).
        [$result, $payment] = DB::transaction(function () use ($sale, $customer, $company, $branch, $requestedPoints, $applicableAmount, $hasOffers, $eventKey, $reference, $channel, $effectiveAt) {
            $result = $this->redemptionService->redeem($customer, $company, $requestedPoints, $applicableAmount, [
                'branch' => $branch,
                'source_type' => Sale::class,
                'source_id' => $sale->id,
                'event_key' => $eventKey,
                'description' => "Canje de puntos en venta {$sale->sale_number} ({$channel})",
                'effective_at' => $effectiveAt,
                'metadata' => [
                    'channel' => $channel,
                    'origin' => 'online',
                    'external_reference' => $reference,
                    'sale_number' => $sale->sale_number,
                ],
                'is_offer' => $hasOffers,
            ]);

            // Nunca canjear más que el monto aplicable de la venta.
            if (bccomp((string) $result['redeemed_amount'], $applicableAmount, 4) > 0) {
                throw ValidationException::withMessages(['requested_points' => 'El canje excede el monto aplicable de la venta.']);
            }

            $method = PaymentMethod::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('type', PaymentMethod::TYPE_LOYALTY_POINTS)
                ->orderBy('id')
                ->first();

            if ($method === null) {
                throw ValidationException::withMessages(['payments' => 'El método de pago con puntos de fidelidad no está disponible.']);
            }

            $payment = SalePayment::create([
                'sale_id' => $sale->id,
                'cash_session_id' => null,
                'payment_method_id' => $method->id,
                'affects_cash_snapshot' => false,
                'created_by' => $sale->user_id,
                'amount' => $result['redeemed_amount'],
                'received_amount' => $result['redeemed_amount'],
                'change_amount' => 0,
                'cash_effect_amount' => 0,
                'reference' => null,
                'status' => SalePayment::STATUS_COMPLETED,
            ]);

            return [$result, $payment];
        });

        return [
            'redeemed' => true,
            'duplicate' => false,
            'redeemed_points' => (string) $result['redeemed_points'],
            'redeemed_amount' => (string) $result['redeemed_amount'],
            'balance_after' => (string) $result['balance_after'],
            'payment' => $payment,
        ];
    }

    private function paymentFor(Sale $sale, Company $company): ?SalePayment
    {
        return SalePayment::query()
            ->where('sale_id', $sale->id)
            ->whereHas('paymentMethod', fn ($query) => $query
                ->where('company_id', $company->id)
                ->where('type', PaymentMethod::TYPE_LOYALTY_POINTS))
            ->orderBy('id')
            ->first();
    }

    private function reference(string $externalReference): string
    {
        $reference = trim($externalReference);
        if ($reference === '' || strlen($reference) > 100 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $reference)) {
            throw ValidationException::withMessages(['external_reference' => 'La referencia externa del pedido es obligatoria y debe ser estable.']);
        }

        return $reference;
    }
}
