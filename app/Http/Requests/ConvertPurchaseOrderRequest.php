<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class ConvertPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
{
    $company = Company::query()->find((int) session('active_company_id'));

    return $company !== null
        && $this->user()?->hasPermission('compras.ordenes', $company) === true
        && $this->user()?->hasPermission('compras.crear', $company) === true;
}

    public function rules(): array
    {
        return [
            'payment_type' => ['required', 'in:cash,credit'],
            'due_date' => ['nullable', 'date', 'required_if:payment_type,credit'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'supplier_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'unit_cost' => ['prohibited'],
            'unit_cost_snapshot' => ['prohibited'],
            'product_id' => ['prohibited'],
        ];
    }
}
