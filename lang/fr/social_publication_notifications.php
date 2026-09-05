<?php

return [
    'email' => [
        'publication' => 'Publication',
        'details' => 'Résultat par plateforme',
        'action' => 'Consulter l’historique Pulse',
        'published' => 'Publiés',
        'failed' => 'Échecs',
        'accounts' => 'Comptes',
    ],
    'titles' => [
        'success' => 'Pulse : publication réussie',
        'partial' => 'Pulse : publication partiellement réussie',
        'failed' => 'Pulse : échec de la publication',
        'canceled' => 'Pulse : publication annulée',
        'attention' => 'Pulse : publication à vérifier',
    ],
    'summary' => ':published/:total comptes publiés · :failed en échec · :canceled annulés · :unknown à vérifier.',
    'statuses' => [
        'published' => 'Publié',
        'failed' => 'Échec',
        'canceled' => 'Annulé',
        'unknown' => 'Résultat non confirmé',
        'reconnect_required' => 'Compte à reconnecter',
        'scheduled' => 'Programmé, pas encore publié',
        'submitted' => 'Transmis, en attente de confirmation',
        'queued' => 'En attente',
        'sending' => 'En cours',
        'publishing' => 'En cours',
        'pending' => 'En attente',
        'not_submitted' => 'Non transmis',
        'remote_approval_required' => 'Validation de la plateforme requise',
    ],
    'next_steps' => [
        'partial' => 'Consultez l’historique Pulse pour corriger les erreurs et relancer les comptes en échec.',
        'failed' => 'Consultez l’historique Pulse pour vérifier les erreurs avant une nouvelle tentative.',
        'canceled' => 'Aucun compte n’a publié ce contenu. Consultez l’historique Pulse pour les détails.',
        'attention' => 'Vérifiez le statut dans l’historique Pulse et reconnectez le compte si nécessaire. Ne republiez pas tant que le résultat reste incertain.',
    ],
];
