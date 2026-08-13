<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSuspendedSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'price' => ['prohibited'],
            'tax' => ['prohibited'],
            'totals' => ['prohibited'],
            'status' => ['prohibited'],
            'suspension_number' => ['prohibited'],
            'checkout_token' => ['prohibited'],
            'recovery_token' => ['prohibited'],
            'suspended_sale_id' => ['prohibited'],
            'payments' => ['prohibited'],
            'items.*.price' => ['prohibited'],
            'items.*.sale_price' => ['prohibited'],
            'items.*.tax' => ['prohibited'],
            'items.*.tax_rate' => ['prohibited'],
            'items.*.subtotal' => ['prohibited'],
            'items.*.total' => ['prohibited'],
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
