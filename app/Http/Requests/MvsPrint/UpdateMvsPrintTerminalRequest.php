<?php

namespace App\Http\Requests\MvsPrint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMvsPrintTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('active_company_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'auto_print' => $this->boolean('auto_print'),
            'auto_cut' => $this->boolean('auto_cut'),
            'open_drawer' => $this->boolean('open_drawer'),
            'enabled' => $this->boolean('enabled'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'printer_name' => ['nullable', 'string', 'max:255'],
            'paper_width' => ['sometimes', Rule::in(['58', '80'])],
            'auto_print' => ['boolean'],
            'auto_cut' => ['boolean'],
            'open_drawer' => ['boolean'],
            'drawer_command' => ['nullable', 'array', 'max:32'],
            'drawer_command.*' => ['integer', 'min:0', 'max:255'],
            'enabled' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'El nombre de la terminal no puede superar 100 caracteres.',
            'paper_width.in' => 'El ancho de papel debe ser 58 o 80 mm.',
            'drawer_command.max' => 'El comando de cajón no puede superar 32 bytes.',
            'drawer_command.*.integer' => 'El comando de cajón debe ser una lista de bytes (0-255).',
            'drawer_command.*.min' => 'Cada byte del comando de cajón debe estar entre 0 y 255.',
            'drawer_command.*.max' => 'Cada byte del comando de cajón debe estar entre 0 y 255.',
        ];
    }
}