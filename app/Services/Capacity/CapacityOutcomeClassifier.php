<?php

namespace App\Services\Capacity;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CapacityOutcomeClassifier
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $scenariosByRoute = null;

    public function __construct(
        private readonly CapacityScenarioCatalog $catalog
    ) {}

    public function classify(
        Request $request,
        int $statusCode,
        ?Response $response,
        ?string $activeScenarioKey = null
    ): ?bool
    {
        $routeName = $request->route()?->getName();
        if (! is_string($routeName)) {
            return null;
        }

        $scenario = $this->scenariosByRoute()[$routeName] ?? null;
        if (! is_array($scenario)) {
            return null;
        }
        if ($activeScenarioKey !== null && ($scenario['key'] ?? null) !== $activeScenarioKey) {
            return null;
        }

        if (strtoupper($request->method()) !== ($scenario['method'] ?? null)
            || ! in_array($statusCode, $scenario['accepted_status_codes'] ?? [], true)) {
            return false;
        }

        $outcome = data_get($scenario, 'protocol.outcome', []);
        $strategy = is_array($outcome) ? ($outcome['strategy'] ?? null) : null;
        if ($strategy === 'status_code') {
            return true;
        }
        if ($response === null) {
            return false;
        }

        try {
            $content = $response->getContent();
            if (! is_string($content) || $content === '') {
                return false;
            }

            $payload = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                return false;
            }

            $field = is_string($outcome['field'] ?? null) ? $outcome['field'] : null;
            if ($field === null) {
                return false;
            }

            return match ($strategy) {
                'json_key_present' => data_get($payload, $field) !== null,
                'json_field_equals' => data_get($payload, $field) === ($outcome['value'] ?? null),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scenariosByRoute(): array
    {
        if ($this->scenariosByRoute !== null) {
            return $this->scenariosByRoute;
        }

        $this->scenariosByRoute = [];
        foreach ($this->catalog->all() as $scenario) {
            foreach ($scenario['route_names'] ?? [] as $routeName) {
                if (is_string($routeName) && ! isset($this->scenariosByRoute[$routeName])) {
                    $this->scenariosByRoute[$routeName] = $scenario;
                }
            }
        }

        return $this->scenariosByRoute;
    }
}
