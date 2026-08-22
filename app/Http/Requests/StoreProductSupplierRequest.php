<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreProductSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::query()->find((int) session('active_company_id'));

        return $company !== null
            && $this->user()?->hasPermission('productos.editar', $company) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_primary' => $this->boolean('is_primary'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');
        $routeProduct = $this->route('producto');
        $routeItem = $this->route('item');
        $productId = $routeProduct instanceof Product
            ? (int) $routeProduct->id
            : ($routeItem instanceof OrderItem ? (int) $routeItem->product_id : 0);
        $company = Company::query()->find($companyId);
        $canManageCosts = $company !== null
            && $this->user()?->hasPermission('compras.ordenes', $company) === true;

        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
                Rule::unique('product_suppliers', 'supplier_id')->where('product_id', $productId),
            ],
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'current_cost' => [Rule::prohibitedIf(! $canManageCosts), 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('is_primary') && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_primary', 'El proveedor principal debe tener una relación activa.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
