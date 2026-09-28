<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('active_company_id');
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
                    ->where('branch_id', $branchId)),
            ],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Debe seleccionar una sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no pertenece a la empresa activa o está inactiva.',
            'code.required' => 'Debe ingresar el código de la caja.',
            'code.max' => 'El código no puede superar los 50 caracteres.',
            'code.regex' => 'El código sólo puede contener letras minúsculas, números y guiones bajos.',
            'code.unique' => 'Ya existe una caja con ese código en la sucursal.',
            'name.required' => 'Debe ingresar el nombre de la caja.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
        ];
    }
}
