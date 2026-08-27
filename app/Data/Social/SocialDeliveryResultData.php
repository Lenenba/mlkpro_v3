<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SocialDeliveryResultData
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNKNOWN = 'unknown';

    private function __construct(
        public string $status,
        public ?string $providerPostId,
        public ?string $providerStatus,
        public ?CarbonImmutable $remoteScheduledFor,
    ) {}

    public static function submitted(
        string $providerPostId,
        ?string $providerStatus = null,
        ?CarbonImmutable $remoteScheduledFor = null,
    ): self {
        self::ensureNotBlank($providerPostId, 'post ID');

        if ($providerStatus !== null) {
            self::ensureNotBlank($providerStatus, 'status');
        }

        return new self(
            status: self::STATUS_SUBMITTED,
            providerPostId: $providerPostId,
            providerStatus: $providerStatus,
            remoteScheduledFor: $remoteScheduledFor?->utc(),
        );
    }

    public static function unknown(): self
    {
        return new self(
            status: self::STATUS_UNKNOWN,
            providerPostId: null,
            providerStatus: null,
            remoteScheduledFor: null,
        );
    }

    private static function ensureNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The social delivery provider %s must not be blank.', $field));
        }
    }
}
