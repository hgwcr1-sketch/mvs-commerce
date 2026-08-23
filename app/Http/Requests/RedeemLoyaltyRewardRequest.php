<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\LoyaltyReward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RedeemLoyaltyRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');

        return [
            'customer_id' => ['required', 'integer', Rule::exists(Customer::class, 'id')->where('company_id', $companyId)->where('is_active', true)],
            'reward_id' => ['required', 'integer', Rule::exists(LoyaltyReward::class, 'id')->where('company_id', $companyId)],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'El cliente no pertenece a la empresa actual o está inactivo.',
            'reward_id.exists' => 'El premio no pertenece a la empresa actual.',
        ];
    }
}
