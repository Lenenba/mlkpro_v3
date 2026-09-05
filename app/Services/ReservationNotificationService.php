<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationReview;
use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ReservationDatabaseNotification;
use App\Support\LocalePreference;
use App\Support\NotificationDispatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReservationNotificationService
{
    private const REMINDER_TOLERANCE_MINUTES = 20;

    public function __construct(
        private readonly ReservationNotificationPreferenceService $preferences,
        private readonly SmsNotificationService $smsService
    ) {}

    public function handleCreated(Reservation $reservation, User $actor): void
    {
        $this->notifyLifecycle(
            $reservation,
            'created',
            $actor,
            [
                'source' => $reservation->source ?: '-',
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
            [],
            includeClient: true,
            includeInternal: true
        );
    }

    public function handleCancelled(Reservation $reservation, User $actor): void
    {
        $this->notifyLifecycle(
            $reservation,
            'cancelled',
            $actor,
            [
                'reason' => $reservation->cancel_reason,
            ],
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
            'teamMember.user:id,name,locale',
            'client:id,first_name,last_name,company_name',
        ])->first();

        if ($reservation === null) {
            return;
        }

        $this->notifyLifecycle(
            $reservation,
            'review_submitted',
            $actor,
            [
                'rating' => ((int) $review->rating).' / 5',
                'feedback' => $review->feedback,
            ],
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

        $account = User::query()->find($item->account_id);
        if ($account === null) {
            return false;
        }
        $config = match ($event) {
            'queue_ticket_created' => ['include_client' => true, 'include_internal' => false, 'dedupe' => true],
            'queue_eta_10m' => ['include_client' => true, 'include_internal' => false, 'dedupe' => true],
            'queue_pre_call' => ['include_client' => true, 'include_internal' => false, 'dedupe' => true],
            'queue_called' => ['include_client' => true, 'include_internal' => true, 'dedupe' => true],
            'queue_grace_expired' => ['include_client' => true, 'include_internal' => true, 'dedupe' => true],
            'queue_status_changed' => ['include_client' => true, 'include_internal' => false, 'dedupe' => false],
            default => null,
        };

        if ($config === null) {
            return false;
        }

        $settings = $this->preferences->resolveFor($account);
        if ($this->isEventEnabled($settings, $event) === false) {
            return false;
        }

        $shouldDedupe = (bool) ($config['dedupe'] ?? true);
        $metaKey = (string) ($context['meta_key'] ?? ($event.'_sent_at'));
        if ($shouldDedupe && empty($context['force']) && $this->hasQueueNotificationMeta($item, $metaKey)) {
            return false;
        }

        $item->loadMissing([
            'service:id,name',
            'teamMember.user:id,name,email,locale',
            'client:id,first_name,last_name,company_name,email,phone,portal_user_id',
            'client.portalUser:id,name,email,locale',
            'clientUser:id,name,email,phone_number,locale',
            'reservation:id,starts_at,status,team_member_id,client_id,client_user_id',
            'reservation.client:id,first_name,last_name,company_name,email,phone,portal_user_id',
            'reservation.client.portalUser:id,name,email,locale',
            'reservation.clientUser:id,name,email,phone_number,locale',
            'reservation.teamMember.user:id,name,email,locale',
        ]);

        $clientUser = $item->clientUser
            ?: $item->reservation?->clientUser
            ?: $item->client?->portalUser
            ?: $item->reservation?->client?->portalUser;

        $client = $item->client ?: $item->reservation?->client;
        $memberUser = $item->teamMember?->user ?: $item->reservation?->teamMember?->user;
        $clientLabel = (string) (
            $client?->company_name
            ?: trim(($client?->first_name ?? '').' '.($client?->last_name ?? ''))
            ?: ($clientUser?->name ?? '')
        );

        $serviceLabel = $item->service?->name ?: '';
        $queueLabel = $item->queue_number ?: ('#'.$item->id);
        $fromStatus = is_string($context['from_status'] ?? null)
            ? trim((string) $context['from_status'])
            : null;
        $toStatus = is_string($context['to_status'] ?? null)
            ? trim((string) $context['to_status'])
            : (string) $item->status;
        $internalUsers = collect([$account, $memberUser])
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->reject(function (User $user) use ($actor) {
                return $actor && (int) $user->id === (int) $actor->id;
            })
            ->values();

        $userRecipients = collect();
        if ((bool) ($config['include_internal'] ?? false)) {
            $userRecipients = $userRecipients->merge($internalUsers);
        }
        if ((bool) ($config['include_client'] ?? false) && $clientUser instanceof User) {
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
            $locale = LocalePreference::forNotifiable($recipient, $account);
            $copy = $this->queueCopy(
                $event,
                $isClientRecipient,
                $locale,
                $clientLabel,
                $item->eta_minutes,
                $fromStatus,
                $toStatus
            );
            $details = $this->queueDetails(
                $item,
                $account,
                $locale,
                $queueLabel,
                $serviceLabel,
                $clientLabel,
                $memberUser,
                $fromStatus,
                $toStatus
            );
            $actionUrl = $isClientRecipient
                ? route('client.reservations.index')
                : route('reservation.index');

            if ((bool) ($settings['in_app'] ?? false)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                    'title' => $copy['title'],
                    'message' => $copy['message'],
                    'title_key' => $copy['title_key'],
                    'message_key' => $copy['message_key'],
                    'parameters' => $copy['parameters'],
                    'content_version' => 2,
                    'audience' => $isClientRecipient ? 'client' : 'internal',
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

            if ((bool) ($settings['email'] ?? false) && filled($recipient->email)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ActionEmailNotification(
                    $copy['title'],
                    $copy['message'],
                    $details,
                    $actionUrl,
                    $this->translate('actions.open_reservations', $locale),
                    $copy['title'],
                    accountOwnerId: $account->id,
                    mirrorInApp: false
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
            (bool) ($config['include_client'] ?? false)
            && ($clientUser instanceof User) === false
            && $client instanceof Customer
            && filled($client->email)
            && (bool) ($settings['email'] ?? false)
        ) {
            $locale = LocalePreference::forNotifiable($client, $account);
            $copy = $this->queueCopy(
                $event,
                true,
                $locale,
                $clientLabel,
                $item->eta_minutes,
                $fromStatus,
                $toStatus
            );
            $dispatchOk = NotificationDispatcher::send($client, new ActionEmailNotification(
                $copy['title'],
                $copy['message'],
                $this->queueDetails(
                    $item,
                    $account,
                    $locale,
                    $queueLabel,
                    $serviceLabel,
                    $clientLabel,
                    $memberUser,
                    $fromStatus,
                    $toStatus
                ),
                route('client.reservations.book'),
                $this->translate('actions.open_reservations', $locale),
                $copy['title'],
                accountOwnerId: $account->id,
                mirrorInApp: false
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

        if ((bool) ($settings['sms'] ?? false) && (bool) ($config['include_client'] ?? false)) {
            $smsLocale = LocalePreference::forNotifiable(
                $clientUser instanceof User ? $clientUser : $client,
                $account
            );
            $smsMessage = $this->queueSmsMessage(
                $event,
                $queueLabel,
                $serviceLabel,
                $toStatus,
                [
                    'eta_minutes' => $item->eta_minutes,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'position' => is_numeric($item->position) ? (int) $item->position : null,
                    'company_name' => (string) ($account->company_name ?: $account->name ?: ''),
                    'client_name' => $this->queueClientName($item, $client, $clientUser),
                    'team_member_name' => (string) ($memberUser?->name ?? ''),
                ],
                $smsLocale
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

        if ($sent > 0 && $shouldDedupe) {
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
                'teamMember.user:id,name,locale',
                'client:id,first_name,last_name,company_name,email,portal_user_id',
                'client.portalUser:id,name,email,locale',
                'clientUser:id,name,email,locale',
            ])
            ->get();

        foreach ($reservations as $reservation) {
            $account = User::query()->find($reservation->account_id);
            if ($account === null) {
                continue;
            }

            $settings = $this->preferences->resolveFor($account);
            if ($this->isEventEnabled($settings, 'reminder') === false) {
                continue;
            }

            $minutesUntilStart = (int) $now->diffInMinutes($reservation->starts_at, false);
            if ($minutesUntilStart < 0) {
                continue;
            }

            foreach ($settings['reminder_hours'] as $hours) {
                $targetMinutes = ((int) $hours) * 60;
                if (abs($minutesUntilStart - $targetMinutes) > self::REMINDER_TOLERANCE_MINUTES) {
                    continue;
                }

                $metaKey = 'reminder_'.((int) $hours).'h_sent_at';
                if ($this->hasNotificationMeta($reservation, $metaKey)) {
                    continue;
                }

                $sent = $this->notifyLifecycle(
                    $reservation,
                    'reminder',
                    null,
                    [
                        'hours' => (int) $hours,
                    ],
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
                'teamMember.user:id,name,locale',
                'client:id,first_name,last_name,company_name,email,portal_user_id',
                'client.portalUser:id,name,email,locale',
                'clientUser:id,name,email,locale',
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
        if ($account === null) {
            return false;
        }

        $settings = $this->preferences->resolveFor($account);
        if ($this->isEventEnabled($settings, 'review_request') === false) {
            return false;
        }

        $sent = $this->notifyLifecycle(
            $reservation,
            'review_request',
            null,
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
        array $context = [],
        bool $includeClient = true,
        bool $includeInternal = true
    ): int {
        $account = User::query()->find($reservation->account_id);
        if ($account === null) {
            return 0;
        }

        $settings = $this->preferences->resolveFor($account);
        if ($this->isEventEnabled($settings, $event) === false) {
            return 0;
        }
        $reservation->loadMissing([
            'service:id,name',
            'teamMember.user:id,name,email,locale',
            'client:id,first_name,last_name,company_name,email,portal_user_id',
            'client.portalUser:id,name,email,locale',
            'clientUser:id,name,email,locale',
        ]);

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
            $locale = LocalePreference::forNotifiable($recipient, $account);
            $copy = $this->lifecycleCopy(
                $reservation,
                $event,
                $actor,
                $account,
                $isClientRecipient,
                $locale,
                $context
            );
            $details = $this->reservationDetails($reservation, $account, $locale, $context);
            $actionUrl = $isClientRecipient
                ? route('client.reservations.index')
                : route('reservation.index');

            if ((bool) ($settings['in_app'] ?? false)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ReservationDatabaseNotification([
                    'title' => $copy['title'],
                    'message' => $copy['message'],
                    'title_key' => $copy['title_key'],
                    'message_key' => $copy['message_key'],
                    'parameters' => $copy['parameters'],
                    'content_version' => 2,
                    'audience' => $isClientRecipient ? 'client' : 'internal',
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

            if ((bool) ($settings['email'] ?? false) && filled($recipient->email)) {
                $dispatchOk = NotificationDispatcher::send($recipient, new ActionEmailNotification(
                    $copy['title'],
                    $copy['message'],
                    $details,
                    $actionUrl,
                    $this->translate('actions.open_reservation', $locale),
                    $copy['title'],
                    accountOwnerId: $account->id,
                    mirrorInApp: false
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
            && ($clientUser instanceof User) === false
            && $client instanceof Customer
            && filled($client->email)
            && (bool) ($settings['email'] ?? false)
        ) {
            $locale = LocalePreference::forNotifiable($client, $account);
            $copy = $this->lifecycleCopy(
                $reservation,
                $event,
                $actor,
                $account,
                true,
                $locale,
                $context
            );
            $dispatchOk = NotificationDispatcher::send($client, new ActionEmailNotification(
                $copy['title'],
                $copy['message'],
                $this->reservationDetails($reservation, $account, $locale, $context),
                route('client.reservations.book'),
                $this->translate('actions.open_reservations', $locale),
                $copy['title'],
                accountOwnerId: $account->id,
                mirrorInApp: false
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

    /**
     * @return array{
     *     title: string,
     *     message: string,
     *     title_key: string,
     *     message_key: string,
     *     parameters: array<string, string|int>
     * }
     */
    private function queueCopy(
        string $event,
        bool $isClientRecipient,
        string $locale,
        string $clientLabel,
        mixed $etaMinutes,
        ?string $fromStatus,
        string $toStatus
    ): array {
        $audience = $isClientRecipient ? 'client' : 'internal';
        $baseKey = 'queue.'.$audience.'.'.$event;
        $parameters = [
            'client' => $clientLabel !== '' ? $clientLabel : $this->translate('fallback.client', $locale),
            'minutes' => is_numeric($etaMinutes) ? max(0, (int) $etaMinutes) : 10,
            'from' => filled($fromStatus) ? (string) $fromStatus : '-',
            'to' => $toStatus !== '' ? $toStatus : '-',
        ];
        $titleKey = 'reservation_notifications.'.$baseKey.'.title';
        $messageKey = 'reservation_notifications.'.$baseKey.'.message';

        return [
            'title' => LocalePreference::trans($titleKey, $parameters, $locale),
            'message' => LocalePreference::trans($messageKey, $parameters, $locale),
            'title_key' => $titleKey,
            'message_key' => $messageKey,
            'parameters' => $parameters,
        ];
    }

    /**
     * @return array<int, array{label: string, value: mixed}>
     */
    private function queueDetails(
        ReservationQueueItem $item,
        User $account,
        string $locale,
        string $queueLabel,
        string $serviceLabel,
        string $clientLabel,
        ?User $memberUser,
        ?string $fromStatus,
        string $toStatus
    ): array {
        $details = [
            ['label' => $this->translate('details.queue', $locale), 'value' => $queueLabel],
            ['label' => $this->translate('details.type', $locale), 'value' => $item->item_type],
            [
                'label' => $this->translate('details.service', $locale),
                'value' => $serviceLabel !== '' ? $serviceLabel : $this->translate('details.service', $locale),
            ],
            [
                'label' => $this->translate('details.client', $locale),
                'value' => $clientLabel !== '' ? $clientLabel : $this->translate('fallback.client', $locale),
            ],
            ['label' => $this->translate('details.status', $locale), 'value' => $toStatus],
            ['label' => $this->translate('details.position', $locale), 'value' => $item->position ?? '-'],
            ['label' => 'ETA', 'value' => $item->eta_minutes !== null ? ((int) $item->eta_minutes.' min') : '-'],
            [
                'label' => $this->translate('details.team_member', $locale),
                'value' => $memberUser?->name ?: $this->translate('fallback.team_member', $locale),
            ],
        ];

        if (filled($fromStatus)) {
            $details[] = [
                'label' => $this->translate('details.from_status', $locale),
                'value' => $fromStatus,
            ];
        }
        if ($toStatus !== '') {
            $details[] = [
                'label' => $this->translate('details.to_status', $locale),
                'value' => $toStatus,
            ];
        }

        if ($item->call_expires_at) {
            $details[] = [
                'label' => $this->translate('details.call_expires_at', $locale),
                'value' => $item->call_expires_at->copy()
                    ->setTimezone($account->company_timezone ?: config('app.timezone', 'UTC'))
                    ->format('Y-m-d H:i'),
            ];
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     title: string,
     *     message: string,
     *     title_key: string,
     *     message_key: string,
     *     parameters: array<string, string|int>
     * }
     */
    private function lifecycleCopy(
        Reservation $reservation,
        string $event,
        ?User $actor,
        User $account,
        bool $isClientRecipient,
        string $locale,
        array $context
    ): array {
        $audience = $isClientRecipient ? 'client' : 'internal';
        $variant = match (true) {
            $event === 'created' => $reservation->source === Reservation::SOURCE_CLIENT
                ? 'client_source'
                : 'staff_source',
            $isClientRecipient && in_array($event, ['rescheduled', 'cancelled'], true) => $this->actorIsReservationClient($reservation, $actor)
                ? 'self'
                : 'staff',
            default => null,
        };
        $baseKey = 'lifecycle.'.$audience.'.'.$event.($variant ? '.'.$variant : '');
        $parameters = [
            'actor' => (string) ($actor?->name ?: $this->translate('fallback.user', $locale)),
            'client' => $this->clientLabel($reservation) ?: $this->translate('fallback.client', $locale),
            'company' => (string) ($account->company_name ?: $account->name ?: $this->translate('fallback.company', $locale)),
            'hours' => (int) ($context['hours'] ?? 0),
        ];
        $titleKey = 'reservation_notifications.'.$baseKey.'.title';
        $messageKey = 'reservation_notifications.'.$baseKey.'.message';

        return [
            'title' => LocalePreference::trans($titleKey, $parameters, $locale),
            'message' => LocalePreference::trans($messageKey, $parameters, $locale),
            'title_key' => $titleKey,
            'message_key' => $messageKey,
            'parameters' => $parameters,
        ];
    }

    private function actorIsReservationClient(Reservation $reservation, ?User $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        $clientUser = $reservation->clientUser ?: $reservation->client?->portalUser;

        return $clientUser instanceof User && (int) $clientUser->id === (int) $actor->id;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{label: string, value: mixed}>
     */
    private function reservationDetails(
        Reservation $reservation,
        User $account,
        string $locale,
        array $context
    ): array {
        $startsAt = $reservation->starts_at?->copy()
            ?->setTimezone($account->company_timezone ?: config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i');
        $details = [
            [
                'label' => $this->translate('details.service', $locale),
                'value' => $reservation->service?->name ?: $this->translate('fallback.reservation', $locale),
            ],
            [
                'label' => $this->translate('details.when', $locale),
                'value' => $startsAt ?: '-',
            ],
            [
                'label' => $this->translate('details.team_member', $locale),
                'value' => $reservation->teamMember?->user?->name ?: $this->translate('fallback.team_member', $locale),
            ],
            [
                'label' => $this->translate('details.client', $locale),
                'value' => $this->clientLabel($reservation) ?: '-',
            ],
            [
                'label' => $this->translate('details.status', $locale),
                'value' => $reservation->status,
            ],
        ];

        foreach (['source', 'reason', 'rating'] as $key) {
            if (blank($context[$key] ?? null)) {
                continue;
            }

            $details[] = [
                'label' => $this->translate('details.'.$key, $locale),
                'value' => $context[$key],
            ];
        }

        if (array_key_exists('feedback', $context)) {
            $details[] = [
                'label' => $this->translate('details.feedback', $locale),
                'value' => filled($context['feedback'] ?? null)
                    ? $context['feedback']
                    : $this->translate('details.no_feedback', $locale),
            ];
        }

        return $details;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function translate(string $key, string $locale, array $replace = []): string
    {
        return LocalePreference::trans('reservation_notifications.'.$key, $replace, $locale);
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
            'queue_ticket_created' => (bool) ($settings['notify_on_queue_ticket_created'] ?? true),
            'queue_eta_10m' => (bool) ($settings['notify_on_queue_eta_10m'] ?? true),
            'queue_status_changed' => (bool) ($settings['notify_on_queue_status_changed'] ?? false),
            default => true,
        };
    }

    private function hasNotificationMeta(Reservation $reservation, string $key): bool
    {
        $metadata = (array) ($reservation->metadata ?? []);
        $notifications = (array) ($metadata['notifications'] ?? []);

        return filled($notifications[$key] ?? null);
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

        return filled($notifications[$key] ?? null);
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
            return '+1'.$digits;
        }

        if (strlen($digits) >= 11) {
            return '+'.ltrim($digits, '+');
        }

        return null;
    }

    private function queueSmsMessage(
        string $event,
        string $queueLabel,
        string $serviceLabel,
        string $status,
        array $context = [],
        string $locale = 'en'
    ): string {
        $companyName = $this->smsCompactLabel((string) ($context['company_name'] ?? ''), 42);
        $clientName = $this->smsCompactLabel((string) ($context['client_name'] ?? ''), 40);
        $teamMemberName = $this->smsCompactLabel((string) ($context['team_member_name'] ?? ''), 32);
        $queueLabel = $this->smsCompactLabel($queueLabel, 22);
        $serviceLabel = $this->smsCompactLabel($serviceLabel, 42);

        $etaMinutes = is_numeric($context['eta_minutes'] ?? null)
            ? max(0, (int) $context['eta_minutes'])
            : null;
        $position = is_numeric($context['position'] ?? null)
            ? max(1, (int) $context['position'])
            : null;
        $fromStatus = is_string($context['from_status'] ?? null)
            ? trim((string) $context['from_status'])
            : null;
        $toStatus = is_string($context['to_status'] ?? null)
            ? trim((string) $context['to_status'])
            : $status;
        $copy = $this->queueCopy(
            $event,
            true,
            $locale,
            $clientName,
            $etaMinutes,
            $fromStatus,
            $toStatus
        );
        $headline = $companyName !== '' ? $companyName : $this->translate('fallback.company', $locale);
        $service = $serviceLabel !== '' ? $serviceLabel : $this->translate('details.service', $locale);
        $lines = [
            $headline,
            "[{$queueLabel}] {$service}",
            $copy['message'],
        ];

        if ($clientName !== '' && strtolower($clientName) !== 'client') {
            $lines[] = $this->translate('details.client', $locale).": {$clientName}";
        }

        if ($position !== null) {
            $lines[] = $this->translate('details.position', $locale).": {$position}";
        }

        if ($etaMinutes !== null) {
            $lines[] = "ETA: {$etaMinutes} min";
        }

        if ($teamMemberName !== '') {
            $lines[] = $this->translate('details.team_member', $locale).": {$teamMemberName}";
        }

        return implode("\n", $lines);
    }

    private function queueClientName(
        ReservationQueueItem $item,
        ?Customer $client,
        ?User $clientUser
    ): string {
        $candidates = [
            (string) data_get($item->metadata, 'guest_name', ''),
            trim((string) (($client?->first_name ?? '').' '.($client?->last_name ?? ''))),
            (string) ($clientUser?->name ?? ''),
            (string) ($client?->company_name ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $cleaned = trim($candidate);
            if ($cleaned !== '') {
                return $cleaned;
            }
        }

        return '';
    }

    private function smsCompactLabel(?string $value, int $maxLength): string
    {
        $cleaned = preg_replace('/\s+/', ' ', trim((string) $value)) ?: '';
        if ($cleaned === '' || $maxLength < 4) {
            return $cleaned;
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($cleaned) <= $maxLength) {
                return $cleaned;
            }

            return mb_substr($cleaned, 0, $maxLength - 3).'...';
        }

        if (strlen($cleaned) <= $maxLength) {
            return $cleaned;
        }

        return substr($cleaned, 0, $maxLength - 3).'...';
    }

    private function clientLabel(Reservation $reservation): string
    {
        return (string) (
            $reservation->client?->company_name
            ?: trim(($reservation->client?->first_name ?? '').' '.($reservation->client?->last_name ?? ''))
            ?: ($reservation->clientUser?->name ?? '')
        );
    }
}
