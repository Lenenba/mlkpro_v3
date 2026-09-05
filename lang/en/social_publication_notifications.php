<?php

return [
    'email' => [
        'publication' => 'Publication',
        'details' => 'Results by platform',
        'action' => 'View Pulse history',
        'published' => 'Published',
        'failed' => 'Failed',
        'accounts' => 'Accounts',
    ],
    'titles' => [
        'success' => 'Pulse: publication successful',
        'partial' => 'Pulse: publication partially successful',
        'failed' => 'Pulse: publication failed',
        'canceled' => 'Pulse: publication canceled',
        'attention' => 'Pulse: publication needs review',
    ],
    'summary' => ':published/:total accounts published · :failed failed · :canceled canceled · :unknown need review.',
    'statuses' => [
        'published' => 'Published',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
        'unknown' => 'Result unconfirmed',
        'reconnect_required' => 'Account reconnection required',
        'scheduled' => 'Scheduled, not yet published',
        'submitted' => 'Submitted, awaiting confirmation',
        'queued' => 'Queued',
        'sending' => 'In progress',
        'publishing' => 'In progress',
        'pending' => 'Pending',
        'not_submitted' => 'Not submitted',
        'remote_approval_required' => 'Platform approval required',
    ],
    'next_steps' => [
        'partial' => 'Check Pulse history to fix the errors and retry the failed accounts.',
        'failed' => 'Check Pulse history for errors before trying again.',
        'canceled' => 'No account published this content. Check Pulse history for details.',
        'attention' => 'Check the status in Pulse history and reconnect the account if needed. Do not republish while the result is uncertain.',
    ],
];
