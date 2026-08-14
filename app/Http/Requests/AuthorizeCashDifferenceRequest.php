<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class AuthorizeCashDifferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::find((int) session('active_company_id'));
        return $company !== null && $this->user()?->hasPermission('caja.autorizar_diferencia', $company) === true;
    }

    public function rules(): array
    {
        return ['confirmation' => ['accepted']];
    }
}
