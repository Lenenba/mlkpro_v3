<?php

namespace App\Services\Capacity;

class CapacityScenarioCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return collect(config('capacity.scenarios', []))
            ->map(function ($scenario, $key) {
                if (! is_array($scenario)) {
                    return null;
                }

                $routeNames = $scenario['route_names'] ?? [$scenario['route_name'] ?? null];
                $routeNames = is_array($routeNames) ? $routeNames : [$routeNames];
                $routeNames = array_values(array_filter(array_map(
                    static fn ($routeName) => is_string($routeName) && trim($routeName) !== '' ? trim($routeName) : null,
                    $routeNames
                )));

                if ($routeNames === []) {
                    return null;
                }

                $targets = is_array($scenario['targets'] ?? null) ? $scenario['targets'] : [];
                $profile = is_array($scenario['profile'] ?? null) ? $scenario['profile'] : [];
                $remediation = is_array($scenario['remediation'] ?? null) ? $scenario['remediation'] : [];

                return [
                    'key' => (string) $key,
                    'label' => (string) ($scenario['label'] ?? $key),
                    'method' => strtoupper((string) ($scenario['method'] ?? 'GET')),
                    'route_names' => $routeNames,
                    'targets' => [
                        'min_samples' => max(1, (int) ($targets['min_samples'] ?? 10)),
                        'p95_ms' => max(1, (int) ($targets['p95_ms'] ?? 1000)),
                        'p99_ms' => max(1, (int) ($targets['p99_ms'] ?? 1500)),
                        'error_count_24h' => max(0, (int) ($targets['error_count_24h'] ?? 0)),
                    ],
                    'profile' => $profile,
                    'remediation' => array_values(array_filter(array_map(
                        static fn ($item) => is_string($item) && trim($item) !== '' ? trim($item) : null,
                        $remediation
                    ))),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
