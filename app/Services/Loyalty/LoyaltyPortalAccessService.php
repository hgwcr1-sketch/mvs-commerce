<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\Core\PortalAccessService;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

/**
 * Acceso seguro al portal del cliente de Fidelización (F33/F34).
 *
 * Extiende el servicio genérico de acceso a portal añadiendo funcionalidad
 * específica de Fidelidad (QR, URL de fidelidad).
 */
class LoyaltyPortalAccessService extends PortalAccessService
{
    /** Genera (o regenera) el acceso activo del cliente con URL de Fidelidad. */
    public function generate(Customer $customer, Company $company, ?User $user): array
    {
        $result = parent::generate($customer, $company, $user);
        $result['url'] = $this->loyaltyUrl($result['token']);

        return $result;
    }

    public function loyaltyUrl(string $token): string
    {
        return route('loyalty.portal.access', ['token' => $token]);
    }

    /**
     * F33: el QR se genera localmente (chillerlan/php-qrcode) y codifica únicamente
     * el enlace seguro F34. Nunca se usan APIs externas: enviarían el token a terceros.
     */
    public function qrSupported(): bool
    {
        return class_exists(QRCode::class);
    }

    /**
     * SVG del QR para un enlace seguro ya generado. El token solo existe en claro
     * en el momento de la generación, por lo que el QR se entrega junto al enlace
     * y nunca se persiste. Salida SVG vectorial: impresión nítida y escala responsive.
     */
    public function qrSvg(string $url): string
    {
        if (! $this->qrSupported()) {
            throw new RuntimeException('La generación local de QR no está disponible.');
        }

        $svg = (new QRCode(new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::H,
            'scale' => 6,
            'svgAddXmlHeader' => false,
            'outputBase64' => false,
        ])))->render($url);

        if (! is_string($svg)) {
            throw new RuntimeException('No fue posible generar el código QR.');
        }

        return $svg;
    }
}
