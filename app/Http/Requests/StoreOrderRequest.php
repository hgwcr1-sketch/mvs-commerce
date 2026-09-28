<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::query()->find((int) session('active_company_id'));

        return $company !== null && $this->user()?->hasPermission('pedidos.crear', $company);
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'items.*.request_note' => ['nullable', 'string', 'max:1000'],
            'company_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'], 'supplier_id' => ['prohibited'], 'status' => ['prohibited'],
            'items.*.stock_snapshot' => ['prohibited'], 'items.*.sale_price_snapshot' => ['prohibited'],
            'items.*.cost_snapshot' => ['prohibited'], 'items.*.last_cost_snapshot' => ['prohibited'],
            'items.*.allows_decimals_snapshot' => ['prohibited'],
            'items.*.approved_quantity' => ['prohibited'], 'items.*.item_status' => ['prohibited'],
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
