<?php

use App\Models\User;
use App\Services\Social\SocialScheduledTimeResolver;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

function pulseSchedulingOwner(string $timezone = 'America/Toronto'): User
{
    return (new User)->forceFill(['company_timezone' => $timezone]);
}

it('interprets a naive browser value in the workspace timezone', function () {
    $resolved = app(SocialScheduledTimeResolver::class)->resolve(
        pulseSchedulingOwner(),
        '2026-08-29T10:00',
    );

    expect($resolved?->toIso8601String())->toBe('2026-08-29T14:00:00+00:00');
});

it('preserves an explicit instant independently of the workspace timezone', function () {
    $resolved = app(SocialScheduledTimeResolver::class)->resolve(
        pulseSchedulingOwner(),
        '2026-08-29T10:00:00+02:00',
    );

    expect($resolved?->toIso8601String())->toBe('2026-08-29T08:00:00+00:00');
});

it('rejects nonexistent and ambiguous daylight saving wall clocks', function (string $value) {
    expect(fn () => app(SocialScheduledTimeResolver::class)->resolve(
        pulseSchedulingOwner(),
        $value,
    ))->toThrow(ValidationException::class);
})->with([
    'spring gap' => '2026-03-08T02:30',
    'autumn overlap' => '2026-11-01T01:30',
]);

it('supports empty values date instances and an invalid timezone fallback', function () {
    config()->set('app.timezone', 'UTC');
    $resolver = app(SocialScheduledTimeResolver::class);

    expect($resolver->resolve(pulseSchedulingOwner(), null))->toBeNull()
        ->and($resolver->resolve(
            pulseSchedulingOwner('Not/A_Timezone'),
            '2026-08-29T10:00',
        )?->toIso8601String())->toBe('2026-08-29T10:00:00+00:00')
        ->and($resolver->resolve(
            pulseSchedulingOwner(),
            Carbon::parse('2026-08-29T14:00:00Z'),
        )?->toIso8601String())->toBe('2026-08-29T14:00:00+00:00');
});
