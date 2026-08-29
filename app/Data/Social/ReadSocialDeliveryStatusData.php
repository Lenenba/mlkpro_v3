<?php

namespace App\Data\Social;

use InvalidArgumentException;

final readonly class ReadSocialDeliveryStatusData
{
    public function __construct(
        public int $tenantId,
        public int $postId,
        public int $targetId,
        public int $connectionId,
        public string $providerPostId,
        public string $deliveryProvider,
        public string $transportGeneration,
        public string $logicalDestinationKey,
    ) {
        foreach ([
            'tenant ID' => $this->tenantId,
            'post ID' => $this->postId,
            'target ID' => $this->targetId,
            'connection ID' => $this->connectionId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'The social delivery %s must be positive.',
                    $field,
                ));
            }
        }

        self::ensureBoundedValue($this->providerPostId, 191, 'provider post ID');
        self::ensureBoundedValue($this->deliveryProvider, 32, 'provider');
        self::ensureBoundedValue($this->transportGeneration, 32, 'transport generation');

        if (preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $this->logicalDestinationKey) !== 1) {
            throw new InvalidArgumentException(
                'The social delivery logical destination key must be canonical.',
            );
        }
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
