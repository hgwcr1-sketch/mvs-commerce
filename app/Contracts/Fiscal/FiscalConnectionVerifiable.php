<?php

namespace App\Contracts\Fiscal;

use App\DTOs\Fiscal\FiscalConnectionResult;

/**
 * Capacidad opcional de verificar conexión SIN emitir documentos.
 * Separada de FiscalProviderInterface para no romper implementaciones.
 */
interface FiscalConnectionVerifiable
{
    /**
     * @param array{api_key?: string, api_secret?: string} $credentials
     */
    public function verifyConnection(array $credentials): FiscalConnectionResult;
}
