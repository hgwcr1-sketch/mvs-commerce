<?php

namespace App\Http\Requests;

use App\Models\CashRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cashRegister = $this->route('cashRegister');

        return $cashRegister instanceof CashRegister
            && (int) $cashRegister->company_id === (int) session('active_company_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::lower(Str::slug((string) $this->input('code'), '_')),
            'is_active' => $this->boolean('is_active'),
            'is_default' => $this->boolean('is_default'),
        ]);
    }

    public function rules(): array
    {
        /** @var CashRegister $cashRegister */
        $cashRegister = $this->route('cashRegister');
        $companyId = (int) session('active_company_id');
        $branchId = (int) $this->input('branch_id');

        return [
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('is_active', true)),
            ],
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/',
                Rule::unique('cash_registers', 'code')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId))->ignore($cashRegister),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return (new StoreCashRegisterRequest())->messages();
    }
}
