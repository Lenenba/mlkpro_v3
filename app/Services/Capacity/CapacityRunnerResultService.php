<?php

namespace App\Services\Capacity;

use App\Services\Observability\ObservabilityCacheStore;
use App\Services\Observability\TelemetryScope;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class CapacityRunnerResultService
{
    public const SCHEMA_VERSION = 3;

    /**
     * @var array<int, string>
     */
    private const RESULT_FIELDS = [
        'schema_version',
        'run_id',
        'environment',
        'commit',
        'scenario_key',
        'manifest_hash',
        'fixture_hash',
        'baseline_fingerprint',
        'target_origin_hash',
        'runner',
        'runner_hash',
        'started_at',
        'ended_at',
        'virtual_users',
        'duration_seconds',
        'ramp_up_seconds',
        'request_interval_ms',
        'request_timeout_ms',
        'attempted_requests',
        'completed_requests',
        'transport_errors',
        'assertion_failures',
        'client_latency_ms',
    ];

    /**
     * @var array<int, string>
     */
    private const LATENCY_FIELDS = [
        'p50',
        'p95',
        'p99',
        'max',
    ];

    public function __construct(
        private readonly ObservabilityCacheStore $cache,
        private readonly CapacityScenarioCatalog $catalog,
        private readonly TelemetryScope $telemetryScope,
        private readonly CapacityPreflightService $preflight,
        private readonly CapacityRunContextService $runContext
    ) {}

    /**
     * Validate and canonicalize an external runner result without persisting it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CapacityRunnerResultValidationException
     */
    public function validate(array $payload): array
    {
        [$result] = $this->validateWithScope($payload);

        return $result;
    }

    /**
     * Validate, sanitize, and persist an external runner result.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws CapacityRunnerResultValidationException
     * @throws RuntimeException
     */
    public function ingest(array $payload): array
    {
        [$result, $scopeId, $baselineFingerprint] = $this->validateWithScope($payload);
        $stored = [
            ...$result,
            'scope_id' => $scopeId,
            'baseline_fingerprint' => $baselineFingerprint,
            'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];

        $storedSuccessfully = $this->cache->append(
            $this->cacheKey($scopeId, (string) $result['scenario_key']),
            $stored,
            max(1, (int) config('capacity.runner_results.limit', 25)),
            $this->retentionHours()
        );

        if (! $storedSuccessfully) {
            throw new RuntimeException('The validated capacity runner result could not be persisted.');
        }

        return $stored;
    }

    /**
     * Return the latest result for the baseline currently configured.
     *
     * @return array<string, mixed>|null
     */
    public function latestForCurrentScope(string $scenarioKey): ?array
    {
        $scopeId = $this->currentScopeId();
        if ($scopeId === null) {
            return null;
        }

        $result = $this->latestForScope($scopeId, $scenarioKey);
        if ($result === null
            || ! hash_equals(
                $this->baselineFingerprint(),
                (string) ($result['baseline_fingerprint'] ?? '')
            )) {
            return null;
        }

        return $result;
    }

    /**
     * Return the latest result matching an explicit telemetry scope.
     *
     * @return array<string, mixed>|null
     */
    public function latestForScope(string $scopeId, string $scenarioKey): ?array
    {
        $scopeId = strtolower(trim($scopeId));
        $scenarioKey = trim($scenarioKey);
        if (preg_match('/^[a-f0-9]{64}$/', $scopeId) !== 1 || $scenarioKey === '') {
            return null;
        }

        $scenario = $this->scenario($scenarioKey);
        if ($scenario === null) {
            return null;
        }

        $manifestHash = (string) ($scenario['manifest_hash'] ?? '');

        foreach (array_reverse($this->cache->get($this->cacheKey($scopeId, $scenarioKey))) as $result) {
            if (($result['schema_version'] ?? null) !== self::SCHEMA_VERSION
                || ($result['scope_id'] ?? null) !== $scopeId
                || ($result['scenario_key'] ?? null) !== $scenarioKey
                || ($result['manifest_hash'] ?? null) !== $manifestHash) {
                continue;
            }

            return $result;
        }

        return null;
    }

    public function currentScopeId(): ?string
    {
        $baseline = $this->baselineConfiguration();

        return $this->telemetryScope->idFor([
            'environment' => $baseline['environment'] ?? null,
            'release' => config('observability.release'),
            'run_id' => $baseline['run_id'] ?? null,
            'commit' => $baseline['commit'] ?? null,
            'started_at' => $baseline['started_at'] ?? null,
            'ended_at' => $baseline['ended_at'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     *
     * @throws CapacityRunnerResultValidationException
     */
    private function validateWithScope(array $payload): array
    {
        $errors = [];
        $this->validateShape($payload, $errors);

        $preflight = $this->preflight->summary();
        if (($preflight['ready'] ?? false) !== true || ($preflight['issues'] ?? []) !== []) {
            $errors[] = 'The current capacity preflight is not ready to accept runner evidence.';
        }
        foreach ($this->catalog->issues() as $issue) {
            $errors[] = 'The current capacity catalog is invalid: '.$issue;
        }

        $baseline = $this->validatedBaseline($errors);
        $scenarioKey = $this->requiredString($payload, 'scenario_key', $errors);
        $scenario = $scenarioKey === null ? null : $this->scenario($scenarioKey);
        if ($scenarioKey !== null && $scenario === null) {
            $errors[] = 'scenario_key does not identify a configured capacity scenario.';
        }

        $schemaVersion = $this->requiredInteger($payload, 'schema_version', $errors, 1);
        if ($schemaVersion !== null && $schemaVersion !== self::SCHEMA_VERSION) {
            $errors[] = sprintf(
                'schema_version must be %d.',
                self::SCHEMA_VERSION
            );
        }

        $runId = $this->requiredString($payload, 'run_id', $errors);
        $environment = $this->requiredString($payload, 'environment', $errors);
        $commit = $this->requiredString($payload, 'commit', $errors);
        $manifestHash = $this->hash($payload, 'manifest_hash', $errors);
        $fixtureHash = $this->hash($payload, 'fixture_hash', $errors);
        $baselineFingerprint = $this->hash($payload, 'baseline_fingerprint', $errors);
        $targetOriginHash = $this->hash($payload, 'target_origin_hash', $errors);
        $runner = $this->requiredString($payload, 'runner', $errors);
        $runnerHash = $this->hash($payload, 'runner_hash', $errors);
        $expectedBaselineFingerprint = $this->baselineFingerprint();

        $this->matchesBaseline($runId, 'run_id', $baseline, $errors);
        $this->matchesBaseline($environment, 'environment', $baseline, $errors);
        $this->matchesBaseline($commit, 'commit', $baseline, $errors);
        $this->matchesBaseline($runner, 'runner', $baseline, $errors);
        $this->matchesBaseline($runnerHash, 'runner_hash', $baseline, $errors);
        $this->matchesBaseline($fixtureHash, 'fixture_hash', $baseline, $errors);

        if ($targetOriginHash !== null
            && ! in_array($targetOriginHash, $this->approvedOriginHashes($baseline), true)) {
            $errors[] = 'target_origin_hash does not match an approved baseline origin.';
        }

        if ($baselineFingerprint !== null
            && ! hash_equals($expectedBaselineFingerprint, $baselineFingerprint)) {
            $errors[] = 'baseline_fingerprint does not match the current baseline identity.';
        }

        if ($manifestHash !== null
            && $scenario !== null
            && $manifestHash !== ($scenario['manifest_hash'] ?? null)) {
            $errors[] = 'manifest_hash does not match the current scenario manifest.';
        }
        if ($scenario !== null
            && $this->isFormalBlocker(is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [])) {
            $errors[] = 'scenario_key is blocked by an active formal capacity blocker.';
        }

        $startedAt = $this->utcTimestamp($payload, 'started_at', $errors);
        $endedAt = $this->utcTimestamp($payload, 'ended_at', $errors);
        if ($startedAt !== null && $endedAt !== null) {
            if ($startedAt->greaterThanOrEqualTo($endedAt)) {
                $errors[] = 'started_at must be earlier than ended_at.';
            }

            if (($baseline['started_at_parsed'] ?? null) instanceof Carbon
                && $startedAt->lessThan($baseline['started_at_parsed'])) {
                $errors[] = 'started_at must be inside the configured baseline period.';
            }

            if (($baseline['ended_at_parsed'] ?? null) instanceof Carbon
                && $endedAt->greaterThan($baseline['ended_at_parsed'])) {
                $errors[] = 'ended_at must be inside the configured baseline period.';
            }
            if ($endedAt->isFuture()) {
                $errors[] = 'ended_at cannot be in the future.';
            }
        }

        $virtualUsers = $this->requiredInteger($payload, 'virtual_users', $errors, 1);
        $durationSeconds = $this->requiredInteger($payload, 'duration_seconds', $errors, 1);
        $rampUpSeconds = $this->requiredInteger($payload, 'ramp_up_seconds', $errors, 0);
        $requestIntervalMs = $this->requiredInteger($payload, 'request_interval_ms', $errors, 1);
        $requestTimeoutMs = $this->requiredInteger($payload, 'request_timeout_ms', $errors, 500);
        $attemptedRequests = $this->requiredInteger($payload, 'attempted_requests', $errors, 0);
        $completedRequests = $this->requiredInteger($payload, 'completed_requests', $errors, 0);
        $transportErrors = $this->requiredInteger($payload, 'transport_errors', $errors, 0);
        $assertionFailures = $this->requiredInteger($payload, 'assertion_failures', $errors, 0);

        if ($startedAt !== null
            && $endedAt !== null
            && $durationSeconds !== null
            && $startedAt->lessThan($endedAt)) {
            $actualDurationMicroseconds = (float) $startedAt->diffInMicroseconds($endedAt);
            $declaredDurationMicroseconds = (float) ($durationSeconds * 1_000_000);
            $durationToleranceMicroseconds = min(
                5,
                max(0, (float) config('capacity.runner_results.duration_tolerance_seconds', 2))
            ) * 1_000_000;

            if (abs($actualDurationMicroseconds - $declaredDurationMicroseconds) > $durationToleranceMicroseconds) {
                $errors[] = 'ended_at minus started_at must match duration_seconds within the configured tolerance.';
            }
        }

        if ($scenario !== null) {
            $this->validateProfile(
                $scenario,
                $virtualUsers,
                $durationSeconds,
                $rampUpSeconds,
                $requestIntervalMs,
                $requestTimeoutMs,
                $errors
            );

            $minimumSamples = max(1, (int) data_get($scenario, 'targets.min_samples', 1));
            $minimumCompletedRequests = max(
                $minimumSamples,
                (int) data_get($scenario, 'profile.minimum_completed_requests', $minimumSamples)
            );
            if ($attemptedRequests !== null && $attemptedRequests < $minimumSamples) {
                $errors[] = "attempted_requests must be at least {$minimumSamples} for this scenario.";
            }
            if ($completedRequests !== null && $completedRequests < $minimumSamples) {
                $errors[] = "completed_requests must be at least {$minimumSamples} for this scenario.";
            }
            if ($attemptedRequests !== null && $attemptedRequests < $minimumCompletedRequests) {
                $errors[] = "attempted_requests must satisfy the scenario load envelope ({$minimumCompletedRequests}).";
            }
            if ($completedRequests !== null && $completedRequests < $minimumCompletedRequests) {
                $errors[] = "completed_requests must satisfy the scenario load envelope ({$minimumCompletedRequests}).";
            }
        }

        if ($transportErrors !== null && $transportErrors !== 0) {
            $errors[] = 'transport_errors must be zero for an accepted capacity result.';
        }
        if ($assertionFailures !== null && $assertionFailures !== 0) {
            $errors[] = 'assertion_failures must be zero for an accepted capacity result.';
        }
        if ($attemptedRequests !== null
            && $completedRequests !== null
            && $transportErrors !== null
            && $attemptedRequests !== $completedRequests + $transportErrors) {
            $errors[] = 'attempted_requests must equal completed_requests plus transport_errors.';
        }

        $latency = $this->latency($payload, $errors);
        $scopeId = $baseline['scope_id'] ?? null;
        if (is_string($scopeId)
            && $scenarioKey !== null
            && $startedAt !== null
            && $endedAt !== null) {
            $this->validateLifecycle($scopeId, $scenarioKey, $startedAt, $endedAt, $errors);
        }

        if ($errors !== [] || ! is_string($scopeId)) {
            throw new CapacityRunnerResultValidationException(array_values(array_unique($errors)));
        }

        return [[
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'environment' => $environment,
            'commit' => $commit,
            'scenario_key' => $scenarioKey,
            'manifest_hash' => $manifestHash,
            'fixture_hash' => $fixtureHash,
            'baseline_fingerprint' => $baselineFingerprint,
            'target_origin_hash' => $targetOriginHash,
            'runner' => $runner,
            'runner_hash' => $runnerHash,
            'started_at' => $startedAt?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'ended_at' => $endedAt?->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'virtual_users' => $virtualUsers,
            'duration_seconds' => $durationSeconds,
            'ramp_up_seconds' => $rampUpSeconds,
            'request_interval_ms' => $requestIntervalMs,
            'request_timeout_ms' => $requestTimeoutMs,
            'attempted_requests' => $attemptedRequests,
            'completed_requests' => $completedRequests,
            'transport_errors' => $transportErrors,
            'assertion_failures' => $assertionFailures,
            'client_latency_ms' => $latency,
        ], $scopeId, $expectedBaselineFingerprint];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     */
    private function validateShape(array $payload, array &$errors): void
    {
        foreach (array_diff(self::RESULT_FIELDS, array_keys($payload)) as $field) {
            $errors[] = "{$field} is required.";
        }

        foreach (array_diff(array_keys($payload), self::RESULT_FIELDS) as $field) {
            $errors[] = "{$field} is not allowed in a capacity runner result.";
        }

        $latency = $payload['client_latency_ms'] ?? null;
        if (! is_array($latency)) {
            if (array_key_exists('client_latency_ms', $payload)) {
                $errors[] = 'client_latency_ms must be an object.';
            }

            return;
        }

        foreach (array_diff(self::LATENCY_FIELDS, array_keys($latency)) as $field) {
            $errors[] = "client_latency_ms.{$field} is required.";
        }

        foreach (array_diff(array_keys($latency), self::LATENCY_FIELDS) as $field) {
            $errors[] = "client_latency_ms.{$field} is not allowed.";
        }
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function validatedBaseline(array &$errors): array
    {
        $baseline = $this->baselineConfiguration();

        foreach ([
            'run_id',
            'environment',
            'commit',
            'started_at',
            'ended_at',
            'traffic',
            'runner',
            'runner_hash',
            'fixture_hash',
            'allowed_origins',
            'exclusions',
            'mode',
            'approval_reference',
            'owner',
            'validator',
        ] as $field) {
            if (! is_string($baseline[$field] ?? null) || trim((string) $baseline[$field]) === '') {
                $errors[] = "The configured baseline {$field} is required.";
            } else {
                $baseline[$field] = trim((string) $baseline[$field]);
            }
        }

        if (is_string($baseline['runner_hash'] ?? null)) {
            $baseline['runner_hash'] = strtolower($baseline['runner_hash']);
            if (preg_match('/^[a-f0-9]{64}$/', $baseline['runner_hash']) !== 1) {
                $errors[] = 'The configured baseline runner_hash must be a 64-character SHA-256 hexadecimal digest.';
            }
        }
        if (is_string($baseline['fixture_hash'] ?? null)) {
            $baseline['fixture_hash'] = strtolower($baseline['fixture_hash']);
            if (preg_match('/^[a-f0-9]{64}$/', $baseline['fixture_hash']) !== 1) {
                $errors[] = 'The configured baseline fixture_hash must be a 64-character SHA-256 hexadecimal digest.';
            }
        }
        $baseline['allowed_origins'] = $this->normalizedAllowedOrigins(
            $baseline['allowed_origins'] ?? null,
            $errors
        );

        $release = config('observability.release');
        if (! is_string($release) || trim($release) === '') {
            $errors[] = 'The observability release is required to identify the runner result scope.';
        }

        if (is_string($baseline['environment'] ?? null)
            && $baseline['environment'] !== (string) config('app.env')) {
            $errors[] = 'The configured baseline environment must match the application environment.';
        }

        $representative = filter_var($baseline['representative'] ?? false, FILTER_VALIDATE_BOOL);
        $approved = filter_var($baseline['approved'] ?? false, FILTER_VALIDATE_BOOL);
        $queueCanariesVerified = filter_var(
            $baseline['queue_canaries_verified'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        $isolatedTenantVerified = filter_var(
            $baseline['isolated_tenant_verified'] ?? false,
            FILTER_VALIDATE_BOOL
        );
        if (! $representative) {
            $errors[] = 'The configured baseline must be explicitly marked representative.';
        }
        if (! $approved) {
            $errors[] = 'The configured baseline execution must be explicitly approved.';
        }
        if (! $queueCanariesVerified) {
            $errors[] = 'The configured baseline must verify the P0-005 queue canaries.';
        }

        $mode = $baseline['mode'] ?? null;
        if (! in_array($mode, ['staging', 'production_read_only'], true)) {
            $errors[] = 'The configured baseline mode must be staging or production_read_only.';
        }
        $allowedStagingEnvironments = collect(config('capacity.allowed_staging_environments', ['staging']))
            ->filter(fn ($environment): bool => is_string($environment))
            ->map(fn (string $environment): string => strtolower(trim($environment)))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($mode === 'staging'
            && ! in_array(strtolower((string) ($baseline['environment'] ?? '')), $allowedStagingEnvironments, true)) {
            $errors[] = 'The configured staging baseline environment must be explicitly allowlisted.';
        }
        if ($mode === 'production_read_only'
            && strtolower((string) ($baseline['environment'] ?? '')) !== 'production') {
            $errors[] = 'The configured production_read_only baseline must run in production.';
        }

        $requiresIsolatedTenant = collect($this->catalog->all())->contains(function (array $scenario): bool {
            $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];

            return (bool) data_get($scenario, 'safety.requires_isolated_tenant', false)
                && ! $this->isFormalBlocker($blocker);
        });
        if ($requiresIsolatedTenant && ! $isolatedTenantVerified) {
            $errors[] = 'The configured baseline must verify an isolated tenant for controlled-write scenarios.';
        }
        if (($baseline['owner'] ?? null) === ($baseline['validator'] ?? null)
            && is_string($baseline['owner'] ?? null)) {
            $errors[] = 'The configured baseline owner and validator must be distinct.';
        }
        if ($mode === 'production_read_only') {
            foreach ($this->catalog->all() as $scenario) {
                $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];
                if (data_get($scenario, 'safety.mode') === 'read_only' || $this->isFormalBlocker($blocker)) {
                    continue;
                }

                $errors[] = sprintf(
                    'Scenario %s must have a formal blocker for a production_read_only baseline.',
                    (string) ($scenario['key'] ?? 'unknown')
                );
            }
        }

        $baseline['started_at_parsed'] = $this->configuredUtcTimestamp(
            $baseline['started_at'] ?? null,
            'started_at',
            $errors
        );
        $baseline['ended_at_parsed'] = $this->configuredUtcTimestamp(
            $baseline['ended_at'] ?? null,
            'ended_at',
            $errors
        );

        if (($baseline['started_at_parsed'] ?? null) instanceof Carbon
            && ($baseline['ended_at_parsed'] ?? null) instanceof Carbon
            && $baseline['started_at_parsed']->greaterThanOrEqualTo($baseline['ended_at_parsed'])) {
            $errors[] = 'The configured baseline started_at must be earlier than ended_at.';
        }

        $baseline['scope_id'] = $this->telemetryScope->idFor([
            'environment' => $baseline['environment'] ?? null,
            'release' => $release,
            'run_id' => $baseline['run_id'] ?? null,
            'commit' => $baseline['commit'] ?? null,
            'started_at' => $baseline['started_at'] ?? null,
            'ended_at' => $baseline['ended_at'] ?? null,
        ]);

        if ($baseline['scope_id'] === null) {
            $errors[] = 'The configured baseline cannot produce a telemetry scope identifier.';
        }

        return $baseline;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<int, string>  $errors
     */
    private function validateProfile(
        array $scenario,
        ?int $virtualUsers,
        ?int $durationSeconds,
        ?int $rampUpSeconds,
        ?int $requestIntervalMs,
        ?int $requestTimeoutMs,
        array &$errors
    ): void {
        $expectedVirtualUsers = data_get($scenario, 'profile.virtual_users');
        $expectedDuration = $this->durationInSeconds(data_get($scenario, 'profile.duration'));
        $expectedRampUp = $this->durationInSeconds(data_get($scenario, 'profile.ramp_up'));
        $expectedRequestIntervalMs = data_get($scenario, 'profile.request_interval_ms');
        $expectedRequestTimeoutMs = data_get($scenario, 'profile.request_timeout_ms');

        if (! is_int($expectedVirtualUsers) && ! ctype_digit((string) $expectedVirtualUsers)) {
            $errors[] = 'The configured scenario virtual_users profile is invalid.';
        } elseif ($virtualUsers !== null && $virtualUsers !== (int) $expectedVirtualUsers) {
            $errors[] = sprintf(
                'virtual_users must match the scenario profile (%d).',
                (int) $expectedVirtualUsers
            );
        }

        if ($expectedDuration === null || $expectedDuration < 1) {
            $errors[] = 'The configured scenario duration profile is invalid.';
        } elseif ($durationSeconds !== null && $durationSeconds !== $expectedDuration) {
            $errors[] = "duration_seconds must match the scenario profile ({$expectedDuration}).";
        }

        if ($expectedRampUp === null || $expectedRampUp < 0) {
            $errors[] = 'The configured scenario ramp_up profile is invalid.';
        } elseif ($rampUpSeconds !== null && $rampUpSeconds !== $expectedRampUp) {
            $errors[] = "ramp_up_seconds must match the scenario profile ({$expectedRampUp}).";
        }

        if (! is_int($expectedRequestIntervalMs) || $expectedRequestIntervalMs < 1) {
            $errors[] = 'The configured scenario request_interval_ms profile is invalid.';
        } elseif ($requestIntervalMs !== null && $requestIntervalMs !== $expectedRequestIntervalMs) {
            $errors[] = "request_interval_ms must match the scenario profile ({$expectedRequestIntervalMs}).";
        }

        if (! is_int($expectedRequestTimeoutMs)
            || $expectedRequestTimeoutMs < 500
            || $expectedRequestTimeoutMs > 60_000) {
            $errors[] = 'The configured scenario request_timeout_ms profile is invalid.';
        } elseif ($requestTimeoutMs !== null && $requestTimeoutMs !== $expectedRequestTimeoutMs) {
            $errors[] = "request_timeout_ms must match the scenario profile ({$expectedRequestTimeoutMs}).";
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     * @return array{p50: float, p95: float, p99: float, max: float}|null
     */
    private function latency(array $payload, array &$errors): ?array
    {
        $configured = $payload['client_latency_ms'] ?? null;
        if (! is_array($configured)) {
            return null;
        }

        $latency = [];
        foreach (self::LATENCY_FIELDS as $field) {
            $value = $configured[$field] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                if (array_key_exists($field, $configured)) {
                    $errors[] = "client_latency_ms.{$field} must be a finite non-negative number.";
                }

                continue;
            }

            $latency[$field] = (float) $value;
        }

        if (count($latency) === count(self::LATENCY_FIELDS)
            && ! ($latency['p50'] <= $latency['p95']
                && $latency['p95'] <= $latency['p99']
                && $latency['p99'] <= $latency['max'])) {
            $errors[] = 'Client latency must be monotonic: p50 <= p95 <= p99 <= max.';
        }

        return count($latency) === count(self::LATENCY_FIELDS)
            ? [
                'p50' => $latency['p50'],
                'p95' => $latency['p95'],
                'p99' => $latency['p99'],
                'max' => $latency['max'],
            ]
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     */
    private function requiredString(array $payload, string $field, array &$errors): ?string
    {
        if (! array_key_exists($field, $payload)) {
            return null;
        }

        if (! is_string($payload[$field]) || trim($payload[$field]) === '') {
            $errors[] = "{$field} must be a non-empty string.";

            return null;
        }

        return trim($payload[$field]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     */
    private function hash(array $payload, string $field, array &$errors): ?string
    {
        $value = $this->requiredString($payload, $field, $errors);
        if ($value === null) {
            return null;
        }

        $value = strtolower($value);
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $errors[] = "{$field} must be a 64-character SHA-256 hexadecimal digest.";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     */
    private function requiredInteger(
        array $payload,
        string $field,
        array &$errors,
        int $minimum
    ): ?int {
        if (! array_key_exists($field, $payload)) {
            return null;
        }

        if (! is_int($payload[$field]) || $payload[$field] < $minimum) {
            $errors[] = "{$field} must be an integer greater than or equal to {$minimum}.";

            return null;
        }

        return $payload[$field];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $errors
     */
    private function utcTimestamp(array $payload, string $field, array &$errors): ?Carbon
    {
        if (! array_key_exists($field, $payload)) {
            return null;
        }

        return $this->parseUtcTimestamp($payload[$field], $field, $errors);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function configuredUtcTimestamp(mixed $value, string $field, array &$errors): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->parseUtcTimestamp($value, "configured baseline {$field}", $errors);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseUtcTimestamp(mixed $value, string $field, array &$errors): ?Carbon
    {
        if (! is_string($value)
            || preg_match('/(?:Z|\+00:00|\+0000)$/i', trim($value)) !== 1) {
            $errors[] = "{$field} must be a timestamp with an explicit UTC offset.";

            return null;
        }

        try {
            return Carbon::parse(trim($value))->utc();
        } catch (Throwable) {
            $errors[] = "{$field} must be a valid timestamp.";

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<int, string>  $errors
     */
    private function matchesBaseline(
        ?string $value,
        string $field,
        array $baseline,
        array &$errors
    ): void {
        if ($value !== null
            && is_string($baseline[$field] ?? null)
            && $value !== $baseline[$field]) {
            $errors[] = "{$field} does not match the configured baseline.";
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scenario(string $scenarioKey): ?array
    {
        foreach ($this->catalog->all() as $scenario) {
            if (($scenario['key'] ?? null) === $scenarioKey) {
                return $scenario;
            }
        }

        return null;
    }

    private function durationInSeconds(mixed $duration): ?int
    {
        if (is_int($duration)) {
            return $duration;
        }

        if (! is_string($duration)
            || preg_match('/^(\d+)(ms|s|m|h)$/', strtolower(trim($duration)), $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return match ($matches[2]) {
            'ms' => $value % 1000 === 0 ? intdiv($value, 1000) : null,
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
        };
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function validateLifecycle(
        string $scopeId,
        string $scenarioKey,
        Carbon $runnerStartedAt,
        Carbon $runnerEndedAt,
        array &$errors
    ): void {
        $startedAt = null;
        $stoppedAt = null;

        foreach ($this->runContext->lifecycleForScope($scopeId, $scenarioKey) as $event) {
            if (($event['state'] ?? null) === 'cancelled') {
                $errors[] = 'The capacity scenario lifecycle was cancelled and cannot accept runner evidence.';

                return;
            }

            try {
                $recordedAt = Carbon::parse((string) ($event['recorded_at'] ?? ''));
            } catch (Throwable) {
                $errors[] = 'The capacity scenario lifecycle contains an invalid timestamp.';

                return;
            }

            if (($event['state'] ?? null) === 'started' && $startedAt === null) {
                $startedAt = $recordedAt;
            } elseif (($event['state'] ?? null) === 'stopped'
                && $startedAt instanceof Carbon
                && $recordedAt->greaterThanOrEqualTo($startedAt)) {
                $stoppedAt = $recordedAt;
            }
        }

        if (! $startedAt instanceof Carbon || ! $stoppedAt instanceof Carbon) {
            $errors[] = 'Runner evidence requires a completed started-to-stopped capacity scenario lifecycle.';

            return;
        }
        if ($runnerStartedAt->lessThan($startedAt)) {
            $errors[] = 'runner started_at must be on or after the capacity scenario start marker.';
        }
        if ($runnerEndedAt->greaterThan($stoppedAt)) {
            $errors[] = 'runner ended_at must be on or before the capacity scenario stop marker.';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baselineConfiguration(): array
    {
        $baseline = config('capacity.baseline', []);

        return is_array($baseline) ? $baseline : [];
    }

    public function baselineFingerprint(): string
    {
        $baseline = $this->baselineConfiguration();
        $originErrors = [];
        $identity = [
            'release' => $this->normalizedBaselineString(config('observability.release')),
            'run_id' => $this->normalizedBaselineString($baseline['run_id'] ?? null),
            'environment' => $this->normalizedBaselineString($baseline['environment'] ?? null),
            'commit' => $this->normalizedBaselineString($baseline['commit'] ?? null),
            'period' => [
                'started_at' => $this->normalizedBaselineString($baseline['started_at'] ?? null),
                'ended_at' => $this->normalizedBaselineString($baseline['ended_at'] ?? null),
            ],
            'traffic' => $this->normalizedBaselineString($baseline['traffic'] ?? null),
            'runner' => $this->normalizedBaselineString($baseline['runner'] ?? null),
            'runner_hash' => is_string($baseline['runner_hash'] ?? null)
                ? strtolower(trim($baseline['runner_hash']))
                : null,
            'fixture_hash' => is_string($baseline['fixture_hash'] ?? null)
                ? strtolower(trim($baseline['fixture_hash']))
                : null,
            'allowed_origins' => $this->normalizedAllowedOrigins(
                $baseline['allowed_origins'] ?? null,
                $originErrors
            ),
            'exclusions' => $this->normalizedExclusions($baseline['exclusions'] ?? null),
            'mode' => $this->normalizedBaselineString($baseline['mode'] ?? null),
            'representative' => filter_var($baseline['representative'] ?? false, FILTER_VALIDATE_BOOL),
            'approved' => filter_var($baseline['approved'] ?? false, FILTER_VALIDATE_BOOL),
            'approval_reference' => $this->normalizedBaselineString($baseline['approval_reference'] ?? null),
            'queue_canaries_verified' => filter_var(
                $baseline['queue_canaries_verified'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            'isolated_tenant_verified' => filter_var(
                $baseline['isolated_tenant_verified'] ?? false,
                FILTER_VALIDATE_BOOL
            ),
            'owner' => $this->normalizedBaselineString($baseline['owner'] ?? null),
            'validator' => $this->normalizedBaselineString($baseline['validator'] ?? null),
        ];

        return hash('sha256', json_encode($this->canonicalize($identity), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, string>  $errors
     * @return array<int, string>
     */
    private function normalizedAllowedOrigins(mixed $value, array &$errors): array
    {
        $values = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : []);
        $origins = [];
        foreach ($values as $configuredOrigin) {
            $origin = $this->normalizedHttpsOrigin($configuredOrigin);
            if ($origin === null) {
                $errors[] = 'The configured baseline allowed_origins must contain only exact HTTPS origins.';

                continue;
            }
            $origins[] = $origin;
        }
        $origins = array_values(array_unique($origins));
        sort($origins);
        if ($origins === []) {
            $errors[] = 'The configured baseline allowed_origins must contain at least one exact HTTPS origin.';
        }

        return $origins;
    }

    private function normalizedHttpsOrigin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url(trim($value));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1
            && preg_match('/^\[[a-f0-9:]+\]$/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '');
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @return array<int, string>
     */
    private function approvedOriginHashes(array $baseline): array
    {
        $origins = is_array($baseline['allowed_origins'] ?? null) ? $baseline['allowed_origins'] : [];

        return array_map(fn (string $origin): string => hash('sha256', $origin), $origins);
    }

    private function normalizedBaselineString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizedExclusions(mixed $value): ?array
    {
        $values = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);
        if ($values === null) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (mixed $exclusion): string => is_string($exclusion) ? trim($exclusion) : '',
            $values
        )));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * @param  array<string, mixed>  $blocker
     */
    private function isFormalBlocker(array $blocker): bool
    {
        if (! is_string($blocker['reason'] ?? null)
            || ! is_string($blocker['owner'] ?? null)
            || ! is_string($blocker['review_at'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($blocker['review_at'])->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    private function retentionHours(): int
    {
        return max(
            24,
            (int) config('capacity.runner_results.retention_hours', 24),
            (int) config('observability.request.retention_hours', 24)
        );
    }

    private function cacheKey(string $scopeId, string $scenarioKey): string
    {
        return "capacity:runner-results:{$scopeId}:{$scenarioKey}";
    }
}
