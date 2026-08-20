<?php

use App\Models\User;
use App\Services\Demo\DemoScenarioRegistry;
use App\Services\Demo\Scenarios\StudioNaya\StudioNayaBlueprint;

it('persists namespaced demo scenario types without truncation', function (string $demoType) {
    expect(strlen($demoType))->toBeGreaterThan(20);

    $user = User::factory()->create([
        'is_demo' => true,
        'is_demo_user' => true,
        'demo_type' => $demoType,
        'demo_role' => 'scenario_staff',
    ]);

    $persisted = User::query()->findOrFail($user->id);

    expect($persisted->demo_type)->toBe($demoType)
        ->and(User::query()
            ->whereKey($user->id)
            ->where('demo_type', $demoType)
            ->exists())->toBeTrue();
})->with([
    'Studio Naya' => ['scenario:'.StudioNayaBlueprint::KEY],
    'maximum accepted scenario key' => ['scenario:'.str_repeat('a', DemoScenarioRegistry::MAX_KEY_LENGTH)],
]);

it('refuses to shrink demo types destructively while namespaced scenarios exist', function () {
    $demoType = 'scenario:'.StudioNayaBlueprint::KEY;
    $user = User::factory()->create([
        'is_demo' => true,
        'is_demo_user' => true,
        'demo_type' => $demoType,
        'demo_role' => 'scenario_owner',
    ]);
    $migration = require database_path(
        'migrations/2026_08_20_000002_expand_demo_type_for_scenario_identifiers.php'
    );

    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        'Cannot restore users.demo_type to 20 characters while namespaced scenario identifiers exist.'
    );

    expect($user->fresh()->demo_type)->toBe($demoType);
});
