<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = Company::query()->find((int) session('active_company_id'));

        return $company !== null && $this->user()?->hasPermission('cotizaciones.crear', $company);
    }

    public function rules(): array
    {
        $company = Company::query()->find((int) session('active_company_id'));
        $canDiscount = $company && $this->user()?->hasPermission('pos.aplicar_descuento', $company);
        $canPrice = $company && $this->user()?->hasPermission('pos.cambiar_precio', $company);

        return [
            'customer_id' => ['nullable', 'integer'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => $canPrice ? ['nullable', 'numeric', 'gt:0'] : ['prohibited'],
            'items.*.discount' => $canDiscount ? ['nullable', 'numeric', 'min:0'] : ['prohibited'],
            'items.*.discount_type' => $canDiscount ? ['nullable', 'in:fixed,percentage'] : ['prohibited'],
            'discount_total' => $canDiscount ? ['nullable', 'numeric', 'min:0'] : ['prohibited'],
            'discount_total_type' => $canDiscount ? ['nullable', 'in:fixed,percentage'] : ['prohibited'],
        ];
    }
}
