<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditNoteExpirationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credit_note_expiration_policy' => ['required', 'in:none,30,60,90,custom'],
            'credit_note_custom_expiration_days' => ['required_if:credit_note_expiration_policy,custom', 'integer', 'min:1', 'max:3650'],
            'credit_note_consumer_final' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'credit_note_expiration_policy.in' => 'La política de vigencia seleccionada no es válida.',
            'credit_note_custom_expiration_days.required_if' => 'Debe indicar la cantidad de días para el plazo personalizado.',
            'credit_note_custom_expiration_days.min' => 'El plazo personalizado debe ser de al menos 1 día.',
            'credit_note_custom_expiration_days.max' => 'El plazo personalizado no puede superar 3650 días.',
        ];
    }
}