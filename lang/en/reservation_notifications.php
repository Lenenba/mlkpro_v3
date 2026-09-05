<?php

return [
    'lifecycle' => [
        'internal' => [
            'created' => [
                'client_source' => [
                    'title' => 'New reservation request',
                    'message' => ':client submitted a new reservation request.',
                ],
                'staff_source' => [
                    'title' => 'Reservation created',
                    'message' => ':actor created a reservation for :client.',
                ],
            ],
            'rescheduled' => [
                'title' => 'Reservation rescheduled',
                'message' => ':actor rescheduled the reservation for :client.',
            ],
            'cancelled' => [
                'title' => 'Reservation cancelled',
                'message' => ':actor cancelled the reservation for :client.',
            ],
            'completed' => [
                'title' => 'Reservation completed',
                'message' => 'The reservation for :client was marked as completed.',
            ],
            'reminder' => [
                'title' => 'Upcoming reservation',
                'message' => 'The reservation for :client starts in :hours hour(s).',
            ],
            'review_submitted' => [
                'title' => 'New review received',
                'message' => ':client submitted a review for a completed reservation.',
            ],
        ],
        'client' => [
            'created' => [
                'client_source' => [
                    'title' => 'Your request was sent',
                    'message' => 'Your reservation request was successfully sent to :company.',
                ],
                'staff_source' => [
                    'title' => 'Your reservation was created',
                    'message' => ':company created a reservation for you.',
                ],
            ],
            'rescheduled' => [
                'self' => [
                    'title' => 'Your reservation was rescheduled',
                    'message' => 'You successfully rescheduled your reservation.',
                ],
                'staff' => [
                    'title' => 'Your reservation was rescheduled',
                    'message' => ':company changed the time of your reservation.',
                ],
            ],
            'cancelled' => [
                'self' => [
                    'title' => 'Your reservation was cancelled',
                    'message' => 'You successfully cancelled your reservation.',
                ],
                'staff' => [
                    'title' => 'Your reservation was cancelled',
                    'message' => ':company cancelled your reservation.',
                ],
            ],
            'completed' => [
                'title' => 'Your reservation is complete',
                'message' => 'Your service is now complete. Thank you for choosing :company.',
            ],
            'reminder' => [
                'title' => 'Reservation reminder',
                'message' => 'Your reservation starts in :hours hour(s).',
            ],
            'review_request' => [
                'title' => 'How was your service?',
                'message' => 'Your reservation is complete. Share your rating and feedback.',
            ],
        ],
    ],
    'queue' => [
        'client' => [
            'queue_ticket_created' => [
                'title' => 'Your ticket is confirmed',
                'message' => 'Your place in the queue is confirmed.',
            ],
            'queue_eta_10m' => [
                'title' => 'Your turn is approaching',
                'message' => 'Your turn is expected in approximately :minutes minutes.',
            ],
            'queue_pre_call' => [
                'title' => 'Please get ready',
                'message' => 'You are almost next. Please be ready.',
            ],
            'queue_called' => [
                'title' => 'It is your turn',
                'message' => 'It is your turn. Please come to the service point.',
            ],
            'queue_grace_expired' => [
                'title' => 'Your call window has expired',
                'message' => 'Your arrival window expired, and your ticket was marked as missed.',
            ],
            'queue_status_changed' => [
                'title' => 'Your status was updated',
                'message' => 'Your queue status changed from “:from” to “:to”.',
            ],
        ],
        'internal' => [
            'queue_called' => [
                'title' => 'Client called',
                'message' => ':client was called to the service point.',
            ],
            'queue_grace_expired' => [
                'title' => 'Call window expired',
                'message' => ':client’s call window expired, and the ticket was marked as missed.',
            ],
        ],
    ],
    'details' => [
        'source' => 'Source',
        'reason' => 'Reason',
        'rating' => 'Rating',
        'feedback' => 'Feedback',
        'no_feedback' => 'No feedback provided',
        'service' => 'Service',
        'when' => 'Date and time',
        'team_member' => 'Team member',
        'client' => 'Client',
        'status' => 'Status',
        'queue' => 'Queue',
        'type' => 'Type',
        'position' => 'Position',
        'from_status' => 'Previous status',
        'to_status' => 'New status',
        'call_expires_at' => 'Call window expires',
    ],
    'actions' => [
        'open_reservation' => 'Open reservation',
        'open_reservations' => 'Open reservations',
    ],
    'fallback' => [
        'user' => 'A user',
        'team_member' => 'Team member',
        'reservation' => 'Reservation',
        'client' => 'Client',
        'company' => 'Your service provider',
    ],
    'legacy' => [
        'client_reservation' => [
            'title' => 'Your reservation was updated',
            'message' => 'An update about your reservation is available.',
        ],
        'email_confirmation' => [
            'title' => 'Reservation confirmation',
            'message' => 'Your reservation confirmation was sent by email.',
        ],
    ],
];
