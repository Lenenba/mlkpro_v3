<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $connectionName = (string) config('queue.default', 'sync');
        $connection = config("queue.connections.{$connectionName}", []);
        $driver = (string) ($connection['driver'] ?? $connectionName);

        $pendingByQueue = $this->pendingByQueue($driver);
        $pendingJobs = array_sum($pendingByQueue);
        $oldestJobMinutes = $this->oldestJobMinutes($driver);

        return [
            'connection' => $connectionName,
            'driver' => $driver,
            'measurable' => $driver === 'database' && Schema::hasTable('jobs'),
            'pending_jobs' => $pendingJobs,
            'pending_by_queue' => $pendingByQueue,
            'oldest_job_minutes' => $oldestJobMinutes,
            'failed_jobs_24h' => $this->failedJobsSince(now()->subDay()),
            'failed_jobs_7d' => $this->failedJobsSince(now()->subDays(7)),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pendingByQueue(string $driver): array
    {
        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as aggregate')
            ->groupBy('queue')
            ->orderBy('queue')
            ->pluck('aggregate', 'queue')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    private function oldestJobMinutes(string $driver): ?float
    {
        if ($driver !== 'database' || ! Schema::hasTable('jobs')) {
            return null;
        }

        $createdAt = DB::table('jobs')->min('created_at');
        if ($createdAt === null) {
            return null;
        }

        return round((now()->timestamp - (int) $createdAt) / 60, 1);
    }

    private function failedJobsSince(\DateTimeInterface $since): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return DB::table('failed_jobs')
            ->where('failed_at', '>=', $since)
            ->count();
    }
}
