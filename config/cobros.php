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

];
