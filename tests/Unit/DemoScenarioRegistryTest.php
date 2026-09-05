<?php

use App\Enums\DemoDataVolume;
use App\Services\Demo\Contracts\DemoScenario;
use App\Services\Demo\DemoScenarioContext;
use App\Services\Demo\DemoScenarioRegistry;

function demoScenarioStub(string $key, int $version = 1): DemoScenario
{
    return new class($key, $version) implements DemoScenario
    {
        public function __construct(
            private readonly string $scenarioKey,
            private readonly int $scenarioVersion,
        ) {}

        public function key(): string
        {
            return $this->scenarioKey;
        }

        public function version(): int
        {
            return $this->scenarioVersion;
        }

        public function defaultVolume(): DemoDataVolume
        {
            return DemoDataVolume::Medium;
        }

        public function generate(DemoScenarioContext $context): array
        {
            return ['scenario' => $this->scenarioKey];
        }
    };
}

test('demo scenario registry resolves iterable scenarios by key', function () {
    $salon = demoScenarioStub('salon_hair');
    $cleaning = demoScenarioStub('cleaning_company');
    $registry = new DemoScenarioRegistry([$salon, $cleaning]);

    expect($registry->keys())->toBe(['cleaning_company', 'salon_hair'])
        ->and($registry->has(' SALON_HAIR '))->toBeTrue()
        ->and($registry->get('salon_hair'))->toBe($salon);
});

test('demo scenario registry rejects duplicate scenario keys', function () {
    expect(fn () => new DemoScenarioRegistry([
        demoScenarioStub('salon_hair'),
        demoScenarioStub('salon_hair'),
    ]))->toThrow(LogicException::class, 'A demo scenario is already registered for key [salon_hair].');
});

test('demo scenario registry rejects invalid keys and versions', function () {
    expect(fn () => new DemoScenarioRegistry([demoScenarioStub('Salon Hair')]))
        ->toThrow(InvalidArgumentException::class, 'Invalid demo scenario key [Salon Hair].')
        ->and(fn () => new DemoScenarioRegistry([demoScenarioStub('salon_hair', 0)]))
        ->toThrow(InvalidArgumentException::class, 'Demo scenario [salon_hair] must have a positive version.');
});

test('demo scenario registry rejects unknown scenarios', function () {
    $registry = new DemoScenarioRegistry;

    expect(fn () => $registry->get('unknown'))
        ->toThrow(InvalidArgumentException::class, 'Unknown demo scenario [unknown].');
});
