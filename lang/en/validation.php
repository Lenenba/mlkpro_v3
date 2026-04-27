<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'email' => 'The :attribute field must be a valid email address.',
    'url' => 'The :attribute field must be a valid URL.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'unique' => 'The :attribute has already been taken.',
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'string' => 'The :attribute must not be greater than :max characters.',
    ],
    'password' => [
        'letters' => 'The :attribute must contain at least one letter.',
        'mixed' => 'The :attribute must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute must contain at least one number.',
        'symbols' => 'The :attribute must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'attributes' => [
        'name' => 'name',
        'email' => 'email',
        'text' => 'post text',
        'image_url' => 'image URL',
        'link_url' => 'destination link',
        'link_cta_label' => 'CTA label',
        'password' => 'password',
        'password_confirmation' => 'password confirmation',
        'code' => 'verification code',
        'target_connection_ids' => 'target accounts',
    ],
];
