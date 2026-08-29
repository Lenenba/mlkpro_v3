<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SocialDeliveryReconciliationClaimData
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
        public string $claimToken,
        public int $claimVersion,
        public CarbonImmutable $claimExpiresAt,
        public int $attempts,
        public ?CarbonImmutable $originalNextReconcileAt,
        public string $expectedLegacyStatus,
        public string $expectedDeliveryStatus,
        public string $expectedSyncStatus,
        public int $expectedCurrentRevisionId,
        public int $expectedLastSubmittedRevisionId,
        public string $expectedPayloadHash,
    ) {
        foreach ([
            $this->tenantId,
            $this->postId,
            $this->targetId,
            $this->connectionId,
            $this->claimVersion,
            $this->expectedCurrentRevisionId,
            $this->expectedLastSubmittedRevisionId,
        ] as $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException(
                    'A social delivery reconciliation claim contains a non-positive identifier.',
                );
            }
        }

        if ($this->attempts < 0) {
            throw new InvalidArgumentException(
                'A social delivery reconciliation claim contains an invalid attempt count.',
            );
        }

        if (trim($this->providerPostId) === ''
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', $this->logicalDestinationKey) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $this->expectedPayloadHash) !== 1
            || preg_match('/\A[0-9a-f-]{36}\z/i', $this->claimToken) !== 1
            || trim($this->deliveryProvider) === ''
            || trim($this->transportGeneration) === ''
            || trim($this->expectedLegacyStatus) === ''
            || trim($this->expectedDeliveryStatus) === ''
            || trim($this->expectedSyncStatus) === '') {
            throw new InvalidArgumentException(
                'A social delivery reconciliation claim contains an invalid immutable snapshot.',
            );
        }
    }

    public function statusRequest(): ReadSocialDeliveryStatusData
    {
        return new ReadSocialDeliveryStatusData(
            tenantId: $this->tenantId,
            postId: $this->postId,
            targetId: $this->targetId,
            connectionId: $this->connectionId,
            providerPostId: $this->providerPostId,
            deliveryProvider: $this->deliveryProvider,
            transportGeneration: $this->transportGeneration,
            logicalDestinationKey: $this->logicalDestinationKey,
        );
    }
}
