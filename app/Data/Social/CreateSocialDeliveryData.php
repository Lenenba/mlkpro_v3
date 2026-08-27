<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Provider-neutral, text-only WP2-A create command. The connection ID is a local
 * reference without an implied model. Neither key asserts remote provider support.
 */
final readonly class CreateSocialDeliveryData
{
    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_SCHEDULED = 'scheduled';

    private function __construct(
        public int $tenantId,
        public int $connectionId,
        public string $externalOrganizationId,
        public string $externalChannelId,
        public string $text,
        public string $mode,
        public ?CarbonImmutable $scheduledFor,
        public string $idempotencyKey,
        public ?string $correlationKey,
    ) {
        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException('The social delivery tenant ID must be positive.');
        }

        if ($this->connectionId <= 0) {
            throw new InvalidArgumentException('The social delivery connection ID must be positive.');
        }

        self::ensureNotBlank($this->externalOrganizationId, 'external organization ID');
        self::ensureNotBlank($this->externalChannelId, 'external channel ID');
        self::ensureNotBlank($this->text, 'text');
        self::ensureNotBlank($this->idempotencyKey, 'idempotency key');

        if ($this->correlationKey !== null) {
            self::ensureNotBlank($this->correlationKey, 'correlation key');
        }
    }

    public static function immediate(
        int $tenantId,
        int $connectionId,
        string $externalOrganizationId,
        string $externalChannelId,
        string $text,
        string $idempotencyKey,
        ?string $correlationKey = null,
    ): self {
        return new self(
            tenantId: $tenantId,
            connectionId: $connectionId,
            externalOrganizationId: $externalOrganizationId,
            externalChannelId: $externalChannelId,
            text: $text,
            mode: self::MODE_IMMEDIATE,
            scheduledFor: null,
            idempotencyKey: $idempotencyKey,
            correlationKey: $correlationKey,
        );
    }

    public static function scheduled(
        int $tenantId,
        int $connectionId,
        string $externalOrganizationId,
        string $externalChannelId,
        string $text,
        CarbonImmutable $scheduledFor,
        string $idempotencyKey,
        ?string $correlationKey = null,
    ): self {
        return new self(
            tenantId: $tenantId,
            connectionId: $connectionId,
            externalOrganizationId: $externalOrganizationId,
            externalChannelId: $externalChannelId,
            text: $text,
            mode: self::MODE_SCHEDULED,
            scheduledFor: $scheduledFor->utc(),
            idempotencyKey: $idempotencyKey,
            correlationKey: $correlationKey,
        );
    }

    private static function ensureNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The social delivery %s must not be blank.', $field));
        }
    }
}
