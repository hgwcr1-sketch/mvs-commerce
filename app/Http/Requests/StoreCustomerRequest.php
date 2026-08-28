<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Services\PhoneNumberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $phoneNumbers = app(PhoneNumberService::class);
        $phone = $phoneNumbers->normalizePhone($this->input('phone'));
        $countryCode = $phoneNumbers->normalizeCountryCode($this->input('phone_country_code'));

        if ($phone !== null && $countryCode === null) {
            $countryCode = Company::query()
                ->whereKey(session('active_company_id'))
                ->value('default_phone_country_code');
        }

        $this->merge([
            'phone' => $phone,
            'phone_country_code' => $phoneNumbers->normalizeCountryCode($countryCode),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [

            'customer_type' => 'required|in:individual,company',

            'identification_type' => 'nullable|in:01,02,03,04,05',

            'identification' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'identification')
                    ->where('company_id', session('active_company_id')),
            ],

            'name' => 'required|string|max:150',

            'commercial_name' => 'nullable|string|max:150',

            'taxpayer_name' => 'nullable|string|max:255',

            'phone_country_code' => ['nullable', 'regex:/^\+[1-9]\d{0,3}$/'],

            'phone' => ['nullable', 'regex:/^\d{4,15}$/'],

            'mobile' => 'nullable|string|max:30',

            'email' => 'nullable|email|max:150',

            'accepts_email_invoice' => 'nullable|boolean',

            'country_id' => 'nullable|exists:countries,id',

            'province_id' => 'nullable|exists:provinces,id',

            'canton_id' => 'nullable|exists:cantons,id',

            'district_id' => 'nullable|exists:districts,id',

            'address' => 'nullable|string',

            'notes' => 'nullable|string|max:2000',

            'credit_limit' => 'required|numeric|min:0',

            'credit_days' => 'nullable|integer|min:0',

            'credit_days' => 'nullable|integer|min:0',

            'price_level' => [
                'required',
                Rule::in([
                    'normal',
                    'wholesale',
                    'a',
                    'b',
                    'c',
                ]),
            ],

            'points' => 'nullable|integer|min:0',

            'birth_date' => 'nullable|date',

            'is_active' => 'nullable|boolean',

            'create_portal_access' => 'nullable|boolean',

        ];
    }

    /**
     * Mensajes de validación.
     */
    public function messages(): array
    {
        return [

            'customer_type.required' => 'Debe seleccionar el tipo de cliente.',
            'customer_type.in' => 'El tipo de cliente seleccionado no es válido.',

            'identification_type.in' => 'El tipo de identificación no es válido.',

            'identification.unique' => 'Ya existe un cliente con esta identificación.',
            'identification.max' => 'La identificación no puede superar los 50 caracteres.',

            'name.required' => 'El nombre del cliente es obligatorio.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'commercial_name.max' => 'El nombre comercial no puede superar los 150 caracteres.',

            'phone.max' => 'El teléfono no puede superar los 30 caracteres.',
            'mobile.max' => 'El celular no puede superar los 30 caracteres.',

            'email.email' => 'Debe ingresar un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 150 caracteres.',

            'country_id.exists' => 'El país seleccionado no es válido.',
            'province_id.exists' => 'La provincia seleccionada no es válida.',
            'canton_id.exists' => 'El cantón seleccionado no es válido.',
            'district_id.exists' => 'El distrito seleccionado no es válido.',

            'credit_limit.numeric' => 'El límite de crédito debe ser un número.',
            'credit_limit.min' => 'El límite de crédito no puede ser negativo.',

            'credit_days.integer' => 'Los días de crédito deben ser un número entero.',
            'credit_days.min' => 'Los días de crédito no pueden ser negativos.',

            'points.integer' => 'Los puntos deben ser un número entero.',
            'points.min' => 'Los puntos no pueden ser negativos.',

            'birth_date.date' => 'La fecha de nacimiento no es válida.',

        ];
    }
}
