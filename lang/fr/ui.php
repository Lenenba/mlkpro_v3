<?php

return [
    'auth' => [
        'account_suspended' => 'Compte suspendu. Veuillez contacter le support.',
        'two_factor_delivery_failed' => 'Impossible d envoyer un code de verification. Veuillez reessayer.',
        'update_temporary_password' => 'Veuillez mettre a jour votre mot de passe temporaire.',
        'social' => [
            'provider_not_configured' => 'La connexion :provider n est pas encore configuree.',
            'provider_not_ready' => 'La connexion :provider n est pas encore disponible.',
            'callback_not_ready' => 'Le callback :provider n est pas encore actif.',
            'callback_failed' => 'La connexion :provider n a pas pu etre terminee.',
            'invalid_state' => 'Cette tentative de connexion sociale n est plus valide. Recommencez.',
            'missing_code' => ':provider n a pas renvoye le code attendu.',
            'token_exchange_failed' => 'Impossible de finaliser l authentification :provider.',
            'profile_fetch_failed' => 'Impossible de recuperer le profil :provider.',
            'profile_incomplete' => ':provider n a pas renvoye les informations minimales requises.',
            'email_not_verified' => ':provider n a pas renvoye une adresse email verifiee exploitable.',
            'provider_already_linked' => 'Un autre compte :provider est deja lie a cet utilisateur.',
            'account_not_available' => 'Le compte local lie a :provider est introuvable.',
        ],
        'two_factor' => [
            'challenge_delivery_failed' => 'Impossible d envoyer un code de verification. Veuillez reessayer.',
            'too_many_attempts' => 'Trop de tentatives. Reessayez dans :seconds secondes.',
            'invalid_or_expired' => 'Code invalide ou expire.',
            'resend_wait' => 'Veuillez patienter :seconds secondes avant de demander un nouveau code.',
            'app_resend_unavailable' => 'Les codes applicatifs ne peuvent pas etre renvoyes.',
            'resend_failed' => 'Impossible d envoyer un nouveau code pour le moment.',
            'resent' => 'Nouveau code envoye.',
            'sms_message' => ':app: code de verification :code. Expire dans :minutes min.',
        ],
    ],
    'onboarding' => [
        'only_owner' => 'Seul le proprietaire du compte peut terminer l onboarding.',
        'completed' => 'Onboarding termine.',
        'team_passwords' => 'Mots de passe equipe : :credentials',
        'checkout_canceled' => 'Paiement annule.',
        'checkout_session_missing' => 'La session de paiement est manquante.',
        'sync_subscription_failed' => 'Impossible de synchroniser l abonnement.',
        'billing_not_configured' => 'La facturation n est pas encore configuree.',
        'checkout_requires_stripe' => 'Le paiement onboarding est disponible uniquement avec Stripe.',
        'checkout_start_failed' => 'Impossible de demarrer le paiement.',
    ],
];
