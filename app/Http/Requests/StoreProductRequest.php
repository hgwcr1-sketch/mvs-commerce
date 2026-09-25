<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Determinar si el usuario está autorizado.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación.
     */
    public function rules(): array
    {
        $companyId = session('active_company_id');

        return [

            'category_id' => [
                'required',
                Rule::exists('product_categories', 'id')
                    ->where('company_id', $companyId),
            ],

            'brand_id' => [
                'nullable',
                Rule::exists('brands', 'id')
                    ->where('company_id', $companyId),
            ],

            'unit_id' => [
                'required',
                Rule::exists('units', 'id')
                    ->where('company_id', $companyId),
            ],

            'style_id' => [
                'nullable',
                Rule::exists('styles', 'id')
                    ->where('company_id', $companyId),
            ],

            'size_id' => [
                'nullable',
                Rule::exists('sizes', 'id')
                    ->where('company_id', $companyId),
            ],

            'color_id' => [
                'nullable',
                Rule::exists('colors', 'id')
                    ->where('company_id', $companyId),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'internal_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'internal_code')
                    ->where('company_id', $companyId),
            ],

            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->where('company_id', $companyId),
            ],

            'product_type' => [
                'required',
                Rule::in([
                    'product',
                    'service',
                    'combo',
                ]),
            ],

            'cabys_code' => [
                'nullable',
                'string',
                'max:20',
            ],

            'short_description' => [
                'nullable',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'cost' => [
                'required',
                'numeric',
                'min:0',
            ],

            'sale_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'wholesale_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'special_price' => [
    'nullable',
    'numeric',
    'min:0',
],

            'price_a' => [
    'nullable',
    'numeric',
    'min:0',
],

'price_b' => [
    'nullable',
    'numeric',
    'min:0',
],

'price_c' => [
    'nullable',
    'numeric',
    'min:0',
],

            'stock' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'track_inventory' => [
                'nullable',
                'boolean',
            ],

            'minimum_stock' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'maximum_stock' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'allow_negative_stock' => [
                'nullable',
                'boolean',
            ],

            'fiscal_profile_id' => [
                'nullable',
                'integer',
                Rule::exists('fiscal_profiles', 'id'),
                'required_without:tax_rate',
            ],

            'tax_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'required_without:fiscal_profile_id',
            ],

            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

        ];
    }

    /**
     * Mensajes personalizados.
     */
    public function messages(): array
    {
        return [

            'name.required' =>
                'Debe ingresar el nombre del producto.',

            'internal_code.required' =>
                'Debe ingresar el código interno.',

            'internal_code.unique' =>
                'El código interno ya existe en esta empresa.',

            'barcode.unique' =>
                'El código de barras ya existe en esta empresa.',

            'category_id.required' =>
                'Debe seleccionar una categoría.',

            'category_id.exists' =>
                'La categoría seleccionada no pertenece a la empresa activa.',

            'brand_id.exists' =>
                'La marca seleccionada no pertenece a la empresa activa.',

            'unit_id.required' =>
                'Debe seleccionar una unidad de medida.',

            'unit_id.exists' =>
                'La unidad seleccionada no pertenece a la empresa activa.',

            'style_id.exists' =>
                'El estilo seleccionado no pertenece a la empresa activa.',

            'size_id.exists' =>
                'La talla seleccionada no pertenece a la empresa activa.',

            'color_id.exists' =>
                'El color seleccionado no pertenece a la empresa activa.',

            'cost.required' =>
                'Debe ingresar el costo.',

            'sale_price.required' =>
                'Debe ingresar el precio de venta.',

            'tax_rate.required_without' =>
                'Debe seleccionar el tratamiento fiscal.',

            'fiscal_profile_id.required_without' =>
                'Debe seleccionar el tratamiento fiscal.',

            'image.image' =>
                'El archivo debe ser una imagen.',

            'image.mimes' =>
                'La imagen debe ser JPG, JPEG, PNG o WEBP.',

            'image.max' =>
                'La imagen no puede superar los 5 MB.',

        ];
    }
}