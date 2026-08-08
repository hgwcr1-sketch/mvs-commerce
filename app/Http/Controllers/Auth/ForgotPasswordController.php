<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    /**
     * Mostrar formulario para solicitar recuperación de contraseña.
     */
    public function create()
    {
        return view('auth.forgot-password');
    }

    /**
     * Enviar enlace de recuperación de contraseña.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        return back()
            ->withErrors([
                'email' => __($status),
            ])
            ->onlyInput('email');
    }
}