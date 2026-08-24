<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoyaltyAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');

        return [
            'customer_id' => ['required', 'integer', Rule::exists(Customer::class, 'id')->where('company_id', $companyId)->where('is_active', true)],
            'direction' => ['required', Rule::in(['sumar', 'restar'])],
            'points' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,4'],
            'reason' => ['required', 'string', 'max:255'],
            'event_token' => ['required', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Seleccione un cliente.',
            'customer_id.exists' => 'El cliente no pertenece a la empresa actual o está inactivo.',
            'direction.required' => 'Indique si desea sumar o restar puntos.',
            'direction.in' => 'La dirección del ajuste no es válida.',
            'points.required' => 'Ingrese la cantidad de puntos del ajuste.',
            'points.numeric' => 'La cantidad de puntos debe ser numérica.',
            'points.gt' => 'La cantidad de puntos debe ser mayor que cero.',
            'points.max' => 'La cantidad de puntos excede el máximo permitido.',
            'points.decimal' => 'La cantidad admite como máximo cuatro decimales.',
            'reason.required' => 'El motivo del ajuste es obligatorio.',
            'reason.max' => 'El motivo no puede superar 255 caracteres.',
            'event_token.required' => 'Token de operación ausente; recargue el formulario.',
            'event_token.uuid' => 'El token de operación no es válido.',
        ];
    }
}
