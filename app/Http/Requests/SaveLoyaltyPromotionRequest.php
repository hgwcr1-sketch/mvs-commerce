<?php

namespace App\Http\Requests;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class SaveLoyaltyPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $timezone = Company::query()->whereKey(session('active_company_id'))->value('timezone') ?: config('app.timezone');
        $values = [];
        foreach (['starts_at', 'ends_at'] as $field) {
            if ($this->filled($field)) {
                $values[$field] = CarbonImmutable::parse($this->input($field), $timezone)->utc()->format('Y-m-d H:i:s');
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
