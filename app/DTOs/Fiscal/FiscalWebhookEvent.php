<?php

namespace App\DTOs\Fiscal;

/**
 * Evento fiscal neutral proveniente de un callback del proveedor
 * (aceptación/rechazo y similares). Provider-agnostic: cada adapter
 * traduce su formato propio a este contrato antes de procesarlo.
 */
final class FiscalWebhookEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly ?string $providerDocumentId = null,
        public readonly ?string $clave = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['accepted', 'rejected'], true);
    }
}
