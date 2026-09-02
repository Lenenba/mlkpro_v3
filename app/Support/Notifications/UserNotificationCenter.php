<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Notifications\ActionEmailNotification;
use App\Notifications\EmailMirrorNotification;
use App\Notifications\ReservationDatabaseNotification;
use App\Services\NotificationPreferenceService;
use App\Support\DataTablePagination;
use App\Support\LocalePreference;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class UserNotificationCenter
{
    private const STATUS_ALL = 'all';

    private const STATUS_UNREAD = 'unread';

    private const STATUS_READ = 'read';

    private const STATUS_ARCHIVED = 'archived';

    /**
     * @return array<string, mixed>
     */
    public function headerPayload(User $user, int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $user->loadMissing('role');
        $query = $this->headerQuery($user);

        return [
            'unread_count' => (clone $query)->count(),
            'items' => (clone $query)
                ->limit($limit)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification, $user))
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function pagePayload(User $user, array $filters = [], ?string $locale = null): array
    {
        $user->loadMissing('role');
        $status = $this->normalizeStatus($filters['status'] ?? null);
        $type = $this->normalizeTypeFilter($filters['type'] ?? null);
        $perPage = DataTablePagination::resolve($filters['per_page'] ?? null, 10);

        $baseQuery = $user->notifications()->latest();
        $filteredQuery = $this->applyFilters(clone $baseQuery, $status, $type);

        /** @var LengthAwarePaginator $notifications */
        $notifications = $filteredQuery
            ->paginate($perPage)
            ->withQueryString();

        $notifications->setCollection(
            $notifications->getCollection()
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification, $user, $locale))
        );

        return [
            'notifications' => $notifications,
            'filters' => [
                'status' => $status,
                'type' => $type,
                'per_page' => $perPage,
            ],
            'stats' => [
                'all' => (clone $baseQuery)->count(),
                'unread' => (clone $baseQuery)
                    ->whereNull('read_at')
                    ->whereNull('archived_at')
                    ->count(),
                'read' => (clone $baseQuery)
                    ->whereNotNull('read_at')
                    ->whereNull('archived_at')
                    ->count(),
                'archived' => (clone $baseQuery)
                    ->whereNotNull('archived_at')
                    ->count(),
            ],
            'type_options' => $this->typeOptions(
                (clone $baseQuery)->get(['id', 'type', 'data'])
            ),
            'per_page_options' => DataTablePagination::options(),
        ];
    }

    public function markRead(DatabaseNotification $notification): bool
    {
        if ($notification->read_at) {
            return false;
        }

        $notification->forceFill([
            'read_at' => now(),
        ])->save();

        return true;
    }

    public function archive(DatabaseNotification $notification): bool
    {
        $updates = [];

        if (! $notification->read_at) {
            $updates['read_at'] = now();
        }

        if (blank($notification->getAttribute('archived_at'))) {
            $updates['archived_at'] = now();
        }

        if ($updates === []) {
            return false;
        }

        $notification->forceFill($updates)->save();

        return true;
    }

    public function restore(DatabaseNotification $notification): bool
    {
        if (blank($notification->getAttribute('archived_at'))) {
            return false;
        }

        $notification->forceFill([
            'archived_at' => null,
        ])->save();

        return true;
    }

    public function markReadAndArchive(DatabaseNotification $notification): bool
    {
        return $this->archive($notification);
    }

    public function markAllHeaderReadAndArchive(User $user): int
    {
        return $this->headerQuery($user)->update([
            'read_at' => now(),
            'archived_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function belongsTo(User $user, DatabaseNotification $notification): bool
    {
        return (string) $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(
        DatabaseNotification $notification,
        ?User $user = null,
        ?string $locale = null
    ): array {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = $this->resolveType($notification);
        $archivedAt = $notification->getAttribute('archived_at');
        $content = $this->localizedContent($notification, $data, $user, $locale);

        return [
            'id' => $notification->id,
            'title' => $content['title'],
            'message' => $content['message'],
            'type' => $type,
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'archived_at' => $this->formatDateValue($archivedAt),
            'is_read' => $notification->read_at !== null,
            'is_archived' => filled($archivedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    private function localizedContent(
        DatabaseNotification $notification,
        array $data,
        ?User $user,
        ?string $requestedLocale
    ): array {
        $locale = $this->presentationLocale($user, $requestedLocale);
        $title = (string) ($data['title'] ?? 'Notification');
        $message = (string) ($data['message'] ?? '');
        $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];
        $titleKey = is_string($data['title_key'] ?? null) ? trim($data['title_key']) : '';
        $messageKey = is_string($data['message_key'] ?? null) ? trim($data['message_key']) : '';

        if ($titleKey !== '') {
            $title = $this->translateOrFallback($titleKey, $parameters, $locale, $title);
        }
        if ($messageKey !== '') {
            $message = $this->translateOrFallback($messageKey, $parameters, $locale, $message);
        }

        if ($user?->isClient() && $titleKey === '' && $messageKey === '') {
            if ($notification->type === ReservationDatabaseNotification::class) {
                return $this->legacyReservationContent($data, $locale);
            }

            if ($this->isLegacyReservationEmailMirror($notification, $data)) {
                return [
                    'title' => LocalePreference::trans('reservation_notifications.legacy.email_confirmation.title', locale: $locale),
                    'message' => LocalePreference::trans('reservation_notifications.legacy.email_confirmation.message', locale: $locale),
                ];
            }
        }

        return compact('title', 'message');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, message: string}
     */
    private function legacyReservationContent(array $data, string $locale): array
    {
        $event = is_string($data['event'] ?? null) ? trim($data['event']) : '';
        $queueEvents = [
            'queue_ticket_created',
            'queue_eta_10m',
            'queue_pre_call',
            'queue_called',
            'queue_grace_expired',
            'queue_status_changed',
        ];

        if (in_array($event, $queueEvents, true)) {
            $parameters = [
                'minutes' => 10,
                'from' => '-',
                'to' => (string) ($data['status'] ?? '-'),
            ];
            $baseKey = 'reservation_notifications.queue.client.'.$event;

            return [
                'title' => LocalePreference::trans($baseKey.'.title', $parameters, $locale),
                'message' => LocalePreference::trans($baseKey.'.message', $parameters, $locale),
            ];
        }

        return [
            'title' => LocalePreference::trans('reservation_notifications.legacy.client_reservation.title', locale: $locale),
            'message' => LocalePreference::trans('reservation_notifications.legacy.client_reservation.message', locale: $locale),
        ];
    }

    private function presentationLocale(?User $user, ?string $requestedLocale = null): string
    {
        if (LocalePreference::isSupported($requestedLocale)) {
            return LocalePreference::normalize($requestedLocale);
        }

        $applicationLocale = app()->getLocale();

        return LocalePreference::isSupported($applicationLocale)
            ? LocalePreference::normalize($applicationLocale)
            : LocalePreference::forUser($user);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function translateOrFallback(
        string $key,
        array $parameters,
        string $locale,
        string $fallback
    ): string {
        $translated = LocalePreference::trans($key, $parameters, $locale);

        return $translated !== $key ? $translated : $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isLegacyReservationEmailMirror(DatabaseNotification $notification, array $data): bool
    {
        if ($notification->type !== EmailMirrorNotification::class) {
            return false;
        }

        $sourceNotification = (string) data_get($data, 'data.notification', '');
        $actionUrl = (string) ($data['action_url'] ?? data_get($data, 'data.action_url', ''));

        return $sourceNotification === ActionEmailNotification::class
            && Str::contains($actionUrl, '/client/reservations');
    }

    /**
     * @return Collection<int, array{id: string, count: int}>
     */
    private function typeOptions(Collection $notifications): Collection
    {
        return $notifications
            ->map(fn (DatabaseNotification $notification): string => $this->resolveType($notification))
            ->filter(fn (?string $type): bool => filled($type))
            ->countBy()
            ->map(fn (int $count, string $type): array => [
                'id' => $type,
                'count' => $count,
            ])
            ->sortBy('id')
            ->values();
    }

    private function normalizeStatus(mixed $value): string
    {
        $candidate = is_string($value) ? trim($value) : '';

        return in_array($candidate, [
            self::STATUS_ALL,
            self::STATUS_UNREAD,
            self::STATUS_READ,
            self::STATUS_ARCHIVED,
        ], true)
            ? $candidate
            : self::STATUS_ALL;
    }

    private function normalizeTypeFilter(mixed $value): ?string
    {
        $candidate = is_string($value) ? trim($value) : '';

        return $candidate !== '' ? Str::lower($candidate) : null;
    }

    private function headerQuery(User $user)
    {
        return $user->notifications()
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->latest();
    }

    private function applyFilters($query, string $status, ?string $type)
    {
        if ($status === self::STATUS_UNREAD) {
            $query->whereNull('read_at')
                ->whereNull('archived_at');
        } elseif ($status === self::STATUS_READ) {
            $query->whereNotNull('read_at')
                ->whereNull('archived_at');
        } elseif ($status === self::STATUS_ARCHIVED) {
            $query->whereNotNull('archived_at');
        }

        if ($type !== null) {
            $query->where(function ($subQuery) use ($type): void {
                $subQuery->where('data', 'like', '%"category":"'.$type.'"%');

                if ($type === 'message') {
                    $subQuery->orWhere('data', 'like', '%"category":"'.NotificationPreferenceService::CATEGORY_EMAILS_MIRROR.'"%');
                }
            });
        }

        return $query;
    }

    private function resolveType(DatabaseNotification $notification): string
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $category = Str::lower((string) ($data['category'] ?? ''));

        return match ($category) {
            NotificationPreferenceService::CATEGORY_ORDERS => 'orders',
            NotificationPreferenceService::CATEGORY_EMAILS_MIRROR => 'message',
            NotificationPreferenceService::CATEGORY_BILLING,
            NotificationPreferenceService::CATEGORY_CRM,
            NotificationPreferenceService::CATEGORY_PLANNING,
            NotificationPreferenceService::CATEGORY_SALES,
            NotificationPreferenceService::CATEGORY_STOCK,
            NotificationPreferenceService::CATEGORY_SUPPORT,
            NotificationPreferenceService::CATEGORY_SECURITY => $category,
            NotificationPreferenceService::CATEGORY_SYSTEM => 'system',
            default => $category !== '' ? $category : 'system',
        };
    }

    private function formatDateValue(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
