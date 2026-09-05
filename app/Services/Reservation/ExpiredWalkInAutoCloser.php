<?php

namespace App\Services\Reservation;

use App\Models\ReservationQueueItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ExpiredWalkInAutoCloser
{
    public const EXPIRED_REASON = 'Automatically closed because the walk-in ticket remained unserved after the business day ended.';

    public const MAX_TICKETS_PER_ACCOUNT_PER_RUN = 500;

    private const CLOSABLE_STATUSES = [
        ReservationQueueItem::STATUS_CHECKED_IN,
        ReservationQueueItem::STATUS_PRE_CALLED,
        ReservationQueueItem::STATUS_CALLED,
        ReservationQueueItem::STATUS_SKIPPED,
    ];

    /**
     * @return LazyCollection<int, int>
     */
    public function candidateAccountIds(): LazyCollection
    {
        return ReservationQueueItem::query()
            ->select('account_id')
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->whereIn('status', self::CLOSABLE_STATUSES)
            ->distinct()
            ->orderBy('account_id')
            ->lazyById(100, 'account_id')
            ->map(fn (ReservationQueueItem $ticket): int => (int) $ticket->account_id);
    }

    /**
     * @return array{checked: int, eligible: int, closed: int, dry_run: bool}
     */
    public function closeExpired(int $accountId, bool $dryRun = false, ?CarbonInterface $now = null): array
    {
        $nowUtc = $this->asUtc($now);
        $summary = [
            'checked' => 0,
            'eligible' => 0,
            'closed' => 0,
            'dry_run' => $dryRun,
        ];

        $account = User::query()
            ->select(['id', 'company_timezone'])
            ->find($accountId);
        if (! $account) {
            return $summary;
        }

        $localDayStartedAtUtc = Carbon::instance($nowUtc->toDateTime())
            ->setTimezone($this->normalizeTimezone((string) ($account->company_timezone ?: config('app.timezone', 'UTC'))))
            ->startOfDay()
            ->utc();

        $tickets = ReservationQueueItem::query()
            ->select(['id', 'account_id'])
            ->forAccount($accountId)
            ->where('item_type', ReservationQueueItem::TYPE_TICKET)
            ->whereIn('status', self::CLOSABLE_STATUSES)
            ->where('created_at', '<', $localDayStartedAtUtc)
            ->orderBy('id')
            ->limit(self::MAX_TICKETS_PER_ACCOUNT_PER_RUN)
            ->get();

        foreach ($tickets as $ticket) {
            $summary['checked']++;
            $summary['eligible']++;
            if (! $dryRun && $this->closeAsNoShow($ticket, $localDayStartedAtUtc, $nowUtc)) {
                $summary['closed']++;
            }
        }

        return $summary;
    }

    private function closeAsNoShow(
        ReservationQueueItem $ticket,
        CarbonInterface $localDayStartedAtUtc,
        CarbonInterface $nowUtc
    ): bool {
        return DB::transaction(function () use ($ticket, $localDayStartedAtUtc, $nowUtc): bool {
            $locked = ReservationQueueItem::query()
                ->forAccount((int) $ticket->account_id)
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $locked
                || $locked->item_type !== ReservationQueueItem::TYPE_TICKET
                || ! in_array($locked->status, self::CLOSABLE_STATUSES, true)
                || $locked->created_at->gte($localDayStartedAtUtc)
            ) {
                return false;
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['auto_close'] = [
                'closed_at' => $nowUtc->toIso8601String(),
                'reason' => self::EXPIRED_REASON,
                'trigger' => 'reservations:auto-close-expired-walk-ins',
                'previous_status' => (string) $locked->status,
            ];

            $locked->forceFill([
                'status' => ReservationQueueItem::STATUS_NO_SHOW,
                'finished_at' => $nowUtc,
                'call_expires_at' => null,
                'metadata' => $metadata,
            ])->save();

            return true;
        }, 3);
    }

    private function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return (string) config('app.timezone', 'UTC');
        }
    }

    private function asUtc(?CarbonInterface $now): Carbon
    {
        if ($now !== null) {
            return Carbon::instance($now->toDateTime())->utc();
        }

        return now('UTC');
    }
}
