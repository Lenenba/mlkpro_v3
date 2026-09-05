<?php

namespace App\Services\Social;

use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverMapping;
use Carbon\CarbonInterface;

final class SocialTransportMappingManifest
{
    public const SCHEMA_VERSION = 'pulse_transport_mapping_manifest_v1';

    public static function hashFor(SocialTransportCutover $cutover): string
    {
        $mappings = SocialTransportCutoverMapping::query()
            ->where('social_transport_cutover_id', $cutover->getKey())
            ->where('user_id', $cutover->user_id)
            ->orderBy('logical_destination_key')
            ->orderBy('legacy_connection_id')
            ->orderBy('replacement_connection_id')
            ->get();
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => (int) $cutover->user_id,
            'cutover_id' => (int) $cutover->getKey(),
            'mappings' => $mappings
                ->map(fn (SocialTransportCutoverMapping $mapping): array => [
                    'legacy_connection_id' => (int) $mapping->legacy_connection_id,
                    'replacement_connection_id' => (int) $mapping->replacement_connection_id,
                    'logical_destination_key' => (string) $mapping->logical_destination_key,
                    'owner_validated_by_user_id' => (int) $mapping->owner_validated_by_user_id,
                    'owner_validated_at' => self::canonicalTimestamp(
                        $mapping->owner_validated_at,
                    ),
                    'owner_evidence_hash' => (string) $mapping->owner_evidence_hash,
                    'shadow_validated_at' => self::canonicalTimestamp(
                        $mapping->shadow_validated_at,
                    ),
                    'shadow_evidence_hash' => $mapping->shadow_evidence_hash === null
                        ? null
                        : (string) $mapping->shadow_evidence_hash,
                ])
                ->values()
                ->all(),
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private static function canonicalTimestamp(mixed $timestamp): ?string
    {
        if (! $timestamp instanceof CarbonInterface) {
            return null;
        }

        return $timestamp->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
