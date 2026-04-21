<?php

namespace App\Services\Quotes;

use App\Models\Quote;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use Illuminate\Support\Carbon;

class QuoteRecoveryPriorityScorer
{
    public const LABEL_CLOSED = 'closed';

    public const LABEL_HIGH = 'high';

    public const LABEL_LOW = 'low';

    public const LABEL_MEDIUM = 'medium';

    public const LABEL_URGENT = 'urgent';

    public function score(
        Quote $quote,
        string $queue,
        array $signals = [],
        ?Carbon $referenceTime = null
    ): array {
        $now = $referenceTime ? $referenceTime->copy() : now();

        if ($queue === BuildQuoteRecoveryIndexData::QUEUE_CLOSED) {
            return [
                'score' => 0,
                'label' => self::LABEL_CLOSED,
                'reason' => 'Closed quote',
            ];
        }

        if ($quote->getRawOriginal('recovery_priority') !== null) {
            $manualScore = (int) $quote->getRawOriginal('recovery_priority');

            return [
                'score' => $manualScore,
                'label' => $this->labelFor($manualScore),
                'reason' => 'Manual recovery priority',
            ];
        }

        $score = $this->baseScore($queue);
        $reason = $this->primaryReason($queue, $signals, $now);

        $total = (float) ($quote->total ?? 0);
        if ($total >= 5000) {
            $score += 10;
        } elseif ($total >= 2000) {
            $score += 5;
        }

        $nextFollowUpAt = $signals['next_follow_up_at'] ?? null;
        if (($signals['is_due'] ?? false) && $nextFollowUpAt instanceof Carbon) {
            $score += $nextFollowUpAt->lt($now) ? 10 : 5;
        }

        $lastViewedAt = $signals['last_viewed_at'] ?? null;
        if (($signals['is_viewed_not_accepted'] ?? false) && $lastViewedAt instanceof Carbon) {
            if ($lastViewedAt->gte($now->copy()->subDay())) {
                $score += 10;
            } elseif ($lastViewedAt->gte($now->copy()->subDays(3))) {
                $score += 5;
            }
        }

        $quoteAgeDays = (int) ($signals['quote_age_days'] ?? 0);
        if (($signals['is_never_followed'] ?? false) && $quoteAgeDays > 0 && $quoteAgeDays <= 3) {
            $score += 5;
        }

        if (($signals['is_expired'] ?? false) && $quoteAgeDays >= 30) {
            $score -= 5;
        }

        $followUpCount = (int) ($quote->follow_up_count ?? 0);
        if (($signals['is_expired'] ?? false) && $followUpCount >= 3) {
            $score -= 5;
        }

        $finalScore = max(0, min(100, $score));

        return [
            'score' => $finalScore,
            'label' => $this->labelFor($finalScore),
            'reason' => $reason,
        ];
    }

    private function baseScore(string $queue): int
    {
        return match ($queue) {
            BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED => 90,
            BuildQuoteRecoveryIndexData::QUEUE_DUE => 80,
            BuildQuoteRecoveryIndexData::QUEUE_HIGH_VALUE => 70,
            BuildQuoteRecoveryIndexData::QUEUE_NEVER_FOLLOWED => 60,
            BuildQuoteRecoveryIndexData::QUEUE_EXPIRED => 50,
            BuildQuoteRecoveryIndexData::QUEUE_ACTIVE => 30,
            default => 0,
        };
    }

    private function labelFor(int $score): string
    {
        return match (true) {
            $score >= 90 => self::LABEL_URGENT,
            $score >= 75 => self::LABEL_HIGH,
            $score >= 50 => self::LABEL_MEDIUM,
            $score > 0 => self::LABEL_LOW,
            default => self::LABEL_CLOSED,
        };
    }

    private function primaryReason(string $queue, array $signals, Carbon $referenceTime): string
    {
        return match ($queue) {
            BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED => $this->viewedReason($signals, $referenceTime),
            BuildQuoteRecoveryIndexData::QUEUE_DUE => $this->dueReason($signals, $referenceTime),
            BuildQuoteRecoveryIndexData::QUEUE_HIGH_VALUE => 'High-value open quote',
            BuildQuoteRecoveryIndexData::QUEUE_NEVER_FOLLOWED => 'Sent quote with no follow-up',
            BuildQuoteRecoveryIndexData::QUEUE_EXPIRED => 'Old quote without resolution',
            BuildQuoteRecoveryIndexData::QUEUE_ACTIVE => 'Open quote needs review',
            default => 'Quote requires review',
        };
    }

    private function dueReason(array $signals, Carbon $referenceTime): string
    {
        $nextFollowUpAt = $signals['next_follow_up_at'] ?? null;

        if ($nextFollowUpAt instanceof Carbon && $nextFollowUpAt->lt($referenceTime)) {
            return 'Follow-up overdue';
        }

        return 'Follow-up due soon';
    }

    private function viewedReason(array $signals, Carbon $referenceTime): string
    {
        $lastViewedAt = $signals['last_viewed_at'] ?? null;

        if ($lastViewedAt instanceof Carbon && $lastViewedAt->gte($referenceTime->copy()->subDay())) {
            return 'Viewed recently without decision';
        }

        return 'Viewed quote without decision';
    }
}
