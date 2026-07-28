<?php

use App\Services\SuperAdminDashboardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<int, mixed>  $arguments
 */
function invokeSuperAdminDashboardMethod(
    SuperAdminDashboardService $service,
    string $method,
    array $arguments = []
): mixed {
    $reflection = new ReflectionMethod($service, $method);

    return $reflection->invokeArgs($service, $arguments);
}

beforeEach(function () {
    Storage::fake('public');
});

test('super admin failure categories use the configured failed job database and table', function () {
    config()->set('database.connections.superadmin_failed_jobs', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'superadmin_failed_jobs');
    config()->set('queue.failed.table', 'custom_queue_failures');

    Schema::connection('superadmin_failed_jobs')->create('custom_queue_failures', function (Blueprint $table) {
        $table->id();
        $table->text('payload');
        $table->text('exception');
        $table->timestamp('failed_at');
    });

    DB::connection('superadmin_failed_jobs')->table('custom_queue_failures')->insert([
        [
            'payload' => 'App\\Jobs\\SendInvoiceMail',
            'exception' => 'SMTP transport unavailable',
            'failed_at' => now()->subHour(),
        ],
        [
            'payload' => 'App\\Jobs\\SyncStripePayment',
            'exception' => 'Stripe request failed',
            'failed_at' => now()->subHours(2),
        ],
        [
            'payload' => 'App\\Jobs\\UnrelatedJob',
            'exception' => 'Unrelated failure',
            'failed_at' => now()->subHours(3),
        ],
        [
            'payload' => 'App\\Jobs\\SendOldMail',
            'exception' => 'SMTP transport unavailable',
            'failed_at' => now()->subDays(2),
        ],
    ]);

    $service = app(SuperAdminDashboardService::class);
    $health = invokeSuperAdminDashboardMethod($service, 'healthStats');
    $alerts = invokeSuperAdminDashboardMethod($service, 'platformAlerts', [[], $health]);

    expect($health)
        ->toMatchArray([
            'failed_jobs_24h' => 3,
            'failed_jobs_7d' => 4,
            'failed_mail_jobs_24h' => 1,
            'failed_mail_jobs_measurable' => true,
            'failed_stripe_jobs_24h' => 1,
            'failed_stripe_jobs_measurable' => true,
            'queue_failed_jobs_measurable' => true,
        ])
        ->and($alerts)
        ->toMatchArray([
            'stripe_failures_24h' => 1,
            'stripe_failures_measurable' => true,
            'smtp_failures_24h' => 1,
            'smtp_failures_measurable' => true,
        ]);
});

test('super admin failure categories remain unknown for a non measurable backend', function () {
    config()->set('queue.failed.driver', 'null');
    config()->set('queue.failed.database', 'missing_connection');
    config()->set('queue.failed.table', 'missing_failed_jobs');

    $service = app(SuperAdminDashboardService::class);
    $health = invokeSuperAdminDashboardMethod($service, 'healthStats');
    $alerts = invokeSuperAdminDashboardMethod($service, 'platformAlerts', [[], $health]);

    expect($health)
        ->toMatchArray([
            'failed_jobs_24h' => null,
            'failed_jobs_7d' => null,
            'failed_mail_jobs_24h' => null,
            'failed_mail_jobs_measurable' => false,
            'failed_stripe_jobs_24h' => null,
            'failed_stripe_jobs_measurable' => false,
            'queue_failed_jobs_measurable' => false,
        ])
        ->and($alerts)
        ->toMatchArray([
            'stripe_failures_24h' => null,
            'stripe_failures_measurable' => false,
            'smtp_failures_24h' => null,
            'smtp_failures_measurable' => false,
        ]);
});

test('super admin failure categories do not fail when the configured table disappears', function () {
    config()->set('queue.failed.driver', 'database-uuids');
    config()->set('queue.failed.database', 'sqlite');
    config()->set('queue.failed.table', 'missing_failed_jobs_table');

    $service = app(SuperAdminDashboardService::class);
    $health = invokeSuperAdminDashboardMethod($service, 'healthStats');

    expect($health['failed_jobs_24h'])->toBeNull()
        ->and($health['failed_mail_jobs_24h'])->toBeNull()
        ->and($health['failed_stripe_jobs_24h'])->toBeNull()
        ->and($health['failed_mail_jobs_measurable'])->toBeFalse()
        ->and($health['failed_stripe_jobs_measurable'])->toBeFalse()
        ->and($health['queue_failed_jobs_measurable'])->toBeFalse();
});
