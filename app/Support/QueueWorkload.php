<?php

namespace App\Support;

class QueueWorkload
{
    public static function queue(string $workload, string $fallback = 'default'): string
    {
        return (string) config("async.workloads.{$workload}.queue", $fallback);
    }

    /**
     * @param  array<int, int>  $fallback
     * @return array<int, int>
     */
    public static function backoff(string $workload, array $fallback = []): array
    {
        $configured = config("async.workloads.{$workload}.backoff", $fallback);
        if (! is_array($configured)) {
            return $fallback;
        }

        $normalized = array_values(array_filter(
            array_map(static fn ($value): int => max(0, (int) $value), $configured),
            static fn (int $value): bool => $value >= 0
        ));

        return $normalized !== [] ? $normalized : $fallback;
    }
}
