<?php

return [

    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',

    'confirmed' => 'La confirmación de :attribute no coincide.',
    
    'password' => [
    'letters' => 'La :attribute debe contener al menos una letra.',
    'mixed' => 'La :attribute debe contener al menos una letra mayúscula y una minúscula.',
    'numbers' => 'La :attribute debe contener al menos un número.',
    'symbols' => 'La :attribute debe contener al menos un símbolo.',
    'uncompromised' => 'La :attribute proporcionada ha aparecido en una filtración de datos. Elige una contraseña diferente.',
],

    'attributes' => [
        'password' => 'contraseña',
        'password_confirmation' => 'confirmación de contraseña',
        'email' => 'correo electrónico',
        'taxpayer_activities.*.code' => 'código de actividad económica',
    ],

];
