<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class QuickStoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'identification_type', 'identification', 'phone', 'mobile', 'email', 'customer_type'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }

        $normalized['customer_type'] ??= 'individual';

        if (isset($normalized['email'])) {
            $normalized['email'] = mb_strtolower($normalized['email']);
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'customer_type' => ['required', Rule::in(['individual', 'company'])],
            'identification_type' => ['nullable', Rule::in(['01', '02', '03', '04', '05'])],
            'identification' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'identification')
                    ->where('company_id', session('active_company_id')),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'company_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'points' => ['prohibited'],
            'credit_limit' => ['prohibited'],
            'credit_days' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del cliente es obligatorio.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',
            'customer_type.required' => 'Debe seleccionar el tipo de cliente.',
            'customer_type.in' => 'El tipo de cliente seleccionado no es válido.',
            'identification_type.in' => 'El tipo de identificación no es válido.',
            'identification.unique' => 'Ya existe un cliente con esta identificación en la empresa.',
            'identification.max' => 'La identificación no puede superar los 50 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 30 caracteres.',
            'mobile.max' => 'El celular no puede superar los 30 caracteres.',
            'email.email' => 'Debe ingresar un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 150 caracteres.',
            'company_id.prohibited' => 'El campo empresa no está permitido en la creación rápida.',
            'is_active.prohibited' => 'El campo estado no está permitido en la creación rápida.',
            'points.prohibited' => 'El campo puntos no está permitido en la creación rápida.',
            'credit_limit.prohibited' => 'El campo límite de crédito no está permitido en la creación rápida.',
            'credit_days.prohibited' => 'El campo días de crédito no está permitido en la creación rápida.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Revise la información ingresada.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
