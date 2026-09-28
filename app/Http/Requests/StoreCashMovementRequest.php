<?php

namespace App\Http\Requests;

use App\Models\CashMovement;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::find((int) session('active_company_id'));

        return $company !== null
            && $this->user() !== null
            && $this->user()->hasPermission('caja.movimientos', $company);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
            'notes' => ($notes = trim((string) $this->input('notes'))) === '' ? null : $notes,
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                CashMovement::TYPE_ENTRY,
                CashMovement::TYPE_EXIT,
                CashMovement::TYPE_WITHDRAWAL,
            ])],
            'amount' => ['required', 'integer', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'request_token' => ['required', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de movimiento no es válido.',
            'amount.required' => 'Debe indicar el monto.',
            'amount.integer' => 'El monto en colones debe ser un número entero.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'reason.required' => 'Debe indicar el motivo del movimiento.',
            'request_token.uuid' => 'La solicitud del movimiento no es válida.',
        ];
    }
}
