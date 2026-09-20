<?php

namespace App\Http\Middleware\Cobros;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as SharedAuthenticate;

class Authenticate extends SharedAuthenticate
{
    protected function unauthenticated($request, array $guards)
    {
        throw new AuthenticationException('Unauthenticated.', $guards, route('cobros.login'));
    }
}
