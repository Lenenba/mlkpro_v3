<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;

class ObservabilityCacheStore
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(string $key, array $entry, int $limit, int $ttlHours): void
    {
        $entries = $this->get($key);
        $entries[] = $entry;

        if (count($entries) > $limit) {
            $entries = array_slice($entries, -1 * $limit);
        }

        Cache::put($key, $entries, now()->addHours(max(1, $ttlHours)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(string $key): array
    {
        $entries = Cache::get($key, []);

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    public function addIndexValue(string $key, string $value, int $ttlHours): void
    {
        $values = Cache::get($key, []);
        $values = is_array($values) ? array_values(array_unique(array_merge($values, [$value]))) : [$value];

        Cache::put($key, $values, now()->addHours(max(1, $ttlHours)));
    }

    /**
     * @return array<int, string>
     */
    public function indexValues(string $key): array
    {
        $values = Cache::get($key, []);

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
