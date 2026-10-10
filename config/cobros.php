<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CobrOS — configuración de reglas comerciales del onboarding (16R-1)
    |--------------------------------------------------------------------------
    |
    | multi_lender_enabled: bandera del Panel Maestro para habilitar en el
    | futuro la modalidad «Cobrador multiprestamista» en el registro público.
    | DESACTIVADA por defecto y sin interfaz pública de activación todavía;
    | el servidor la aplica en PublicRegistrationController y en
    | CobrosRegistrationActivationService aunque la UI estuviera alterada.
    |
    | max_draft_routes: una ruta por cobrador durante el registro público.
    | No es una cuota comercial de clientes: solo límite de rutas en borrador.
    |
    */

    'registration' => [

        'multi_lender_enabled' => (bool) env('COBROS_MULTI_LENDER_ENABLED', false),

        'max_draft_routes' => 1,

    ],

    /*
    |--------------------------------------------------------------------------
    | CobrOS — facturación (bloque 17A-1)
    |--------------------------------------------------------------------------
    |
    | mixed_enabled: modalidad de facturación MIXTA, DESACTIVADA por defecto.
    | Solo el Panel Maestro podrá habilitarla en el futuro; mientras tanto el
    | servidor rechaza contratos mixtos aunque una interfaz los ofreciera.
    |
    */

    'billing' => [

        'mixed_enabled' => (bool) env('COBROS_BILLING_MIXED_ENABLED', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | CobrOS — programa de referidos y crecimiento (PRE-19B-1)
    |--------------------------------------------------------------------------
    |
    | bonus_percent: porcentaje V1 (10 %) de la bonificación sobre el importe
    | del primer pago confirmado del referido o de la primera licencia pagada
    | por un colaborador nuevo. Las bonificaciones se registran SIEMPRE como
    | saldo a favor (cobros_referral_bonus_credits.status=credit); nunca como
    | desembolso automático. La incorporación laboral (invitación) no paga
    | bonificación por sí misma.
    |
    */

    'referrals' => [

        'bonus_percent' => (float) env('COBROS_REFERRAL_BONUS_PERCENT', 10),

    ],

    /*
    |--------------------------------------------------------------------------
    | Landing pública de bienvenida (PRE-19B-5A)
    |--------------------------------------------------------------------------
    | Países mostrados en la pantalla pública, desde configuración dinámica.
    | Agregar un país aquí lo publica en la landing sin tocar la vista.
    */

    'landing' => [

        'countries' => [
            ['iso' => 'CR', 'name' => 'Costa Rica', 'flag' => '🇨🇷', 'dial_code' => '+506', 'phone_digits' => 8],
            ['iso' => 'CO', 'name' => 'Colombia', 'flag' => '🇨🇴', 'dial_code' => '+57', 'phone_digits' => 10],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Teléfono del registro público (PRE-19B-5E)
    |--------------------------------------------------------------------------
    | Prefijo internacional y longitud nacional por país. Agregar un país a
    | landing.countries con su dial_code/phone_digits extiende el registro
    | sin tocar vistas ni validación.
    */

    'phone' => [

        'countries' => [
            'CR' => ['dial_code' => '+506', 'phone_digits' => 8],
            'CO' => ['dial_code' => '+57', 'phone_digits' => 10],
        ],

    ],

];
