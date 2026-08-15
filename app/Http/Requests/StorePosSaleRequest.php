<?php

namespace App\Http\Requests;

use App\Models\Company;
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
        $companyId = (int) session('active_company_id');

        $company = $companyId > 0
            ? Company::query()->find($companyId)
            : null;

        $user = $this->user();

        $canDiscount = $company !== null
            && $user !== null
            && $user->hasPermission('pos.aplicar_descuento', $company);

        $canOverridePrice = $company !== null
            && $user !== null
            && $user->hasPermission('pos.cambiar_precio', $company);

        return [
            'checkout_token' => ['required', 'uuid'],
            'cash_session_id' => ['nullable', 'integer'],
            'suspended_sale_id' => ['nullable', 'integer', 'required_with:recovery_token'],
            'recovery_token' => ['nullable', 'uuid', 'required_with:suspended_sale_id'],
            'customer_id' => ['nullable', 'integer'],

            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer', 'distinct'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+$/'],
            'payments.*.received_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+$/'],
            'payments.*.reference' => ['nullable', 'string', 'max:150'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'regex:/^\d+(?:\.\d{1,4})?$/',
            ],

            'items.*.discount' => $canDiscount
                ? [
                    'nullable',
                    'numeric',
                    'min:0',
                    'regex:/^\d+(?:\.\d{1,4})?$/',
                ]
                : ['prohibited'],

            'items.*.discount_type' => $canDiscount
                ? [
                    'nullable',
                    'in:fixed,percentage',
                ]
                : ['prohibited'],

            'discount_total' => $canDiscount
                ? [
                    'nullable',
                    'numeric',
                    'min:0',
                    'regex:/^\d+(?:\.\d{1,4})?$/',
                ]
                : ['prohibited'],

            'discount_total_type' => $canDiscount
                ? [
                    'nullable',
                    'in:fixed,percentage',
                ]
                : ['prohibited'],

            'items.*.unit_price' => $canOverridePrice
                ? [
                    'nullable',
                    'numeric',
                    'gt:0',
                    'regex:/^\d+(?:\.\d{1,4})?$/',
                ]
                : ['prohibited'],

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
            'payments.*.change_amount' => ['prohibited'],
            'payment_method_id' => ['prohibited'],
            'received_amount' => ['prohibited'],
            'sale_number' => ['prohibited'],
            'status' => ['prohibited'],
            'affects_cash_snapshot' => ['prohibited'],
            'cash_effect_amount' => ['prohibited'],
            'payments.*.affects_cash_snapshot' => ['prohibited'],
            'payments.*.cash_effect_amount' => ['prohibited'],

            'items.*.price' => ['prohibited'],
            'items.*.sale_price' => ['prohibited'],
            'items.*.cost' => ['prohibited'],
            'items.*.tax' => ['prohibited'],
            'items.*.tax_rate' => ['prohibited'],
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