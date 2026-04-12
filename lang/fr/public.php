<?php

return [
    'quote' => [
        'status' => [
            'archived_cannot_update' => 'Les devis archives ne peuvent pas etre modifies.',
            'already_accepted' => 'Ce devis est deja accepte.',
            'already_declined' => 'Ce devis est deja refuse.',
        ],
        'actions' => [
            'archived_cannot_accept' => 'Les devis archives ne peuvent pas etre acceptes.',
            'archived_cannot_decline' => 'Les devis archives ne peuvent pas etre refuses.',
            'already_accepted' => 'Le devis est deja accepte.',
            'already_declined' => 'Le devis est deja refuse.',
            'accepted' => 'Devis accepte.',
            'declined' => 'Devis refuse.',
            'deposit_below_required' => 'L acompte est inferieur au montant requis.',
        ],
    ],
    'invoice' => [
        'messages' => [
            'cannot_pay' => 'Cette facture ne peut pas etre payee.',
            'already_paid' => 'Cette facture est deja payee.',
            'handled_by_company' => 'Les actions sur la facture sont gerees par l entreprise.',
            'amount_exceeds_balance_due' => 'Le montant depasse le solde du.',
            'stripe_not_configured' => 'Stripe n est pas configure.',
            'stripe_checkout_unavailable' => 'Impossible de demarrer le paiement Stripe.',
        ],
    ],
    'work' => [
        'messages' => [
            'handled_by_company' => 'Les actions sur l intervention sont gerees par l entreprise.',
            'already_validated' => 'L intervention est deja validee.',
            'not_ready_for_validation' => 'Cette intervention n est pas prete pour la validation.',
            'validated' => 'Intervention validee.',
            'already_marked_dispute' => 'L intervention est deja marquee comme contestee.',
            'cannot_dispute_now' => 'Cette intervention ne peut pas etre contestee pour le moment.',
            'marked_dispute' => 'Intervention marquee comme contestee.',
            'cannot_be_scheduled' => 'Cette intervention ne peut pas etre planifiee.',
            'add_team_member' => 'Ajoutez au moins un membre d equipe avant de confirmer le planning.',
            'schedule_already_confirmed' => 'Planning deja confirme.',
            'schedule_in_progress' => 'Planning en cours. Les taches seront creees sous peu.',
            'schedule_confirmed' => 'Planning confirme, les taches ont ete creees.',
            'cannot_update_now' => 'Cette intervention ne peut pas etre modifiee pour le moment.',
            'schedule_confirmed_already' => 'Ce planning a deja ete confirme.',
            'schedule_sent_back' => 'Le planning a ete renvoye pour mise a jour.',
        ],
    ],
];
