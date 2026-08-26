<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) session('active_company_id');

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('company_user', 'user_id')->where(
                    fn ($query) => $query->where('company_id', $companyId)
                ),
                Rule::unique('professionals', 'user_id')->where(
                    fn ($query) => $query->where('company_id', $companyId)
                ),
            ],
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => [
                'integer',
                'distinct',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                ),
            ],
            'specialties' => ['nullable', 'array'],
            'specialties.*' => [
                'integer',
                'distinct',
                Rule::exists('specialties', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('is_active', true)
                ),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Debe seleccionar un usuario.',
            'user_id.exists' => 'El usuario seleccionado no pertenece a la empresa activa.',
            'user_id.unique' => 'El usuario ya tiene un perfil profesional en esta empresa.',
            'branches.required' => 'Debe seleccionar al menos una sucursal.',
            'branches.min' => 'Debe seleccionar al menos una sucursal.',
            'branches.*.exists' => 'Una sucursal seleccionada no pertenece a la empresa activa o está inactiva.',
            'specialties.*.exists' => 'Una especialidad seleccionada no pertenece a la empresa activa o está inactiva.',
        ];
    }
}
