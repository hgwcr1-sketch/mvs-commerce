<?php

namespace App\Support;

use App\Models\FiscalDocumentCustody;

/**
 * Extracción de artefactos XML realmente custodiados de un comprobante
 * electrónico. Sin custodia: nada; nunca fabrica XML.
 */
final class FiscalCustodyArtifacts
{
    /**
     * XML disponibles en la custodia: '_enviado' desde el payload y
     * '_firmado' desde la respuesta del proveedor/Hacienda.
     *
     * @return array<string, string>
     */
    public static function xmls(?FiscalDocumentCustody $custody): array
    {
        if ($custody === null) {
            return [];
        }

        $artifacts = [];

        $sent = is_array($custody->payload) ? self::walkForXml($custody->payload) : null;
        if ($sent !== null) {
            $artifacts['_enviado'] = $sent;
        }

        $signed = is_array($custody->response) ? self::walkForXml($custody->response) : null;
        if ($signed !== null) {
            $artifacts['_firmado'] = $signed;
        }

        return $artifacts;
    }

    public static function firstXml(?FiscalDocumentCustody $custody): ?string
    {
        $xmls = self::xmls($custody);

        return $xmls['_enviado'] ?? $xmls['_firmado'] ?? null;
    }

    public static function walkForXml(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && stripos((string) $key, 'xml') !== false && str_contains($value, '<')) {
                return $value;
            }
            if (is_array($value)) {
                $found = self::walkForXml($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
