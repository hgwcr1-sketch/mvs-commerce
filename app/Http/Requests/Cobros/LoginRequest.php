<?php

namespace App\Http\Requests\Cobros;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected $redirectRoute = 'cobros.login';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.*' => 'Ingrese un correo electrónico válido.',
            'password.*' => 'Ingrese su contraseña.',
        ];
    }
}
