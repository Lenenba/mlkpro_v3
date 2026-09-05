<?php

namespace App\Services\Social;

use App\Support\QueueWorkload;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class SocialConnectionDeliveryMutex
{
    private const LOCK_TTL_SECONDS = 3600;

    public function acquire(int $connectionId): ?Lock
    {
        if ($connectionId < 1) {
            throw new InvalidArgumentException('The Pulse social connection lock identity is invalid.');
        }

        $lock = Cache::lock(
            'pulse:social-connection-delivery:v1:'.$connectionId,
            max(self::LOCK_TTL_SECONDS, QueueWorkload::timeout('social_publish') + 60),
        );

        return $lock->get() ? $lock : null;
    }

    public function acquireTenant(int $tenantId): ?Lock
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('The Pulse tenant delivery lock identity is invalid.');
        }

        $lock = Cache::lock(
            'pulse:social-tenant-delivery:v1:'.$tenantId,
            self::LOCK_TTL_SECONDS,
        );

        return $lock->get() ? $lock : null;
    }
}
