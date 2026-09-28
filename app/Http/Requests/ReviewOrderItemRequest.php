<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReviewOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'approved_quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'supplier_id' => ['nullable', 'integer'],
            'review_note' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['prohibited'],
            'requested_quantity' => ['prohibited'],
            'item_status' => ['prohibited'],
            'stock_snapshot' => ['prohibited'],
            'sale_price_snapshot' => ['prohibited'],
            'cost_snapshot' => ['prohibited'],
            'last_cost_snapshot' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('approved_quantity') || ! is_numeric($this->input('approved_quantity'))) {
                return;
            }

            $approved = (float) $this->input('approved_quantity');
            if ($approved > 0 && ! $this->filled('supplier_id')) {
                $validator->errors()->add('supplier_id', 'Debe seleccionar un proveedor para una cantidad aprobada.');
            }
            if ($approved === 0.0 && $this->filled('supplier_id')) {
                $validator->errors()->add('supplier_id', 'Una línea rechazada no puede tener proveedor.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
