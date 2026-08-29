<?php

namespace App\Http\Requests;

use App\Models\LoyaltyRegistrationIncentive;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltyRegistrationIncentiveRequest extends FormRequest
{
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
        ];
    }
}
