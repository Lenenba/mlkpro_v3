<?php

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Throwable;

class TelemetryScope
{
    /**
     * @return array{environment: string, release: string|null, run_id: string|null, commit: string|null, scope_id: string|null}
     */
    public function tags(): array
    {
        $scope = [
            'environment' => (string) config('app.env'),
            'release' => $this->nullableString(config('observability.release')),
            'run_id' => $this->nullableString(config('capacity.baseline.run_id')),
            'commit' => $this->nullableString(config('capacity.baseline.commit')),
            'started_at' => $this->nullableString(config('capacity.baseline.started_at')),
            'ended_at' => $this->nullableString(config('capacity.baseline.ended_at')),
        ];

        return [
            'environment' => $scope['environment'],
            'release' => $scope['release'],
            'run_id' => $scope['run_id'],
            'commit' => $scope['commit'],
            'scope_id' => $this->idFor($scope),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    public function idFor(array $scope): ?string
    {
        $values = [];
        foreach (['environment', 'release', 'run_id', 'commit', 'started_at', 'ended_at'] as $field) {
            $value = $this->nullableString($scope[$field] ?? null);
            if ($value === null) {
                return null;
            }

            if (in_array($field, ['started_at', 'ended_at'], true)) {
                try {
                    $value = Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
                } catch (Throwable) {
                    return null;
                }
            }

            $values[] = $value;
        }

        return hash('sha256', implode('|', $values));
    }

    public function activeId(): ?string
    {
        $scope = [
            'environment' => (string) config('app.env'),
            'release' => config('observability.release'),
            'run_id' => config('capacity.baseline.run_id'),
            'commit' => config('capacity.baseline.commit'),
            'started_at' => config('capacity.baseline.started_at'),
            'ended_at' => config('capacity.baseline.ended_at'),
        ];
        $scopeId = $this->idFor($scope);
        if ($scopeId === null) {
            return null;
        }

        try {
            $now = now();

            return $now->betweenIncluded(
                Carbon::parse((string) $scope['started_at']),
                Carbon::parse((string) $scope['ended_at'])
            ) ? $scopeId : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<string, mixed>|null  $scope
     * @return array<int, array<string, mixed>>
     */
    public function filter(array $samples, ?array $scope, int $retentionHours): array
    {
        if (($scope['match_none'] ?? false) === true) {
            return [];
        }

        $scope ??= [];

        try {
            $startedAt = isset($scope['started_at'])
                ? Carbon::parse((string) $scope['started_at'])
                : now()->subHours(max(1, $retentionHours));
            $endedAt = isset($scope['ended_at'])
                ? Carbon::parse((string) $scope['ended_at'])
                : now();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($samples, function (array $sample) use ($scope, $startedAt, $endedAt): bool {
            $recordedAt = $sample['recorded_at'] ?? null;
            if (! is_string($recordedAt) || $recordedAt === '') {
                return false;
            }

            try {
                $timestamp = Carbon::parse($recordedAt);
            } catch (Throwable) {
                return false;
            }

            if ($timestamp->lessThan($startedAt) || $timestamp->greaterThan($endedAt)) {
                return false;
            }

            foreach (['environment', 'release', 'run_id', 'commit', 'scope_id'] as $tag) {
                if (! array_key_exists($tag, $scope)) {
                    continue;
                }

                if ($this->nullableString($sample[$tag] ?? null) !== $this->nullableString($scope[$tag])) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
