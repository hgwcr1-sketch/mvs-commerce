<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCashClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::find((int) session('active_company_id'));
        return $company !== null && $this->user()?->hasPermission('caja.cerrar', $company) === true;
    }

    protected function prepareForValidation(): void
    {
        $payments = collect($this->input('payments', []))->map(function ($payment) {
            if (! is_array($payment)) return $payment;
            $payment['reference'] = ($value = trim((string) ($payment['reference'] ?? ''))) === '' ? null : $value;
            $payment['notes'] = ($value = trim((string) ($payment['notes'] ?? ''))) === '' ? null : $value;
            return $payment;
        })->all();
        $this->merge(['payments' => $payments, 'closing_notes' => ($notes = trim((string) $this->input('closing_notes'))) === '' ? null : $notes]);
    }

    public function rules(): array
    {
        return [
            'request_token' => ['required', 'uuid'],
            'denominations' => ['required', 'array'],
            'denominations.*' => ['required', 'integer', 'min:0'],
            'payments' => ['present', 'array'],
            'payments.*' => ['required', 'array'],
            'payments.*.reported_amount' => ['required', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:150'],
            'payments.*.notes' => ['nullable', 'string', 'max:5000'],
            'closing_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'denominations.required' => 'Debe completar el conteo de denominaciones.',
            'denominations.*.integer' => 'Cada cantidad debe ser un número entero.',
            'denominations.*.min' => 'Las cantidades no pueden ser negativas.',
            'payments.*.reported_amount.integer' => 'Los montos reportados deben ser enteros en colones.',
            'payments.*.reported_amount.min' => 'Los montos reportados no pueden ser negativos.',
        ];
    }
}
