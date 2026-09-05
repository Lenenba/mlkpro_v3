<?php

namespace App\Services\Reservation;

use App\Models\Reservation;
use App\Models\ReservationCheckIn;
use App\Models\ReservationQueueItem;
use App\Models\ReservationSetting;
use App\Models\ReservationStatusTransition;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PastReservationOutcomeReconciler
{
    public const SOURCE = Reservation::STATUS_CHANGE_SOURCE_SCHEDULED_RECONCILIATION;

    public const MAX_RESERVATIONS_PER_RUN = 500;

    public function __construct(
        private readonly PastReservationOutcomeDecision $decision
    ) {}

    /**
     * @return array{checked: int, eligible: int, signaled: int, skipped: int, reasons: array<string, int>, dry_run: bool, enabled: bool}
     */
    public function reconcile(int $accountId, bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        $nowUtc = $this->asUtc($now);
        $settings = $this->settingsForAccount($accountId);
        $summary = $this->emptySummary($dryRun, (bool) $settings['past_reservation_reconciliation_enabled']);

        if (! $summary['enabled'] || $settings['past_reservation_reconciliation_mode'] !== 'signal_only') {
            return $summary;
        }

        $graceMinutes = max(0, min(10080, (int) $settings['past_reservation_grace_minutes']));
        $candidateCutoff = $nowUtc->copy()->subMinutes($graceMinutes);
        $processed = 0;

        Reservation::query()
            ->forAccount($accountId)
            ->whereIn('status', Reservation::ACTIVE_STATUSES)
            ->whereNull('outcome_review_required_at')
            ->where('ends_at', '<=', $candidateCutoff)
            ->where(function ($query): void {
                $query->whereColumn('created_at', '>=', 'ends_at')
                    ->orWhereNull('status_change_source')
                    ->orWhereNotIn('status_change_source', Reservation::HUMAN_STATUS_CHANGE_SOURCES)
                    ->orWhereNull('status_changed_at')
                    ->orWhereColumn('status_changed_at', '<', 'ends_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (
                $accountId,
                $settings,
                $nowUtc,
                $dryRun,
                &$processed,
                &$summary
            ): bool {
                foreach ($reservations as $reservation) {
                    if ($processed >= self::MAX_RESERVATIONS_PER_RUN) {
                        return false;
                    }

                    $processed++;
                    $result = $this->reconcileCandidate(
                        $accountId,
                        (int) $reservation->id,
                        (int) $reservation->status_version,
                        (int) $reservation->schedule_version,
                        (int) $reservation->mutation_version,
                        $settings,
                        $dryRun,
                        $nowUtc
                    );

                    $summary['checked']++;
                    $summary['reasons'][$result['reason']] = ($summary['reasons'][$result['reason']] ?? 0) + 1;
                    if ($result['eligible']) {
                        $summary['eligible']++;
                    } else {
                        $summary['skipped']++;
                    }
                    if ($result['signaled']) {
                        $summary['signaled']++;
                    }
                }

                return true;
            });

        ksort($summary['reasons']);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{eligible: bool, signaled: bool, reason: string}
     */
    public function reconcileCandidate(
        int $accountId,
        int $reservationId,
        int $expectedStatusVersion,
        int $expectedScheduleVersion,
        int $expectedMutationVersion,
        array $settings,
        bool $dryRun = false,
        ?CarbonInterface $now = null
    ): array {
        $nowUtc = $this->asUtc($now);

        return DB::transaction(function () use (
            $accountId,
            $reservationId,
            $expectedStatusVersion,
            $expectedScheduleVersion,
            $expectedMutationVersion,
            $settings,
            $dryRun,
            $nowUtc
        ): array {
            $reservation = Reservation::query()
                ->forAccount($accountId)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return ['eligible' => false, 'signaled' => false, 'reason' => 'tenant_or_reservation_mismatch'];
            }

            if (
                (int) $reservation->status_version !== $expectedStatusVersion
                || (int) $reservation->schedule_version !== $expectedScheduleVersion
                || (int) $reservation->mutation_version !== $expectedMutationVersion
            ) {
                return ['eligible' => false, 'signaled' => false, 'reason' => 'version_changed'];
            }

            $hasCheckIn = ReservationCheckIn::query()
                ->forAccount($accountId)
                ->where('reservation_id', $reservationId)
                ->exists();
            $queueStatuses = ReservationQueueItem::query()
                ->forAccount($accountId)
                ->where('reservation_id', $reservationId)
                ->pluck('status')
                ->map(fn ($status): string => (string) $status)
                ->all();

            $decision = $this->decision->decide(
                $reservation,
                $nowUtc,
                $settings,
                $hasCheckIn,
                $queueStatuses
            );

            if ($decision['action'] !== PastReservationOutcomeDecision::ACTION_SIGNAL) {
                return ['eligible' => false, 'signaled' => false, 'reason' => $decision['reason']];
            }

            if ($dryRun) {
                return ['eligible' => true, 'signaled' => false, 'reason' => $decision['reason']];
            }

            $idempotencyKey = $this->idempotencyKey($reservation);
            if (ReservationStatusTransition::query()
                ->where('account_id', $accountId)
                ->where('idempotency_key', $idempotencyKey)
                ->exists()) {
                return ['eligible' => true, 'signaled' => false, 'reason' => 'already_recorded'];
            }

            $reservation->forceFill([
                'outcome_review_required_at' => $nowUtc,
                'outcome_review_reason_code' => $decision['reason'],
                'mutation_version' => $expectedMutationVersion + 1,
            ])->save();

            ReservationStatusTransition::query()->create([
                'account_id' => $accountId,
                'reservation_id' => $reservationId,
                'event_type' => ReservationStatusTransition::EVENT_OUTCOME_REVIEW_REQUESTED,
                'from_status' => (string) $reservation->status,
                'to_status' => (string) $reservation->status,
                'actor_type' => ReservationStatusTransition::ACTOR_SYSTEM,
                'actor_user_id' => null,
                'source' => self::SOURCE,
                'reason_code' => $decision['reason'],
                'reason' => $this->reasonDescription($decision['reason']),
                'status_version' => (int) $reservation->status_version,
                'schedule_version' => (int) $reservation->schedule_version,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'mode' => 'signal_only',
                    'ends_at' => $reservation->ends_at?->toIso8601String(),
                    'grace_minutes' => (int) ($settings['past_reservation_grace_minutes'] ?? 120),
                    'has_check_in' => $hasCheckIn,
                    'queue_statuses' => array_values(array_unique($queueStatuses)),
                    'mutation_version' => $expectedMutationVersion + 1,
                ],
                'occurred_at' => $nowUtc,
            ]);

            return ['eligible' => true, 'signaled' => true, 'reason' => $decision['reason']];
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForAccount(int $accountId): array
    {
        $settings = ReservationSetting::query()
            ->forAccount($accountId)
            ->accountDefault()
            ->first();

        return [
            'past_reservation_reconciliation_enabled' => (bool) ($settings?->past_reservation_reconciliation_enabled ?? false),
            'past_reservation_reconciliation_mode' => (string) ($settings?->past_reservation_reconciliation_mode ?? 'signal_only'),
            'past_reservation_grace_minutes' => max(0, min(10080, (int) ($settings?->past_reservation_grace_minutes ?? 120))),
            'past_reservation_max_catchup_days' => max(1, min(365, (int) ($settings?->past_reservation_max_catchup_days ?? 7))),
        ];
    }

    /**
     * @return array{checked: int, eligible: int, signaled: int, skipped: int, reasons: array<string, int>, dry_run: bool, enabled: bool}
     */
    private function emptySummary(bool $dryRun, bool $enabled): array
    {
        return [
            'checked' => 0,
            'eligible' => 0,
            'signaled' => 0,
            'skipped' => 0,
            'reasons' => [],
            'dry_run' => $dryRun,
            'enabled' => $enabled,
        ];
    }

    private function idempotencyKey(Reservation $reservation): string
    {
        return hash('sha256', implode(':', [
            self::SOURCE,
            (int) $reservation->account_id,
            (int) $reservation->id,
            (int) $reservation->status_version,
            (int) $reservation->schedule_version,
            (int) $reservation->mutation_version,
        ]));
    }

    private function reasonDescription(string $reason): string
    {
        return match ($reason) {
            PastReservationOutcomeDecision::REASON_PRESENCE_EVIDENCE => 'Past reservation has presence or queue evidence and needs a human outcome decision.',
            PastReservationOutcomeDecision::REASON_BACKDATED => 'Reservation was created after its scheduled end and needs a human outcome decision.',
            PastReservationOutcomeDecision::REASON_LEGACY_UNKNOWN => 'Past reservation has no reliable status provenance and needs a human outcome decision.',
            PastReservationOutcomeDecision::REASON_STALE_BACKLOG => 'Past reservation is outside the normal catch-up window and needs a human outcome decision.',
            default => 'Past reservation has no terminal outcome and needs a human decision.',
        };
    }

    private function asUtc(?CarbonInterface $now): Carbon
    {
        if ($now !== null) {
            return Carbon::instance($now->toDateTime())->utc();
        }

        return now('UTC');
    }
}
