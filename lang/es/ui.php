<?php

return [
    'auth' => [
        'account_suspended' => 'Cuenta suspendida. Ponte en contacto con soporte.',
        'two_factor_delivery_failed' => 'No se pudo enviar el codigo de verificacion. Intentalo de nuevo.',
        'update_temporary_password' => 'Actualiza tu contrasena temporal.',
        'two_factor' => [
            'challenge_delivery_failed' => 'No se pudo enviar un codigo de verificacion. Intentalo de nuevo.',
            'too_many_attempts' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',
            'invalid_or_expired' => 'Codigo invalido o caducado.',
            'resend_wait' => 'Espera :seconds segundos antes de pedir un nuevo codigo.',
            'app_resend_unavailable' => 'Los codigos de la aplicacion de autenticacion no se pueden reenviar.',
            'resend_failed' => 'No se puede enviar un nuevo codigo en este momento.',
            'resent' => 'Se ha enviado un nuevo codigo.',
            'sms_message' => ':app: codigo de verificacion :code. Caduca en :minutes min.',
        ],
    ],
    'onboarding' => [
        'only_owner' => 'Solo el propietario de la cuenta puede completar el onboarding.',
        'completed' => 'Onboarding completado.',
        'team_passwords' => 'Contrasenas del equipo: :credentials',
        'checkout_canceled' => 'Pago cancelado.',
        'checkout_session_missing' => 'Falta la sesion de pago.',
        'sync_subscription_failed' => 'No se pudo sincronizar la suscripcion.',
        'billing_not_configured' => 'La facturacion todavia no esta configurada.',
        'checkout_requires_stripe' => 'El pago de onboarding solo esta disponible con Stripe.',
        'checkout_start_failed' => 'No se pudo iniciar el pago.',
    ],
];
