<?php

namespace App\Services\Capacity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

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
                $safety = is_array($scenario['safety'] ?? null) ? $scenario['safety'] : [];
                $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];
                $method = strtoupper((string) ($scenario['method'] ?? 'GET'));
                $acceptedStatusCodes = is_array($scenario['accepted_status_codes'] ?? null)
                    ? $scenario['accepted_status_codes']
                    : ($method === 'GET' ? [200] : [200, 201]);
                $acceptedStatusCodes = array_values(array_unique(array_filter(
                    array_map(static fn ($status): int => (int) $status, $acceptedStatusCodes),
                    static fn (int $status): bool => $status >= 100 && $status <= 599
                )));
                $protocol = array_replace_recursive(
                    is_array(config('capacity.protocol')) ? config('capacity.protocol') : [],
                    is_array($scenario['protocol'] ?? null) ? $scenario['protocol'] : []
                );
                $routeUris = collect($routeNames)
                    ->mapWithKeys(function (string $routeName): array {
                        $uri = Route::getRoutes()->getByName($routeName)?->uri();

                        return is_string($uri) ? [$routeName => '/'.ltrim($uri, '/')] : [];
                    })
                    ->all();

                return [
                    'key' => (string) $key,
                    'label' => (string) ($scenario['label'] ?? $key),
                    'method' => $method,
                    'route_names' => $routeNames,
                    'accepted_status_codes' => $acceptedStatusCodes,
                    'route_uris' => $routeUris,
                    'protocol' => [
                        'transport' => (string) ($protocol['transport'] ?? 'unknown'),
                        'request_format' => (string) ($protocol['request_format'] ?? 'unknown'),
                        'headers' => is_array($protocol['headers'] ?? null) ? $protocol['headers'] : [],
                        'follow_redirects' => (bool) ($protocol['follow_redirects'] ?? true),
                        'runner_policy' => (string) ($protocol['runner_policy'] ?? 'unknown'),
                        'authentication' => (string) ($protocol['authentication'] ?? 'unknown'),
                        'csrf' => (bool) ($protocol['csrf'] ?? false),
                        'fixture_strategy' => (string) ($protocol['fixture_strategy'] ?? 'unknown'),
                        'unique_by' => is_array($protocol['unique_by'] ?? null)
                            ? array_values($protocol['unique_by'])
                            : [],
                        'preparation' => $this->normalizedPreparation($protocol['preparation'] ?? null),
                        'fixture_reference' => $this->nullableString($protocol['fixture_reference'] ?? null),
                        'outcome' => is_array($protocol['outcome'] ?? null) ? $protocol['outcome'] : [],
                    ],
                    'targets' => [
                        'min_samples' => max(1, (int) ($targets['min_samples'] ?? 10)),
                        'p95_ms' => max(1, (int) ($targets['p95_ms'] ?? 1000)),
                        'p99_ms' => max(1, (int) ($targets['p99_ms'] ?? 1500)),
                        'error_count_24h' => max(0, (int) ($targets['error_count_24h'] ?? 0)),
                    ],
                    'profile' => $profile,
                    'safety' => [
                        'mode' => (string) ($safety['mode'] ?? 'unknown'),
                        'requires_isolated_tenant' => (bool) ($safety['requires_isolated_tenant'] ?? false),
                        'external_effects' => array_values(array_filter(
                            is_array($safety['external_effects'] ?? null) ? $safety['external_effects'] : [],
                            'is_string'
                        )),
                    ],
                    'blocker' => [
                        'reason' => $this->nullableString($blocker['reason'] ?? null),
                        'owner' => $this->nullableString($blocker['owner'] ?? null),
                        'review_at' => $this->nullableString($blocker['review_at'] ?? null),
                    ],
                    'remediation' => array_values(array_filter(array_map(
                        static fn ($item) => is_string($item) && trim($item) !== '' ? trim($item) : null,
                        $remediation
                    ))),
                ];
            })
            ->filter()
            ->map(fn (array $scenario): array => [
                ...$scenario,
                'manifest_hash' => $this->manifestHash($scenario),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function issues(): array
    {
        $configured = config('capacity.scenarios', []);
        if (! is_array($configured) || $configured === []) {
            return ['No capacity scenarios are configured.'];
        }

        $issues = [];
        $sampleSize = max(1, (int) config('observability.request.max_scope_samples', 20_000));
        $trackedRoutes = config('observability.request.tracked_routes', []);
        $trackedRoutes = is_array($trackedRoutes) ? $trackedRoutes : [];

        foreach ($configured as $key => $scenario) {
            if (! is_array($scenario)) {
                $issues[] = "Scenario {$key} is not an array.";

                continue;
            }

            $routeNames = $scenario['route_names'] ?? [$scenario['route_name'] ?? null];
            $routeNames = is_array($routeNames) ? $routeNames : [$routeNames];
            $routeNames = array_values(array_filter($routeNames, 'is_string'));
            $method = strtoupper((string) ($scenario['method'] ?? 'GET'));
            $acceptedStatusCodes = $scenario['accepted_status_codes'] ?? ($method === 'GET' ? [200] : [200, 201]);
            $profile = is_array($scenario['profile'] ?? null) ? $scenario['profile'] : [];
            $protocol = array_replace_recursive(
                is_array(config('capacity.protocol')) ? config('capacity.protocol') : [],
                is_array($scenario['protocol'] ?? null) ? $scenario['protocol'] : []
            );

            if ($routeNames === []) {
                $issues[] = "Scenario {$key} has no route name.";
            }
            if (! is_array($acceptedStatusCodes)
                || $acceptedStatusCodes === []
                || collect($acceptedStatusCodes)->contains(
                    fn ($status): bool => ! is_numeric($status) || (int) $status < 100 || (int) $status > 599
                )) {
                $issues[] = "Scenario {$key} must define valid accepted_status_codes.";
            }
            foreach (['transport', 'request_format', 'runner_policy', 'authentication', 'fixture_strategy', 'fixture_reference'] as $protocolKey) {
                if (! is_string($protocol[$protocolKey] ?? null) || trim((string) $protocol[$protocolKey]) === '') {
                    $issues[] = "Scenario {$key} protocol is missing {$protocolKey}.";
                }
            }
            if (! is_array($protocol['headers'] ?? null)
                || strtolower((string) data_get($protocol, 'headers.Accept')) !== 'application/json') {
                $issues[] = "Scenario {$key} protocol must request application/json responses.";
            }
            if (strtolower((string) data_get($protocol, 'headers.Content-Type')) !== 'application/json') {
                $issues[] = "Scenario {$key} protocol must send application/json requests.";
            }
            if (($protocol['runner_policy'] ?? null) !== 'external_approved_harness') {
                $issues[] = "Scenario {$key} must use the external approved harness policy.";
            }
            if (($protocol['follow_redirects'] ?? null) !== false) {
                $issues[] = "Scenario {$key} must disable automatic redirects.";
            }
            $fixtureStrategy = $protocol['fixture_strategy'] ?? null;
            if (! in_array($fixtureStrategy, ['repeat', 'one_shot'], true)) {
                $issues[] = "Scenario {$key} protocol must define repeat or one_shot fixture_strategy.";
            }
            $uniqueBy = $protocol['unique_by'] ?? null;
            if (! is_array($uniqueBy) || ! array_is_list($uniqueBy)) {
                $issues[] = "Scenario {$key} protocol unique_by must be a list.";
                $uniqueBy = [];
            }
            foreach ($uniqueBy as $uniqueGroupIndex => $uniqueGroup) {
                if (! is_array($uniqueGroup)
                    || ! array_is_list($uniqueGroup)
                    || $uniqueGroup === []
                    || collect($uniqueGroup)->contains(fn ($path): bool => ! is_string($path)
                        || preg_match('/^(?:body|headers|path_parameters|query)\.[A-Za-z0-9_.-]+$/', $path) !== 1)) {
                    $issues[] = "Scenario {$key} unique_by group {$uniqueGroupIndex} is invalid.";
                }
            }
            $preparation = $protocol['preparation'] ?? null;
            if (! is_array($preparation) || ! array_is_list($preparation)) {
                $issues[] = "Scenario {$key} protocol preparation must be a list.";
                $preparation = [];
            }
            foreach ($preparation as $preparationIndex => $preparationRequest) {
                if (! is_array($preparationRequest)) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must be an array.";

                    continue;
                }
                $allowedPreparationFields = [
                    'method',
                    'route_name',
                    'accepted_status_codes',
                    'authentication',
                    'csrf',
                    'share_session_headers',
                    'outcome',
                ];
                if (array_diff(array_keys($preparationRequest), $allowedPreparationFields) !== []) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} has unsupported fields.";
                }
                $preparationMethod = strtoupper((string) ($preparationRequest['method'] ?? ''));
                $preparationRouteName = $preparationRequest['route_name'] ?? null;
                $preparationStatuses = $preparationRequest['accepted_status_codes'] ?? null;
                if (! in_array($preparationMethod, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} has an invalid method.";
                }
                if (! is_string($preparationRouteName) || trim($preparationRouteName) === '') {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} has no route name.";
                } else {
                    $preparationRoute = Route::getRoutes()->getByName($preparationRouteName);
                    if ($preparationRoute === null) {
                        $issues[] = "Scenario {$key} preparation {$preparationIndex} references missing route {$preparationRouteName}.";
                    } elseif (! in_array($preparationMethod, $preparationRoute->methods(), true)) {
                        $issues[] = "Scenario {$key} preparation {$preparationIndex} expects {$preparationMethod} but route {$preparationRouteName} does not support it.";
                    }
                }
                if (! is_array($preparationStatuses)
                    || $preparationStatuses === []
                    || collect($preparationStatuses)->contains(
                        fn ($status): bool => ! is_int($status) || $status < 100 || $status > 599
                    )) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must define valid accepted_status_codes.";
                }
                if (! is_string($preparationRequest['authentication'] ?? null)
                    || trim((string) $preparationRequest['authentication']) === '') {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must define authentication.";
                }
                if (! is_bool($preparationRequest['csrf'] ?? null)) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must define csrf as a boolean.";
                }
                if (! is_bool($preparationRequest['share_session_headers'] ?? null)) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must define share_session_headers as a boolean.";
                }
                $preparationOutcome = $preparationRequest['outcome'] ?? null;
                $preparationOutcomeStrategy = is_array($preparationOutcome)
                    ? ($preparationOutcome['strategy'] ?? null)
                    : null;
                if (! in_array($preparationOutcomeStrategy, ['status_code', 'json_key_present', 'json_field_equals'], true)) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} must define a supported outcome strategy.";
                }
                if (in_array($preparationOutcomeStrategy, ['json_key_present', 'json_field_equals'], true)
                    && (! is_string($preparationOutcome['field'] ?? null)
                        || trim((string) $preparationOutcome['field']) === '')) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} outcome must define a JSON field.";
                }
                if ($preparationOutcomeStrategy === 'json_field_equals'
                    && ! array_key_exists('value', is_array($preparationOutcome) ? $preparationOutcome : [])) {
                    $issues[] = "Scenario {$key} preparation {$preparationIndex} outcome must define the expected JSON value.";
                }
            }
            $outcomeStrategy = data_get($protocol, 'outcome.strategy');
            if (! in_array($outcomeStrategy, ['status_code', 'json_key_present', 'json_field_equals'], true)) {
                $issues[] = "Scenario {$key} protocol must define a supported outcome strategy.";
            }
            if (in_array($outcomeStrategy, ['json_key_present', 'json_field_equals'], true)
                && (! is_string(data_get($protocol, 'outcome.field'))
                    || trim((string) data_get($protocol, 'outcome.field')) === '')) {
                $issues[] = "Scenario {$key} protocol outcome must define a JSON field.";
            }
            if ($outcomeStrategy === 'json_field_equals'
                && ! array_key_exists('value', is_array($protocol['outcome'] ?? null) ? $protocol['outcome'] : [])) {
                $issues[] = "Scenario {$key} protocol outcome must define the expected JSON value.";
            }

            foreach ($routeNames as $routeName) {
                $route = Route::getRoutes()->getByName($routeName);
                if ($route === null) {
                    $issues[] = "Scenario {$key} references missing route {$routeName}.";

                    continue;
                }

                if (! in_array($method, $route->methods(), true)) {
                    $issues[] = "Scenario {$key} expects {$method} but route {$routeName} does not support it.";
                }

                $routePattern = ltrim($route->uri(), '/');
                $tracked = collect($trackedRoutes)->contains(fn ($pattern): bool => is_string($pattern)
                    && (Str::is($pattern, $routeName) || Str::is($pattern, $routePattern)));
                if (! $tracked) {
                    $issues[] = "Scenario {$key} route {$routeName} is not tracked by observability.";
                }
            }

            $minSamples = (int) data_get($scenario, 'targets.min_samples', 0);
            $p95 = (int) data_get($scenario, 'targets.p95_ms', 0);
            $p99 = (int) data_get($scenario, 'targets.p99_ms', 0);
            if ($minSamples < 1 || $minSamples > $sampleSize) {
                $issues[] = "Scenario {$key} min_samples must be between 1 and {$sampleSize}.";
            }
            if ($p95 < 1 || $p99 < $p95) {
                $issues[] = "Scenario {$key} must define p99_ms greater than or equal to p95_ms.";
            }

            if (! is_int(data_get($scenario, 'profile.virtual_users'))
                || (int) data_get($scenario, 'profile.virtual_users') < 1) {
                $issues[] = "Scenario {$key} profile virtual_users must be a positive integer.";
            }
            if (! is_int(data_get($scenario, 'profile.request_interval_ms'))
                || (int) data_get($scenario, 'profile.request_interval_ms') < 1) {
                $issues[] = "Scenario {$key} profile request_interval_ms must be a positive integer.";
            }
            $minimumRequestInterval = data_get($scenario, 'profile.minimum_request_interval_ms');
            if ($minimumRequestInterval !== null
                && (! is_int($minimumRequestInterval) || $minimumRequestInterval < 1)) {
                $issues[] = "Scenario {$key} profile minimum_request_interval_ms must be a positive integer when configured.";
            } elseif (is_int($minimumRequestInterval)
                && (int) data_get($scenario, 'profile.request_interval_ms', 0) < $minimumRequestInterval) {
                $issues[] = "Scenario {$key} profile request_interval_ms is below its signed safety minimum.";
            }
            if (! is_int(data_get($scenario, 'profile.request_timeout_ms'))
                || (int) data_get($scenario, 'profile.request_timeout_ms') < 500
                || (int) data_get($scenario, 'profile.request_timeout_ms') > 60_000) {
                $issues[] = "Scenario {$key} profile request_timeout_ms must be an integer between 500 and 60000.";
            }
            foreach (['duration', 'ramp_up'] as $profileKey) {
                $duration = data_get($scenario, "profile.{$profileKey}");
                $durationSeconds = $this->durationInSeconds($duration);
                if ($durationSeconds === null || ($profileKey === 'duration' && $durationSeconds < 1)) {
                    $issues[] = "Scenario {$key} profile {$profileKey} must use an explicit duration unit.";
                }
            }
            $minimumCompletedRequests = data_get($scenario, 'profile.minimum_completed_requests');
            if (! is_int($minimumCompletedRequests)
                || $minimumCompletedRequests < $minSamples
                || $minimumCompletedRequests > $sampleSize) {
                $issues[] = "Scenario {$key} profile minimum_completed_requests must be between {$minSamples} and {$sampleSize}.";
            }
            $maximumTheoreticalRequests = $this->maximumTheoreticalRequests($profile);
            if ($maximumTheoreticalRequests === null) {
                $issues[] = "Scenario {$key} profile cannot produce a deterministic request budget.";
            } elseif (is_int($minimumCompletedRequests)
                && $minimumCompletedRequests > $maximumTheoreticalRequests) {
                $issues[] = "Scenario {$key} minimum_completed_requests exceeds its theoretical request budget.";
            }

            $safetyMode = data_get($scenario, 'safety.mode');
            if (! in_array($safetyMode, ['read_only', 'controlled_write'], true)) {
                $issues[] = "Scenario {$key} must define a supported safety mode.";
            }
            if ($safetyMode === 'controlled_write'
                && data_get($scenario, 'safety.requires_isolated_tenant') !== true) {
                $issues[] = "Scenario {$key} controlled_write safety must require an isolated tenant.";
            }
            if ($safetyMode === 'read_only' && $fixtureStrategy !== 'repeat') {
                $issues[] = "Scenario {$key} read_only safety must use repeat fixtures.";
            }
            if ($safetyMode === 'controlled_write' && $fixtureStrategy !== 'one_shot') {
                $issues[] = "Scenario {$key} controlled_write safety must use one_shot fixtures.";
            }
            if ($safetyMode === 'controlled_write' && $uniqueBy === []) {
                $issues[] = "Scenario {$key} controlled_write safety must define at least one semantic unique_by group.";
            }
            if ($preparation !== [] && $fixtureStrategy !== 'one_shot') {
                $issues[] = "Scenario {$key} preparation is only supported for one_shot fixtures.";
            }
            $blocker = is_array($scenario['blocker'] ?? null) ? $scenario['blocker'] : [];
            $blockerValues = collect(['reason', 'owner', 'review_at'])
                ->map(fn (string $field): ?string => $this->nullableString($blocker[$field] ?? null));
            if ($blockerValues->filter()->isNotEmpty()
                && $blockerValues->contains(fn (?string $value): bool => $value === null)) {
                $issues[] = "Scenario {$key} blocker must define reason, owner, and review_at together.";
            } elseif ($blockerValues->every(fn (?string $value): bool => $value !== null)) {
                try {
                    if (! Carbon::parse((string) $blockerValues->get(2))->isFuture()) {
                        $issues[] = "Scenario {$key} blocker review_at must be in the future.";
                    }
                } catch (Throwable) {
                    $issues[] = "Scenario {$key} blocker review_at must be a valid future timestamp.";
                }
            }
        }

        return array_values(array_unique($issues));
    }

    public function durationInSeconds(mixed $duration): ?int
    {
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
     * Compute the exact maximum used by the fixed-delay runner when responses
     * complete immediately. Each worker sends first at its ramp delay.
     *
     * @param  array<string, mixed>  $profile
     */
    public function maximumTheoreticalRequests(array $profile): ?int
    {
        $virtualUsers = $profile['virtual_users'] ?? null;
        $durationSeconds = $this->durationInSeconds($profile['duration'] ?? null);
        $rampUpSeconds = $this->durationInSeconds($profile['ramp_up'] ?? null);
        $requestIntervalMs = $profile['request_interval_ms'] ?? null;

        if (! is_int($virtualUsers)
            || $virtualUsers < 1
            || $durationSeconds === null
            || $durationSeconds < 1
            || $rampUpSeconds === null
            || $rampUpSeconds < 0
            || $rampUpSeconds >= $durationSeconds
            || ! is_int($requestIntervalMs)
            || $requestIntervalMs < 1) {
            return null;
        }

        if ($durationSeconds > intdiv(PHP_INT_MAX, 1000)
            || $rampUpSeconds > intdiv(PHP_INT_MAX, 1000)) {
            return null;
        }
        $durationMs = $durationSeconds * 1000;
        $rampUpMs = $rampUpSeconds * 1000;
        $rampDenominator = max(1, $virtualUsers - 1);
        if ($durationMs > intdiv(PHP_INT_MAX, $rampDenominator)
            || $rampUpMs > intdiv(PHP_INT_MAX, $rampDenominator)
            || $requestIntervalMs > intdiv(PHP_INT_MAX, $rampDenominator)) {
            return null;
        }
        $intervalDenominator = $requestIntervalMs * $rampDenominator;
        $maximum = 0;
        for ($workerIndex = 0; $workerIndex < $virtualUsers; $workerIndex++) {
            $numerator = $virtualUsers === 1
                ? $durationMs
                : ($durationMs * $rampDenominator) - ($rampUpMs * $workerIndex);
            $denominator = $virtualUsers === 1 ? $requestIntervalMs : $intervalDenominator;
            if ($numerator > PHP_INT_MAX - ($denominator - 1)) {
                return null;
            }
            $workerMaximum = intdiv($numerator + $denominator - 1, $denominator);
            if ($maximum > PHP_INT_MAX - $workerMaximum) {
                return null;
            }
            $maximum += $workerMaximum;
        }

        return max(1, $maximum);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizedPreparation(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn (mixed $request): bool => is_array($request))
            ->map(function (array $request): array {
                $routeName = $this->nullableString($request['route_name'] ?? null);
                $routeUri = $routeName === null
                    ? null
                    : Route::getRoutes()->getByName($routeName)?->uri();

                return [
                    'method' => strtoupper((string) ($request['method'] ?? '')),
                    'route_name' => $routeName,
                    'route_uri' => is_string($routeUri) ? '/'.ltrim($routeUri, '/') : null,
                    'accepted_status_codes' => array_values(array_filter(
                        is_array($request['accepted_status_codes'] ?? null)
                            ? $request['accepted_status_codes']
                            : [],
                        'is_int'
                    )),
                    'authentication' => (string) ($request['authentication'] ?? 'unknown'),
                    'csrf' => (bool) ($request['csrf'] ?? false),
                    'share_session_headers' => (bool) ($request['share_session_headers'] ?? false),
                    'outcome' => is_array($request['outcome'] ?? null) ? $request['outcome'] : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function manifestHash(array $scenario): string
    {
        $manifest = collect($scenario)->only([
            'key',
            'method',
            'route_names',
            'route_uris',
            'accepted_status_codes',
            'protocol',
            'profile',
            'safety',
            'targets',
            'blocker',
        ])->all();

        return hash('sha256', json_encode($this->canonicalize($manifest), JSON_THROW_ON_ERROR));
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
}
