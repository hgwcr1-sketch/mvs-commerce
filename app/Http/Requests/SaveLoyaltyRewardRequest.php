<?php

namespace App\Http\Requests;

use App\Models\LoyaltyReward;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLoyaltyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mode = $this->input('availability_mode');
        $companyId = (int) session('active_company_id');

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LoyaltyReward::TYPES)],
            'availability_mode' => ['required', 'string', Rule::in(LoyaltyReward::MODES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'points_cost' => ['required', 'numeric', 'gt:0', 'lte:999999999999.9999', 'decimal:0,4'],
            'stock_quantity' => $mode === LoyaltyReward::MODE_LIMITED
                ? ['required', 'numeric', 'gt:0', 'lte:99999999999.9999', 'decimal:0,4']
                : ['nullable', 'prohibited'],
            'product_id' => $mode === LoyaltyReward::MODE_PRODUCT
                ? ['required', 'integer', Rule::exists(Product::class, 'id')->where('company_id', $companyId)->where('is_active', true)]
                : ['nullable', 'prohibited'],
        ];
    }
}
