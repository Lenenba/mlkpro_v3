<?php

use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    config()->set('queue.default', 'database');
});

function phase9CreateSuperadmin(): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    )->id;

    return User::query()->create([
        'name' => 'Phase9 Superadmin',
        'email' => 'phase9-superadmin-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'email_verified_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function phase9CapacityScenario(
    string $label,
    string $routeName,
    string $method,
    array $outcome
): array {
    return [
        'label' => $label,
        'route_name' => $routeName,
        'method' => $method,
        'accepted_status_codes' => [$method === 'GET' ? 200 : 201],
        'protocol' => [
            'authentication' => $method === 'GET' ? 'authenticated_session' : 'public_csrf_session',
            'csrf' => $method !== 'GET',
            'fixture_reference' => 'external:phase9-command-test',
            'outcome' => $outcome,
        ],
        'profile' => [
            'virtual_users' => 10,
            'duration' => '5m',
            'ramp_up' => '30s',
            'minimum_completed_requests' => 100,
        ],
        'safety' => [
            'mode' => $method === 'GET' ? 'read_only' : 'controlled_write',
            'requires_isolated_tenant' => $method !== 'GET',
            'external_effects' => [],
        ],
        'blocker' => ['reason' => null, 'owner' => null, 'review_at' => null],
        'targets' => [
            'min_samples' => 2,
            'p95_ms' => 500,
            'p99_ms' => 700,
            'error_count_24h' => 0,
        ],
        'remediation' => [
            'Collect the approved external capacity evidence.',
        ],
    ];
}

it('reports capacity scenarios and remediation as json', function () {
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => phase9CapacityScenario(
            'Dashboard usage',
            'dashboard',
            'GET',
            ['strategy' => 'status_code']
        ),
    ]);
    config()->set('capacity.shared.queue.max_pending_jobs', 50);
    config()->set('capacity.shared.queue.max_oldest_job_minutes', 30);
    config()->set('capacity.shared.queue.max_failed_jobs_24h', 5);
    config()->set('capacity.shared.database.max_slow_queries_24h', 10);
    config()->set('capacity.shared.app.max_errors_1h', 5);

    Artisan::call('capacity:report', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('warning')
        ->and($payload['scenarios'][0]['status'])->toBe('insufficient_data')
        ->and($payload['scenarios'][0]['observed']['count_24h'])->toBe(0)
        ->and($payload['scenarios'][0]['observed']['runner_result'])->toBeNull()
        ->and($payload['shared_checks'][0]['status'])->toBe('unknown')
        ->and($payload['remediation'])->toContain('Collect the approved external capacity evidence.');
});

it('notifies platform admins when capacity validation fails', function () {
    phase9CreateSuperadmin();

    config()->set('observability.request.tracked_routes', ['public.requests.store']);
    config()->set('capacity.scenarios', [
        'public_request_submission' => phase9CapacityScenario(
            'Public request submission',
            'public.requests.store',
            'POST',
            ['strategy' => 'json_field_equals', 'field' => 'tone', 'value' => 'success']
        ),
    ]);
    config()->set('capacity.shared.queue.max_pending_jobs', 50);
    config()->set('capacity.shared.queue.max_oldest_job_minutes', 30);
    config()->set('capacity.shared.queue.max_failed_jobs_24h', 5);
    config()->set('capacity.shared.database.max_slow_queries_24h', 10);
    config()->set('capacity.shared.app.max_errors_1h', 5);

    Artisan::call('capacity:report', ['--notify' => true]);

    expect(PlatformNotification::query()
        ->where('category', 'operational_health')
        ->where('reference', 'like', 'capacity:%')
        ->count())->toBeGreaterThan(0);
});
