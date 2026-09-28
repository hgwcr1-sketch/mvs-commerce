<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class PreparePurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::query()->find((int) session('active_company_id'));

        return $company !== null && $this->user()?->hasPermission('pedidos.preparar_compra', $company) === true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.allocated_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_id' => ['prohibited'],
            'unit_cost' => ['prohibited'],
            'unit_cost_snapshot' => ['prohibited'],
            'product_id' => ['prohibited'],
        ];
    }
}
