<?php

namespace App\Services\Sales;

use App\Models\CreditNote;

/**
 * Resultado de la emisión de una Nota de Crédito.
 *
 * Transporta temporalmente el código de aplicación en texto plano SOLO
 * durante la respuesta de la emisión inicial. El repository persiste
 * únicamente el hash; el plaintext no debe persistirse ni reproductirse.
 */
final class IssuedCreditNoteResult
{
    public function __construct(
        public readonly CreditNote $creditNote,
        public readonly ?string $applicationCode,
    ) {}
}