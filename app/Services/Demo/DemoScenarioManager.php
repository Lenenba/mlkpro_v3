<?php

namespace App\Services\Demo;

use App\Services\Demo\Contracts\DemoScenario;
use LogicException;

final readonly class DemoScenarioManager
{
    public function __construct(private DemoScenarioRegistry $registry) {}

    public function scenario(string $key): DemoScenario
    {
        return $this->registry->get($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $key, DemoScenarioContext $context): array
    {
        $scenario = $this->scenario($key);
        $this->assertContextMatchesWorkspace($scenario, $context);

        return $scenario->generate($context);
    }

    private function assertContextMatchesWorkspace(DemoScenario $scenario, DemoScenarioContext $context): void
    {
        $workspace = $context->workspace;
        $workspaceScenarioKey = strtolower(trim((string) $workspace->scenario_key));

        if ($workspaceScenarioKey !== '' && $workspaceScenarioKey !== $scenario->key()) {
            throw new LogicException(sprintf(
                'Workspace scenario [%s] cannot be generated with scenario [%s].',
                $workspaceScenarioKey,
                $scenario->key(),
            ));
        }

        if ($workspace->scenario_version !== null && (int) $workspace->scenario_version !== $scenario->version()) {
            throw new LogicException(sprintf(
                'Workspace scenario version [%d] does not match registered version [%d].',
                $workspace->scenario_version,
                $scenario->version(),
            ));
        }

        if ($workspace->data_volume !== null && $workspace->data_volume !== $context->dataVolume) {
            throw new LogicException('Workspace data volume does not match the demo scenario context.');
        }

        if ($workspace->reference_date !== null
            && $workspace->reference_date->toDateString() !== $context->referenceDate->toDateString()) {
            throw new LogicException('Workspace reference date does not match the demo scenario context.');
        }

        if ($workspace->random_seed !== null && (int) $workspace->random_seed !== $context->randomSeed) {
            throw new LogicException('Workspace random seed does not match the demo scenario context.');
        }
    }
}
