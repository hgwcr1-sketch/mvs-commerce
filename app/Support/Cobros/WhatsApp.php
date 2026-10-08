<?php

namespace App\Support\Cobros;

use App\Models\Company;
use App\Models\Customer;

/**
 * Enlace wa.me compartido por el recibo y la ficha de Mi Cartera (sin duplicar lógica).
 *
 * Nunca incluye cédulas ni documentos. Sin teléfono utilizable => null
 * (la UI muestra ➕ AGREGAR TELÉFONO o la explicación correspondiente).
 */
final class WhatsApp
{
    /** Teléfono internacional utilizable del cliente o null. */
    public static function phone(Company $company, Customer $customer): ?string
    {
        $raw = trim((string) ($customer->phone ?: $customer->mobile));
        if ($raw === '') {
            return null;
        }

        // Normalización: espacios, guiones y paréntesis fuera; nunca altera el dato almacenado.
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        // Único punto de internacionalización de CobrOS: país configurable por cliente/empresa
        // (phone_country_code / default_phone_country_code) con fallback CR (+506) encapsulado
        // aquí, preparado para sustituirse por configuración de país sin tocar vistas.
        $countryCode = preg_replace(
            '/\D/',
            '',
            (string) ($customer->phone_country_code ?: $company->default_phone_country_code ?: '506')
        );
        if ($countryCode === null || $countryCode === '') {
            $countryCode = '506';
        }

        // Ya trae nuestro prefijo internacional (ej. 50688133806 / +50688133806): no duplicar.
        if (str_starts_with($digits, $countryCode) && strlen($digits) >= strlen($countryCode) + 8) {
            return $digits;
        }

        // Número internacional con otro prefijo (>=10 dígitos): se respeta tal cual.
        if (strlen($digits) >= 10) {
            return $digits;
        }

        // Local CR (8 dígitos, ej. 88133806) => 50688133806.
        return $countryCode.$digits;
    }

    /** Enlace wa.me genero (saludo + contexto de la ficha), sin datos sensibles. */
    public static function url(Company $company, Customer $customer): ?string
    {
        $phone = self::phone($company, $customer);
        if ($phone === null) {
            return null;
        }

        $message = $company->trade_name."\n"
            .'Cliente: '.$customer->name."\n"
            .'Hola, le escribe su cobrador de MVS CobrOS.';

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }

    /**
     * Enlace wa.me del recibo: recibo, empresa, fecha, monto abonado, mora
     * pagada (solo si > 0) y saldo nuevo. Nunca expone reparto interno.
     */
    public static function receiptUrl(
        Company $company,
        Customer $customer,
        string $receiptNumber,
        string $amount,
        string $balanceAfter,
        string $paidAt,
        string $currencyCode,
        ?string $loanReference = null,
        ?string $nextDue = null,
        ?string $nextAmount = null,
        bool $loanCancelled = false,
        string $lateFeePaid = '0.0000'
    ): ?string {
        $phone = self::phone($company, $customer);
        if ($phone === null) {
            return null;
        }

        $sign = Money::symbol($currencyCode);
        $message = 'Recibo de cobro '.$receiptNumber."\n"
            .$company->trade_name."\n"
            .'Cliente: '.$customer->name."\n"
            .($loanReference !== null && $loanReference !== '' ? 'Préstamo: '.$loanReference."\n" : '')
            .'Fecha: '.$paidAt."\n"
            .'Monto abonado: '.$sign.Money::format($amount)."\n"
            .(bccomp($lateFeePaid, '0', 4) > 0 ? 'Mora pagada: '.$sign.Money::format($lateFeePaid)."\n" : '')
            .'Saldo nuevo: '.$sign.Money::format($balanceAfter);

        if ($loanReference !== null && $loanReference !== '') {
            $message .= $loanCancelled
                ? "\n".'Préstamo cancelado ✓'
                : (($nextDue !== null && $nextAmount !== null)
                    ? "\n".'Próximo pago: '.$nextDue."\n".'Monto pendiente: '.$sign.Money::format($nextAmount)
                    : '');
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }

    /**
     * Enlace wa.me de entrega de préstamo (mensaje sencillo, sin cédulas).
     * Null sin teléfono utilizable: la UI ofrece descargar el comprobante
     * y el flujo NO se rompe. El PDF se adjunta manualmente por WhatsApp.
     */
    public static function loanUrl(
        Company $company,
        Customer $customer,
        string $principal,
        string $total,
        int $installmentCount,
        string $installmentAmount,
        string $frequency,
        string $firstDue,
        string $reference
    ): ?string {
        $phone = self::phone($company, $customer);
        if ($phone === null) {
            return null;
        }

        $message = 'Hola '.$customer->name.".\n"
            ."Queda confirmado su préstamo.\n\n"
            .'Monto recibido: '.Money::human($principal, $company->currency)."\n"
            .'Total a pagar: '.Money::human($total, $company->currency)."\n"
            .'Pagos: '.$installmentCount.' de '.Money::human($installmentAmount, $company->currency)."\n"
            .'Frecuencia: '.$frequency."\n"
            .'Primer pago: '.$firstDue."\n"
            .'Referencia del préstamo: '.$reference."\n\n"
            .'Le enviamos su comprobante con las condiciones y calendario de pagos.';

        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }
}
