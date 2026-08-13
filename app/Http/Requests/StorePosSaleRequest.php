<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checkout_token' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer'],
            'payment_method_id' => ['required', 'integer'],
            'received_amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+$/'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'price' => ['prohibited'],
            'cost' => ['prohibited'],
            'tax' => ['prohibited'],
            'discount' => ['prohibited'],
            'totals' => ['prohibited'],
            'stock' => ['prohibited'],
            'change_amount' => ['prohibited'],
            'sale_number' => ['prohibited'],
            'status' => ['prohibited'],
            'items.*.price' => ['prohibited'],
            'items.*.sale_price' => ['prohibited'],
            'items.*.cost' => ['prohibited'],
            'items.*.tax' => ['prohibited'],
            'items.*.tax_rate' => ['prohibited'],
            'items.*.discount' => ['prohibited'],
            'items.*.subtotal' => ['prohibited'],
            'items.*.total' => ['prohibited'],
            'items.*.stock' => ['prohibited'],
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
