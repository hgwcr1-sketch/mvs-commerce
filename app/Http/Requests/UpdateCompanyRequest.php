<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
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

            'trade_name' => 'required|string|max:150',

            'legal_name' => 'nullable|string|max:200',

            'identification_type' => 'nullable|in:01,02,03,04,05',

            'identification_number' => 'nullable|string|max:50',

            'phone' => 'nullable|string|max:30',

            'email' => 'nullable|email|max:150',

            'country_id' => 'nullable|exists:countries,id',

            'province_id' => 'nullable|exists:provinces,id',

            'canton_id' => 'nullable|exists:cantons,id',

            'district_id' => 'nullable|exists:districts,id',

            'address' => 'nullable|string',

            'logo' => 'nullable|image|max:2048',

            'currency' => 'nullable|string|max:10',

            'timezone' => 'nullable|string|max:100',

        ];
    }

    /**
     * Mensajes de validación.
     */
    public function messages(): array
    {
        return [

            'trade_name.required' => 'El nombre comercial de la empresa es obligatorio.',
            'trade_name.max' => 'El nombre comercial no puede superar los 150 caracteres.',

            'legal_name.max' => 'La razón social no puede superar los 200 caracteres.',

            'identification_type.in' => 'El tipo de identificación no es válido.',
            'identification_number.max' => 'La identificación no puede superar los 50 caracteres.',

            'phone.max' => 'El teléfono no puede superar los 30 caracteres.',

            'email.email' => 'Debe ingresar un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 150 caracteres.',

            'country_id.exists' => 'El país seleccionado no es válido.',
            'province_id.exists' => 'La provincia seleccionada no es válida.',
            'canton_id.exists' => 'El cantón seleccionado no es válido.',
            'district_id.exists' => 'El distrito seleccionado no es válido.',

            'logo.image' => 'El logo debe ser una imagen válida.',
            'logo.max' => 'El logo no puede superar los 2 MB.',

        ];
    }
}