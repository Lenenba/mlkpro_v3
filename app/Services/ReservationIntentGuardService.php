<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Support\ReservationPresetResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ReservationIntentGuardService
{
    private const DEFAULT_DUPLICATE_WINDOW_MINUTES = 120;

    public function ensureCanCreateTicket(
        int $accountId,
        ?int $clientId,
        ?int $clientUserId,
        array $settings = []
    ): void {
        if (!$this->queueGuardsEnabled($settings)) {
            return;
        }

        if (!$this->hasClientContext($clientId, $clientUserId)) {
            return;
        }

        if ($this->findActiveTicket($accountId, $clientId, $clientUserId)) {
            throw ValidationException::withMessages([
                'queue' => ['You already have an active queue ticket. Please wait or cancel it first.'],
            ]);
        }

        if ($this->findNearbyActiveReservation($accountId, $clientId, $clientUserId, $settings)) {
            throw ValidationException::withMessages([
                'queue' => ['You already have a nearby reservation. Please check in instead of taking a new ticket.'],
            ]);
        }
    }

    public function ensureCanCreateReservation(
        int $accountId,
        ?int $clientId,
        ?int $clientUserId,
        array $settings = []
    ): void {
        if (!$this->queueGuardsEnabled($settings)) {
            return;
        }

        if (!$this->hasClientContext($clientId, $clientUserId)) {
            return;
        }

        if ($this->findActiveTicket($accountId, $clientId, $clientUserId)) {
            throw ValidationException::withMessages([
                'reservation' => ['You already have an active queue ticket. Please complete or cancel it first.'],
            ]);
        }
    }

    public function ensureCanCreateGuestTicket(
        int $accountId,
        ?string $guestPhoneNormalized,
        array $settings = []
    ): void
    {
        if (!$this->queueGuardsEnabled($settings)) {
            return;
        }

        $normalized = $this->normalizePhone($guestPhoneNormalized);
        if (!$normalized) {
            return;
        }

        if ($this->findActiveTicketByGuestPhone($accountId, $normalized)) {
            throw ValidationException::withMessages([
                'queue' => ['An active queue ticket already exists for this phone number.'],
            ]);
        }
    }

    public function findActiveTicket(int $accountId, ?int $clientId, ?int $clientUserId): ?ReservationQueueItem
    {
        if (!$this->hasClientContext($clientId, $clientUserId)) {
            return null;
        }

        return ReservationQueueItem::query()
            ->forAccount($accountId)
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->whereIn('status', ReservationQueueItem::ACTIVE_STATUSES)
            ->where(fn (Builder $query) => $this->scopeByClient($query, $clientId, $clientUserId))
            ->orderByDesc('created_at')
            ->first();
    }

    public function findNearbyActiveReservation(
        int $accountId,
        ?int $clientId,
        ?int $clientUserId,
        array $settings = []
    ): ?Reservation {
        if (!$this->hasClientContext($clientId, $clientUserId)) {
            return null;
        }

        $windowMinutes = max(
            10,
            (int) ($settings['queue_duplicate_window_minutes'] ?? self::DEFAULT_DUPLICATE_WINDOW_MINUTES)
        );
        $now = now('UTC');
        $windowStart = $now->copy()->subMinutes($windowMinutes);
        $windowEnd = $now->copy()->addMinutes($windowMinutes);

        return Reservation::query()
            ->forAccount($accountId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where(fn (Builder $query) => $this->scopeByClient($query, $clientId, $clientUserId))
            ->where(function (Builder $query) use ($windowStart, $windowEnd, $now) {
                $query->whereBetween('starts_at', [$windowStart, $windowEnd])
                    ->orWhere(function (Builder $activeNow) use ($now) {
                        $activeNow->where('starts_at', '<=', $now)
                            ->where('ends_at', '>=', $now);
                    });
            })
            ->orderBy('starts_at')
            ->first();
    }

    public function findActiveTicketByGuestPhone(int $accountId, ?string $guestPhoneNormalized): ?ReservationQueueItem
    {
        $normalized = $this->normalizePhone($guestPhoneNormalized);
        if (!$normalized) {
            return null;
        }

        $items = ReservationQueueItem::query()
            ->forAccount($accountId)
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->whereIn('status', ReservationQueueItem::ACTIVE_STATUSES)
            ->whereNotNull('metadata')
            ->orderByDesc('created_at')
            ->get(['id', 'metadata']);

        foreach ($items as $item) {
            $guestPhone = $this->normalizePhone((string) data_get($item->metadata, 'guest_phone_normalized'))
                ?: $this->normalizePhone((string) data_get($item->metadata, 'guest_phone'));

            if ($guestPhone === $normalized) {
                return $item;
            }
        }

        return null;
    }

    private function hasClientContext(?int $clientId, ?int $clientUserId): bool
    {
        return ($clientId ?? 0) > 0 || ($clientUserId ?? 0) > 0;
    }

    private function scopeByClient(Builder $query, ?int $clientId, ?int $clientUserId): void
    {
        $resolvedClientId = (int) ($clientId ?? 0);
        $resolvedClientUserId = (int) ($clientUserId ?? 0);

        if ($resolvedClientUserId > 0 && $resolvedClientId > 0) {
            $query->where(function (Builder $where) use ($resolvedClientId, $resolvedClientUserId) {
                $where->where('client_user_id', $resolvedClientUserId)
                    ->orWhere('client_id', $resolvedClientId);
            });
            return;
        }

        if ($resolvedClientUserId > 0) {
            $query->where('client_user_id', $resolvedClientUserId);
            return;
        }

        if ($resolvedClientId > 0) {
            $query->where('client_id', $resolvedClientId);
        }
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?: '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '1' . $digits;
        }

        if (strlen($digits) > 11) {
            return ltrim($digits, '0');
        }

        return $digits;
    }

    private function queueGuardsEnabled(array $settings): bool
    {
        return ReservationPresetResolver::queueFeaturesEnabled((string) ($settings['business_preset'] ?? null))
            && (bool) ($settings['queue_mode_enabled'] ?? false);
    }
}
