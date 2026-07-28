<?php

namespace App\Services\Capacity;

use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\TelemetryScope;

class CapacityRunContextService
{
    private const STATE_KEY = 'capacity:scenario-state';

    private const ACTIVE_KEY_PREFIX = 'capacity:active-scenario:';

    private const STARTED_KEY_PREFIX = 'capacity:started-scenario:';

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly TelemetryScope $scope
    ) {}

    public function activeScenarioKey(): ?string
    {
        $scopeId = $this->scope->activeId();
        if ($scopeId === null) {
            return null;
        }

        return $this->activeScenarioKeyForScope($scopeId);
    }

    public function start(string $scenarioKey): bool
    {
        $scopeId = $this->scope->activeId();
        $scenarioKey = trim($scenarioKey);
        if ($scopeId === null || $scenarioKey === '') {
            return false;
        }

        $activeKey = $this->activeKey($scopeId);
        $startedKey = $this->startedKey($scopeId, $scenarioKey);

        return $this->cache->synchronized($activeKey, function () use (
            $activeKey,
            $scenarioKey,
            $scopeId,
            $startedKey
        ): bool {
            if (! $this->cache->add($startedKey, true, $this->retentionHours())) {
                return false;
            }
            if (! $this->cache->add($activeKey, $scenarioKey, $this->retentionHours())) {
                $this->cache->forget($startedKey);

                return false;
            }

            if ($this->recordState($scopeId, $scenarioKey, 'started')) {
                return true;
            }

            $this->cache->forget($activeKey);
            $this->cache->forget($startedKey);

            return false;
        });
    }

    public function stop(string $scenarioKey): bool
    {
        $scopeId = $this->scope->activeId();
        $scenarioKey = trim($scenarioKey);
        if ($scopeId === null || $scenarioKey === '') {
            return false;
        }

        $activeKey = $this->activeKey($scopeId);

        return $this->cache->synchronized($activeKey, function () use ($activeKey, $scenarioKey, $scopeId): bool {
            if ($this->activeScenarioKeyForScope($scopeId) !== $scenarioKey
                || ! $this->recordState($scopeId, $scenarioKey, 'stopped')) {
                return false;
            }

            return $this->cache->forget($activeKey);
        });
    }

    public function cancel(string $scenarioKey): bool
    {
        $scopeId = $this->scope->activeId();
        $scenarioKey = trim($scenarioKey);
        if ($scopeId === null || $scenarioKey === '') {
            return false;
        }

        $activeKey = $this->activeKey($scopeId);

        return $this->cache->synchronized($activeKey, function () use ($activeKey, $scenarioKey, $scopeId): bool {
            if ($this->activeScenarioKeyForScope($scopeId) !== $scenarioKey
                || ! $this->cache->forget($activeKey)) {
                return false;
            }

            $startedForgotten = $this->cache->forget($this->startedKey($scopeId, $scenarioKey));
            $stateRecorded = $this->recordState($scopeId, $scenarioKey, 'cancelled');

            return $startedForgotten && $stateRecorded;
        });
    }

    private function recordState(string $scopeId, string $scenarioKey, string $state): bool
    {
        return $this->cache->append(self::STATE_KEY, [
            'scope_id' => $scopeId,
            'scenario_key' => $scenarioKey,
            'state' => $state,
            'recorded_at' => now()->toIso8601String(),
        ], 100, $this->retentionHours());
    }

    private function activeKey(string $scopeId): string
    {
        return self::ACTIVE_KEY_PREFIX.$scopeId;
    }

    private function activeScenarioKeyForScope(string $scopeId): ?string
    {
        $activeKey = $this->activeKey($scopeId);
        $scenarioKey = $this->cache->values([$activeKey])[$activeKey] ?? null;

        return is_string($scenarioKey) && trim($scenarioKey) !== '' ? trim($scenarioKey) : null;
    }

    private function startedKey(string $scopeId, string $scenarioKey): string
    {
        return self::STARTED_KEY_PREFIX.$scopeId.':'.sha1($scenarioKey);
    }

    private function retentionHours(): int
    {
        return max(24, (int) config('observability.request.retention_hours', 24));
    }
}
