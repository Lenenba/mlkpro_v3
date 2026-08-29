<?php

namespace App\Services\Social;

use App\Data\Social\SocialDeliveryStatusResultData;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SocialReconciliationCadence
{
    public function canReserve(string $status, int $completedAttempts): bool
    {
        if (! in_array($status, SocialDeliveryStatusResultData::allowedStatuses(), true)) {
            throw new InvalidArgumentException('The social delivery reconciliation status is invalid.');
        }

        if ($completedAttempts < 0) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation completed attempt count cannot be negative.',
            );
        }

        return match ($status) {
            SocialDeliveryStatusResultData::STATUS_SENDING => $completedAttempts < 5,
            SocialDeliveryStatusResultData::STATUS_UNKNOWN => $completedAttempts < 3,
            default => true,
        };
    }

    public function nextAt(
        string $status,
        ?CarbonImmutable $remoteScheduledFor,
        int $attempts,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if (! in_array($status, SocialDeliveryStatusResultData::allowedStatuses(), true)) {
            throw new InvalidArgumentException('The social delivery reconciliation status is invalid.');
        }

        if ($attempts <= 0) {
            throw new InvalidArgumentException(
                'The social delivery reconciliation attempt number must be positive.',
            );
        }

        $now = $now->utc();

        return match ($status) {
            SocialDeliveryStatusResultData::STATUS_SENDING => $attempts < 5
                ? $now->addMinutes(2)
                : null,
            SocialDeliveryStatusResultData::STATUS_UNKNOWN => $attempts < 3
                ? $now->addMinutes(5)
                : null,
            SocialDeliveryStatusResultData::STATUS_SCHEDULED => $this->nextScheduledAt(
                $remoteScheduledFor,
                $now,
            ),
            default => null,
        };
    }

    private function nextScheduledAt(
        ?CarbonImmutable $remoteScheduledFor,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if (! $remoteScheduledFor) {
            return null;
        }

        $secondsUntilScheduled = $now->diffInSeconds($remoteScheduledFor->utc(), false);

        if ($secondsUntilScheduled > 24 * 60 * 60) {
            return $now->addDay();
        }

        if ($secondsUntilScheduled > 2 * 60 * 60) {
            return $now->addHours(2);
        }

        if ($secondsUntilScheduled > 0) {
            return $now->addMinutes(15);
        }

        if ($secondsUntilScheduled >= -30 * 60) {
            return $now->addMinutes(5);
        }

        return null;
    }
}
