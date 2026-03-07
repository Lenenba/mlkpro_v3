<?php

use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\Observability\ErrorMetricsService;
use App\Services\Observability\RequestMetricsService;
use App\Services\Observability\SlowQueryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    config()->set('queue.default', 'database');
});

function phase8CreateSuperadmin(): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    )->id;

    return User::query()->create([
        'name' => 'Phase8 Superadmin',
        'email' => 'phase8-superadmin-'.Str::lower(Str::random(8)).'@example.com',
        'password' => 'password',
        'role_id' => $roleId,
        'email_verified_at' => now(),
    ]);
}

it('reports observability metrics and alerts as json', function () {
    config()->set('observability.alerts.queue_pending_jobs', 1);
    config()->set('observability.alerts.failed_jobs_24h', 1);
    config()->set('observability.alerts.slow_queries_24h', 1);
    config()->set('observability.alerts.errors_1h', 1);
    config()->set('observability.alerts.request_p95_ms', 500);
    config()->set('observability.alerts.request_p99_ms', 1000);

    DB::table('jobs')->insert([
        'queue' => 'notifications',
        'payload' => json_encode(['displayName' => 'ActionEmailNotification']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinutes(15)->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'notifications',
        'payload' => json_encode(['displayName' => 'ActionEmailNotification']),
        'exception' => 'SMTP failure',
        'failed_at' => now()->subMinutes(30),
    ]);

    app(RequestMetricsService::class)->recordRouteSample('dashboard', 600, 200, [
        'method' => 'GET',
        'path' => '/dashboard',
    ]);
    app(RequestMetricsService::class)->recordRouteSample('dashboard', 1800, 500, [
        'method' => 'GET',
        'path' => '/dashboard',
    ]);

    app(SlowQueryService::class)->record('select * from invoices where user_id = ?', 650, [
        'connection' => 'sqlite',
        'route_name' => 'dashboard',
        'path' => '/dashboard',
    ]);

    app(ErrorMetricsService::class)->record(new RuntimeException('Observability boom'));

    Artisan::call('observability:report', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('critical')
        ->and($payload['queue']['pending_jobs'])->toBe(1)
        ->and($payload['queue']['failed_jobs_24h'])->toBe(1)
        ->and($payload['slow_queries']['count_24h'])->toBe(1)
        ->and($payload['errors']['count_1h'])->toBe(1)
        ->and(collect($payload['requests'])->firstWhere('route_name', 'dashboard'))->not->toBeNull()
        ->and(count($payload['alerts']))->toBeGreaterThan(0);
});

it('notifies platform admins when observability alerts are active', function () {
    config()->set('observability.alerts.queue_pending_jobs', 1);

    phase8CreateSuperadmin();

    DB::table('jobs')->insert([
        'queue' => 'notifications',
        'payload' => json_encode(['displayName' => 'ActionEmailNotification']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinutes(5)->timestamp,
    ]);

    Artisan::call('observability:report', ['--notify' => true]);

    expect(PlatformNotification::query()
        ->where('category', 'operational_health')
        ->count())->toBeGreaterThan(0);
});
