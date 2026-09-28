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

    /**
     * Valores normalizados de la configuración de Fidelización para persistir en la fila única por empresa.
     */
    public function toValues(): array
    {
        $values = [
            'earning_percentage' => $this->validated('earning_percentage'),
            'redemption_minimum_enabled' => $this->boolean('redemption_minimum_enabled'),
            'redemption_minimum_amount' => $this->validated('redemption_minimum_amount') ?? '0',
            'earn_on_offers' => $this->boolean('earn_on_offers'),
            'birthday_enabled' => $this->boolean('birthday_enabled'),
            'birthday_points' => $this->validated('birthday_points'),
            'returning_customer_enabled' => $this->boolean('returning_customer_enabled'),
            'returning_customer_days' => $this->validated('returning_customer_days'),
            'returning_customer_points' => $this->validated('returning_customer_points'),
            'expiration_enabled' => $this->boolean('expiration_enabled'),
            'expiration_months' => $this->validated('expiration_months'),
        ];
        if (array_key_exists('is_active', $this->validated())) {
            $values['is_active'] = $this->boolean('is_active');
        }
        if ($this->filled('point_value')) {
            $values['point_value'] = $this->validated('point_value');
        }
        if ($this->filled('maximum_redemption_percent')) {
            $values['maximum_redemption_percent'] = $this->validated('maximum_redemption_percent');
        }

        return $values;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
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
            'expiration_enabled' => ['nullable', 'boolean'],
            'expiration_months' => $this->boolean('expiration_enabled')
                ? ['required', 'integer', 'min:1', 'max:120']
                : ['nullable', 'prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.boolean' => 'El estado de Fidelización no es válido.',
            'earning_percentage.required' => 'Ingrese el porcentaje de acumulación.',
            'earning_percentage.numeric' => 'El porcentaje de acumulación debe ser numérico.',
            'earning_percentage.gt' => 'El porcentaje de acumulación debe ser mayor que cero.',
            'earning_percentage.lte' => 'El porcentaje de acumulación no puede superar 100%.',
            'earning_percentage.decimal' => 'El porcentaje admite como máximo cuatro decimales.',
            'point_value.numeric' => 'El valor monetario del punto debe ser numérico.',
            'point_value.gt' => 'El valor monetario del punto debe ser mayor que cero.',
            'point_value.decimal' => 'El valor del punto admite como máximo cuatro decimales.',
            'redemption_minimum_enabled.boolean' => 'El estado del monto mínimo no es válido.',
            'redemption_minimum_amount.required' => 'Ingrese el monto monetario mínimo para utilizar puntos.',
            'redemption_minimum_amount.numeric' => 'El monto monetario mínimo debe ser numérico.',
            'redemption_minimum_amount.min' => 'El monto monetario mínimo no puede ser negativo.',
            'redemption_minimum_amount.gt' => 'El monto monetario mínimo debe ser mayor que cero.',
            'redemption_minimum_amount.decimal' => 'El monto mínimo admite como máximo cuatro decimales.',
            'maximum_redemption_percent.numeric' => 'El porcentaje máximo de canje debe ser numérico.',
            'maximum_redemption_percent.gt' => 'El porcentaje máximo de canje debe ser mayor que cero.',
            'maximum_redemption_percent.lte' => 'El porcentaje máximo de canje no puede superar 100%.',
            'maximum_redemption_percent.decimal' => 'El porcentaje máximo de canje admite como máximo cuatro decimales.',
            'earn_on_offers.boolean' => 'El estado de acumulación en ofertas no es válido.',
            'birthday_enabled.required' => 'El estado del bono de cumpleaños es obligatorio.',
            'birthday_enabled.boolean' => 'El estado del bono de cumpleaños no es válido.',
            'birthday_points.required' => 'El campo de puntos de cumpleaños es obligatorio.',
            'birthday_points.numeric' => 'Los puntos de cumpleaños deben ser numéricos.',
            'birthday_points.min' => 'Los puntos de cumpleaños no pueden ser negativos.',
            'birthday_points.gt' => 'Ingrese una cantidad mayor que cero para activar el bono.',
            'birthday_points.decimal' => 'Los puntos de cumpleaños admiten como máximo cuatro decimales.',
            'returning_customer_enabled.required' => 'El estado del bono por retorno es obligatorio.',
            'returning_customer_enabled.boolean' => 'El estado del bono por retorno no es válido.',
            'returning_customer_days.required' => 'El campo de días sin comprar es obligatorio.',
            'returning_customer_days.integer' => 'Los días de inactividad deben ser un número entero.',
            'returning_customer_days.min' => 'Ingrese al menos un día para activar el bono de retorno.',
            'returning_customer_days.max' => 'Los días de inactividad no pueden superar 3650.',
            'returning_customer_points.numeric' => 'Los puntos de retorno deben ser numéricos.',
            'returning_customer_points.min' => 'Los puntos de retorno no pueden ser negativos.',
            'returning_customer_points.gt' => 'Ingrese puntos mayores que cero para activar el bono de retorno.',
            'returning_customer_points.decimal' => 'Los puntos de retorno admiten como máximo cuatro decimales.',
            'returning_customer_points.required' => 'El campo de puntos por retorno es obligatorio.',
            'expiration_enabled.boolean' => 'El estado del vencimiento de puntos no es válido.',
            'expiration_months.required' => 'Ingrese la cantidad de meses de inactividad para el vencimiento.',
            'expiration_months.integer' => 'Los meses de inactividad deben ser un número entero.',
            'expiration_months.min' => 'Los meses de inactividad deben ser al menos 1.',
            'expiration_months.max' => 'Los meses de inactividad no pueden superar 120 (10 años).',
            'expiration_months.prohibited' => 'No puede indicar meses de inactividad con el vencimiento desactivado.',
        ];
    }
}
