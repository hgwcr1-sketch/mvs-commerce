<?php

namespace App\Http\Requests\MvsPrint;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreMvsPrintTerminalRequest extends FormRequest
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
        $companyId = (int) session('active_company_id');

        return [
            'name' => ['required', 'string', 'max:100'],
            'branch_id' => [
                'required',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'printer_name' => ['nullable', 'string', 'max:255'],
            'paper_width' => ['required', Rule::in(['58', '80'])],
            'auto_print' => ['boolean'],
            'auto_cut' => ['boolean'],
            'open_drawer' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Debe indicar el nombre de la terminal.',
            'name.max' => 'El nombre de la terminal no puede superar 100 caracteres.',
            'branch_id.required' => 'Debe indicar la sucursal de la terminal.',
            'branch_id.exists' => 'La sucursal seleccionada no existe en esta empresa.',
            'paper_width.required' => 'Debe indicar el ancho de papel.',
            'paper_width.in' => 'El ancho de papel debe ser 58 o 80 mm.',
        ];
    }
}