<?php

use App\Enums\DemoDataVolume;
use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\Demo\DemoScenarioContext;
use Carbon\CarbonImmutable;

function demoScenarioContext(int $seed = 12345): DemoScenarioContext
{
    $owner = new User;
    $owner->id = 42;

    $workspace = new DemoWorkspace([
        'owner_user_id' => 42,
        'timezone' => 'America/Toronto',
    ]);

    return new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Medium,
        referenceDate: '2026-08-20',
        randomSeed: $seed,
    );
}

test('demo scenario context normalizes its date in the workspace timezone', function () {
    $context = demoScenarioContext();

    expect($context->referenceDate)->toBeInstanceOf(CarbonImmutable::class)
        ->and($context->referenceDate->format('Y-m-d H:i:s'))->toBe('2026-08-20 00:00:00')
        ->and($context->referenceDate->getTimezone()->getName())->toBe('America/Toronto');
});

test('named random streams reproduce the same sequence for the same seed', function () {
    $first = demoScenarioContext();
    $second = demoScenarioContext();

    $firstSequence = [
        $first->randomizer('customers')->getInt(1, 1_000_000),
        $first->randomizer('customers')->getInt(1, 1_000_000),
        $first->randomizer('customers')->getInt(1, 1_000_000),
    ];
    $secondSequence = [
        $second->randomizer('customers')->getInt(1, 1_000_000),
        $second->randomizer('customers')->getInt(1, 1_000_000),
        $second->randomizer('customers')->getInt(1, 1_000_000),
    ];

    expect($secondSequence)->toBe($firstSequence);
});

test('named random streams are isolated from calls made on other streams', function () {
    $first = demoScenarioContext();
    $second = demoScenarioContext();

    $expectedFirst = $first->randomizer('reservations')->getInt(1, 1_000_000);
    $expectedSecond = $first->randomizer('reservations')->getInt(1, 1_000_000);

    $actualFirst = $second->randomizer('reservations')->getInt(1, 1_000_000);
    $second->randomizer('inventory')->getBytes(64);
    $actualSecond = $second->randomizer('reservations')->getInt(1, 1_000_000);

    expect([$actualFirst, $actualSecond])->toBe([$expectedFirst, $expectedSecond]);
});

test('demo scenario context rejects a different workspace owner', function () {
    $owner = new User;
    $owner->id = 99;

    $workspace = new DemoWorkspace([
        'owner_user_id' => 42,
        'timezone' => 'UTC',
    ]);

    expect(fn () => new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Small,
        referenceDate: '2026-08-20',
        randomSeed: 1,
    ))->toThrow(InvalidArgumentException::class, 'The demo scenario owner does not belong to the workspace.');
});

test('demo scenario context rejects invalid timezone identifiers', function () {
    $owner = new User;
    $workspace = new DemoWorkspace;

    expect(fn () => new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Small,
        referenceDate: '2026-08-20',
        randomSeed: 1,
        timezone: 'Not/A-Timezone',
    ))->toThrow(InvalidArgumentException::class, 'Invalid demo scenario timezone [Not/A-Timezone].');
});
