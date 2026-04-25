<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Notifications\ProspectStaleReminderNotification;
use App\Services\Requests\LeadTriageClassifier;
use App\Support\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProspectStaleReminderService
{
    public const ACTION_STALE = 'prospect_stale_reminder_sent';

    public function __construct(
        private readonly LeadTriageClassifier $classifier,
        private readonly NotificationPreferenceService $preferences
    ) {}

    /**
     * @return array{scanned:int,stale:int,sent:int,skipped:int}
     */
    public function process(?Carbon $now = null, bool $dryRun = false): array
    {
        $referenceTime = $now?->copy() ?? now();
        $accountIds = LeadRequest::query()
            ->whereNull('archived_at')
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->map(fn ($value) => (int) $value)
            ->values();

        $summary = [
            'scanned' => 0,
            'stale' => 0,
            'sent' => 0,
            'skipped' => 0,
        ];

        foreach ($accountIds as $accountId) {
            $timezone = TaskTimingService::resolveTimezoneForAccountId($accountId);
            $accountNow = $referenceTime->copy()->setTimezone($timezone);
            $today = $accountNow->toDateString();

            $leads = LeadRequest::query()
                ->where('user_id', $accountId)
                ->whereNull('archived_at')
                ->whereNotIn('status', [
                    LeadRequest::STATUS_CONVERTED,
                    LeadRequest::STATUS_WON,
                    LeadRequest::STATUS_LOST,
                ])
                ->with([
                    'user',
                    'assignee.user',
                ])
                ->orderBy('last_activity_at')
                ->orderBy('created_at')
                ->get();

            foreach ($leads as $lead) {
                $summary['scanned']++;

                $classified = $this->classifier->classify($lead, $accountNow);
                if (! ($classified['is_stale'] ?? false)) {
                    continue;
                }

                $summary['stale']++;

                if ($this->alreadySentForDate($lead, $today)) {
                    $summary['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                $daysWithoutActivity = max(1, (int) ($classified['days_since_activity'] ?? 0));
                if ($this->sendReminder(
                    $lead,
                    $daysWithoutActivity,
                    $today,
                    $classified['stale_since_at'] ?? null
                )) {
                    $summary['sent']++;
                } else {
                    $summary['skipped']++;
                }
            }
        }

        return $summary;
    }

    private function alreadySentForDate(LeadRequest $lead, string $reminderDate): bool
    {
        $lastReminder = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', self::ACTION_STALE)
            ->latest('created_at')
            ->first();

        return data_get($lastReminder?->properties, 'reminder_date') === $reminderDate;
    }

    private function recordAudit(
        LeadRequest $lead,
        int $daysWithoutActivity,
        string $reminderDate,
        ?Carbon $staleSinceAt,
        int $recipientCount
    ): void {
        ActivityLog::record(null, $lead, self::ACTION_STALE, [
            'lead_id' => $lead->id,
            'reminder_date' => $reminderDate,
            'days_without_activity' => $daysWithoutActivity,
            'stale_since_at' => $staleSinceAt?->toIso8601String(),
            'recipient_count' => $recipientCount,
        ], 'Prospect stale reminder sent');
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(LeadRequest $lead): Collection
    {
        return collect([
            $lead->user,
            $lead->assignee?->user,
        ])
            ->filter(fn ($recipient) => $recipient instanceof User)
            ->unique('id')
            ->filter(fn (User $recipient) => $this->preferences->shouldNotify(
                $recipient,
                NotificationPreferenceService::CATEGORY_CRM,
                NotificationPreferenceService::CHANNEL_IN_APP
            ))
            ->values();
    }

    private function sendReminder(
        LeadRequest $lead,
        int $daysWithoutActivity,
        string $reminderDate,
        ?Carbon $staleSinceAt
    ): bool {
        $recipients = $this->recipients($lead);
        if ($recipients->isEmpty()) {
            return false;
        }

        $sent = false;

        foreach ($recipients as $recipient) {
            $sent = NotificationDispatcher::send(
                $recipient,
                new ProspectStaleReminderNotification($lead, $daysWithoutActivity),
                [
                    'lead_id' => $lead->id,
                    'recipient_id' => $recipient->id,
                    'days_without_activity' => $daysWithoutActivity,
                ]
            ) || $sent;
        }

        if ($sent) {
            $this->recordAudit($lead, $daysWithoutActivity, $reminderDate, $staleSinceAt, $recipients->count());
        }

        return $sent;
    }
}
