<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');
        $company = $companyId > 0 ? Company::query()->find($companyId) : null;
        $user = $this->user();

        $canDiscount = $company !== null
            && $user !== null
            && $user->hasPermission('pos.aplicar_descuento', $company);

        $canOverridePrice = $company !== null
            && $user !== null
            && $user->hasPermission('pos.cambiar_precio', $company);

        return [
            'customer_id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'regex:/^\d+(?:\.\d{1,4})?$/',
            ],

            'items.*.discount' => $canDiscount
                ? ['nullable', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,4})?$/']
                : ['prohibited'],

            'items.*.discount_type' => $canDiscount
                ? ['nullable', 'in:fixed,percentage']
                : ['prohibited'],

            'items.*.unit_price' => $canOverridePrice
                ? ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,4})?$/']
                : ['prohibited'],

            'items.*.price' => ['prohibited'],
            'items.*.tax' => ['prohibited'],
            'items.*.subtotal' => ['prohibited'],
            'items.*.total' => ['prohibited'],
            'items.*.gross_total' => ['prohibited'],
            'items.*.discount_total' => ['prohibited'],
            'items.*.unit_cost' => ['prohibited'],
            'items.*.barcode' => ['prohibited'],
            'items.*.product_code' => ['prohibited'],
            'items.*.tax_rate' => ['prohibited'],
            'quote_number' => ['prohibited'],
            'status' => ['prohibited'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}