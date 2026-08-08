<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
{
    $data = [
        'color' => $this->input('color', '#B1922D'),
        'is_active' => $this->boolean('is_active'),
    ];

    if ($this->filled('name')) {
        $data['slug'] = \Illuminate\Support\Str::slug($this->name);
    }

    $this->merge($data);
}

    public function rules(): array
{
    $companyId = session('active_company_id');

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
                ->where('company_id', $companyId),
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
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'slug.unique' => 'Ya existe una categoría con ese nombre.',
            'parent_id.exists' => 'La categoría padre seleccionada no existe.',
        ];
    }
}