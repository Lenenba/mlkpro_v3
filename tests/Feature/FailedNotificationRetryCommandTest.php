<?php

use Illuminate\Support\Facades\DB;

function insertFailedNotificationJob(array $overrides = []): void
{
    $defaults = [
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"displayName":"Illuminate\\\\Notifications\\\\SendQueuedNotifications","notification":"App\\\\Notifications\\\\InviteUserNotification"}',
        'exception' => 'Expected response code "250" but got code "421", with message "421 Timeout - closing connection".',
        'failed_at' => now(),
    ];

    DB::table('failed_jobs')->insert(array_merge($defaults, $overrides));
}

test('notifications:retry-failed dry run lists eligible transient notification jobs', function () {
    insertFailedNotificationJob();

    $this->artisan('notifications:retry-failed', [
        '--dry-run' => true,
        '--max' => 10,
        '--within-hours' => 24,
    ])
        ->expectsOutputToContain('Dry run: 1 failed notification jobs are eligible for retry.')
        ->assertExitCode(0);
});

test('notifications:retry-failed skips non-transient errors by default', function () {
    insertFailedNotificationJob([
        'exception' => 'Invalid recipient address',
    ]);

    $this->artisan('notifications:retry-failed', [
        '--dry-run' => true,
        '--max' => 10,
        '--within-hours' => 24,
    ])
        ->expectsOutputToContain('No eligible failed notification jobs to retry.')
        ->assertExitCode(0);
});

test('notifications:retry-failed can include non-transient errors with --all-errors', function () {
    insertFailedNotificationJob([
        'exception' => 'Invalid recipient address',
    ]);

    $this->artisan('notifications:retry-failed', [
        '--dry-run' => true,
        '--all-errors' => true,
        '--max' => 10,
        '--within-hours' => 24,
    ])
        ->expectsOutputToContain('Dry run: 1 failed notification jobs are eligible for retry.')
        ->assertExitCode(0);
});
