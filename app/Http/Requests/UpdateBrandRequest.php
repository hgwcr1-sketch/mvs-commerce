<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
{
    $companyId = session('active_company_id');
    $brand = $this->route('marca');

    return [
        'name' => [
            'required',
            'string',
            'max:100',
        ],

        'slug' => [
            'required',
            'string',
            'max:255',
            Rule::unique('brands', 'slug')
                ->where('company_id', $companyId)
                ->ignore($brand),
        ],

        'logo' => ['nullable', 'string', 'max:255'],
        'website' => ['nullable', 'url', 'max:255'],
        'description' => ['nullable', 'string'],
        'sort_order' => ['nullable', 'integer', 'min:0'],
        'is_active' => ['boolean'],
    ];
}

}
