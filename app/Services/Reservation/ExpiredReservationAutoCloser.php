<?php

namespace App\Services\Reservation;

use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Services\ReservationAvailabilityService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpiredReservationAutoCloser
{
    public const EXPIRED_REASON = 'Automatically closed because the reservation date passed without check-in or completion.';

    public const QUEUE_GRACE_REASON = 'Automatically marked no-show because the queue call grace period expired.';

    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function closeExpired(?int $accountId = null, bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        $nowUtc = $this->asUtc($now);
        $summary = [
            'checked' => 0,
            'eligible' => 0,
            'closed' => 0,
            'queue_items_closed' => 0,
            'skipped_today_or_future' => 0,
            'skipped_checked_in' => 0,
            'skipped_arrived_queue' => 0,
            'dry_run' => $dryRun,
        ];

        Reservation::query()
            ->with([
                'account:id,company_timezone',
                'checkIns:id,reservation_id',
                'queueItems:id,reservation_id,status,item_type',
            ])
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $nowUtc)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$summary, $nowUtc, $dryRun): void {
                foreach ($reservations as $reservation) {
                    $summary['checked']++;

                    $eligibility = $this->eligibilityForExpiredAutoClose($reservation, $nowUtc);
                    if (! $eligibility['eligible']) {
                        $summary[$eligibility['reason']]++;

                        continue;
                    }

                    $summary['eligible']++;
                    if ($dryRun) {
                        continue;
                    }

                    $result = $this->closeReservationAsNoShow(
                        $reservation,
                        $nowUtc,
                        self::EXPIRED_REASON,
                        [ReservationQueueItem::STATUS_NOT_ARRIVED]
                    );

                    if ($result['closed']) {
                        $summary['closed']++;
                        $summary['queue_items_closed'] += $result['queue_items_closed'];
                    }
                }
            });

        return $summary;
    }

    public function markReservationNoShowFromQueueGrace(int $reservationId, ?CarbonInterface $now = null): bool
    {
        $reservation = Reservation::query()
            ->whereKey($reservationId)
            ->first();

        if (! $reservation) {
            return false;
        }

        return $this->closeReservationAsNoShow(
            $reservation,
            $this->asUtc($now),
            self::QUEUE_GRACE_REASON,
            []
        )['closed'];
    }

    /**
     * @return array{closed: bool, queue_items_closed: int}
     */
    private function closeReservationAsNoShow(
        Reservation $reservation,
        CarbonInterface $nowUtc,
        string $reason,
        array $queueStatusesToClose
    ): array {
        return DB::transaction(function () use ($reservation, $nowUtc, $reason, $queueStatusesToClose): array {
            $locked = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! in_array($locked->status, Reservation::ACTIVE_STATUSES, true)) {
                return ['closed' => false, 'queue_items_closed' => 0];
            }

            $metadata = $this->availabilityService->metadataForStatusTransition($locked, Reservation::STATUS_NO_SHOW) ?? [];
            $metadata['auto_close'] = [
                'closed_at' => $nowUtc->toIso8601String(),
                'reason' => $reason,
                'trigger' => $reason === self::QUEUE_GRACE_REASON
                    ? 'queue_grace_expiry'
                    : 'reservations:auto-close-expired',
            ];

            $locked->forceFill([
                'status' => Reservation::STATUS_NO_SHOW,
                'auto_closed_at' => $nowUtc,
                'auto_closed_reason' => $reason,
                'metadata' => $metadata,
            ])->save();

            $queueItemsClosed = 0;
            if ($queueStatusesToClose !== []) {
                $queueItemsClosed = ReservationQueueItem::query()
                    ->where('reservation_id', $locked->id)
                    ->whereIn('status', $queueStatusesToClose)
                    ->update([
                        'status' => ReservationQueueItem::STATUS_NO_SHOW,
                        'finished_at' => $nowUtc,
                        'call_expires_at' => null,
                    ]);
            }

            $this->syncPublicBookingProspectStatus($locked, $nowUtc);

            return ['closed' => true, 'queue_items_closed' => $queueItemsClosed];
        });
    }

    /**
     * @return array{eligible: bool, reason: string}
     */
    private function eligibilityForExpiredAutoClose(Reservation $reservation, CarbonInterface $nowUtc): array
    {
        if (! $this->isPastLocalReservationDay($reservation, $nowUtc)) {
            return ['eligible' => false, 'reason' => 'skipped_today_or_future'];
        }

        if ($reservation->checkIns->isNotEmpty()) {
            return ['eligible' => false, 'reason' => 'skipped_checked_in'];
        }

        $arrivedOrActiveStatuses = [
            ReservationQueueItem::STATUS_CHECKED_IN,
            ReservationQueueItem::STATUS_PRE_CALLED,
            ReservationQueueItem::STATUS_CALLED,
            ReservationQueueItem::STATUS_SKIPPED,
            ReservationQueueItem::STATUS_IN_SERVICE,
        ];

        if ($reservation->queueItems->contains(fn (ReservationQueueItem $item): bool => in_array($item->status, $arrivedOrActiveStatuses, true))) {
            return ['eligible' => false, 'reason' => 'skipped_arrived_queue'];
        }

        return ['eligible' => true, 'reason' => 'eligible'];
    }

    private function isPastLocalReservationDay(Reservation $reservation, CarbonInterface $nowUtc): bool
    {
        $timezone = $this->timezoneForReservation($reservation);
        $reservationDate = $reservation->starts_at->copy()->setTimezone($timezone)->toDateString();
        $today = Carbon::instance($nowUtc->toDateTime())->setTimezone($timezone)->toDateString();

        return $reservationDate < $today;
    }

    private function timezoneForReservation(Reservation $reservation): string
    {
        $timezone = trim((string) ($reservation->timezone ?: $reservation->account?->company_timezone ?: config('app.timezone', 'UTC')));

        if ($timezone === '') {
            return config('app.timezone', 'UTC');
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return config('app.timezone', 'UTC');
        }
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
        if ($now) {
            return Carbon::instance($now->toDateTime())->utc();
        }

        return now('UTC');
    }
}
