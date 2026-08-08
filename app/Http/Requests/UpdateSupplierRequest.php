<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = session('active_company_id');
        $supplier = $this->route('proveedore');

        return [
            'supplier_type' => [
                'required',
                Rule::in(['individual', 'company']),
            ],

            'identification_type' => [
                'nullable',
                Rule::in(['01', '02', '03', '04', '05']),
            ],

            'identification' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'identification')
                    ->where(fn ($query) => $query->where('company_id', $companyId))
                    ->ignore($supplier?->id),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'commercial_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'contact_name' => [
                'nullable',
                'string',
                'max:150',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'mobile' => [
                'nullable',
                'string',
                'max:30',
            ],

            'email' => [
                'nullable',
                'email',
                'max:150',
            ],

            'country_id' => [
                'nullable',
                'exists:countries,id',
            ],

            'province_id' => [
                'nullable',
                'exists:provinces,id',
            ],

            'canton_id' => [
                'nullable',
                'exists:cantons,id',
            ],

            'district_id' => [
                'nullable',
                'exists:districts,id',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'credit_days' => [
    'nullable',
    'numeric',
    'min:0',
],

            'credit_limit' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'notes' => [
                'nullable',
                'string',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}
