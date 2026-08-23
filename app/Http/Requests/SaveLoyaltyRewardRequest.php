<?php

namespace App\Http\Requests;

use App\Models\LoyaltyReward;
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
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(LoyaltyReward::TYPES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'points_cost' => ['required', 'numeric', 'gt:0', 'lte:999999999999.9999', 'decimal:0,4'],
        ];
    }
}
