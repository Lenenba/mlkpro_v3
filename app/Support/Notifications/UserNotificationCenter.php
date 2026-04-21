<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Services\NotificationPreferenceService;
use App\Support\DataTablePagination;
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
        $query = $this->headerQuery($user);

        return [
            'unread_count' => (clone $query)->count(),
            'items' => (clone $query)
                ->limit($limit)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
                ->values(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function pagePayload(User $user, array $filters = []): array
    {
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
                ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
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
    public function present(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = $this->resolveType($notification);
        $archivedAt = $notification->getAttribute('archived_at');

        return [
            'id' => $notification->id,
            'title' => (string) ($data['title'] ?? 'Notification'),
            'message' => (string) ($data['message'] ?? ''),
            'action_url' => $data['action_url'] ?? null,
            'type' => $type,
            'notification_class' => (string) $notification->type,
            'data' => $data,
            'created_at' => $notification->created_at?->toIso8601String(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'archived_at' => $this->formatDateValue($archivedAt),
            'is_read' => $notification->read_at !== null,
            'is_archived' => filled($archivedAt),
        ];
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
