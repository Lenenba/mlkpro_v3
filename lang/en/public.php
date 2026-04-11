<?php

return [
    'quote' => [
        'status' => [
            'archived_cannot_update' => 'Archived quotes cannot be updated.',
            'already_accepted' => 'This quote is already accepted.',
            'already_declined' => 'This quote is already declined.',
        ],
        'actions' => [
            'archived_cannot_accept' => 'Archived quotes cannot be accepted.',
            'archived_cannot_decline' => 'Archived quotes cannot be declined.',
            'already_accepted' => 'Quote already accepted.',
            'already_declined' => 'Quote already declined.',
            'accepted' => 'Quote accepted.',
            'declined' => 'Quote declined.',
            'deposit_below_required' => 'Deposit is below the required amount.',
        ],
    ],
    'invoice' => [
        'messages' => [
            'cannot_pay' => 'This invoice cannot be paid.',
            'already_paid' => 'This invoice is already paid.',
            'handled_by_company' => 'Invoice actions are handled by the company.',
            'amount_exceeds_balance_due' => 'Amount exceeds the balance due.',
            'stripe_not_configured' => 'Stripe is not configured.',
            'stripe_checkout_unavailable' => 'Unable to start Stripe checkout.',
        ],
    ],
    'work' => [
        'messages' => [
            'handled_by_company' => 'Job actions are handled by the company.',
            'already_validated' => 'Job already validated.',
            'not_ready_for_validation' => 'This job is not ready for validation.',
            'validated' => 'Job validated.',
            'already_marked_dispute' => 'Job already marked as dispute.',
            'cannot_dispute_now' => 'This job cannot be disputed right now.',
            'marked_dispute' => 'Job marked as dispute.',
            'cannot_be_scheduled' => 'This job cannot be scheduled.',
            'add_team_member' => 'Add at least one team member before confirming the schedule.',
            'schedule_already_confirmed' => 'Schedule already confirmed.',
            'schedule_in_progress' => 'Schedule in progress. Tasks will be created shortly.',
            'schedule_confirmed' => 'Schedule confirmed, tasks have been created.',
            'cannot_update_now' => 'This job cannot be updated right now.',
            'schedule_confirmed_already' => 'This schedule has already been confirmed.',
            'schedule_sent_back' => 'Schedule sent back for updates.',
        ],
    ],
];
