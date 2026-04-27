<?php

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit etre une chaine de caracteres.',
    'email' => 'Le champ :attribute doit etre une adresse email valide.',
    'url' => 'Le champ :attribute doit etre une URL valide.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'unique' => 'La valeur du champ :attribute est deja utilisee.',
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caracteres.',
    ],
    'max' => [
        'string' => 'Le champ :attribute ne doit pas depasser :max caracteres.',
    ],
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Le champ :attribute a apparu dans une fuite de donnees. Veuillez choisir une autre valeur.',
    ],
    'attributes' => [
        'name' => 'nom',
        'email' => 'email',
        'text' => 'texte du post',
        'image_url' => 'URL image',
        'link_url' => 'lien de destination',
        'link_cta_label' => 'libelle du CTA',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'code' => 'code de verification',
        'target_connection_ids' => 'comptes cibles',
    ],
];
