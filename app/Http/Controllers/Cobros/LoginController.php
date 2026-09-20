<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Auth\LoginController as SharedLoginController;
use App\Http\Requests\Cobros\LoginRequest;
use Illuminate\Http\Request;

class LoginController extends SharedLoginController
{
    public function create()
    {
        return view('cobros.auth.login');
    }

    public function authenticate(LoginRequest $request)
    {
        return parent::store($request);
    }

    protected function authenticated(Request $request)
    {
        // Entry always resolves CobrOS permissions, never a cross-product intended URL.
        $request->session()->forget('url.intended');

        return redirect()->route('cobros.index');
    }

    protected function failedLoginRedirect()
    {
        return redirect()->route('cobros.login');
    }

    protected function loggedOut()
    {
        return redirect()->route('cobros.login');
    }
}
