<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ForceChangePasswordController extends Controller
{
    public function show(): View
    {
        $user = Auth::user();

        return view('cobros.auth.force-change', [
            'user' => $user,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()],
        ]);

        $user->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);

        return redirect()->route('cobros.index')
            ->with('success', 'Contraseña actualizada correctamente. Bienvenido a CobrOS.');
    }
}
