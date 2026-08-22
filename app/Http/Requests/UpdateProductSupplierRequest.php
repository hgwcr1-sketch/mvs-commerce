<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\ProductSupplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::query()->find((int) session('active_company_id'));
        $relation = $this->route('productSupplier');

        return $company !== null
            && $relation instanceof ProductSupplier
            && (int) $relation->company_id === (int) $company->id
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
        $company = Company::query()->find((int) session('active_company_id'));
        $canManageCosts = $company !== null
            && $this->user()?->hasPermission('compras.ordenes', $company) === true;

        return [
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'current_cost' => [Rule::prohibitedIf(! $canManageCosts), 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'is_primary' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'product_id' => ['prohibited'],
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
}
