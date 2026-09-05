<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use Carbon\CarbonInterface;

class PastReservationOutcomeDecision
{
    public const ACTION_LEAVE = 'leave';

    public const ACTION_SIGNAL = 'signal';

    public const REASON_FEATURE_DISABLED = 'feature_disabled';

    public const REASON_UNSUPPORTED_MODE = 'unsupported_mode';

    public const REASON_TERMINAL_STATUS = 'terminal_status';

    public const REASON_ALREADY_REVIEWED = 'already_reviewed';

    public const REASON_BEFORE_GRACE = 'before_grace';

    public const REASON_MANUALLY_REAFFIRMED = 'manually_reaffirmed';

    public const REASON_PRESENCE_EVIDENCE = 'presence_evidence';

    public const REASON_BACKDATED = 'backdated_reservation';

    public const REASON_LEGACY_UNKNOWN = 'legacy_unknown';

    public const REASON_STALE_BACKLOG = 'stale_backlog';

    public const REASON_OUTCOME_MISSING = 'outcome_missing';

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, string>  $queueStatuses
     * @return array{action: string, reason: string}
     */
    public function decide(
        Reservation $reservation,
        CarbonInterface $nowUtc,
        array $settings,
        bool $hasCheckIn = false,
        array $queueStatuses = []
    ): array {
        if (! (bool) ($settings['past_reservation_reconciliation_enabled'] ?? false)) {
            return $this->leave(self::REASON_FEATURE_DISABLED);
        }

        if (($settings['past_reservation_reconciliation_mode'] ?? 'signal_only') !== 'signal_only') {
            return $this->leave(self::REASON_UNSUPPORTED_MODE);
        }

        if (! in_array((string) $reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return $this->leave(self::REASON_TERMINAL_STATUS);
        }

        if ($reservation->outcome_review_required_at !== null) {
            return $this->leave(self::REASON_ALREADY_REVIEWED);
        }

        if ($reservation->ends_at === null) {
            return $this->leave(self::REASON_BEFORE_GRACE);
        }

        $graceMinutes = max(0, min(10080, (int) ($settings['past_reservation_grace_minutes'] ?? 120)));
        if ($nowUtc->lt($reservation->ends_at->copy()->addMinutes($graceMinutes))) {
            return $this->leave(self::REASON_BEFORE_GRACE);
        }

        $isBackdated = $reservation->created_at !== null
            && $reservation->created_at->gte($reservation->ends_at);
        if (! $isBackdated && $this->wasReaffirmedByHumanAfterEnd($reservation)) {
            return $this->leave(self::REASON_MANUALLY_REAFFIRMED);
        }

        if ($hasCheckIn || $this->hasQueueEvidence($queueStatuses)) {
            return $this->signal(self::REASON_PRESENCE_EVIDENCE);
        }

        if ($isBackdated) {
            return $this->signal(self::REASON_BACKDATED);
        }

        if ((string) $reservation->status_change_source === Reservation::STATUS_CHANGE_SOURCE_LEGACY_UNKNOWN) {
            return $this->signal(self::REASON_LEGACY_UNKNOWN);
        }

        $maxCatchupDays = max(1, min(365, (int) ($settings['past_reservation_max_catchup_days'] ?? 7)));
        if ($reservation->ends_at->lt($nowUtc->copy()->subDays($maxCatchupDays))) {
            return $this->signal(self::REASON_STALE_BACKLOG);
        }

        return $this->signal(self::REASON_OUTCOME_MISSING);
    }

    private function wasReaffirmedByHumanAfterEnd(Reservation $reservation): bool
    {
        return in_array((string) $reservation->status_change_source, Reservation::HUMAN_STATUS_CHANGE_SOURCES, true)
            && $reservation->status_changed_at !== null
            && $reservation->status_changed_at->gte($reservation->ends_at);
    }

    /**
     * @param  array<int, string>  $queueStatuses
     */
    private function hasQueueEvidence(array $queueStatuses): bool
    {
        return collect($queueStatuses)->contains(fn (string $status): bool => in_array($status, [
            ReservationQueueItem::STATUS_CHECKED_IN,
            ReservationQueueItem::STATUS_PRE_CALLED,
            ReservationQueueItem::STATUS_CALLED,
            ReservationQueueItem::STATUS_SKIPPED,
            ReservationQueueItem::STATUS_IN_SERVICE,
            ReservationQueueItem::STATUS_AWAITING_PAYMENT,
            ReservationQueueItem::STATUS_DONE,
        ], true));
    }

    /**
     * @return array{action: string, reason: string}
     */
    private function leave(string $reason): array
    {
        return ['action' => self::ACTION_LEAVE, 'reason' => $reason];
    }

    /**
     * @return array{action: string, reason: string}
     */
    private function signal(string $reason): array
    {
        return ['action' => self::ACTION_SIGNAL, 'reason' => $reason];
    }
}
