<?php

namespace App\Http\Requests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::lower(Str::slug((string) $this->input('code'), '_')),
            'is_active' => $this->boolean('is_active'),
            'affects_cash' => $this->boolean('affects_cash'),
            'requires_reference' => $this->boolean('requires_reference'),
            'allows_change' => $this->boolean('allows_change'),
        ]);
    }

    public function rules(): array
    {
        $companyId = session('active_company_id');

        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('payment_methods', 'code')->where('company_id', $companyId),
            ],
            'type' => ['required', Rule::in($this->allowedTypes())],
            'is_active' => ['boolean'],
            'affects_cash' => ['boolean'],
            'requires_reference' => ['boolean'],
            'allows_change' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Debe ingresar el nombre de la forma de pago.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'code.required' => 'Debe ingresar el código de la forma de pago.',
            'code.max' => 'El código no puede superar los 50 caracteres.',
            'code.regex' => 'El código sólo puede contener letras minúsculas, números y guiones bajos.',
            'code.unique' => 'Ya existe una forma de pago con ese código en la empresa.',
            'type.required' => 'Debe seleccionar el tipo de forma de pago.',
            'type.in' => 'El tipo de forma de pago seleccionado no es válido.',
            'sort_order.required' => 'Debe indicar el orden.',
            'sort_order.integer' => 'El orden debe ser un número entero.',
            'sort_order.min' => 'El orden no puede ser negativo.',
        ];
    }

    protected function allowedTypes(): array
    {
        return [
            PaymentMethod::TYPE_CASH,
            PaymentMethod::TYPE_CARD,
            PaymentMethod::TYPE_SINPE,
            PaymentMethod::TYPE_BANK_TRANSFER,
            PaymentMethod::TYPE_CREDIT,
            PaymentMethod::TYPE_LOYALTY_POINTS,
            PaymentMethod::TYPE_OTHER,
        ];
    }
}
