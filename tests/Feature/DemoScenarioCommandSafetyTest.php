<?php

use App\Models\DemoWorkspace;

it('refuses scenario creation when demo mode is disabled', function () {
    config()->set('demo.enabled', false);

    $this->artisan('demo:scenario:create studio_naya_coiffure --no-interaction')
        ->expectsOutputToContain('Demo mode is disabled.')
        ->assertExitCode(1);

    expect(DemoWorkspace::query()->count())->toBe(0);
});

it('refuses scenario reset when baseline reset is disabled', function () {
    config()->set('demo.enabled', true);
    config()->set('demo.allow_reset', false);

    $this->artisan('demo:scenario:reset 42 --force --no-interaction')
        ->expectsOutputToContain('Demo reset is disabled.')
        ->assertExitCode(1);

    expect(DemoWorkspace::query()->count())->toBe(0);
});

it('requires an exact numeric workspace id before resolving a reset actor', function () {
    config()->set('demo.enabled', true);
    config()->set('demo.allow_reset', true);

    $this->artisan('demo:scenario:reset studio-naya --force --no-interaction')
        ->expectsOutputToContain('The workspace argument must be an exact positive numeric ID.')
        ->assertExitCode(1);

    expect(DemoWorkspace::query()->count())->toBe(0);
});
