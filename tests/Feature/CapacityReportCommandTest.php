<?php

use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\Observability\RequestMetricsService;
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

it('reports capacity scenarios and remediation as json', function () {
    config()->set('observability.request.tracked_routes', ['dashboard']);
    config()->set('capacity.scenarios', [
        'dashboard_usage' => [
            'label' => 'Dashboard usage',
            'route_name' => 'dashboard',
            'method' => 'GET',
            'profile' => ['virtual_users' => 10, 'duration' => '5m'],
            'targets' => [
                'min_samples' => 2,
                'p95_ms' => 500,
                'p99_ms' => 700,
                'error_count_24h' => 0,
            ],
            'remediation' => [
                'Optimize dashboard aggregates and cache stable counters.',
            ],
        ],
    ]);
    config()->set('capacity.shared.queue.max_pending_jobs', 50);
    config()->set('capacity.shared.queue.max_oldest_job_minutes', 30);
    config()->set('capacity.shared.queue.max_failed_jobs_24h', 5);
    config()->set('capacity.shared.database.max_slow_queries_24h', 10);
    config()->set('capacity.shared.app.max_errors_1h', 5);

    app(RequestMetricsService::class)->recordRouteSample('dashboard', 450, 200, [
        'method' => 'GET',
        'path' => '/dashboard',
    ]);
    app(RequestMetricsService::class)->recordRouteSample('dashboard', 950, 500, [
        'method' => 'GET',
        'path' => '/dashboard',
    ]);

    Artisan::call('capacity:report', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('critical')
        ->and($payload['scenarios'][0]['status'])->toBe('fail')
        ->and($payload['scenarios'][0]['observed']['count_24h'])->toBe(2)
        ->and($payload['scenarios'][0]['observed']['error_count_24h'])->toBe(1)
        ->and($payload['shared_checks'][0]['status'])->toBe('pass')
        ->and($payload['remediation'])->toContain('Optimize dashboard aggregates and cache stable counters.');
});

it('notifies platform admins when capacity validation fails', function () {
    phase9CreateSuperadmin();

    config()->set('observability.request.tracked_routes', ['public.requests.store']);
    config()->set('capacity.scenarios', [
        'public_request_submission' => [
            'label' => 'Public request submission',
            'route_name' => 'public.requests.store',
            'method' => 'POST',
            'profile' => ['virtual_users' => 10, 'duration' => '5m'],
            'targets' => [
                'min_samples' => 1,
                'p95_ms' => 300,
                'p99_ms' => 500,
                'error_count_24h' => 0,
            ],
            'remediation' => [
                'Verify public request flow latency.',
            ],
        ],
    ]);
    config()->set('capacity.shared.queue.max_pending_jobs', 50);
    config()->set('capacity.shared.queue.max_oldest_job_minutes', 30);
    config()->set('capacity.shared.queue.max_failed_jobs_24h', 5);
    config()->set('capacity.shared.database.max_slow_queries_24h', 10);
    config()->set('capacity.shared.app.max_errors_1h', 5);

    app(RequestMetricsService::class)->recordRouteSample('public.requests.store', 800, 200, [
        'method' => 'POST',
        'path' => '/public/requests/tenant',
    ]);

    Artisan::call('capacity:report', ['--notify' => true]);

    expect(PlatformNotification::query()
        ->where('category', 'operational_health')
        ->where('reference', 'like', 'capacity:%')
        ->count())->toBeGreaterThan(0);
});
