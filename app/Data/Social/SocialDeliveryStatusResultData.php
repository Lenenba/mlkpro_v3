<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SocialDeliveryStatusResultData
{
    public const STATUS_APPROVAL_REQUIRED = 'approval_required';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ERROR = 'error';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_UNKNOWN = 'unknown';

    private function __construct(
        public string $status,
        public CarbonImmutable $observedAt,
        public ?string $providerStatus = null,
        public ?CarbonImmutable $remoteScheduledFor = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
        if (! in_array($this->status, self::allowedStatuses(), true)) {
            throw new InvalidArgumentException('The normalized social delivery status is invalid.');
        }

        if ($this->providerStatus !== null) {
            self::ensureBoundedValue($this->providerStatus, 64, 'provider status');
        }

        if ($this->errorCode !== null) {
            self::ensureBoundedValue($this->errorCode, 191, 'error code');

            if (preg_match('/\A[a-z0-9_.:-]+\z/i', $this->errorCode) !== 1) {
                throw new InvalidArgumentException(
                    'The social delivery error code contains unsupported characters.',
                );
            }
        }

        if ($this->errorMessage !== null) {
            self::ensureBoundedValue($this->errorMessage, 2000, 'error message');
        }
    }

    /**
     * @return array<int, string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_APPROVAL_REQUIRED,
            self::STATUS_DRAFT,
            self::STATUS_ERROR,
            self::STATUS_SCHEDULED,
            self::STATUS_SENDING,
            self::STATUS_SENT,
            self::STATUS_UNKNOWN,
        ];
    }

    public static function observed(
        string $status,
        CarbonImmutable $observedAt,
        ?string $providerStatus = null,
        ?CarbonImmutable $remoteScheduledFor = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: $status,
            observedAt: $observedAt->utc(),
            providerStatus: $providerStatus,
            remoteScheduledFor: $remoteScheduledFor?->utc(),
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    private static function ensureBoundedValue(string $value, int $maximumLength, string $field): void
    {
        if (trim($value) === '' || mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(sprintf(
                'The social delivery %s must be non-blank and at most %d characters.',
                $field,
                $maximumLength,
            ));
        }
    }
}
