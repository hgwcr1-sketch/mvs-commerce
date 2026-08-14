<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StartCashClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::find((int) session('active_company_id'));
        return $company !== null && $this->user()?->hasPermission('caja.cerrar', $company) === true;
    }

    public function rules(): array
    {
        return ['request_token' => ['required', 'uuid']];
    }
}
