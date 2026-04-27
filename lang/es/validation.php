<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser una cadena de texto.',
    'email' => 'El campo :attribute debe ser un correo electronico valido.',
    'url' => 'El campo :attribute debe ser una URL valida.',
    'confirmed' => 'La confirmacion de :attribute no coincide.',
    'current_password' => 'La contrasena es incorrecta.',
    'unique' => 'El valor de :attribute ya esta en uso.',
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'max' => [
        'string' => 'El campo :attribute no debe ser mayor que :max caracteres.',
    ],
    'password' => [
        'letters' => 'El campo :attribute debe contener al menos una letra.',
        'mixed' => 'El campo :attribute debe contener al menos una mayuscula y una minuscula.',
        'numbers' => 'El campo :attribute debe contener al menos un numero.',
        'symbols' => 'El campo :attribute debe contener al menos un simbolo.',
        'uncompromised' => 'El :attribute indicado aparece en una filtracion de datos. Elige otro :attribute.',
    ],
    'attributes' => [
        'name' => 'nombre',
        'email' => 'email',
        'text' => 'texto del post',
        'image_url' => 'URL de imagen',
        'link_url' => 'enlace de destino',
        'link_cta_label' => 'texto del CTA',
        'password' => 'contrasena',
        'password_confirmation' => 'confirmacion de la contrasena',
        'code' => 'codigo de verificacion',
        'target_connection_ids' => 'cuentas objetivo',
    ],
];
