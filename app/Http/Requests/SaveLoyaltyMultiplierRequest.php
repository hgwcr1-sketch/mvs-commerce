<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLoyaltyMultiplierRequest extends FormRequest
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
        $companyId = (int) session('active_company_id');

        return [
            'name' => ['required', 'string', 'max:120'],
            'multiplier' => ['required', 'numeric', 'gt:0', 'lte:10', 'decimal:0,4'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'branch_id' => ['nullable', Rule::exists(Branch::class, 'id')->where('company_id', $companyId)],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
