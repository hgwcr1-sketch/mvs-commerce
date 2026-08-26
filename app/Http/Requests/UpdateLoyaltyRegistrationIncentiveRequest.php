<?php

namespace App\Http\Requests;

use App\Models\LoyaltyRegistrationIncentive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyRegistrationIncentiveRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $setting = LoyaltyRegistrationIncentive::query()
            ->where('company_id', (int) session('active_company_id'))
            ->first();
        $defaults = [
            'minimum_purchase_enabled' => $setting?->minimum_purchase_enabled ?? false,
            'minimum_purchase_amount' => $setting?->minimum_purchase_amount ?? '0',
            'award_timing' => $setting?->award_timing ?? LoyaltyRegistrationIncentive::TIMING_REGISTRATION,
            'allow_on_first_purchase' => $setting?->allow_on_first_purchase ?? true,
            'bypass_redemption_minimum' => $setting?->bypass_redemption_minimum ?? false,
            'expiration_enabled' => $setting?->expiration_enabled ?? false,
            'expiration_days' => $setting?->expiration_days,
        ];

        $missing = [];
        foreach ($defaults as $key => $value) {
            if (! $this->request->has($key)) {
                $missing[$key] = $value;
            }
        }
        $this->merge($missing);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'benefit_type' => ['required', Rule::in(LoyaltyRegistrationIncentive::TYPES)],
            'benefit_value' => [
                'required',
                'numeric',
                'gt:0',
                'lte:999999999999999.9999',
                'decimal:0,4',
                Rule::when(
                    $this->input('benefit_type') === LoyaltyRegistrationIncentive::TYPE_PERCENTAGE,
                    ['lte:100'],
                ),
            ],
            'minimum_purchase_enabled' => ['required', 'boolean'],
            'minimum_purchase_amount' => [
                Rule::requiredIf($this->boolean('minimum_purchase_enabled')),
                'nullable',
                'numeric',
                'min:0',
                'lte:999999999999999.9999',
                'decimal:0,4',
                Rule::when($this->boolean('minimum_purchase_enabled'), ['gt:0']),
            ],
            'award_timing' => ['required', Rule::in(LoyaltyRegistrationIncentive::TIMINGS)],
            'allow_on_first_purchase' => ['required', 'boolean'],
            'bypass_redemption_minimum' => ['required', 'boolean'],
            'expiration_enabled' => ['required', 'boolean'],
            'expiration_days' => $this->boolean('expiration_enabled')
                ? ['required', 'integer', 'min:1', 'max:3650']
                : ['nullable', 'prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_enabled.boolean' => 'El estado del incentivo no es válido.',
            'benefit_type.required' => 'Seleccione el tipo de incentivo.',
            'benefit_type.in' => 'El tipo de incentivo no es válido.',
            'benefit_value.required' => 'Ingrese el valor del incentivo.',
            'benefit_value.numeric' => 'El valor del incentivo debe ser numérico.',
            'benefit_value.gt' => 'El valor del incentivo debe ser mayor que cero.',
            'benefit_value.lte' => $this->input('benefit_type') === LoyaltyRegistrationIncentive::TYPE_PERCENTAGE
                ? 'El porcentaje del incentivo no puede superar 100%.'
                : 'El valor del incentivo supera el máximo permitido.',
            'benefit_value.decimal' => 'El valor del incentivo admite como máximo cuatro decimales.',
            'minimum_purchase_enabled.boolean' => 'El estado de la compra mínima no es válido.',
            'minimum_purchase_amount.required' => 'Ingrese el monto mínimo de compra.',
            'minimum_purchase_amount.numeric' => 'El monto mínimo debe ser numérico.',
            'minimum_purchase_amount.min' => 'El monto mínimo no puede ser negativo.',
            'minimum_purchase_amount.gt' => 'El monto mínimo debe ser mayor que cero cuando la regla está activa.',
            'minimum_purchase_amount.lte' => 'El monto mínimo supera el máximo permitido.',
            'minimum_purchase_amount.decimal' => 'El monto mínimo admite como máximo cuatro decimales.',
            'award_timing.required' => 'Seleccione cuándo se concede el incentivo.',
            'award_timing.in' => 'El momento de concesión no es válido.',
            'allow_on_first_purchase.boolean' => 'La opción de primera compra no es válida.',
            'bypass_redemption_minimum.boolean' => 'La excepción al mínimo de canje no es válida.',
            'expiration_enabled.boolean' => 'El estado del vencimiento no es válido.',
            'expiration_days.required' => 'Ingrese los días de vigencia del incentivo.',
            'expiration_days.integer' => 'Los días de vigencia deben ser un número entero.',
            'expiration_days.min' => 'La vigencia debe ser de al menos un día.',
            'expiration_days.max' => 'La vigencia no puede superar 3650 días.',
            'expiration_days.prohibited' => 'No indique días de vigencia si el vencimiento está desactivado.',
        ];
    }
}
