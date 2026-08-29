<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverMapping;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SocialTransportPolicyService
{
    public function allowsNewSubmission(
        int $tenantId,
        string $transportGeneration,
        ?int $connectionId = null,
        ?string $logicalDestinationKey = null,
    ): bool {
        $cutover = $this->cutoverForTenant($tenantId);

        if ($cutover === null) {
            if ((bool) config('services.buffer.delivery.enabled', false)
                && $transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1) {
                return false;
            }

            return ($transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                    && ! $this->directConnectionIsSupersededByBuffer(
                        $tenantId,
                        $connectionId,
                    ))
                || ($transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                    && $this->standaloneBufferConnectionIsAuthorized(
                        $tenantId,
                        $connectionId,
                        $logicalDestinationKey,
                    ));
        }

        if (! $this->isAuthorizedRuntimeState($cutover)) {
            return false;
        }

        $state = (string) $cutover->state;

        $allowed = match ($state) {
            SocialTransportCutover::STATE_LEGACY_ONLY,
            SocialTransportCutover::STATE_CANARY_ARMED => $transportGeneration
                === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            SocialTransportCutover::STATE_CANARY_ACTIVE,
            SocialTransportCutover::STATE_DRAINING_LEGACY,
            SocialTransportCutover::STATE_AWAITING_H3,
            SocialTransportCutover::STATE_CUTOVER_COMPLETE => $transportGeneration
                === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            SocialTransportCutover::STATE_ROLLBACK_HOLD => false,
            default => false,
        };

        if (! $allowed
            || $transportGeneration !== SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1) {
            return $allowed;
        }

        return $this->candidateMappingIsAuthorized(
            $cutover,
            $connectionId,
            $logicalDestinationKey,
        );
    }

    public function allowsExistingRemoteEffect(
        int $tenantId,
        string $transportGeneration,
        ?int $connectionId = null,
        ?string $logicalDestinationKey = null,
    ): bool {
        $cutover = $this->cutoverForTenant($tenantId);

        if ($cutover === null) {
            return $transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                || ($transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                    && $this->standaloneBufferConnectionIsAuthorized(
                        $tenantId,
                        $connectionId,
                        $logicalDestinationKey,
                    ));
        }

        if (! $this->isAuthorizedRuntimeState($cutover)) {
            return false;
        }

        $state = (string) $cutover->state;

        if ($state === SocialTransportCutover::STATE_ROLLBACK_HOLD) {
            return false;
        }

        if ($transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1) {
            return in_array($state, [
                SocialTransportCutover::STATE_LEGACY_ONLY,
                SocialTransportCutover::STATE_CANARY_ARMED,
                SocialTransportCutover::STATE_CANARY_ACTIVE,
                SocialTransportCutover::STATE_DRAINING_LEGACY,
            ], true);
        }

        if ($transportGeneration === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1) {
            return in_array($state, [
                SocialTransportCutover::STATE_CANARY_ACTIVE,
                SocialTransportCutover::STATE_DRAINING_LEGACY,
                SocialTransportCutover::STATE_AWAITING_H3,
                SocialTransportCutover::STATE_CUTOVER_COMPLETE,
            ], true) && $this->candidateMappingIsAuthorized(
                $cutover,
                $connectionId,
                $logicalDestinationKey,
            );
        }

        return false;
    }

    public function assertNewSubmissionAllowed(
        int $tenantId,
        string $transportGeneration,
        ?int $connectionId = null,
        ?string $logicalDestinationKey = null,
    ): void {
        if (! $this->allowsNewSubmission(
            $tenantId,
            $transportGeneration,
            $connectionId,
            $logicalDestinationKey,
        )) {
            throw ValidationException::withMessages([
                'post' => 'Pulse delivery is suspended for this workspace while its transport transition is reviewed.',
            ]);
        }
    }

    private function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('The Pulse transport policy tenant ID must be positive.');
        }
    }

    private function cutoverForTenant(int $tenantId): ?SocialTransportCutover
    {
        $this->assertTenantId($tenantId);

        if (! Schema::hasTable('social_transport_cutovers')) {
            return null;
        }

        return SocialTransportCutover::query()
            ->where('user_id', $tenantId)
            ->first();
    }

    private function isAuthorizedRuntimeState(SocialTransportCutover $cutover): bool
    {
        if (! $cutover->hasCoherentState()) {
            return false;
        }

        if (in_array((string) $cutover->state, [
            SocialTransportCutover::STATE_CANARY_ARMED,
            SocialTransportCutover::STATE_CANARY_ACTIVE,
            SocialTransportCutover::STATE_DRAINING_LEGACY,
            SocialTransportCutover::STATE_AWAITING_H3,
            SocialTransportCutover::STATE_CUTOVER_COMPLETE,
        ], true) && ! $this->mappingManifestMatches($cutover)) {
            return false;
        }

        if (! in_array((string) $cutover->state, [
            SocialTransportCutover::STATE_CANARY_ACTIVE,
            SocialTransportCutover::STATE_DRAINING_LEGACY,
            SocialTransportCutover::STATE_AWAITING_H3,
            SocialTransportCutover::STATE_CUTOVER_COMPLETE,
        ], true)) {
            return true;
        }

        return $this->candidateMappingsAreAuthorized($cutover);
    }

    private function candidateMappingIsAuthorized(
        SocialTransportCutover $cutover,
        ?int $connectionId,
        ?string $logicalDestinationKey,
    ): bool {
        if ($connectionId === null || $connectionId <= 0
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $logicalDestinationKey) !== 1) {
            return false;
        }

        $mapping = SocialTransportCutoverMapping::query()
            ->with(['legacyConnection', 'replacementConnection'])
            ->where('social_transport_cutover_id', $cutover->getKey())
            ->where('user_id', $cutover->user_id)
            ->where('replacement_connection_id', $connectionId)
            ->where('logical_destination_key', $logicalDestinationKey)
            ->first();

        return $mapping !== null && $this->mappingIsAuthorized($cutover, $mapping);
    }

    private function standaloneBufferConnectionIsAuthorized(
        int $tenantId,
        ?int $connectionId,
        ?string $logicalDestinationKey,
    ): bool {
        if (! (bool) config('services.buffer.delivery.enabled', false)
            || $connectionId === null
            || $connectionId <= 0
            || preg_match('/\Aldk:v1:[0-9a-f]{64}\z/', (string) $logicalDestinationKey) !== 1) {
            return false;
        }

        $connection = SocialAccountConnection::query()
            ->whereKey($connectionId)
            ->where('user_id', $tenantId)
            ->where('platform', SocialAccountConnection::PLATFORM_FACEBOOK)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            )
            ->where('logical_destination_key', $logicalDestinationKey)
            ->connected()
            ->first();

        if (! $connection instanceof SocialAccountConnection
            || ! (bool) data_get($connection->metadata, 'buffer.standalone_destination', false)
            || ! (bool) data_get($connection->metadata, 'buffer.publication_enabled', false)
            || (bool) data_get($connection->metadata, 'buffer.catalog_only', true)) {
            return false;
        }

        $grant = SocialBufferConnection::query()
            ->where('user_id', $tenantId)
            ->first();

        return $grant?->isConnected() === true
            && in_array('posts:write', (array) $grant->scopes, true)
            && SocialAccountConnection::query()
                ->where('user_id', $tenantId)
                ->where('logical_destination_key', $logicalDestinationKey)
                ->count() === 1;
    }

    private function directConnectionIsSupersededByBuffer(
        int $tenantId,
        ?int $connectionId,
    ): bool {
        if ($connectionId === null || $connectionId <= 0) {
            return false;
        }

        $platform = SocialAccountConnection::query()
            ->whereKey($connectionId)
            ->where('user_id', $tenantId)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            )
            ->value('platform');

        if (! is_string($platform) || $platform === '') {
            return false;
        }

        return SocialAccountConnection::query()
            ->where('user_id', $tenantId)
            ->where('platform', $platform)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            )
            ->connected()
            ->get()
            ->contains(fn (SocialAccountConnection $connection): bool => (
                $this->standaloneBufferConnectionIsAuthorized(
                    $tenantId,
                    (int) $connection->id,
                    (string) $connection->logical_destination_key,
                )
            ));
    }

    private function candidateMappingsAreAuthorized(SocialTransportCutover $cutover): bool
    {
        if (! Schema::hasTable('social_transport_cutover_mappings')) {
            return false;
        }

        $mappings = SocialTransportCutoverMapping::query()
            ->with(['legacyConnection', 'replacementConnection'])
            ->where('social_transport_cutover_id', $cutover->getKey())
            ->where('user_id', $cutover->user_id)
            ->get();

        if ($mappings->isEmpty()) {
            return false;
        }

        $mappedLegacyConnectionIds = $mappings
            ->pluck('legacy_connection_id')
            ->map(fn (int|string $connectionId): int => (int) $connectionId)
            ->all();

        if (SocialAccountConnection::query()
            ->where('user_id', $cutover->user_id)
            ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_DIRECT)
            ->where(
                'transport_generation',
                SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
            )
            ->connected()
            ->whereNotIn('id', $mappedLegacyConnectionIds)
            ->exists()) {
            return false;
        }

        return $mappings->every(
            fn (SocialTransportCutoverMapping $mapping): bool => $this->mappingIsAuthorized(
                $cutover,
                $mapping,
            ),
        );
    }

    private function mappingIsAuthorized(
        SocialTransportCutover $cutover,
        SocialTransportCutoverMapping $mapping,
    ): bool {
        $legacy = $mapping->legacyConnection;
        $replacement = $mapping->replacementConnection;

        return $legacy !== null
                && $replacement !== null
                && (int) $mapping->owner_validated_by_user_id === (int) $cutover->user_id
                && $mapping->owner_validated_at !== null
                && preg_match('/\A[0-9a-f]{64}\z/', (string) $mapping->owner_evidence_hash) === 1
                && $mapping->shadow_validated_at !== null
                && preg_match('/\A[0-9a-f]{64}\z/', (string) $mapping->shadow_evidence_hash) === 1
                && $this->mappingPredatesH2($cutover, $mapping)
                && (int) $legacy->user_id === (int) $cutover->user_id
                && (int) $replacement->user_id === (int) $cutover->user_id
                && $legacy->platform === SocialAccountConnection::PLATFORM_FACEBOOK
                && $replacement->platform === SocialAccountConnection::PLATFORM_FACEBOOK
                && $legacy->delivery_provider === SocialAccountConnection::DELIVERY_PROVIDER_DIRECT
                && $legacy->transport_generation
                    === SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1
                && $replacement->delivery_provider === SocialAccountConnection::DELIVERY_PROVIDER_BUFFER
                && $replacement->transport_generation
                    === SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1
                && $replacement->status === SocialAccountConnection::STATUS_CONNECTED
                && (bool) $replacement->is_active
                && hash_equals(
                    (string) $mapping->logical_destination_key,
                    (string) $legacy->logical_destination_key,
                )
                && hash_equals(
                    (string) $mapping->logical_destination_key,
                    (string) $replacement->logical_destination_key,
                );
    }

    private function mappingPredatesH2(
        SocialTransportCutover $cutover,
        SocialTransportCutoverMapping $mapping,
    ): bool {
        $h2ApprovedAt = $cutover->h2_approved_at;
        $ownerValidatedAt = $mapping->owner_validated_at;
        $shadowValidatedAt = $mapping->shadow_validated_at;

        return $h2ApprovedAt instanceof CarbonInterface
            && $ownerValidatedAt instanceof CarbonInterface
            && $shadowValidatedAt instanceof CarbonInterface
            && $ownerValidatedAt->lessThanOrEqualTo($h2ApprovedAt)
            && $shadowValidatedAt->lessThanOrEqualTo($h2ApprovedAt);
    }

    private function mappingManifestMatches(SocialTransportCutover $cutover): bool
    {
        $recordedHash = (string) $cutover->mapping_manifest_hash;

        return preg_match('/\A[0-9a-f]{64}\z/', $recordedHash) === 1
            && hash_equals($recordedHash, SocialTransportMappingManifest::hashFor($cutover));
    }
}
