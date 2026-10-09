<?php

namespace App\Services\Billing;

use App\Models\BillingPaymentRecord;
use App\Services\Billing\Contracts\PaymentEventTypes;
use App\Services\Billing\DTOs\PaymentEvent;
use App\Services\Billing\DTOs\PaymentRequest;
use App\Services\Billing\Exceptions\PaymentProviderUnavailableException;

/**
 * BLOQUE 17B-1 — servicio principal del motor de pagos MVS.
 *
 * Orquesta la creación y el procesamiento de eventos de pago con:
 *  - idempotencia por proveedor + identificador externo;
 *  - aislamiento por producto/empresa;
 *  - entrega de pagos confirmados vía PaymentDispatcher;
 *  - sin simular éxito: si el proveedor no está disponible, lanza.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentProviderRegistry $registry,
        private readonly PaymentDispatcher $dispatcher,
    ) {
    }

    /**
     * Crea un pago a través del proveedor configurado para el producto+país
     * y registra el registro idempotente. Devuelve el evento normalizado.
     *
     * @throws PaymentProviderUnavailableException
     */
    public function createPayment(PaymentRequest $request, string $countryIso): PaymentEvent
    {
        $provider = $this->registry->forProduct($request->product, $countryIso);

        $event = $provider->createPayment($request);

        $this->persistRecord($event, $request->metadata);

        return $event;
    }

    /**
     * Procesa un evento normalizado entrante (ej: de un webhook futuro o de
     * una confirmación manual). Es idempotente: el mismo proveedor+externalId
     * no se procesa dos veces. Los eventos confirmados se entregan a los
     * handlers del módulo consumidor.
     */
    public function processEvent(PaymentEvent $event): BillingPaymentRecord
    {
        $existing = $this->findByExternalId($event->provider, $event->externalId);

        if ($existing !== null) {
            if ($existing->status === BillingPaymentRecord::STATUS_CONFIRMED
                && $event->type === PaymentEventTypes::CONFIRMED) {
                return $existing;
            }

            $this->updateRecordFromEvent($existing, $event);
            $record = $existing;
        } else {
            $record = $this->persistRecord($event);
        }

        if ($event->isConfirmed()) {
            $this->dispatcher->dispatch($event);
        }

        return $record;
    }

    /** Busca el registro idempotente por proveedor + identificador externo. */
    public function findByExternalId(string $provider, string $externalId): ?BillingPaymentRecord
    {
        return BillingPaymentRecord::query()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->first();
    }

    private function persistRecord(PaymentEvent $event, array $metadata = []): BillingPaymentRecord
    {
        return BillingPaymentRecord::create([
            'provider' => $event->provider,
            'external_id' => $event->externalId,
            'product' => $event->product,
            'company_id' => $event->companyId !== '' ? $event->companyId : null,
            'contract_reference' => $event->contractReference !== '' ? $event->contractReference : null,
            'currency' => $event->currency,
            'amount' => $event->amount,
            'period_year' => $event->periodYear,
            'period_month' => $event->periodMonth,
            'status' => $event->type === PaymentEventTypes::CONFIRMED
                ? BillingPaymentRecord::STATUS_CONFIRMED
                : BillingPaymentRecord::STATUS_PENDING,
            'raw_payload' => $event->rawPayload !== [] ? $event->rawPayload : ($metadata ?: null),
            'confirmed_at' => $event->isConfirmed() ? now() : null,
        ]);
    }

    private function updateRecordFromEvent(BillingPaymentRecord $record, PaymentEvent $event): void
    {
        $status = match ($event->type) {
            PaymentEventTypes::CONFIRMED => BillingPaymentRecord::STATUS_CONFIRMED,
            PaymentEventTypes::FAILED => BillingPaymentRecord::STATUS_FAILED,
            PaymentEventTypes::REFUNDED => BillingPaymentRecord::STATUS_REFUNDED,
            default => $record->status,
        };

        $record->status = $status;
        $record->raw_payload = $event->rawPayload !== [] ? $event->rawPayload : $record->raw_payload;

        if ($event->isConfirmed() && $record->confirmed_at === null) {
            $record->confirmed_at = now();
        }

        $record->save();
    }
}
