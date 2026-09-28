<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountReceivablePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::find((int) session('active_company_id'));
        return $company && $this->user()?->hasPermission('cuentas_cobrar.abonar', $company);
    }
    protected function prepareForValidation(): void
    {
        foreach (['reference', 'notes'] as $key) $this->merge([$key => ($value = trim((string) $this->input($key))) === '' ? null : $value]);
    }
    public function rules(): array
    {
        return ['amount' => ['required', 'numeric', 'gt:0'], 'payment_method_id' => ['required', 'integer'], 'cash_session_id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000']];
    }
}
