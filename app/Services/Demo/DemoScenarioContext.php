<?php

namespace App\Services\Demo;

use App\Enums\DemoDataVolume;
use App\Models\DemoWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateInvalidTimeZoneException;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class DemoScenarioContext
{
    public readonly DemoDataVolume $dataVolume;

    public readonly CarbonImmutable $referenceDate;

    public readonly string $timezone;

    /**
     * @var array<string, Randomizer>
     */
    private array $randomizers = [];

    public function __construct(
        public readonly DemoWorkspace $workspace,
        public readonly User $owner,
        DemoDataVolume|string $dataVolume,
        DateTimeInterface|string $referenceDate,
        public readonly int $randomSeed,
        ?string $timezone = null,
    ) {
        if ($randomSeed < 0) {
            throw new InvalidArgumentException('The demo scenario random seed must be non-negative.');
        }

        $this->assertWorkspaceOwner();
        $this->dataVolume = DemoDataVolume::normalize($dataVolume);
        $this->timezone = $this->normalizeTimezone($timezone);
        $this->referenceDate = $this->normalizeReferenceDate($referenceDate);
    }

    public function randomizer(string $stream): Randomizer
    {
        $stream = trim($stream);

        if ($stream === '') {
            throw new InvalidArgumentException('A demo scenario random stream name cannot be empty.');
        }

        return $this->randomizers[$stream] ??= new Randomizer(
            new Mt19937($this->seedForStream($stream)),
        );
    }

    private function assertWorkspaceOwner(): void
    {
        $workspaceOwnerId = (int) ($this->workspace->owner_user_id ?? 0);
        $ownerId = (int) ($this->owner->getKey() ?? 0);

        if ($workspaceOwnerId > 0 && $ownerId > 0 && $workspaceOwnerId !== $ownerId) {
            throw new InvalidArgumentException('The demo scenario owner does not belong to the workspace.');
        }
    }

    private function normalizeTimezone(?string $timezone): string
    {
        $timezone = trim((string) ($timezone ?: $this->workspace->timezone ?: 'UTC'));

        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (DateInvalidTimeZoneException $exception) {
            throw new InvalidArgumentException(sprintf('Invalid demo scenario timezone [%s].', $timezone), 0, $exception);
        }
    }

    private function normalizeReferenceDate(DateTimeInterface|string $referenceDate): CarbonImmutable
    {
        $timezone = new DateTimeZone($this->timezone);

        if ($referenceDate instanceof DateTimeInterface) {
            return CarbonImmutable::instance($referenceDate)
                ->setTimezone($timezone)
                ->startOfDay();
        }

        return CarbonImmutable::parse($referenceDate, $timezone)->startOfDay();
    }

    private function seedForStream(string $stream): int
    {
        $bytes = substr(hash('sha256', $this->randomSeed."\0".$stream, true), 0, 4);
        $seed = unpack('Nvalue', $bytes);

        return (int) $seed['value'];
    }
}
