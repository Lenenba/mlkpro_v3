<?php

return [
    'lifecycle' => [
        'internal' => [
            'created' => [
                'client_source' => [
                    'title' => 'Nouvelle demande de réservation',
                    'message' => ':client a soumis une nouvelle demande de réservation.',
                ],
                'staff_source' => [
                    'title' => 'Réservation créée',
                    'message' => ':actor a créé une réservation pour :client.',
                ],
            ],
            'rescheduled' => [
                'title' => 'Réservation replanifiée',
                'message' => ':actor a replanifié la réservation de :client.',
            ],
            'cancelled' => [
                'title' => 'Réservation annulée',
                'message' => ':actor a annulé la réservation de :client.',
            ],
            'completed' => [
                'title' => 'Réservation terminée',
                'message' => 'La réservation de :client a été marquée comme terminée.',
            ],
            'reminder' => [
                'title' => 'Réservation à venir',
                'message' => 'La réservation de :client commence dans :hours heure(s).',
            ],
            'review_submitted' => [
                'title' => 'Nouvel avis reçu',
                'message' => ':client a envoyé un avis au sujet d’une réservation terminée.',
            ],
        ],
        'client' => [
            'created' => [
                'client_source' => [
                    'title' => 'Votre demande a été envoyée',
                    'message' => 'Votre demande de réservation a bien été transmise à :company.',
                ],
                'staff_source' => [
                    'title' => 'Votre réservation a été créée',
                    'message' => ':company a créé une réservation pour vous.',
                ],
            ],
            'rescheduled' => [
                'self' => [
                    'title' => 'Votre réservation a été replanifiée',
                    'message' => 'Vous avez bien replanifié votre réservation.',
                ],
                'staff' => [
                    'title' => 'Votre réservation a été replanifiée',
                    'message' => ':company a modifié l’horaire de votre réservation.',
                ],
            ],
            'cancelled' => [
                'self' => [
                    'title' => 'Votre réservation a été annulée',
                    'message' => 'Vous avez bien annulé votre réservation.',
                ],
                'staff' => [
                    'title' => 'Votre réservation a été annulée',
                    'message' => ':company a annulé votre réservation.',
                ],
            ],
            'completed' => [
                'title' => 'Votre réservation est terminée',
                'message' => 'Votre service est maintenant terminé. Merci d’avoir choisi :company.',
            ],
            'reminder' => [
                'title' => 'Rappel de votre réservation',
                'message' => 'Votre réservation commence dans :hours heure(s).',
            ],
            'review_request' => [
                'title' => 'Comment s’est passé votre service ?',
                'message' => 'Votre réservation est terminée. Partagez votre note et votre commentaire.',
            ],
        ],
    ],
    'queue' => [
        'client' => [
            'queue_ticket_created' => [
                'title' => 'Votre ticket est confirmé',
                'message' => 'Votre place dans la file d’attente est confirmée.',
            ],
            'queue_eta_10m' => [
                'title' => 'Votre tour approche',
                'message' => 'Votre tour est prévu dans environ :minutes minutes.',
            ],
            'queue_pre_call' => [
                'title' => 'Préparez-vous',
                'message' => 'Vous êtes presque la prochaine personne appelée. Merci de vous préparer.',
            ],
            'queue_called' => [
                'title' => 'C’est votre tour',
                'message' => 'C’est votre tour. Veuillez vous présenter au point de service.',
            ],
            'queue_grace_expired' => [
                'title' => 'Votre délai d’appel a expiré',
                'message' => 'Votre délai de présentation a expiré et votre ticket a été marqué comme manqué.',
            ],
            'queue_status_changed' => [
                'title' => 'Votre statut a été mis à jour',
                'message' => 'Votre statut dans la file est passé de « :from » à « :to ».',
            ],
        ],
        'internal' => [
            'queue_called' => [
                'title' => 'Client appelé',
                'message' => ':client a été appelé au point de service.',
            ],
            'queue_grace_expired' => [
                'title' => 'Délai d’appel expiré',
                'message' => 'Le délai d’appel de :client a expiré et son ticket a été marqué comme manqué.',
            ],
        ],
    ],
    'details' => [
        'source' => 'Source',
        'reason' => 'Raison',
        'rating' => 'Note',
        'feedback' => 'Commentaire',
        'no_feedback' => 'Aucun commentaire fourni',
        'service' => 'Service',
        'when' => 'Date et heure',
        'team_member' => 'Membre de l’équipe',
        'client' => 'Client',
        'status' => 'Statut',
        'queue' => 'File d’attente',
        'type' => 'Type',
        'position' => 'Position',
        'from_status' => 'Ancien statut',
        'to_status' => 'Nouveau statut',
        'call_expires_at' => 'Expiration de l’appel',
    ],
    'actions' => [
        'open_reservation' => 'Ouvrir la réservation',
        'open_reservations' => 'Ouvrir les réservations',
    ],
    'fallback' => [
        'user' => 'Un utilisateur',
        'team_member' => 'Membre de l’équipe',
        'reservation' => 'Réservation',
        'client' => 'Client',
        'company' => 'Votre prestataire',
    ],
    'legacy' => [
        'client_reservation' => [
            'title' => 'Mise à jour de votre réservation',
            'message' => 'Une mise à jour concernant votre réservation est disponible.',
        ],
        'email_confirmation' => [
            'title' => 'Confirmation de réservation',
            'message' => 'Votre confirmation de réservation a été envoyée par courriel.',
        ],
    ],
];
