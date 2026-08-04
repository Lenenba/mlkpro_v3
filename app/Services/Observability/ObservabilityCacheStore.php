<?php

namespace App\Services\Observability;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ObservabilityCacheStore
{
    private const DROPPED_WRITES_KEY = 'health:dropped-writes';

    private int $localDroppedWrites = 0;

    private int $localReadFailures = 0;

    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(string $key, array $entry, int $limit, int $ttlHours): bool
    {
        return $this->mutate($key, $ttlHours, static function (mixed $stored) use ($entry, $limit): array {
            $entries = is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
            $entries[] = $entry;

            return array_slice($entries, -1 * max(1, $limit));
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(string $key): array
    {
        try {
            $entries = $this->cache()->get($this->key($key), []);

            return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
        } catch (Throwable $exception) {
            $this->localReadFailures++;
            $this->logFailure('read', $exception);

            return [];
        }
    }

    public function addIndexValue(string $key, string $value, int $ttlHours): bool
    {
        $now = now()->timestamp;
        $cutoff = now()->subHours(max(1, $ttlHours))->timestamp;
        $limit = max(25, (int) config('observability.cache.index_size', 500));

        return $this->mutate($key, $ttlHours, static function (mixed $stored) use ($value, $now, $cutoff, $limit): array {
            $indexed = [];

            foreach (is_array($stored) ? $stored : [] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $indexed[$entry] = $now;

                    continue;
                }

                if (! is_array($entry) || ! is_string($entry['value'] ?? null)) {
                    continue;
                }

                $seenAt = (int) ($entry['seen_at'] ?? 0);
                if ($seenAt >= $cutoff) {
                    $indexed[$entry['value']] = max($seenAt, (int) ($indexed[$entry['value']] ?? 0));
                }
            }

            $indexed[$value] = $now;
            asort($indexed);

            return collect(array_slice($indexed, -1 * $limit, null, true))
                ->map(fn (int $seenAt, string $indexedValue): array => [
                    'value' => $indexedValue,
                    'seen_at' => $seenAt,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, string>
     */
    public function indexValues(string $key, int $ttlHours): array
    {
        $cutoff = now()->subHours(max(1, $ttlHours))->timestamp;

        return collect($this->get($key))
            ->filter(fn (array $entry): bool => is_string($entry['value'] ?? null)
                && (int) ($entry['seen_at'] ?? 0) >= $cutoff)
            ->pluck('value')
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function increment(string $key, int $ttlHours, int $amount = 1): bool
    {
        return $this->incrementValue($key, $ttlHours, $amount) !== null;
    }

    public function incrementValue(string $key, int $ttlHours, int $amount = 1): ?int
    {
        try {
            $cache = $this->cache();
            $cacheKey = $this->key($key);
            $cache->add($cacheKey, 0, now()->addHours(max(1, $ttlHours)));

            $incremented = $cache->increment($cacheKey, $amount);
            if ($incremented === false) {
                $this->markDroppedWrite('increment_failed');

                return null;
            }

            return (int) $incremented;
        } catch (Throwable $exception) {
            $this->markDroppedWrite('increment_exception', $exception);

            return null;
        }
    }

    public function put(string $key, mixed $value, int $ttlHours): bool
    {
        try {
            return $this->cache()->put(
                $this->key($key),
                $value,
                now()->addHours(max(1, $ttlHours))
            );
        } catch (Throwable $exception) {
            $this->markDroppedWrite('put_exception', $exception);

            return false;
        }
    }

    public function add(string $key, mixed $value, int $ttlHours): bool
    {
        try {
            return $this->cache()->add(
                $this->key($key),
                $value,
                now()->addHours(max(1, $ttlHours))
            );
        } catch (Throwable $exception) {
            $this->markDroppedWrite('add_exception', $exception);

            return false;
        }
    }

    public function forget(string $key): bool
    {
        try {
            return $this->cache()->forget($this->key($key));
        } catch (Throwable $exception) {
            $this->markDroppedWrite('forget_exception', $exception);

            return false;
        }
    }

    /**
     * @param  Closure(): bool  $callback
     */
    public function synchronized(string $key, Closure $callback): bool
    {
        $lock = null;
        $acquired = false;

        try {
            $store = $this->cache()->getStore();

            if (! $store instanceof LockProvider) {
                $this->markDroppedWrite('lock_unsupported');

                return false;
            }

            $lock = $store->lock(
                $this->key('locks:'.sha1($key)),
                max(1, (int) config('observability.cache.lock_seconds', 5))
            );
            $waitMilliseconds = max(0, (int) config('observability.cache.lock_wait_ms', 25));
            $deadline = hrtime(true) + ($waitMilliseconds * 1_000_000);

            do {
                $acquired = $lock->get();
                if ($acquired || hrtime(true) >= $deadline) {
                    break;
                }

                usleep(1_000);
            } while (true);

            if (! $acquired) {
                $this->markDroppedWrite('lock_timeout');

                return false;
            }

            return $callback();
        } catch (Throwable $exception) {
            $this->markDroppedWrite('synchronized_exception', $exception);

            return false;
        } finally {
            if ($acquired && $lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable $exception) {
                    $this->logFailure('lock_release', $exception);
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function values(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        if ($keys === []) {
            return [];
        }

        $qualified = collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->key($key)])
            ->all();

        try {
            $values = [];
            foreach (array_chunk($qualified, 500, true) as $chunk) {
                $values = array_merge($values, $this->cache()->many(array_values($chunk)));
            }

            return collect($qualified)
                ->mapWithKeys(fn (string $cacheKey, string $key): array => [$key => $values[$cacheKey] ?? null])
                ->all();
        } catch (Throwable $exception) {
            $this->localReadFailures++;
            $this->logFailure('batch_read', $exception);

            return array_fill_keys($keys, null);
        }
    }

    public function integer(string $key): int
    {
        try {
            $value = $this->cache()->get($this->key($key), 0);

            return is_numeric($value) ? (int) $value : 0;
        } catch (Throwable $exception) {
            $this->localReadFailures++;
            $this->logFailure('counter_read', $exception);

            return 0;
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, int>
     */
    public function integers(array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        if ($keys === []) {
            return [];
        }

        $qualified = collect($keys)
            ->mapWithKeys(fn (string $key): array => [$key => $this->key($key)])
            ->all();

        try {
            $values = [];
            foreach (array_chunk($qualified, 500, true) as $chunk) {
                $values = array_merge($values, $this->cache()->many(array_values($chunk)));
            }

            return collect($qualified)
                ->mapWithKeys(function (string $cacheKey, string $key) use ($values): array {
                    $value = $values[$cacheKey] ?? 0;

                    return [$key => is_numeric($value) ? (int) $value : 0];
                })
                ->all();
        } catch (Throwable $exception) {
            $this->localReadFailures++;
            $this->logFailure('counter_batch_read', $exception);

            return array_fill_keys($keys, 0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $storeName = $this->storeName();
        $driver = (string) config("cache.stores.{$storeName}.driver", $storeName);

        $droppedWrites = max($this->localDroppedWrites, $this->integer(self::DROPPED_WRITES_KEY));

        return [
            'store' => $storeName,
            'driver' => $driver,
            'shared' => $this->isSharedStore($storeName, $driver),
            'low_overhead' => in_array($driver, ['redis', 'memcached', 'dynamodb'], true),
            'namespace' => $this->namespace(),
            'dropped_writes' => $droppedWrites,
            'read_failures' => $this->localReadFailures,
        ];
    }

    /**
     * @param  Closure(mixed): array<mixed>  $callback
     */
    private function mutate(string $key, int $ttlHours, Closure $callback): bool
    {
        return $this->synchronized($key, function () use ($key, $ttlHours, $callback): bool {
            $cache = $this->cache();
            $cacheKey = $this->key($key);
            $updated = $callback($cache->get($cacheKey, []));
            if (! $cache->put($cacheKey, $updated, now()->addHours(max(1, $ttlHours)))) {
                $this->markDroppedWrite('mutation_put_failed');

                return false;
            }

            return true;
        });
    }

    private function markDroppedWrite(string $reason, ?Throwable $exception = null): void
    {
        $this->localDroppedWrites++;

        try {
            $cache = $this->cache();
            $key = $this->key(self::DROPPED_WRITES_KEY);
            $cache->add($key, 0, now()->addDay());
            $cache->increment($key);
        } catch (Throwable) {
            // A telemetry failure must never alter the business request.
        }

        $this->logFailure($reason, $exception);
    }

    private function logFailure(string $operation, ?Throwable $exception = null): void
    {
        try {
            Log::warning('observability_cache_failure', [
                'operation' => $operation,
                'exception' => $exception !== null ? $exception::class : null,
            ]);
        } catch (Throwable) {
            // Logging is best-effort as well.
        }
    }

    private function cache(): Repository
    {
        return Cache::store($this->storeName());
    }

    private function storeName(): string
    {
        $configured = trim((string) config('observability.cache.store', config('cache.default', 'database')));

        return $configured !== '' ? $configured : (string) config('cache.default', 'database');
    }

    private function isSharedStore(string $storeName, string $driver): bool
    {
        if ($driver === 'database') {
            $connection = config("cache.stores.{$storeName}.connection") ?: config('database.default');
            $databaseDriver = is_string($connection)
                ? (string) config("database.connections.{$connection}.driver")
                : '';

            return $databaseDriver !== '' && $databaseDriver !== 'sqlite';
        }

        return in_array($driver, ['redis', 'memcached', 'dynamodb'], true);
    }

    private function namespace(): string
    {
        $configured = trim((string) config('observability.cache.prefix', 'observability'));
        $normalized = preg_replace('/[^A-Za-z0-9:_-]+/', '-', $configured) ?? 'observability';

        return trim($normalized, ':-') !== '' ? trim($normalized, ':-') : 'observability';
    }

    private function key(string $key): string
    {
        return $this->namespace().':'.ltrim($key, ':');
    }
}
