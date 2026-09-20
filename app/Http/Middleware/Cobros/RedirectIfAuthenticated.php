<?php

namespace App\Http\Middleware\Cobros;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated as SharedRedirectIfAuthenticated;
use Illuminate\Http\Request;

class RedirectIfAuthenticated extends SharedRedirectIfAuthenticated
{
    protected function redirectTo(Request $request): ?string
    {
        return route('cobros.index');
    }
}
