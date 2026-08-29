<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Services\PhoneNumberService;

class LoyaltyPortalDeliveryService
{
    public function build(Company $company, Customer $customer, string $username, string $plainPassword): array
    {
        $portalUrl = route('loyalty.customer.login', $company);

        $phones = app(PhoneNumberService::class);
        $rawPhone = $customer->phone ?: $customer->mobile;
        $countryCode = $customer->phone_country_code ?: $company->default_phone_country_code;
        $whatsappPhone = $phones->forWhatsApp($countryCode, $rawPhone);
        $whatsappUrl = null;
        $message = $this->message($company, $portalUrl, $username, $plainPassword);

        if ($whatsappPhone) {
            $whatsappUrl = 'https://wa.me/' . $whatsappPhone . '?text=' . rawurlencode($message);
        }

        $copyText = $message;

        return [
            'portal_url' => $portalUrl,
            'username' => $username,
            'password' => $plainPassword,
            'whatsapp_phone' => $whatsappPhone,
            'whatsapp_url' => $whatsappUrl,
            'copy_text' => $copyText,
            'message' => $message,
        ];
    }

    private function message(Company $company, string $portalUrl, string $username, string $plainPassword): string
    {
        return 'Hola, tu acceso al Portal de Clientes de ' . $company->trade_name . ':' . "\n"
            . 'URL: ' . $portalUrl . "\n"
            . 'Usuario: ' . $username . "\n"
            . 'Contraseña temporal: ' . $plainPassword . "\n"
            . 'Deberás cambiarla al ingresar por primera vez.';
    }
}
