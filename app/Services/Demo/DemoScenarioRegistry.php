<?php

namespace App\Services\Demo;

use App\Services\Demo\Contracts\DemoScenario;
use InvalidArgumentException;
use LogicException;

final class DemoScenarioRegistry
{
    public const MAX_KEY_LENGTH = 120;

    /**
     * @var array<string, DemoScenario>
     */
    private array $scenarios = [];

    /**
     * @param  iterable<DemoScenario>  $scenarios
     */
    public function __construct(iterable $scenarios = [])
    {
        foreach ($scenarios as $scenario) {
            $this->register($scenario);
        }
    }

    public function register(DemoScenario $scenario): void
    {
        $key = $this->validateScenario($scenario);

        if (array_key_exists($key, $this->scenarios)) {
            throw new LogicException(sprintf('A demo scenario is already registered for key [%s].', $key));
        }

        $this->scenarios[$key] = $scenario;
        ksort($this->scenarios);
    }

    public function has(string $key): bool
    {
        return array_key_exists($this->normalizeLookupKey($key), $this->scenarios);
    }

    public function get(string $key): DemoScenario
    {
        $key = $this->normalizeLookupKey($key);

        if (! array_key_exists($key, $this->scenarios)) {
            throw new InvalidArgumentException(sprintf('Unknown demo scenario [%s].', $key));
        }

        return $this->scenarios[$key];
    }

    /**
     * @return array<string, DemoScenario>
     */
    public function all(): array
    {
        return $this->scenarios;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->scenarios);
    }

    private function validateScenario(DemoScenario $scenario): string
    {
        $key = trim($scenario->key());

        if (strlen($key) > self::MAX_KEY_LENGTH || preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid demo scenario key [%s].', $key));
        }

        if ($scenario->version() < 1) {
            throw new InvalidArgumentException(sprintf('Demo scenario [%s] must have a positive version.', $key));
        }

        return $key;
    }

    private function normalizeLookupKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
