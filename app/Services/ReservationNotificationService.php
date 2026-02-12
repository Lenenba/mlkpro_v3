<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationReview;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ReservationDatabaseNotification;
use App\Support\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReservationNotificationService
{
    private const REMINDER_TOLERANCE_MINUTES = 20;

    public function __construct(
        private readonly ReservationNotificationPreferenceService $preferences,
        private readonly SmsNotificationService $smsService
    ) {
    }

    public function handleCreated(Reservation $reservation, User $actor): void
    {
        $isClientSource = $reservation->source === Reservation::SOURCE_CLIENT;

        $this->notifyLifecycle(
            $reservation,
            'created',
            $actor,
            $isClientSource ? 'New reservation request' : 'Reservation created',
            $isClientSource
                ? 'A client submitted a new reservation request.'
                : 'A reservation has been created.',
            [
                ['label' => 'Source', 'value' => $reservation->source ?: '-'],
            ],
            includeClient: true,
            includeInternal: true
        );
    }

    public function handleRescheduled(Reservation $reservation, User $actor): void
    {
        $this->notifyLifecycle(
            $reservation,
            'rescheduled',
            $actor,
            'Reservation rescheduled',
            ($actor->name ?: 'A user') . ' rescheduled a reservation.',
            [],
            includeClient: true,
            includeInternal: true
        );
    }

    public function handleCancelled(Reservation $reservation, User $actor): void
    {
        $details = [];
        if ($reservation->cancel_reason) {
            $details[] = ['label' => 'Reason', 'value' => $reservation->cancel_reason];
        }

        $this->notifyLifecycle(
            $reservation,
            'cancelled',
            $actor,
            'Reservation cancelled',
            ($actor->name ?: 'A user') . ' cancelled a reservation.',
            $details,
            includeClient: true,
            includeInternal: true
        );
    }

    public function handleStatusChanged(Reservation $reservation, User $actor, ?string $previousStatus): void
    {
        if ($previousStatus === $reservation->status) {
            return;
        }

        if ($reservation->status === Reservation::STATUS_CANCELLED) {
            $this->handleCancelled($reservation, $actor);
            return;
        }

        if ($reservation->status === Reservation::STATUS_COMPLETED) {
            $this->notifyLifecycle(
                $reservation,
                'completed',
                $actor,
                'Reservation completed',
                'A reservation has been marked as completed.',
                [],
                includeClient: true,
                includeInternal: true
            );

            $this->sendReviewRequestIfNeeded($reservation);
        }
    }

    public function handleReviewSubmitted(ReservationReview $review, User $actor): void
    {
        $reservation = $review->reservation()->with([
            'service:id,name',
            'teamMember.user:id,name',
            'client:id,first_name,last_name,company_name',
        ])->first();

        if (!$reservation) {
            return;
        }

        $details = [
            ['label' => 'Rating', 'value' => ((int) $review->rating) . ' / 5'],
            ['label' => 'Feedback', 'value' => $review->feedback ?: 'No feedback provided'],
        ];

        $this->notifyLifecycle(
            $reservation,
            'review_submitted',
            $actor,
            'Reservation review received',
            'A client submitted a review for a completed reservation.',
            $details,
            includeClient: false,
            includeInternal: true
        );
    }

    public function handleQueueEvent(
        ReservationQueueItem $item,
        string $event,
        ?User $actor = null,
        array $context = []
    ): bool {
        $event = strtolower(trim($event));

        $config = match ($event) {
            'queue_pre_call' => [
                'title' => 'Queue pre-call',
                'message' => 'You are almost next. Please be ready.',
                'include_client' => true,
                'include_internal' => false,
            ],
            'queue_called' => [
                'title' => 'Queue called',
                'message' => 'It is your turn now. Please come to the service point.',
                'include_client' => true,
                'include_internal' => true,
            ],
            'queue_grace_expired' => [
                'title' => 'Queue grace expired',
                'message' => 'A called queue item reached the grace limit and was moved forward.',
                'include_client' => true,
                'include_internal' => true,
            ],
            default => null,
        };

        if (!$config) {
            return false;
        }

        $account = User::query()->find($item->account_id);
        if (!$account) {
            return false;
        }

        $settings = $this->preferences->resolveFor($account);
        if (!$this->isEventEnabled($settings, $event)) {
            return false;
        }

        $metaKey = (string) ($context['meta_key'] ?? ($event . '_sent_at'));
        if (empty($context['force']) && $this->hasQueueNotificationMeta($item, $metaKey)) {
            return false;
        }

        $item->loadMissing([
            'service:id,name',
            'teamMember.user:id,name,email',
            'client:id,first_name,last_name,company_name,email,phone,portal_user_id',
            'client.portalUser:id,name,email',
            'clientUser:id,name,email,phone_number',
            'reservation:id,starts_at,status,team_member_id,client_id,client_user_id',
            'reservation.client:id,first_name,last_name,company_name,email,phone,portal_user_id',
            'reservation.client.portalUser:id,name,email',
            'reservation.clientUser:id,name,email,phone_number',
            'reservation.teamMember.user:id,name,email',
        ]);

        $clientUser = $item->clientUser
            ?: $item->reservation?->clientUser
            ?: $item->client?->portalUser
            ?: $item->reservation?->client?->portalUser;

        $client = $item->client ?: $item->reservation?->client;
        $memberUser = $item->teamMember?->user ?: $item->reservation?->teamMember?->user;
        $clientLabel = (string) (
            $client?->company_name
            ?: trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? ''))
            ?: ($clientUser?->name ?? 'Client')
        );

        $serviceLabel = $item->service?->name ?: 'Service';
        $queueLabel = $item->queue_number ?: ('#' . $item->id);
        $details = [
            ['label' => 'Queue', 'value' => $queueLabel],
            ['label' => 'Type', 'value' => $item->item_type],
            ['label' => 'Service', 'value' => $serviceLabel],
            ['label' => 'Client', 'value' => $clientLabel],
            ['label' => 'Status', 'value' => $item->status],
            ['label' => 'Position', 'value' => $item->position ?? '-'],
            ['label' => 'ETA', 'value' => $item->eta_minutes !== null ? ((int) $item->eta_minutes . ' min') : '-'],
        ];

        $memberLabel = $memberUser?->name ?: 'Team member';
        $details[] = ['label' => 'Team member', 'value' => $memberLabel];

        if ($item->call_expires_at) {
            $callExpiry = $item->call_expires_at->copy()
                ->setTimezone($account->company_timezone ?: config('app.timezone', 'UTC'))
                ->format('Y-m-d H:i');
            $details[] = ['label' => 'Call expires at', 'value' => $callExpiry];
        }

        $internalUsers = collect([$account, $memberUser])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->reject(function (User $user) use ($actor) {
                return $actor && (int) $user->id === (int) $actor->id;
            })
            ->values();

        $userRecipients = collect();
        if (!empty($config['include_internal'])) {
            $userRecipients = $userRecipients->merge($internalUsers);
        }
        if (!empty($config['include_client']) && $clientUser instanceof User) {
            $userRecipients->push($clientUser);
        }
        $userRecipients = $userRecipients
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        $sent = 0;
        $channelStats = [
            'in_app' => 0,
            'email' => 0,
            'sms' => 0,
        ];
        foreach ($userRecipients as $recipient) {
            $isClientRecipient = $clientUser && (int) $recipient->id === (int) $clientUser->id;
            $actionUrl = $isClientRecipient
                ? route('client.reservations.index')
                : route('reservation.index');

            if (!empty($settings['in_app'])) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                    'title' => (string) $config['title'],
                    'message' => (string) $config['message'],
                    'event' => $event,
                    'action_url' => $actionUrl,
                    'reservation_id' => $item->reservation_id,
                    'queue_item_id' => $item->id,
                    'status' => $item->status,
                    'starts_at' => $item->reservation?->starts_at?->toIso8601String(),
                ]), [
                    'reservation_id' => $item->reservation_id,
                    'queue_item_id' => $item->id,
                    'event' => $event,
                ]);
                if ($dispatchOk) {
                    $sent += 1;
                    $channelStats['in_app'] += 1;
                }
            }

            if (!empty($settings['email']) && !empty($recipient->email)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ActionEmailNotification(
                    (string) $config['title'],
                    (string) $config['message'],
                    $details,
                    $actionUrl,
                    'Open reservations',
                    (string) $config['title']
                ), [
                    'reservation_id' => $item->reservation_id,
                    'queue_item_id' => $item->id,
                    'event' => $event,
                ]);
                if ($dispatchOk) {
                    $sent += 1;
                    $channelStats['email'] += 1;
                }
            }
        }

        if (
            !empty($config['include_client'])
            && !($clientUser instanceof User)
            && $client instanceof Customer
            && !empty($client->email)
            && !empty($settings['email'])
        ) {
            $dispatchOk = NotificationDispatcher::send($client, new ActionEmailNotification(
                (string) $config['title'],
                (string) $config['message'],
                $details,
                route('client.reservations.book'),
                'Open reservations',
                (string) $config['title']
            ), [
                'reservation_id' => $item->reservation_id,
                'queue_item_id' => $item->id,
                'event' => $event,
            ]);
            if ($dispatchOk) {
                $sent += 1;
                $channelStats['email'] += 1;
            }
        }

        if (!empty($settings['sms']) && !empty($config['include_client'])) {
            $smsMessage = $this->queueSmsMessage(
                $event,
                $queueLabel,
                $serviceLabel,
                $item->status
            );
            $smsRecipients = $this->resolveQueueSmsRecipients($item, $client, $clientUser);
            foreach ($smsRecipients as $phone) {
                $dispatchOk = $this->smsService->send($phone, $smsMessage);
                if ($dispatchOk) {
                    $sent += 1;
                    $channelStats['sms'] += 1;
                    continue;
                }

                Log::warning('Reservation queue SMS dispatch failed.', [
                    'account_id' => $account->id,
                    'queue_item_id' => $item->id,
                    'event' => $event,
                    'phone_hash' => sha1($phone),
                ]);
            }
        }

        if ($sent > 0) {
            $this->setQueueNotificationMeta($item, $metaKey, now('UTC')->toIso8601String());
        }

        Log::info('Reservation queue notifications processed.', [
            'account_id' => $account->id,
            'queue_item_id' => $item->id,
            'reservation_id' => $item->reservation_id,
            'event' => $event,
            'sent' => $sent,
            'channels' => $channelStats,
        ]);

        return $sent > 0;
    }

    public function processScheduledNotifications(?Carbon $reference = null): array
    {
        $now = ($reference ?: now('UTC'))->copy()->utc();
        $upperBound = $now->copy()->addDays(8);

        $remindersSent = 0;
        $reviewRequestsSent = 0;

        $reservations = Reservation::query()
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '>=', $now)
            ->where('starts_at', '<=', $upperBound)
            ->with([
                'service:id,name',
                'teamMember.user:id,name',
                'client:id,first_name,last_name,company_name,email,portal_user_id',
                'client.portalUser:id,name,email',
                'clientUser:id,name,email',
            ])
            ->get();

        foreach ($reservations as $reservation) {
            $account = User::query()->find($reservation->account_id);
            if (!$account) {
                continue;
            }

            $settings = $this->preferences->resolveFor($account);
            if (!$this->isEventEnabled($settings, 'reminder')) {
                continue;
            }

            $minutesUntilStart = $now->diffInMinutes($reservation->starts_at, false);
            if ($minutesUntilStart < 0) {
                continue;
            }

            foreach ($settings['reminder_hours'] as $hours) {
                $targetMinutes = ((int) $hours) * 60;
                if (abs($minutesUntilStart - $targetMinutes) > self::REMINDER_TOLERANCE_MINUTES) {
                    continue;
                }

                $metaKey = 'reminder_' . ((int) $hours) . 'h_sent_at';
                if ($this->hasNotificationMeta($reservation, $metaKey)) {
                    continue;
                }

                $sent = $this->notifyLifecycle(
                    $reservation,
                    'reminder',
                    null,
                    'Reservation reminder',
                    'Reminder: your reservation starts in ' . ((int) $hours) . ' hour(s).',
                    [],
                    includeClient: true,
                    includeInternal: true
                );

                if ($sent > 0) {
                    $remindersSent += $sent;
                    $this->setNotificationMeta($reservation, $metaKey, $now->toIso8601String());
                }
            }
        }

        $completedReservations = Reservation::query()
            ->where('status', Reservation::STATUS_COMPLETED)
            ->where('ends_at', '<=', $now)
            ->where('ends_at', '>=', $now->copy()->subDays(14))
            ->with([
                'service:id,name',
                'teamMember.user:id,name',
                'client:id,first_name,last_name,company_name,email,portal_user_id',
                'client.portalUser:id,name,email',
                'clientUser:id,name,email',
                'review:id,reservation_id',
            ])
            ->get();

        foreach ($completedReservations as $reservation) {
            if ($this->sendReviewRequestIfNeeded($reservation)) {
                $reviewRequestsSent += 1;
            }
        }

        return [
            'reminders_sent' => $remindersSent,
            'review_requests_sent' => $reviewRequestsSent,
        ];
    }

    public function sendReviewRequestIfNeeded(Reservation $reservation): bool
    {
        if ($reservation->status !== Reservation::STATUS_COMPLETED) {
            return false;
        }

        if ($reservation->relationLoaded('review')) {
            if ($reservation->review) {
                return false;
            }
        } elseif ($reservation->review()->exists()) {
            return false;
        }

        $metaKey = 'review_request_sent_at';
        if ($this->hasNotificationMeta($reservation, $metaKey)) {
            return false;
        }

        $account = User::query()->find($reservation->account_id);
        if (!$account) {
            return false;
        }

        $settings = $this->preferences->resolveFor($account);
        if (!$this->isEventEnabled($settings, 'review_request')) {
            return false;
        }

        $sent = $this->notifyLifecycle(
            $reservation,
            'review_request',
            null,
            'How was your service?',
            'Your reservation is completed. Share your rating and feedback.',
            [],
            includeClient: true,
            includeInternal: false
        );

        if ($sent <= 0) {
            return false;
        }

        $this->setNotificationMeta($reservation, $metaKey, now('UTC')->toIso8601String());

        return true;
    }

    private function notifyLifecycle(
        Reservation $reservation,
        string $event,
        ?User $actor,
        string $title,
        string $message,
        array $details = [],
        bool $includeClient = true,
        bool $includeInternal = true
    ): int {
        $account = User::query()->find($reservation->account_id);
        if (!$account) {
            return 0;
        }

        $settings = $this->preferences->resolveFor($account);
        if (!$this->isEventEnabled($settings, $event)) {
            return 0;
        }

        $reservation->loadMissing([
            'service:id,name',
            'teamMember.user:id,name,email',
            'client:id,first_name,last_name,company_name,email,portal_user_id',
            'client.portalUser:id,name,email',
            'clientUser:id,name,email',
        ]);

        $serviceLabel = $reservation->service?->name ?: 'Reservation';
        $memberLabel = $reservation->teamMember?->user?->name ?: 'Team member';
        $clientLabel = $this->clientLabel($reservation);
        $startsAt = $reservation->starts_at?->copy()
            ?->setTimezone($account->company_timezone ?: config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i');

        $fullDetails = array_merge([
            ['label' => 'Service', 'value' => $serviceLabel],
            ['label' => 'When', 'value' => $startsAt ?: '-'],
            ['label' => 'Team member', 'value' => $memberLabel],
            ['label' => 'Client', 'value' => $clientLabel ?: '-'],
            ['label' => 'Status', 'value' => $reservation->status],
        ], $details);

        $owner = User::query()->find($reservation->account_id);
        $internalUsers = collect([$owner, $reservation->teamMember?->user])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->reject(function (User $user) use ($actor) {
                return $actor && (int) $user->id === (int) $actor->id;
            })
            ->values();

        $clientUser = $reservation->clientUser
            ?: $reservation->client?->portalUser;
        $client = $reservation->client;

        $userRecipients = collect();
        if ($includeInternal) {
            $userRecipients = $userRecipients->merge($internalUsers);
        }
        if ($includeClient && $clientUser instanceof User) {
            $userRecipients->push($clientUser);
        }
        $userRecipients = $userRecipients
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        $sent = 0;
        foreach ($userRecipients as $recipient) {
            $isClientRecipient = $clientUser && (int) $recipient->id === (int) $clientUser->id;
            $actionUrl = $isClientRecipient
                ? route('client.reservations.index')
                : route('reservation.index');

            if (!empty($settings['in_app'])) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                    'title' => $title,
                    'message' => $message,
                    'event' => $event,
                    'action_url' => $actionUrl,
                    'reservation_id' => $reservation->id,
                    'status' => $reservation->status,
                    'starts_at' => $reservation->starts_at?->toIso8601String(),
                ]), [
                    'reservation_id' => $reservation->id,
                    'event' => $event,
                ]);
                if ($dispatchOk) {
                    $sent += 1;
                }
            }

            if (!empty($settings['email']) && !empty($recipient->email)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ActionEmailNotification(
                    $title,
                    $message,
                    $fullDetails,
                    $actionUrl,
                    'Open reservation',
                    $title
                ), [
                    'reservation_id' => $reservation->id,
                    'event' => $event,
                ]);
                if ($dispatchOk) {
                    $sent += 1;
                }
            }
        }

        if (
            $includeClient
            && !($clientUser instanceof User)
            && $client instanceof Customer
            && !empty($client->email)
            && !empty($settings['email'])
        ) {
            $dispatchOk = NotificationDispatcher::send($client, new ActionEmailNotification(
                $title,
                $message,
                $fullDetails,
                route('client.reservations.book'),
                'Open reservations',
                $title
            ), [
                'reservation_id' => $reservation->id,
                'event' => $event,
            ]);
            if ($dispatchOk) {
                $sent += 1;
            }
        }

        return $sent;
    }

    private function isEventEnabled(array $settings, string $event): bool
    {
        if (empty($settings['enabled'])) {
            return false;
        }

        return match ($event) {
            'created' => (bool) ($settings['notify_on_created'] ?? true),
            'rescheduled' => (bool) ($settings['notify_on_rescheduled'] ?? true),
            'cancelled' => (bool) ($settings['notify_on_cancelled'] ?? true),
            'completed' => (bool) ($settings['notify_on_completed'] ?? true),
            'reminder' => (bool) ($settings['notify_on_reminder'] ?? true),
            'review_submitted' => (bool) ($settings['notify_on_review_submitted'] ?? true),
            'review_request' => (bool) ($settings['review_request_on_completed'] ?? true),
            'queue_pre_call' => (bool) ($settings['notify_on_queue_pre_call'] ?? true),
            'queue_called' => (bool) ($settings['notify_on_queue_called'] ?? true),
            'queue_grace_expired' => (bool) ($settings['notify_on_queue_grace_expired'] ?? true),
            default => true,
        };
    }

    private function hasNotificationMeta(Reservation $reservation, string $key): bool
    {
        $metadata = (array) ($reservation->metadata ?? []);
        $notifications = (array) ($metadata['notifications'] ?? []);

        return !empty($notifications[$key]);
    }

    private function setNotificationMeta(Reservation $reservation, string $key, string $value): void
    {
        $metadata = (array) ($reservation->metadata ?? []);
        $notifications = (array) ($metadata['notifications'] ?? []);
        $notifications[$key] = $value;
        $metadata['notifications'] = $notifications;

        $reservation->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    private function hasQueueNotificationMeta(ReservationQueueItem $item, string $key): bool
    {
        $metadata = (array) ($item->metadata ?? []);
        $notifications = (array) ($metadata['notifications'] ?? []);

        return !empty($notifications[$key]);
    }

    private function setQueueNotificationMeta(ReservationQueueItem $item, string $key, string $value): void
    {
        $metadata = (array) ($item->metadata ?? []);
        $notifications = (array) ($metadata['notifications'] ?? []);
        $notifications[$key] = $value;
        $metadata['notifications'] = $notifications;

        $item->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    private function resolveQueueSmsRecipients(
        ReservationQueueItem $item,
        ?Customer $client,
        ?User $clientUser
    ): array {
        $rawCandidates = [
            (string) data_get($item->metadata, 'guest_phone', ''),
            (string) data_get($item->metadata, 'guest_phone_normalized', ''),
            (string) ($client?->phone ?? ''),
            (string) ($item->reservation?->client?->phone ?? ''),
            (string) ($clientUser?->phone_number ?? ''),
        ];

        $normalized = collect($rawCandidates)
            ->map(fn (string $value) => $this->normalizeSmsPhone($value))
            ->filter(fn (?string $value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        return $normalized;
    }

    private function normalizeSmsPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($value)) ?: '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) >= 11) {
            return '+' . ltrim($digits, '+');
        }

        return null;
    }

    private function queueSmsMessage(string $event, string $queueLabel, string $serviceLabel, string $status): string
    {
        return match ($event) {
            'queue_pre_call' => "[{$queueLabel}] {$serviceLabel}: you are almost next.",
            'queue_called' => "[{$queueLabel}] {$serviceLabel}: it is your turn now.",
            'queue_grace_expired' => "[{$queueLabel}] {$serviceLabel}: call window expired ({$status}).",
            default => "[{$queueLabel}] {$serviceLabel}: queue update ({$status}).",
        };
    }

    private function clientLabel(Reservation $reservation): string
    {
        return (string) (
            $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '') . ' ' . ($reservation->client?->last_name ?? ''))
            ?: ($reservation->clientUser?->name ?? '')
        );
    }
}
