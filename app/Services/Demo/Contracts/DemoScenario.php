<?php

namespace App\Services\Demo\Contracts;

use App\Enums\DemoDataVolume;
use App\Services\Demo\DemoScenarioContext;

interface DemoScenario
{
    public function key(): string;

    public function version(): int;

    public function defaultVolume(): DemoDataVolume;

    /**
     * @return array<string, mixed>
     */
    public function generate(DemoScenarioContext $context): array;
}
