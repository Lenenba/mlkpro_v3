<?php

namespace App\Services\Reservation;

use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationStatusTransition;
use App\Services\ReservationAvailabilityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ReservationQueueGraceNoShowMarker
{
    public const REASON = 'Automatically marked no-show because the queue call grace period expired.';

    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
        private readonly ReservationStatusTransitionService $statusTransitions
    ) {}

    public function mark(int $accountId, int $reservationId, ?CarbonInterface $now = null): bool
    {
        $reservation = Reservation::query()
            ->forAccount($accountId)
            ->whereKey($reservationId)
            ->first();

        if (! $reservation || ! in_array((string) $reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return false;
        }

        $nowUtc = $this->asUtc($now);
        $previousStatusVersion = (int) $reservation->status_version;
        $previousScheduleVersion = (int) $reservation->schedule_version;
        $previousMutationVersion = (int) $reservation->mutation_version;
        $metadata = $this->availabilityService->metadataForStatusTransition($reservation, Reservation::STATUS_NO_SHOW) ?? [];
        $metadata['auto_close'] = [
            'closed_at' => $nowUtc->toIso8601String(),
            'reason' => self::REASON,
            'trigger' => 'queue_grace_expiry',
        ];

        $transition = $this->statusTransitions->transition(
            $reservation,
            Reservation::STATUS_NO_SHOW,
            ReservationStatusTransition::ACTOR_SYSTEM,
            null,
            Reservation::STATUS_CHANGE_SOURCE_QUEUE_GRACE,
            'queue_grace_expired',
            self::REASON,
            [
                'auto_closed_at' => $nowUtc,
                'auto_closed_reason' => self::REASON,
                'metadata' => $metadata,
            ],
            ['trigger' => 'queue_grace_expiry'],
            allowedFromStatuses: Reservation::ACTIVE_STATUSES,
            idempotencyKey: hash('sha256', implode(':', [
                'queue-grace-no-show',
                $accountId,
                $reservationId,
                $previousStatusVersion,
                $previousScheduleVersion,
                $previousMutationVersion,
            ])),
            expectedStatusVersion: $previousStatusVersion,
            expectedScheduleVersion: $previousScheduleVersion,
            expectedMutationVersion: $previousMutationVersion,
            occurredAt: $nowUtc
        );

        if (! $transition->performed) {
            return false;
        }

        $updated = $transition->reservation;
        $this->syncPublicBookingProspectStatus($updated, $nowUtc);

        return true;
    }

    private function syncPublicBookingProspectStatus(Reservation $reservation, CarbonInterface $nowUtc): void
    {
        if (! $reservation->prospect_id || ! $reservation->public_booking_link_id) {
            return;
        }

        $prospect = $reservation->prospect()->first();
        if (! $prospect) {
            return;
        }

        $prospect->forceFill([
            'last_activity_at' => $nowUtc,
            'meta' => $prospect->mergePublicBookingMeta([
                'status' => LeadRequest::PUBLIC_STATUS_NO_SHOW,
                'reservation_status' => Reservation::STATUS_NO_SHOW,
                'status_updated_at' => $nowUtc->toIso8601String(),
            ]),
        ])->save();
    }

    private function asUtc(?CarbonInterface $now): Carbon
    {
        if ($now !== null) {
            return Carbon::instance($now->toDateTime())->utc();
        }

        return now('UTC');
    }
}
