<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('queue health command reports backlog and failed job counts as json', function () {
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        [
            'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'ActionEmailNotification']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ],
        [
            'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'LeadFormOwnerNotification']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(5)->timestamp,
        ],
        [
            'queue' => 'works',
            'payload' => json_encode(['displayName' => 'GenerateWorkTasks']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(2)->timestamp,
        ],
    ]);

    DB::table('failed_jobs')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'notifications',
            'payload' => json_encode(['displayName' => 'ActionEmailNotification']),
            'exception' => 'SMTP failure',
            'failed_at' => now()->subHours(2),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'works',
            'payload' => json_encode(['displayName' => 'GenerateWorkTasks']),
            'exception' => 'Runtime exception',
            'failed_at' => now()->subDays(3),
        ],
    ]);

    Artisan::call('queue:health', ['--json' => true]);

    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['connection'])->toBe(config('queue.default'))
        ->and($payload['driver'])->toBe('database')
        ->and($payload['measurable'])->toBeTrue()
        ->and($payload['pending_jobs'])->toBe(3)
        ->and($payload['pending_by_queue'])->toBe([
            'notifications' => 2,
            'works' => 1,
        ])
        ->and($payload['failed_jobs_24h'])->toBe(1)
        ->and($payload['failed_jobs_7d'])->toBe(2)
        ->and($payload['oldest_job_minutes'])->toBeGreaterThanOrEqual(10.0);
});
