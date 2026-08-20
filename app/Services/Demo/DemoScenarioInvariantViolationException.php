<?php

namespace App\Services\Demo;

use JsonSerializable;
use RuntimeException;

class DemoScenarioInvariantViolationException extends RuntimeException implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        private readonly array $report
    ) {
        $violationCount = (int) data_get($report, 'summary.violation_count', 0);

        parent::__construct("Demo scenario validation failed with {$violationCount} violation(s).");
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->report;
    }
}
