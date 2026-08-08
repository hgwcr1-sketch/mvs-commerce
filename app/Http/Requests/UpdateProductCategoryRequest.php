<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'slug' => \Illuminate\Support\Str::slug($this->name),
            ]);
        }
    }

    public function rules(): array
{
    $companyId = session('active_company_id');
    $category = $this->route('categoria');

    return [
        'parent_id' => [
            'nullable',
            Rule::exists('product_categories', 'id')
                ->where('company_id', $companyId),
        ],

        'name' => [
            'required',
            'string',
            'max:100',
        ],

        'slug' => [
            'required',
            'max:150',
            Rule::unique('product_categories', 'slug')
                ->where('company_id', $companyId)
                ->ignore($category),
        ],

        'icon' => ['nullable', 'string', 'max:50'],
        'color' => ['required', 'string', 'max:7'],
        'image' => ['nullable', 'string'],
        'sort_order' => ['nullable', 'integer', 'min:0'],
        'is_active' => ['boolean'],
    ];
}

    public function messages(): array
    {
        return [
            'name.required' => 'Debe ingresar el nombre de la categoría.',
            'slug.unique' => 'Ya existe otra categoría con ese nombre.',
            'parent_id.exists' => 'La categoría padre seleccionada no existe.',
        ];
    }
}