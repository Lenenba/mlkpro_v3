<?php

use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialTransportCutover;
use App\Models\User;
use App\Services\Social\SocialConnectionDeliveryMutex;
use App\Services\Social\SocialTransportCutoverService;
use App\Services\Social\SocialTransportMappingManifest;
use App\Services\Social\SocialTransportReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function pulseStep3ReadinessConnection(User $owner, string $marker): SocialAccountConnection
{
    $externalAccountId = 'private-readiness-'.$marker.'-'.$owner->id;

    return SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Sensitive label '.$marker,
        'external_account_id' => $externalAccountId,
        ...pulseDirectTransportIdentity(
            $owner,
            SocialAccountConnection::PLATFORM_FACEBOOK,
            $externalAccountId,
        ),
        'credentials' => ['access_token' => 'never-expose-'.$marker],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
}

/** @return array<string, mixed> */
function pulseStep3CompleteEmptyQueueEvidence(): array
{
    return [
        'queue_scope_manifest' => [
            'operator_attested_complete_scope_list' => true,
            'scope_count' => 1,
            'measurable_scope_count' => 1,
            'requires_job_policy' => false,
            'scopes' => [[
                'jobs_by_workload' => [
                    'social_publish' => ['total' => 0, 'unparseable_candidates' => 0],
                    'social_automation' => ['total' => 0, 'unparseable_candidates' => 0],
                ],
            ]],
        ],
        'failed_pulse_jobs' => [
            'measurable' => true,
            'total' => 0,
            'unparseable_candidates' => 0,
            'requires_job_policy' => false,
        ],
        'configured_pulse_topology' => [
            'deployed_runtime_proven' => true,
        ],
    ];
}

it('reports tenant scoped drain evidence and remains fail closed before the candidate runtime exists', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $connection = pulseStep3ReadinessConnection($owner, 'owner');
    pulseStep3ReadinessConnection($foreignOwner, 'foreign');
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Future direct post'],
        'status' => SocialPost::STATUS_SCHEDULED,
        'scheduled_for' => now()->addDay(),
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_SCHEDULED,
    ]);
    DB::table('social_automation_rules')->insert([
        'user_id' => $owner->id,
        'name' => 'Active direct reference',
        'is_active' => true,
        'frequency_type' => 'daily',
        'frequency_interval' => 1,
        'approval_mode' => 'required',
        'target_connection_ids' => json_encode([$connection->id], JSON_THROW_ON_ERROR),
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('social_post_templates')->insert([
        'user_id' => $owner->id,
        'name' => 'Future direct template',
        'metadata' => json_encode([
            'selected_target_connection_ids' => [$connection->id],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect(array_keys($report))->toBe([
        'schema_version',
        'scope',
        'sensitive_fields',
        'state',
        'active_transport_generation',
        'mapping',
        'connections',
        'targets',
        'outbox',
        'references',
        'queues',
        'candidate_runtime',
        'canary',
        'legacy_drain',
        'h3',
    ])->and(array_keys($report['mapping']))->toBe([
        'total',
        'owner_validated',
        'shadow_validated',
        'replacement_active',
        'unmapped_direct_active',
        'invalid',
    ])->and(array_keys($report['connections']))->toBe([
        'direct_total',
        'direct_active',
        'candidate_total',
        'candidate_active',
    ])->and(array_keys($report['targets']))->toBe([
        'direct_total',
        'direct_active_or_future',
        'candidate_total',
        'dual_transport_groups',
    ])->and(array_keys($report['outbox']))->toBe([
        'direct_total',
        'direct_unfinished',
        'direct_unresolved_dead',
        'ambiguous',
        'suspended',
    ])->and(array_keys($report['references']))->toBe([
        'automation_rules',
        'templates',
        'active_direct',
        'invalid',
    ])->and(array_keys($report['queues']))->toBe([
        'provided',
        'complete',
        'deployed_runtime_proven',
        'jobs_requiring_policy',
        'failed_jobs_requiring_policy',
    ])->and(array_keys($report['candidate_runtime']))->toBe([
        'distribution_gateway_bound',
        'status_gateway_bound',
        'submission_handler_available',
    ])->and(array_keys($report['canary']))->toBe(['ready', 'blockers'])
        ->and(array_keys($report['legacy_drain']))->toBe(['ready', 'blockers'])
        ->and(array_keys($report['h3']))->toBe(['ready', 'blockers'])
        ->and($report['connections'])->toMatchArray([
            'direct_total' => 1,
            'direct_active' => 1,
        ])->and($report['targets']['direct_active_or_future'])->toBe(1)
        ->and($report['references']['active_direct'])->toBe(2)
        ->and($report['queues']['complete'])->toBeTrue()
        ->and($report['canary']['ready'])->toBeFalse()
        ->and($report['canary']['blockers'])->toContain(
            'candidate_submission_handler_unavailable',
            'h2_canary_contract_missing',
            'owner_mapping_missing',
        )->and($report['legacy_drain']['ready'])->toBeFalse()
        ->and($report['legacy_drain']['blockers'])->toContain(
            'direct_connection_still_active',
            'direct_reference_still_active',
            'direct_target_still_active',
        )->and($encoded)->not->toContain(
            'private-readiness-owner',
            'private-readiness-foreign',
            'never-expose-owner',
            'never-expose-foreign',
            'Sensitive label',
        );
});

it('keeps the CLI expurgated and returns NO-GO when queue evidence and H2 are absent', function () {
    $owner = User::factory()->create();
    pulseStep3ReadinessConnection($owner, 'cli-secret');

    $exitCode = Artisan::call('pulse:transport:readiness', [
        'tenant' => $owner->id,
        '--gate' => 'canary',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(2)
        ->and($output)->toContain(
            '"ready": false',
            'candidate_submission_handler_unavailable',
            'queue_evidence_incomplete',
        )->and($output)->not->toContain(
            'private-readiness-cli-secret',
            'never-expose-cli-secret',
            'Sensitive label',
        );
});

it('refuses a decision-grade readiness snapshot while the tenant transport is active', function () {
    $owner = User::factory()->create();
    $tenantLock = app(SocialConnectionDeliveryMutex::class)->acquireTenant((int) $owner->id);

    expect($tenantLock)->not->toBeNull();

    try {
        $exitCode = Artisan::call('pulse:transport:readiness', [
            'tenant' => $owner->id,
            '--gate' => 'h3',
            '--json' => true,
        ]);
    } finally {
        $tenantLock?->release();
    }

    expect($exitCode)->toBe(2)
        ->and(Artisan::output())->toContain('transport is active');
});

it('reports an empty snapshot without fabricating a canonical drain or H3 proof', function () {
    $owner = User::factory()->create();

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['legacy_drain']['ready'])->toBeFalse()
        ->and($report['legacy_drain']['blockers'])->toContain(
            'cutover_registry_missing',
            'drain_state_not_active',
            'direct_writer_barrier_unproven',
            'drain_observation_window_unproven',
        )
        ->and($report['canary']['ready'])->toBeFalse()
        ->and($report['h3']['ready'])->toBeFalse()
        ->and($report['h3']['blockers'])->toContain('canary_evidence_incomplete');
});

it('opens the H3 dossier only from a proven completed drain snapshot', function () {
    $owner = User::factory()->create();
    $superadminRole = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin'],
    );
    $deliveryApprover = User::factory()->create(['role_id' => $superadminRole->id]);
    $legacy = pulseStep3ReadinessConnection($owner, 'h3-ready');
    $replacement = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'H3 candidate',
        'external_account_id' => 'h3-candidate-'.$owner->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => $legacy->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $service = app(SocialTransportCutoverService::class);
    $mapping = $service->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'H3 readiness owner evidence'),
    );
    DB::table('social_transport_cutover_mappings')
        ->where('id', $mapping->id)
        ->update([
            'owner_validated_at' => now()->subDays(12),
            'shadow_validated_at' => now()->subDays(12),
            'shadow_evidence_hash' => hash('sha256', 'H3 readiness shadow evidence'),
        ]);
    $cutover = SocialTransportCutover::query()->where('user_id', $owner->id)->sole();
    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update([
            'state' => SocialTransportCutover::STATE_AWAITING_H3,
            'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
            'pilot_status' => SocialTransportCutover::PILOT_PASSED,
            'legacy_drain_status' => SocialTransportCutover::DRAIN_COMPLETE,
            'rollback_status' => SocialTransportCutover::ROLLBACK_AVAILABLE,
            'h2_approved_by_user_id' => $deliveryApprover->id,
            'h2_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
            'h2_approved_at' => now()->subDays(10),
            'h2_evidence_hash' => hash('sha256', 'H3 readiness H2 evidence'),
            'canary_contract_hash' => hash('sha256', 'H3 readiness canary contract'),
            'mapping_manifest_hash' => SocialTransportMappingManifest::hashFor($cutover),
            'canary_minimum_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
            'canary_minimum_hours' => SocialTransportCutover::CANARY_MINIMUM_HOURS,
            'canary_maximum_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
            'rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'cutover_at' => now()->subDays(9),
            'canary_started_at' => now()->subDays(8),
            'canary_completed_at' => now()->subDay(),
            'canary_evidence_hash' => hash('sha256', 'H3 readiness canary evidence'),
            'canary_observed_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
            'canary_observed_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
            'canary_observed_rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
            'direct_writer_barrier_at' => now()->subHours(20),
            'legacy_drain_observation_started_at' => now()->subHours(18),
            'legacy_drain_completed_at' => now()->subHours(12),
            'legacy_drain_evidence_hash' => hash('sha256', 'H3 readiness drain evidence'),
        ]);
    DB::table('social_account_connections')
        ->where('id', $legacy->id)
        ->update([
            'status' => SocialAccountConnection::STATUS_DISCONNECTED,
            'is_active' => false,
        ]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($cutover->fresh()->hasCoherentState())->toBeTrue()
        ->and($report['legacy_drain']['ready'])->toBeTrue()
        ->and($report['legacy_drain']['blockers'])->toBe([])
        ->and($report['h3']['ready'])->toBeFalse()
        ->and($report['h3']['blockers'])->toContain(
            'candidate_submission_handler_unavailable',
        )
        ->and($report['h3']['blockers'])->not->toContain(
            'candidate_distribution_gateway_unbound',
            'candidate_status_gateway_unbound',
        )
        ->and($report['canary']['ready'])->toBeFalse()
        ->and($report['canary']['blockers'])->toContain('candidate_submission_handler_unavailable');
});

it('keeps direct retirement globally closed while any tenant still depends on legacy', function () {
    $legacyOwner = User::factory()->create();
    $otherOwner = User::factory()->create();
    pulseStep3ReadinessConnection($legacyOwner, 'global-retirement-secret');
    app(SocialTransportCutoverService::class)->initialize(
        $legacyOwner,
        $legacyOwner,
        hash('sha256', 'global retirement legacy tenant'),
    );
    app(SocialTransportCutoverService::class)->initialize(
        $otherOwner,
        $otherOwner,
        hash('sha256', 'global retirement other tenant'),
    );

    $report = app(SocialTransportReadinessService::class)->globalDirectRetirementReport(
        pulseStep3CompleteEmptyQueueEvidence(),
    );
    $exitCode = Artisan::call('pulse:transport:retirement-readiness', ['--json' => true]);
    $output = Artisan::output();

    expect($report['ready'])->toBeFalse()
        ->and($report['scope'])->toBe('all_tenants_aggregate')
        ->and($report['cutovers'])->toMatchArray(['total' => 2, 'incomplete' => 2])
        ->and($report['direct_connections_active'])->toBe(1)
        ->and($report['blockers'])->toContain(
            'global_direct_writer_barrier_unavailable',
            'tenant_cutover_incomplete',
            'direct_connection_still_active',
        )
        ->and($exitCode)->toBe(2)
        ->and($output)->toContain(
            '"scope": "all_tenants_aggregate"',
            'global_direct_writer_barrier_unavailable',
        )
        ->and($output)->not->toContain('global-retirement-secret');
});

it('keeps a failed direct target in the drain while its post remains retryable', function () {
    $owner = User::factory()->create();
    $connection = pulseStep3ReadinessConnection($owner, 'retryable-failure');
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Retryable direct failure'],
        'status' => SocialPost::STATUS_FAILED,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_FAILED,
    ]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['targets']['direct_active_or_future'])->toBe(1)
        ->and($report['legacy_drain']['ready'])->toBeFalse()
        ->and($report['legacy_drain']['blockers'])->toContain('direct_target_still_active');
});

it('keeps a canceled direct target in the drain when retrying its failed post can requeue it', function () {
    $owner = User::factory()->create();
    $connection = pulseStep3ReadinessConnection($owner, 'retryable-canceled');
    $post = SocialPost::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'content_payload' => ['text' => 'Canceled target on retryable post'],
        'status' => SocialPost::STATUS_FAILED,
    ]);
    SocialPostTarget::query()->create([
        'social_post_id' => $post->id,
        'social_account_connection_id' => $connection->id,
        'delivery_provider' => $connection->delivery_provider,
        'transport_generation' => $connection->transport_generation,
        'logical_destination_key' => $connection->logical_destination_key,
        'status' => SocialPostTarget::STATUS_CANCELED,
    ]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['targets']['direct_active_or_future'])->toBe(1)
        ->and($report['legacy_drain']['ready'])->toBeFalse()
        ->and($report['legacy_drain']['blockers'])->toContain('direct_target_still_active');
});

it('treats malformed active connection references as blockers instead of an empty drain', function () {
    $owner = User::factory()->create();
    DB::table('social_automation_rules')->insert([
        'user_id' => $owner->id,
        'name' => 'Malformed active rule',
        'is_active' => true,
        'frequency_type' => 'daily',
        'frequency_interval' => 1,
        'approval_mode' => 'required',
        'target_connection_ids' => json_encode(['unexpected' => 'object'], JSON_THROW_ON_ERROR),
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('social_post_templates')->insert([
        'user_id' => $owner->id,
        'name' => 'Malformed target template',
        'metadata' => json_encode([
            'selected_target_connection_ids' => 'not-a-list',
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['references'])->toMatchArray([
        'active_direct' => 0,
        'invalid' => 2,
    ])->and($report['canary']['blockers'])->toContain('invalid_reference_exists')
        ->and($report['legacy_drain']['ready'])->toBeFalse()
        ->and($report['legacy_drain']['blockers'])->toContain('invalid_reference_exists');
});

it('blocks unknown cross tenant unmapped duplicate and incomplete connection references', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $direct = pulseStep3ReadinessConnection($owner, 'reference-direct');
    $foreign = pulseStep3ReadinessConnection($foreignOwner, 'reference-foreign');
    $unmappedCandidate = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Unmapped Buffer candidate',
        'external_account_id' => 'unmapped-buffer-'.$owner->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => $direct->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $incomplete = pulseStep3ReadinessConnection($owner, 'reference-incomplete');
    DB::table('social_account_connections')
        ->where('id', $incomplete->id)
        ->update(['transport_generation' => null]);
    $unknownConnectionId = (int) SocialAccountConnection::query()->max('id') + 10_000;
    $referenceSets = [
        'Unknown connection' => [$unknownConnectionId],
        'Cross tenant connection' => [$foreign->id],
        'Unmapped Buffer connection' => [$unmappedCandidate->id],
        'Duplicate direct connection' => [$direct->id, $direct->id],
        'Incomplete transport identity' => [$incomplete->id],
    ];

    foreach ($referenceSets as $name => $connectionIds) {
        DB::table('social_automation_rules')->insert([
            'user_id' => $owner->id,
            'name' => $name,
            'is_active' => true,
            'frequency_type' => 'daily',
            'frequency_interval' => 1,
            'approval_mode' => 'required',
            'target_connection_ids' => json_encode($connectionIds, JSON_THROW_ON_ERROR),
            'max_posts_per_day' => 1,
            'min_hours_between_similar_posts' => 24,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['references'])->toMatchArray([
        'active_direct' => 0,
        'invalid' => 5,
    ])->and($report['canary']['blockers'])->toContain('invalid_reference_exists')
        ->and($report['legacy_drain']['blockers'])->toContain('invalid_reference_exists');
});

it('requires an immutable delivery authority snapshot for H2 readiness', function () {
    $owner = User::factory()->create();
    $superadminRole = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin'],
    );
    $deliveryApprover = User::factory()->create(['role_id' => $superadminRole->id]);
    $cutover = app(SocialTransportCutoverService::class)->initialize(
        $owner,
        $owner,
        hash('sha256', 'readiness approval initialization'),
    );
    $h2Attributes = [
        'state' => SocialTransportCutover::STATE_CANARY_ARMED,
        'active_transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        'pilot_status' => SocialTransportCutover::PILOT_ARMED,
        'rollback_status' => SocialTransportCutover::ROLLBACK_AVAILABLE,
        'h2_approved_by_user_id' => $deliveryApprover->id,
        'h2_approved_at' => now(),
        'h2_evidence_hash' => hash('sha256', 'readiness H2 evidence'),
        'canary_contract_hash' => hash('sha256', 'readiness canary contract'),
        'mapping_manifest_hash' => SocialTransportMappingManifest::hashFor($cutover),
        'canary_minimum_deliveries' => SocialTransportCutover::CANARY_MINIMUM_DELIVERIES,
        'canary_minimum_hours' => SocialTransportCutover::CANARY_MINIMUM_HOURS,
        'canary_maximum_unknown' => SocialTransportCutover::CANARY_MAXIMUM_UNKNOWN,
        'rollback_rto_seconds' => SocialTransportCutover::ROLLBACK_MAXIMUM_RTO_SECONDS,
    ];

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update($h2Attributes);
    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['canary']['blockers'])->toContain('h2_canary_contract_missing');

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update(['h2_approval_authority' => 'workspace_owner']);
    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['canary']['blockers'])->toContain('h2_canary_contract_missing');

    DB::table('social_transport_cutovers')
        ->where('id', $cutover->id)
        ->update([
            'h2_approval_authority' => SocialTransportCutover::APPROVAL_AUTHORITY_SUPERADMIN,
        ]);
    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['canary']['blockers'])->not->toContain('h2_canary_contract_missing');

    $deliveryApprover->forceFill(['role_id' => $owner->role_id])->save();
    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($deliveryApprover->fresh()->isSuperadmin())->toBeFalse()
        ->and($report['canary']['blockers'])->not->toContain('h2_canary_contract_missing');
});

it('invalidates a mapping whose owner or shadow proof was recorded after H2', function () {
    $owner = User::factory()->create();
    $legacy = pulseStep3ReadinessConnection($owner, 'post-h2-mapping');
    $replacement = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Post H2 replacement',
        'external_account_id' => 'post-h2-replacement-'.$owner->id,
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => $legacy->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $mapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $owner,
        $owner,
        $legacy,
        $replacement,
        hash('sha256', 'post H2 mapping evidence'),
    );
    DB::table('social_transport_cutover_mappings')
        ->where('id', $mapping->id)
        ->update([
            'shadow_validated_at' => now(),
            'shadow_evidence_hash' => hash('sha256', 'post H2 shadow evidence'),
        ]);
    DB::table('social_transport_cutovers')
        ->where('id', $mapping->social_transport_cutover_id)
        ->update(['h2_approved_at' => now()->subMinute()]);

    $report = app(SocialTransportReadinessService::class)->report(
        $owner->id,
        pulseStep3CompleteEmptyQueueEvidence(),
    );

    expect($report['mapping']['invalid'])->toBe(1)
        ->and($report['canary']['blockers'])->toContain('owner_mapping_invalid');
});

it('rejects unsupported transition states before writing the registry', function () {
    $owner = User::factory()->create();

    $exitCode = Artisan::call('pulse:transport:transition', [
        'tenant' => $owner->id,
        'operator' => $owner->id,
        'state' => 'canary_active',
        '--evidence-hash' => hash('sha256', 'must not advance'),
        '--confirm' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('before H2 and the concrete candidate runtime')
        ->and(DB::table('social_transport_cutovers')->count())->toBe(0);
});
