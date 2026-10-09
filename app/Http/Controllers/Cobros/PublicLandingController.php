<?php

namespace App\Http\Controllers\Cobros;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PublicLandingController extends Controller
{
    /**
     * Clave temporal de sesión para la referencia de afiliado (?ref=).
     * Solo conserva el código durante la sesión actual: no otorga beneficios
     * ni se registra en base de datos en esta fase.
     */
    public const REFERRAL_SESSION_KEY = 'cobros.referral';

    /**
     * Formato aceptado de referencia: 4 a 64 caracteres de A-Z, a-z, 0-9, "_" o "-".
     * Valores inválidos se ignoran por completo (no se almacenan ni se reflejan).
     */
    public const REFERRAL_PATTERN = '/\A[A-Za-z0-9_-]{4,64}\z/';

    public function __invoke(Request $request): View
    {
        self::captureReferral($request);

        return view('cobros.public-landing');
    }

    /**
     * Captura ?ref= en sesión si respeta el formato permitido. Compartido con
     * el registro público: conserva la referencia sin exponerla ni premiarla.
     */
    public static function captureReferral(Request $request): void
    {
        $ref = $request->query('ref');

        if (is_string($ref) && preg_match(self::REFERRAL_PATTERN, $ref) === 1) {
            $request->session()->put(self::REFERRAL_SESSION_KEY, [
                'code' => $ref,
                'captured_at' => now()->toIso8601String(),
            ]);
        }
    }
}
