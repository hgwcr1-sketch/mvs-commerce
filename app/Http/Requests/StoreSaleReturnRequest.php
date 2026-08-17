<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = collect($this->input('items', []))
            ->filter(function ($item) {
                if (! is_array($item)) {
                    return false;
                }

                $quantity = $item['quantity'] ?? null;

                return $quantity !== null
                    && trim((string) $quantity) !== '';
            })
            ->values()
            ->all();

        $this->merge([
            'items' => $items,
        ]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'regex:/^\d+(?:\.\d{1,4})?$/',
            ],

            'company_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'sale_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'return_number' => ['prohibited'],
            'status' => ['prohibited'],
            'returned_at' => ['prohibited'],

            'items.*.product_id' => ['prohibited'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.tax_rate' => ['prohibited'],
            'items.*.subtotal' => ['prohibited'],
            'items.*.total' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Debe indicar el motivo de la devolución.',
            'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
            'reason.max' => 'El motivo no puede superar 255 caracteres.',

            'items.required' => 'Debe seleccionar al menos un producto para devolver.',
            'items.array' => 'Los productos seleccionados no son válidos.',
            'items.min' => 'Debe seleccionar al menos un producto para devolver.',

            'items.*.sale_item_id.required' => 'La línea de venta es obligatoria.',
            'items.*.sale_item_id.integer' => 'La línea de venta no es válida.',
            'items.*.sale_item_id.distinct' => 'No puede repetir el mismo producto.',

            'items.*.quantity.required' => 'Debe indicar la cantidad a devolver.',
            'items.*.quantity.numeric' => 'La cantidad a devolver debe ser numérica.',
            'items.*.quantity.gt' => 'La cantidad a devolver debe ser mayor que cero.',
            'items.*.quantity.regex' => 'La cantidad puede tener como máximo 4 decimales.',
        ];
    }
}