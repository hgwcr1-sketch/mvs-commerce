<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
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
                'required',
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
                'exists:roles,id',
            ],

            'branches' => [
                'required',
                'array',
                'min:1',
            ],

            'branches.*' => [
                'integer',
                'exists:branches,id',
            ],
        ];
    }

    public function messages(): array
    {
        return [

            'name.required' => 'Debe ingresar el nombre.',

            'email.required' => 'Debe ingresar un correo.',

            'password.required' => 'Debe ingresar una contraseña.',

            'password.confirmed' => 'Las contraseñas no coinciden.',

            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',

            'photo.image' => 'El archivo debe ser una imagen.',

            'role_id.required' => 'Debe seleccionar un rol.',

            'role_id.exists' => 'El rol seleccionado no es válido.',

            'branches.required' => 'Debe seleccionar al menos una sucursal.',

            'branches.min' => 'Debe seleccionar al menos una sucursal.',
        ];
    }
}