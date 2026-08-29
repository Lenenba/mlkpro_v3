<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Provider-neutral social delivery command. The connection ID is a local reference
 * without an implied model. Neither key asserts remote provider support.
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
        public array $assets,
        public ?string $linkUrl,
    ) {
        if ($this->tenantId <= 0) {
            throw new InvalidArgumentException('The social delivery tenant ID must be positive.');
        }

        if ($this->connectionId <= 0) {
            throw new InvalidArgumentException('The social delivery connection ID must be positive.');
        }

        self::ensureNotBlank($this->externalOrganizationId, 'external organization ID');
        self::ensureNotBlank($this->externalChannelId, 'external channel ID');
        self::ensureNotBlank($this->idempotencyKey, 'idempotency key');

        if (trim($this->text) === '' && $this->assets === [] && $this->linkUrl === null) {
            throw new InvalidArgumentException(
                'The social delivery must contain text, at least one asset, or a link.',
            );
        }

        self::ensureValidAssets($this->assets);

        if ($this->linkUrl !== null) {
            self::ensureHttpsUrl($this->linkUrl, 'link URL');
        }

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
        array $assets = [],
        ?string $linkUrl = null,
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
            assets: $assets,
            linkUrl: $linkUrl,
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
        array $assets = [],
        ?string $linkUrl = null,
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
            assets: $assets,
            linkUrl: $linkUrl,
        );
    }

    private static function ensureNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('The social delivery %s must not be blank.', $field));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    private static function ensureValidAssets(array $assets): void
    {
        if (! array_is_list($assets) || count($assets) > 20) {
            throw new InvalidArgumentException(
                'The social delivery assets must be an ordered list containing at most 20 items.',
            );
        }

        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                throw new InvalidArgumentException(
                    sprintf('The social delivery asset at index %d must be an array.', $index),
                );
            }

            $type = trim((string) ($asset['type'] ?? ''));
            if (! in_array($type, ['image', 'video', 'document'], true)) {
                throw new InvalidArgumentException(
                    sprintf('The social delivery asset at index %d has an unsupported type.', $index),
                );
            }

            self::ensureHttpsUrl(
                trim((string) ($asset['url'] ?? '')),
                sprintf('asset URL at index %d', $index),
            );

            if ($type === 'document') {
                self::ensureNotBlank(
                    (string) ($asset['title'] ?? ''),
                    sprintf('document title at index %d', $index),
                );
                self::ensureHttpsUrl(
                    trim((string) ($asset['thumbnail_url'] ?? '')),
                    sprintf('document thumbnail URL at index %d', $index),
                );
            }

            if (array_key_exists('thumbnail_offset', $asset)
                && (! is_int($asset['thumbnail_offset']) || $asset['thumbnail_offset'] < 0)) {
                throw new InvalidArgumentException(
                    sprintf('The social delivery video thumbnail offset at index %d must be a non-negative integer.', $index),
                );
            }
        }
    }

    private static function ensureHttpsUrl(string $value, string $field): void
    {
        $parts = parse_url($value);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === '') {
            throw new InvalidArgumentException(
                sprintf('The social delivery %s must be a public HTTPS URL.', $field),
            );
        }
    }
}
