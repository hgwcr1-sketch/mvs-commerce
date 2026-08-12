<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->route('usuario');

        return [

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],

            'password' => [
                'nullable',
                'confirmed',
                'min:8',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],

            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', session('active_company_id'))
                        ->where('is_active', true)
                ),
            ],

            'branches' => [
                'required',
                'array',
                'min:1',
            ],

            'branches.*' => [
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn ($query) => $query
                        ->where('company_id', session('active_company_id'))
                        ->where('is_active', true)
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [

            'email.unique' => 'El correo ya está registrado.',

            'password.confirmed' => 'Las contraseñas no coinciden.',

            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',

            'role_id.required' => 'Debe seleccionar un rol.',

            'role_id.exists' => 'El rol seleccionado no es válido.',

            'branches.required' => 'Debe seleccionar al menos una sucursal.',

            'branches.min' => 'Debe seleccionar al menos una sucursal.',
        ];
    }
}
