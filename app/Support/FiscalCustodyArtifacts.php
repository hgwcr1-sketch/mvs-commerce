<?php

namespace App\Support;

use App\Models\FiscalDocumentCustody;

/**
 * Extracción de artefactos XML realmente custodiados de un comprobante
 * electrónico. Sin custodia: nada; nunca fabrica XML. Fuente única para
 * CE-MAIL, descarga individual y ZIP del portal.
 */
final class FiscalCustodyArtifacts
{
    /**
     * XML disponibles en la custodia: columnas reales descargadas del
     * proveedor (xml_signed, hacienda_response_xml) con respaldo a XML
     * embebidos legacy en payload/respuesta. '_enviado' solo existe si el
     * payload lo contiene: el proveedor no expone un XML enviado separado.
     *
     * Orden canónico único para todos los consumidores (ZIP, CE-MAIL,
     * descarga): _firmado → _enviado → _respuesta_hacienda.
     *
     * @return array<string, string>
     */
    public static function xmls(?FiscalDocumentCustody $custody): array
    {
        if ($custody === null) {
            return [];
        }

        $artifacts = [];

        $signed = self::nonEmptyString($custody->xml_signed)
            ?? (is_array($custody->response) ? self::walkForXml($custody->response) : null);

        if ($signed !== null) {
            $artifacts['_firmado'] = $signed;
        }

        $sent = is_array($custody->payload) ? self::walkForXml($custody->payload) : null;
        if ($sent !== null) {
            $artifacts['_enviado'] = $sent;
        }

        $hacienda = self::nonEmptyString($custody->hacienda_response_xml);
        if ($hacienda !== null) {
            $artifacts['_respuesta_hacienda'] = $hacienda;
        }

        return $artifacts;
    }

    public static function firstXml(?FiscalDocumentCustody $custody): ?string
    {
        $xmls = self::xmls($custody);

        return $xmls['_firmado'] ?? $xmls['_enviado'] ?? null;
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

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && str_contains($value, '<') ? $value : null;
    }
}
