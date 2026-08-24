<?php

namespace App\Services\Loyalty;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMovement;
use App\Models\LoyaltySetting;
use App\Models\Sale;
use Illuminate\Validation\ValidationException;

/**
 * Acumulación de fidelización para ventas online confirmadas (F36).
 *
 * NO crea una segunda lógica de acumulación: reutiliza exactamente el pipeline
 * del POS (F08 porcentaje y monto elegible, F12 multiplicadores, F13
 * earn_on_offers vía LoyaltyOfferEligibilityService, bonos F10/F11) sobre la
 * misma cuenta central por empresa. La capa solo adapta el evento online:
 *
 * - exige una venta real confirmada (status completed);
 * - resuelve empresa activa y sucursal válida desde la propia venta;
 * - exige una referencia externa estable del pedido para construir un
 *   event_key determinista (`online_sale:{canal}:{referencia}:loyalty:earn`),
 *   de modo que reintentos del mismo evento no dupliquen puntos;
 * - deja trazabilidad de origen online en la metadata del movimiento.
 *
 * No toca inventario ni crea pedidos: eso corresponde al canal que confirma
 * la venta, según D013.
 */
class LoyaltyOnlineSaleService
{
    public const DEFAULT_CHANNEL = 'online';

    public function __construct(
        private readonly LoyaltyEarningService $earningService,
        private readonly LoyaltyOfferEligibilityService $offerEligibility,
        private readonly LoyaltyReturningCustomerService $returningCustomerService,
        private readonly LoyaltyBirthdayService $birthdayService,
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
        $reference = trim($externalReference);
        if ($reference === '' || strlen($reference) > 100 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $reference)) {
            throw ValidationException::withMessages(['external_reference' => 'La referencia externa del pedido es obligatoria y debe ser estable.']);
        }

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
}
