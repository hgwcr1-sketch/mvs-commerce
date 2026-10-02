<?php

namespace App\Http\Middleware\Cobros;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca la entrada explícita a CobrOS.
 *
 * Solo se aplica a las rutas de CobrOS: cualquier visita (pública o
 * autenticada) deja la cookie de primera parte `mvs_entry=cobros` durante
 * 30 días. La puerta Commerce la consume en el login para saber que la
 * última entrada del usuario fue CobrOS y devolverlo allí en lugar de
 * /dashboard. No usa user-agent ni detecta nada de forma implícita: sin
 * visita real a CobrOS la cookie no existe y Commerce se comporta igual.
 */
class MarkEntry
{
    public const COOKIE = 'mvs_entry';

    public const VALUE = 'cobros';

    public function handle(Request $request, Closure $next): Response
    {
        Cookie::queue(cookie(self::COOKIE, self::VALUE, 60 * 24 * 30));

        return $next($request);
    }
}
