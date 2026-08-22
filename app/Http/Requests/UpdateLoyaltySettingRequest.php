<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLoyaltySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'earning_percentage' => ['required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4'],
            'point_value' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'redemption_minimum_enabled' => ['nullable', 'boolean'],
            'redemption_minimum_amount' => [
                Rule::requiredIf($this->boolean('redemption_minimum_enabled')),
                'nullable', 'numeric', 'min:0', 'decimal:0,4',
                Rule::when($this->boolean('redemption_minimum_enabled'), ['gt:0']),
            ],
            'maximum_redemption_percent' => ['nullable', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4'],
            'earn_on_offers' => ['nullable', 'boolean'],
            'birthday_enabled' => ['required', 'boolean'],
            'birthday_points' => [
                'required', 'numeric', 'min:0', 'decimal:0,4',
                Rule::when($this->boolean('birthday_enabled'), ['gt:0']),
            ],
            'returning_customer_enabled' => ['required', 'boolean'],
            'returning_customer_days' => [
                'required', 'integer', 'min:0', 'max:3650',
                Rule::when($this->boolean('returning_customer_enabled'), ['min:1']),
            ],
            'returning_customer_points' => [
                'required', 'numeric', 'min:0', 'decimal:0,4',
                Rule::when($this->boolean('returning_customer_enabled'), ['gt:0']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'earning_percentage.required' => 'Ingrese el porcentaje de acumulación.',
            'earning_percentage.numeric' => 'El porcentaje de acumulación debe ser numérico.',
            'earning_percentage.gt' => 'El porcentaje de acumulación debe ser mayor que cero.',
            'earning_percentage.lte' => 'El porcentaje de acumulación no puede superar 100%.',
            'earning_percentage.decimal' => 'El porcentaje admite como máximo cuatro decimales.',
            'birthday_points.numeric' => 'Los puntos de cumpleaños deben ser numéricos.',
            'birthday_points.min' => 'Los puntos de cumpleaños no pueden ser negativos.',
            'birthday_points.gt' => 'Ingrese una cantidad mayor que cero para activar el bono.',
            'birthday_points.decimal' => 'Los puntos de cumpleaños admiten como máximo cuatro decimales.',
            'returning_customer_days.integer' => 'Los días de inactividad deben ser un número entero.',
            'returning_customer_days.min' => 'Ingrese al menos un día para activar el bono de retorno.',
            'returning_customer_days.max' => 'Los días de inactividad no pueden superar 3650.',
            'returning_customer_points.numeric' => 'Los puntos de retorno deben ser numéricos.',
            'returning_customer_points.min' => 'Los puntos de retorno no pueden ser negativos.',
            'returning_customer_points.gt' => 'Ingrese puntos mayores que cero para activar el bono de retorno.',
            'returning_customer_points.decimal' => 'Los puntos de retorno admiten como máximo cuatro decimales.',
        ];
    }
}
