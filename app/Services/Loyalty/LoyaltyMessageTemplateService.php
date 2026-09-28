<?php

namespace App\Services\Loyalty;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyMessageTemplate;
use App\Services\PhoneNumberService;

class LoyaltyMessageTemplateService
{
    public const TYPES = ['birthday', 'inactive_30', 'inactive_60', 'inactive_90'];

    public const DEFAULTS = [
        'birthday' => 'Hola {nombre} 👋 ¡Feliz cumpleaños! Actualmente tienes {puntos} puntos disponibles.',
        'inactive_30' => 'Hola {nombre} 👋 Hace {dias_sin_comprar} días que no te vemos. Tienes {puntos} puntos disponibles.',
        'inactive_60' => 'Hola {nombre} 👋 Han pasado {dias_sin_comprar} días desde tu última compra. Tienes {puntos} puntos.',
        'inactive_90' => 'Hola {nombre} 👋 Queremos volver a verte. Han pasado {dias_sin_comprar} días y tienes {puntos} puntos.',
    ];

    public function __construct(private readonly PhoneNumberService $phoneNumbers) {}

    public function templates(int $companyId): array
    {
        $stored = LoyaltyMessageTemplate::query()->where('company_id', $companyId)->pluck('body', 'opportunity_type');

        return collect(self::DEFAULTS)->map(fn ($body, $type) => $stored[$type] ?? $body)->all();
    }

    public function message(int $companyId, string $type, Customer $customer, int $days, ?string $branch): string
    {
        $template = $this->templates($companyId)[$type] ?? '';
        $values = [
            '{nombre}' => $customer->name,
            '{dias_sin_comprar}' => (string) $days,
            '{puntos}' => number_format((float) ($customer->loyalty_balance ?? 0), 2, '.', ''),
            '{sucursal}' => $branch ?: 'nuestra tienda',
        ];

        return strtr($template, $values);
    }

    public function whatsappUrl(Company $company, Customer $customer, string $message): ?string
    {
        if (! $company->whatsapp_enabled) {
            return null;
        }

        $number = $this->phoneNumbers->forCustomer($customer);

        return $number ? 'https://wa.me/'.$number.'?text='.rawurlencode($message) : null;
    }
}
