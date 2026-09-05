<?php

use App\Enums\DemoDataVolume;
use App\Models\DemoWorkspace;
use App\Models\User;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioManager;
use App\Services\Demo\DemoScenarioRegistry;
use Tests\TestCase;

uses(TestCase::class);

function managedDemoScenarioStub(): DemoScenario
{
    return new class implements DemoScenario
    {
        public function key(): string
        {
            return 'salon_hair';
        }

        public function version(): int
        {
            return 1;
        }

        public function defaultVolume(): DemoDataVolume
        {
            return DemoDataVolume::Medium;
        }

        public function generate(DemoScenarioContext $context): array
        {
            return ['scenario' => $this->key()];
        }
    };
}

test('demo scenario manager dispatches generation to the registered scenario', function () {
    $scenario = new class implements DemoScenario
    {
        public function key(): string
        {
            return 'salon_hair';
        }

        public function version(): int
        {
            return 1;
        }

        public function defaultVolume(): DemoDataVolume
        {
            return DemoDataVolume::Medium;
        }

        public function generate(DemoScenarioContext $context): array
        {
            return [
                'reference_date' => $context->referenceDate->toDateString(),
                'sample' => $context->randomizer('summary')->getInt(1, 100),
            ];
        }
    };

    $owner = new User;
    $owner->id = 42;

    $workspace = new DemoWorkspace([
        'owner_user_id' => 42,
        'scenario_key' => 'salon_hair',
        'data_volume' => DemoDataVolume::Medium,
        'reference_date' => '2026-08-20',
        'random_seed' => 12345,
        'scenario_version' => 1,
        'timezone' => 'America/Toronto',
    ]);

    $context = new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Medium,
        referenceDate: '2026-08-20',
        randomSeed: 12345,
    );

    $manager = new DemoScenarioManager(new DemoScenarioRegistry([$scenario]));

    expect($manager->generate('salon_hair', $context))
        ->toMatchArray(['reference_date' => '2026-08-20']);
});

test('demo scenario manager rejects workspace metadata for a different scenario', function () {
    $scenario = managedDemoScenarioStub();
    $owner = new User;
    $owner->id = 42;

    $workspace = new DemoWorkspace([
        'owner_user_id' => 42,
        'scenario_key' => 'cleaning_company',
        'timezone' => 'America/Toronto',
    ]);
    $context = new DemoScenarioContext(
        workspace: $workspace,
        owner: $owner,
        dataVolume: DemoDataVolume::Medium,
        referenceDate: '2026-08-20',
        randomSeed: 12345,
    );
    $manager = new DemoScenarioManager(new DemoScenarioRegistry([$scenario]));

    expect(fn () => $manager->generate('salon_hair', $context))
        ->toThrow(
            LogicException::class,
            'Workspace scenario [cleaning_company] cannot be generated with scenario [salon_hair].',
        );
});
