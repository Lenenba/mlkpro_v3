<?php

namespace App\Services\Social;

use App\Models\SocialApprovalRequest;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class SocialEditorialFoundationBackfillService
{
    private const ENTITY_APPROVAL = 'social_approval_request';

    private const ENTITY_POST = 'social_post';

    private const ENTITY_REVISION = 'social_post_revision';

    private const ENTITY_TARGET = 'social_post_target';

    private const LOCK_KEY = 'pulse:wp2b-editorial-foundation-backfill';

    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly SocialPostRevisionSnapshotService $snapshots,
        private readonly SocialBackfillBatchLedgerService $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $this->assertSchemaReady();

        return $this->publicReport($this->analyze(...$this->loadSnapshot(false)), 'preflight');
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return $this->withExclusiveLock(function (): array {
            return DB::transaction(function (): array {
                $this->assertSchemaReady();
                $analysis = $this->analyze(...$this->loadSnapshot(true));
                $this->assertReady($analysis);

                $ledgerEntries = [];
                $created = 0;
                $postsUpdated = 0;
                $approvalsUpdated = 0;
                $targetsUpdated = 0;

                foreach ($analysis['candidates'] as $candidate) {
                    $postBefore = $this->rowAttributes(self::ENTITY_POST, $candidate['post_id']);
                    $revisionId = DB::table('social_post_revisions')->insertGetId(
                        $this->revisionInsert($candidate)
                    );
                    $created++;
                    $ledgerEntries[] = $this->insertLedgerEntry(
                        self::ENTITY_REVISION,
                        $revisionId,
                        (int) $candidate['post']->user_id,
                    );

                    $postUpdate = $candidate['post_update'];
                    $postUpdate['approved_revision_id'] = $candidate['is_approved']
                        ? $revisionId
                        : null;
                    $updated = DB::table('social_posts')
                        ->where('id', $candidate['post_id'])
                        ->whereNull('current_editorial_revision')
                        ->whereNull('payload_hash')
                        ->update($postUpdate);

                    if ($updated !== 1) {
                        throw new LogicException('The legacy Pulse post changed during its editorial backfill.');
                    }

                    $postsUpdated++;
                    $ledgerEntries[] = $this->updateLedgerEntry(
                        self::ENTITY_POST,
                        $candidate['post_id'],
                        (int) $candidate['post']->user_id,
                        $postBefore,
                    );

                    foreach ($candidate['approval_ids'] as $approvalId) {
                        $approvalBefore = $this->rowAttributes(self::ENTITY_APPROVAL, $approvalId);
                        $approvalUpdated = DB::table('social_approval_requests')
                            ->where('id', $approvalId)
                            ->whereNull('social_post_revision_id')
                            ->update(['social_post_revision_id' => $revisionId]);

                        if ($approvalUpdated !== 1) {
                            throw new LogicException('A Pulse approval changed during its editorial backfill.');
                        }

                        $approvalsUpdated++;
                        $ledgerEntries[] = $this->updateLedgerEntry(
                            self::ENTITY_APPROVAL,
                            $approvalId,
                            (int) $candidate['post']->user_id,
                            $approvalBefore,
                        );
                    }

                    foreach ($candidate['targets'] as $target) {
                        $targetBefore = $this->rowAttributes(self::ENTITY_TARGET, $target['id']);
                        $targetUpdate = $target['attributes'];
                        $targetUpdate['current_revision_id'] = $revisionId;
                        $targetUpdate['last_submitted_revision_id'] = $target['submitted']
                            ? $revisionId
                            : null;
                        $targetUpdated = DB::table('social_post_targets')
                            ->where('id', $target['id'])
                            ->whereNull('current_revision_id')
                            ->whereNull('current_editorial_revision')
                            ->whereNull('payload_hash')
                            ->update($targetUpdate);

                        if ($targetUpdated !== 1) {
                            throw new LogicException('A Pulse target changed during its editorial backfill.');
                        }

                        $targetsUpdated++;
                        $ledgerEntries[] = $this->updateLedgerEntry(
                            self::ENTITY_TARGET,
                            $target['id'],
                            (int) $candidate['post']->user_id,
                            $targetBefore,
                        );
                    }
                }

                $verification = $this->analyze(...$this->loadSnapshot(true));
                $this->assertReady($verification);

                if ($verification['candidates'] !== []) {
                    throw new LogicException('The Pulse editorial backfill verification did not converge.');
                }

                $batchId = $this->ledger->record(
                    SocialBackfillBatchLedgerService::OPERATION_EDITORIAL_FOUNDATION,
                    $ledgerEntries,
                );

                $report = $this->publicReport($analysis, 'apply');
                $report['batch_id'] = $batchId;
                $report['posts']['updated'] = $postsUpdated;
                $report['revisions']['created'] = $created;
                $report['approvals']['updated'] = $approvalsUpdated;
                $report['targets']['updated'] = $targetsUpdated;

                return $report;
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(): array
    {
        return $this->withExclusiveLock(function (): array {
            return DB::transaction(function (): array {
                $this->assertSchemaReady();
                $analysis = $this->analyze(...$this->loadSnapshot(true));
                $this->assertReady($analysis);
                $batch = $this->ledger->latestApplied(
                    SocialBackfillBatchLedgerService::OPERATION_EDITORIAL_FOUNDATION,
                    true,
                );

                if (! $batch) {
                    $report = $this->publicReport($analysis, 'rollback');
                    $report['batch_id'] = null;
                    $report['posts']['cleared'] = 0;
                    $report['revisions']['deleted'] = 0;
                    $report['approvals']['cleared'] = 0;
                    $report['targets']['cleared'] = 0;

                    return $report;
                }

                $entries = $this->ledger->entries((int) $batch->id, true);
                $this->ledger->assertManifest($batch, $entries);
                $this->assertEditorialRollbackEntries($entries);
                $this->assertLedgerRowsUnchanged($entries);
                $this->assertNoNewEditorialConsumers($entries);

                $entriesByType = $entries->groupBy('entity_type');

                foreach ($entriesByType->get(self::ENTITY_APPROVAL, collect()) as $entry) {
                    $this->restoreUpdatedLedgerEntry($entry, ['social_post_revision_id' => null]);
                }

                foreach ($entriesByType->get(self::ENTITY_TARGET, collect()) as $entry) {
                    $this->restoreUpdatedLedgerEntry($entry, $this->emptyTargetFoundation());
                }

                foreach ($entriesByType->get(self::ENTITY_POST, collect()) as $entry) {
                    $this->restoreUpdatedLedgerEntry($entry, $this->emptyPostFoundation());
                }

                foreach ($entriesByType->get(self::ENTITY_REVISION, collect()) as $entry) {
                    $deleted = DB::table('social_post_revisions')
                        ->where('id', $entry->entity_id)
                        ->where('origin', SocialPostRevision::ORIGIN_LEGACY_BACKFILL_V1)
                        ->delete();

                    if ($deleted !== 1) {
                        throw new LogicException('The Pulse editorial revision changed during rollback.');
                    }
                }

                $this->ledger->markRolledBack((int) $batch->id);

                $verification = $this->analyze(...$this->loadSnapshot(true));
                $this->assertReady($verification);

                $report = $this->publicReport($analysis, 'rollback');
                $report['batch_id'] = (int) $batch->id;
                $report['posts']['cleared'] = $entriesByType->get(self::ENTITY_POST, collect())->count();
                $report['revisions']['deleted'] = $entriesByType->get(self::ENTITY_REVISION, collect())->count();
                $report['approvals']['cleared'] = $entriesByType->get(self::ENTITY_APPROVAL, collect())->count();
                $report['targets']['cleared'] = $entriesByType->get(self::ENTITY_TARGET, collect())->count();

                return $report;
            });
        });
    }

    /**
     * @return array{0:Collection<int,SocialPost>,1:Collection<int,SocialPostRevision>,2:Collection<int,SocialApprovalRequest>,3:Collection<int,SocialPostTarget>}
     */
    private function loadSnapshot(bool $lockForUpdate): array
    {
        $posts = SocialPost::query()
            ->with(['user', 'createdBy.teamMembership'])
            ->orderBy('id');
        $revisions = SocialPostRevision::query()
            ->with(['createdBy.teamMembership', 'approvedBy.teamMembership'])
            ->orderBy('id');
        $approvals = SocialApprovalRequest::query()
            ->with(['requestedBy.teamMembership', 'resolvedBy.teamMembership'])
            ->orderBy('id');
        $targets = SocialPostTarget::query()
            ->with('socialAccountConnection:id,user_id')
            ->orderBy('id');

        if ($lockForUpdate) {
            $posts->lockForUpdate();
            $revisions->lockForUpdate();
            $approvals->lockForUpdate();
            $targets->lockForUpdate();
        }

        return [$posts->get(), $revisions->get(), $approvals->get(), $targets->get()];
    }

    /**
     * @param  Collection<int, SocialPost>  $posts
     * @param  Collection<int, SocialPostRevision>  $revisions
     * @param  Collection<int, SocialApprovalRequest>  $approvals
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<string, mixed>
     */
    private function analyze(
        Collection $posts,
        Collection $revisions,
        Collection $approvals,
        Collection $targets,
    ): array {
        $report = [
            'contract' => 'pulse_editorial_foundation_backfill_v1',
            'ready' => true,
            'posts' => ['total' => $posts->count(), 'backfillable' => 0, 'already_canonical' => 0],
            'revisions' => ['total' => $revisions->count(), 'synthetic_candidates' => 0],
            'approvals' => ['total' => $approvals->count(), 'backfillable' => 0],
            'targets' => ['total' => $targets->count(), 'backfillable' => 0],
            'anomalies' => ['total' => 0, 'by_reason' => []],
        ];
        $revisionsByPost = $revisions->groupBy('social_post_id');
        $approvalsByPost = $approvals->groupBy('social_post_id');
        $targetsByPost = $targets->groupBy('social_post_id');
        $revisionById = $revisions->keyBy('id');
        $candidates = [];
        $canonicalRevisions = [];
        $tenantUnsafePostIds = $this->recordTenantAnomalies(
            $report,
            $posts,
            $revisions,
            $approvals,
            $targets,
        );

        foreach ($posts as $post) {
            if (isset($tenantUnsafePostIds[(int) $post->id])) {
                continue;
            }

            $postRevisions = $revisionsByPost->get($post->id, collect());
            $postApprovals = $approvalsByPost->get($post->id, collect());
            $postTargets = $targetsByPost->get($post->id, collect());

            if ($postApprovals->count() > 1 && $postRevisions->isEmpty()) {
                $this->recordAnomaly($report, 'approval_history_not_reconstructable');

                continue;
            }

            $configuredTimezone = trim((string) ($post->user?->company_timezone ?? ''));
            if ($configuredTimezone !== '' && ! in_array($configuredTimezone, timezone_identifiers_list(), true)) {
                $this->recordAnomaly($report, 'post_timezone_invalid');

                continue;
            }

            $timezone = $configuredTimezone !== ''
                ? $configuredTimezone
                : (string) config('app.timezone', 'UTC');
            $snapshot = $this->snapshots->forPost($post, $timezone);

            if ($postRevisions->isEmpty()) {
                if (! $this->postFoundationIsEmpty($post)
                    || $postApprovals->contains(fn (SocialApprovalRequest $request): bool => $request->social_post_revision_id !== null)
                    || $postTargets->contains(fn (SocialPostTarget $target): bool => ! $this->targetFoundationIsEmpty($target))) {
                    $this->recordAnomaly($report, 'editorial_foundation_partial_without_revision');

                    continue;
                }

                $approval = $postApprovals->first();
                $targetCandidates = $postTargets->map(fn (SocialPostTarget $target): array => [
                    'id' => (int) $target->id,
                    'submitted' => $this->submissionIsProven($post, $target),
                    'attributes' => $this->targetFoundation($post, $target, $snapshot),
                ]);
                $submissionWasProven = $targetCandidates->contains(
                    fn (array $target): bool => $target['submitted'] === true
                );

                if ($approval && ! in_array((string) $approval->status, SocialApprovalRequest::allowedStatuses(), true)) {
                    $this->recordAnomaly($report, 'approval_status_unknown');

                    continue;
                }

                if ($approval?->status === SocialApprovalRequest::STATUS_APPROVED
                    && $approval->approved_at === null) {
                    $this->recordAnomaly($report, 'approval_resolution_incomplete');

                    continue;
                }

                if ($submissionWasProven && in_array((string) $approval?->status, [
                    SocialApprovalRequest::STATUS_PENDING,
                    SocialApprovalRequest::STATUS_REJECTED,
                ], true)) {
                    $this->recordAnomaly($report, 'submitted_delivery_without_approval');

                    continue;
                }

                $approvalState = $this->approvalState($post, $approval, $submissionWasProven);
                $candidates[] = [
                    'post_id' => (int) $post->id,
                    'post' => $post,
                    'snapshot' => $snapshot,
                    'revision_number' => 1,
                    'approval_ids' => $postApprovals->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'approved_by_user_id' => $approvalState['approved_by_user_id'],
                    'approved_at' => $approvalState['approved_at'],
                    'approval_type' => $approvalState['approval_type'],
                    'is_approved' => $approvalState['is_approved'],
                    'post_update' => $this->postFoundation($post, $snapshot, $approvalState, $targetCandidates),
                    'targets' => $targetCandidates->all(),
                ];
                $report['posts']['backfillable']++;
                $report['revisions']['synthetic_candidates']++;
                $report['approvals']['backfillable'] += $postApprovals->count();
                $report['targets']['backfillable'] += $postTargets->count();

                continue;
            }

            $currentRevision = $postRevisions->firstWhere('revision_number', $post->current_editorial_revision);
            if (! $currentRevision
                || (int) $currentRevision->user_id !== (int) $post->user_id
                || ! hash_equals((string) $currentRevision->payload_hash, (string) $post->payload_hash)
                || ! hash_equals((string) $currentRevision->payload_hash, $snapshot['payload_hash'])
                || ! $this->postFoundationIsComplete($post)
                || ! $this->referencesAreCanonical($post, $postApprovals, $postTargets, $revisionById)) {
                $this->recordAnomaly($report, 'editorial_foundation_conflict');

                continue;
            }

            $report['posts']['already_canonical']++;
            foreach ($postRevisions as $revision) {
                $canonicalRevisions[] = [
                    'id' => (int) $revision->id,
                    'post_id' => (int) $revision->social_post_id,
                    'revision_number' => (int) $revision->revision_number,
                    'payload_hash' => (string) $revision->payload_hash,
                    'origin' => (string) $revision->origin,
                ];
            }
        }

        return [
            'report' => $report,
            'candidates' => $candidates,
            'canonical_revisions' => $canonicalRevisions,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function revisionInsert(array $candidate): array
    {
        /** @var SocialPost $post */
        $post = $candidate['post'];
        $snapshot = $candidate['snapshot'];

        return [
            'user_id' => $post->user_id,
            'social_post_id' => $post->id,
            'revision_number' => $candidate['revision_number'],
            'base_content' => $this->encodeJson($snapshot['base_content']),
            'source_snapshot' => $this->encodeJson($snapshot['source_snapshot']),
            'media_snapshot' => $this->encodeJson($snapshot['media_snapshot']),
            'scheduled_for' => $post->scheduled_for?->copy()->utc(),
            'scheduled_timezone' => $snapshot['scheduled_timezone'],
            'scheduled_local_time' => $snapshot['scheduled_local_time'],
            'payload_hash' => $snapshot['payload_hash'],
            'created_by_user_id' => $post->created_by_user_id,
            'approved_by_user_id' => $candidate['approved_by_user_id'],
            'approved_at' => $candidate['approved_at'],
            'origin' => SocialPostRevision::ORIGIN_LEGACY_BACKFILL_V1,
            'approval_provenance' => $candidate['approval_type'],
            'created_at' => $post->created_at,
            'updated_at' => $post->updated_at,
        ];
    }

    /**
     * @return array{is_approved:bool,editorial_status:string,source:string,approved_by_user_id:int|null,approved_at:Carbon|null,approval_type:string|null}
     */
    private function approvalState(
        SocialPost $post,
        ?SocialApprovalRequest $approval,
        bool $submissionWasProven,
    ): array {
        if ($approval?->status === SocialApprovalRequest::STATUS_APPROVED) {
            return [
                'is_approved' => true,
                'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
                'source' => SocialPost::STATUS_SOURCE_EXPLICIT,
                'approved_by_user_id' => $approval->resolved_by_user_id
                    ? (int) $approval->resolved_by_user_id
                    : null,
                'approved_at' => $approval->approved_at,
                'approval_type' => $approval->resolved_by_user_id
                    ? SocialPostRevision::APPROVAL_TYPE_EXPLICIT
                    : SocialPostRevision::APPROVAL_TYPE_LEGACY_INFERRED,
            ];
        }

        if ($approval?->status === SocialApprovalRequest::STATUS_PENDING) {
            return [
                'is_approved' => false,
                'editorial_status' => SocialPost::EDITORIAL_STATUS_PENDING_APPROVAL,
                'source' => SocialPost::STATUS_SOURCE_EXPLICIT,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'approval_type' => null,
            ];
        }

        if ($approval?->status === SocialApprovalRequest::STATUS_REJECTED) {
            return [
                'is_approved' => false,
                'editorial_status' => SocialPost::EDITORIAL_STATUS_REJECTED,
                'source' => SocialPost::STATUS_SOURCE_EXPLICIT,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'approval_type' => null,
            ];
        }

        $publicationWasRequested = $submissionWasProven
            || filled(data_get($post->metadata, 'publish_requested_at'))
            || in_array((string) $post->status, [
                SocialPost::STATUS_PUBLISHING,
                SocialPost::STATUS_PUBLISHED,
                SocialPost::STATUS_PARTIAL_FAILED,
                SocialPost::STATUS_FAILED,
            ], true);

        return [
            'is_approved' => $publicationWasRequested,
            'editorial_status' => $publicationWasRequested
                ? SocialPost::EDITORIAL_STATUS_APPROVED
                : SocialPost::EDITORIAL_STATUS_DRAFT,
            'source' => SocialPost::STATUS_SOURCE_LEGACY_INFERRED,
            'approved_by_user_id' => null,
            'approved_at' => $publicationWasRequested
                ? ($post->published_at ?? $post->updated_at ?? $post->created_at)
                : null,
            'approval_type' => $publicationWasRequested
                ? SocialPostRevision::APPROVAL_TYPE_LEGACY_INFERRED
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $approvalState
     * @param  Collection<int, array<string, mixed>>  $targetCandidates
     * @return array<string, mixed>
     */
    private function postFoundation(
        SocialPost $post,
        array $snapshot,
        array $approvalState,
        Collection $targetCandidates,
    ): array {
        return [
            'editorial_status' => $approvalState['editorial_status'],
            'delivery_status' => $this->aggregateDeliveryStatus($targetCandidates),
            'sync_status' => $this->aggregateSyncStatus($targetCandidates),
            'current_editorial_revision' => 1,
            'scheduled_timezone' => $snapshot['scheduled_timezone'],
            'scheduled_local_time' => $snapshot['scheduled_local_time'],
            'payload_hash' => $snapshot['payload_hash'],
            'delivery_aggregated_at' => $post->updated_at ?? $post->created_at,
            'editorial_status_source' => $approvalState['source'],
            'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
            'sync_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function targetFoundation(SocialPost $post, SocialPostTarget $target, array $snapshot): array
    {
        $deliveryStatus = $this->targetDeliveryStatus($post, $target);

        return [
            'current_editorial_revision' => 1,
            'delivery_status' => $deliveryStatus,
            'sync_status' => $this->targetSyncStatus($deliveryStatus),
            'payload_hash' => $snapshot['payload_hash'],
        ];
    }

    private function targetSyncStatus(string $deliveryStatus): string
    {
        return match ($deliveryStatus) {
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_FAILED => SocialPost::SYNC_STATUS_ERROR,
            SocialPost::DELIVERY_STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_CANCELED => SocialPost::SYNC_STATUS_SYNCED,
            default => SocialPost::SYNC_STATUS_PENDING,
        };
    }

    private function targetDeliveryStatus(SocialPost $post, SocialPostTarget $target): string
    {
        return match ((string) $target->status) {
            SocialPostTarget::STATUS_SCHEDULED => filled(data_get($post->metadata, 'publish_requested_at'))
                ? SocialPost::DELIVERY_STATUS_SCHEDULED
                : SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
            SocialPostTarget::STATUS_PUBLISHING => SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPostTarget::STATUS_PUBLISHED => SocialPost::DELIVERY_STATUS_PUBLISHED,
            SocialPostTarget::STATUS_FAILED => SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPostTarget::STATUS_CANCELED => SocialPost::DELIVERY_STATUS_CANCELED,
            default => filled(data_get($post->metadata, 'publish_requested_at'))
                ? SocialPost::DELIVERY_STATUS_QUEUED
                : SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $targetCandidates
     */
    private function aggregateDeliveryStatus(Collection $targetCandidates): string
    {
        if ($targetCandidates->isEmpty()) {
            return SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
        }

        $allowed = [
            SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            'sending',
            SocialPost::DELIVERY_STATUS_PUBLISHED,
            SocialPost::DELIVERY_STATUS_FAILED,
            SocialPost::DELIVERY_STATUS_UNKNOWN,
            SocialPost::DELIVERY_STATUS_CANCELED,
        ];
        $statuses = $targetCandidates->map(
            fn (array $target): string => (string) data_get($target, 'attributes.delivery_status')
        );

        if ($statuses->contains(fn (string $status): bool => ! in_array($status, $allowed, true))
            || $statuses->contains(SocialPost::DELIVERY_STATUS_UNKNOWN)) {
            return SocialPost::DELIVERY_STATUS_UNKNOWN;
        }

        $nonCanceled = $statuses->reject(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_CANCELED
        );
        $failedCount = $nonCanceled->filter(
            fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_FAILED
        )->count();

        if ($failedCount > 0 && $failedCount < $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_PARTIAL_FAILED;
        }

        if ($nonCanceled->isNotEmpty() && $failedCount === $nonCanceled->count()) {
            return SocialPost::DELIVERY_STATUS_FAILED;
        }

        if ($nonCanceled->contains('sending')) {
            return SocialPost::DELIVERY_STATUS_PUBLISHING;
        }

        foreach ([
            SocialPost::DELIVERY_STATUS_REMOTE_APPROVAL_REQUIRED,
            SocialPost::DELIVERY_STATUS_SCHEDULED,
            SocialPost::DELIVERY_STATUS_SUBMITTED,
            SocialPost::DELIVERY_STATUS_QUEUED,
        ] as $status) {
            if ($nonCanceled->contains($status)) {
                return $status;
            }
        }

        if ($nonCanceled->isNotEmpty()
            && $nonCanceled->every(
                fn (string $status): bool => $status === SocialPost::DELIVERY_STATUS_PUBLISHED
            )) {
            return SocialPost::DELIVERY_STATUS_PUBLISHED;
        }

        if ($nonCanceled->isEmpty()) {
            return SocialPost::DELIVERY_STATUS_CANCELED;
        }

        return SocialPost::DELIVERY_STATUS_NOT_SUBMITTED;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $targetCandidates
     */
    private function aggregateSyncStatus(Collection $targetCandidates): string
    {
        if ($targetCandidates->isEmpty()) {
            return SocialPost::SYNC_STATUS_PENDING;
        }

        $allowed = [
            SocialPost::SYNC_STATUS_PENDING,
            SocialPost::SYNC_STATUS_SYNCED,
            SocialPost::SYNC_STATUS_ERROR,
            SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
        ];
        $statuses = $targetCandidates->map(
            fn (array $target): string => (string) data_get($target, 'attributes.sync_status')
        );

        if ($statuses->contains(fn (string $status): bool => ! in_array($status, $allowed, true))) {
            return SocialPost::SYNC_STATUS_ERROR;
        }

        foreach ([
            SocialPost::SYNC_STATUS_RECONNECT_REQUIRED,
            SocialPost::SYNC_STATUS_ERROR,
            SocialPost::SYNC_STATUS_PENDING,
        ] as $status) {
            if ($statuses->contains($status)) {
                return $status;
            }
        }

        return SocialPost::SYNC_STATUS_SYNCED;
    }

    private function submissionIsProven(SocialPost $post, SocialPostTarget $target): bool
    {
        return filled(data_get($target->metadata, 'dispatch_requested_at'))
            || filled(data_get($post->metadata, 'publish_requested_at'))
            || in_array((string) $target->status, [
                SocialPostTarget::STATUS_PUBLISHING,
                SocialPostTarget::STATUS_PUBLISHED,
                SocialPostTarget::STATUS_FAILED,
            ], true);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  Collection<int, SocialPost>  $posts
     * @param  Collection<int, SocialPostRevision>  $revisions
     * @param  Collection<int, SocialApprovalRequest>  $approvals
     * @param  Collection<int, SocialPostTarget>  $targets
     * @return array<int, true>
     */
    private function recordTenantAnomalies(
        array &$report,
        Collection $posts,
        Collection $revisions,
        Collection $approvals,
        Collection $targets,
    ): array {
        $postsById = $posts->keyBy('id');
        $unsafePostIds = [];
        $markUnsafe = function (string $reason, ?int $postId = null) use (&$report, &$unsafePostIds): void {
            $this->recordAnomaly($report, $reason);

            if ($postId !== null) {
                $unsafePostIds[$postId] = true;
            }
        };

        foreach ($posts as $post) {
            $postId = (int) $post->id;

            if (! $post->user || (int) $post->user->id !== (int) $post->user_id) {
                $markUnsafe('post_workspace_missing', $postId);
            }

            if (! $this->actorBelongsToWorkspace(
                $post->createdBy,
                $post->created_by_user_id,
                (int) $post->user_id,
            )) {
                $markUnsafe('post_actor_cross_tenant', $postId);
            }
        }

        foreach ($revisions as $revision) {
            $post = $postsById->get($revision->social_post_id);
            $postId = $post ? (int) $post->id : null;

            if (! $post || (int) $revision->user_id !== (int) $post->user_id) {
                $markUnsafe('revision_cross_tenant', $postId);

                continue;
            }

            if (! $this->actorBelongsToWorkspace(
                $revision->createdBy,
                $revision->created_by_user_id,
                (int) $post->user_id,
            ) || ! $this->actorBelongsToWorkspace(
                $revision->approvedBy,
                $revision->approved_by_user_id,
                (int) $post->user_id,
            )) {
                $markUnsafe('revision_actor_cross_tenant', (int) $post->id);
            }
        }

        foreach ($approvals as $approval) {
            $post = $postsById->get($approval->social_post_id);
            $postId = $post ? (int) $post->id : null;

            if (! $post) {
                $markUnsafe('approval_post_missing');

                continue;
            }

            if (! $this->actorBelongsToWorkspace(
                $approval->requestedBy,
                $approval->requested_by_user_id,
                (int) $post->user_id,
            ) || ! $this->actorBelongsToWorkspace(
                $approval->resolvedBy,
                $approval->resolved_by_user_id,
                (int) $post->user_id,
            )) {
                $markUnsafe('approval_actor_cross_tenant', $postId);
            }
        }

        foreach ($targets as $target) {
            $post = $postsById->get($target->social_post_id);
            $postId = $post ? (int) $post->id : null;

            if (! $post) {
                $markUnsafe('target_post_missing');

                continue;
            }

            if ($target->social_account_connection_id !== null
                && (! $target->socialAccountConnection
                    || (int) $target->socialAccountConnection->user_id !== (int) $post->user_id)) {
                $markUnsafe('target_cross_tenant', $postId);
            }
        }

        return $unsafePostIds;
    }

    private function actorBelongsToWorkspace(
        ?User $actor,
        mixed $actorId,
        int $workspaceId,
    ): bool {
        if ($actorId === null) {
            return true;
        }

        return $actor !== null && $actor->accountOwnerId() === $workspaceId;
    }

    /**
     * @param  Collection<int, SocialApprovalRequest>  $approvals
     * @param  Collection<int, SocialPostTarget>  $targets
     * @param  Collection<int|string, SocialPostRevision>  $revisionById
     */
    private function referencesAreCanonical(
        SocialPost $post,
        Collection $approvals,
        Collection $targets,
        Collection $revisionById,
    ): bool {
        $currentRevision = $revisionById->first(
            fn (SocialPostRevision $revision): bool => (int) $revision->social_post_id === (int) $post->id
                && (int) $revision->revision_number === (int) $post->current_editorial_revision
        );
        $approvedRevision = $post->approved_revision_id === null
            ? null
            : $revisionById->get($post->approved_revision_id);

        if ((string) $post->editorial_status === SocialPost::EDITORIAL_STATUS_APPROVED) {
            if (! $currentRevision
                || ! $approvedRevision
                || (int) $approvedRevision->id !== (int) $currentRevision->id
                || (int) $approvedRevision->social_post_id !== (int) $post->id
                || (int) $approvedRevision->user_id !== (int) $post->user_id
                || ! hash_equals((string) $approvedRevision->payload_hash, (string) $post->payload_hash)
                || $approvedRevision->approved_at === null) {
                return false;
            }
        } elseif ($post->approved_revision_id !== null) {
            return false;
        }

        foreach ($approvals as $approval) {
            $revision = $revisionById->get($approval->social_post_revision_id);
            if (! $revision
                || (int) $revision->social_post_id !== (int) $post->id
                || (int) $revision->user_id !== (int) $post->user_id) {
                return false;
            }
        }

        foreach ($targets as $target) {
            $currentRevision = $revisionById->get($target->current_revision_id);
            if (! $currentRevision
                || (int) $currentRevision->social_post_id !== (int) $post->id
                || (int) $currentRevision->user_id !== (int) $post->user_id
                || (int) $target->current_editorial_revision !== (int) $currentRevision->revision_number
                || ! hash_equals((string) $target->payload_hash, (string) $currentRevision->payload_hash)) {
                return false;
            }

            if ($target->last_submitted_revision_id !== null) {
                $submittedRevision = $revisionById->get($target->last_submitted_revision_id);
                if (! $submittedRevision
                    || (int) $submittedRevision->social_post_id !== (int) $post->id
                    || (int) $submittedRevision->user_id !== (int) $post->user_id
                    || $submittedRevision->approved_at === null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function postFoundationIsEmpty(SocialPost $post): bool
    {
        return collect([
            $post->editorial_status,
            $post->delivery_status,
            $post->sync_status,
            $post->current_editorial_revision,
            $post->approved_revision_id,
            $post->scheduled_timezone,
            $post->scheduled_local_time,
            $post->payload_hash,
            $post->delivery_aggregated_at,
            $post->editorial_status_source,
            $post->delivery_status_source,
            $post->sync_status_source,
        ])->every(fn (mixed $value): bool => $value === null);
    }

    private function postFoundationIsComplete(SocialPost $post): bool
    {
        return $post->editorial_status !== null
            && $post->delivery_status !== null
            && $post->sync_status !== null
            && (int) $post->current_editorial_revision > 0
            && in_array((string) $post->scheduled_timezone, timezone_identifiers_list(), true)
            && preg_match('/\A[0-9a-f]{64}\z/', (string) $post->payload_hash) === 1
            && $post->delivery_aggregated_at !== null
            && $post->editorial_status_source !== null
            && $post->delivery_status_source !== null
            && $post->sync_status_source !== null;
    }

    private function targetFoundationIsEmpty(SocialPostTarget $target): bool
    {
        return collect([
            $target->current_revision_id,
            $target->last_submitted_revision_id,
            $target->current_editorial_revision,
            $target->delivery_status,
            $target->sync_status,
            $target->payload_hash,
        ])->every(fn (mixed $value): bool => $value === null);
    }

    /**
     * @return array<string, null>
     */
    private function emptyPostFoundation(): array
    {
        return array_fill_keys([
            'editorial_status',
            'delivery_status',
            'sync_status',
            'current_editorial_revision',
            'approved_revision_id',
            'scheduled_timezone',
            'scheduled_local_time',
            'payload_hash',
            'delivery_aggregated_at',
            'editorial_status_source',
            'delivery_status_source',
            'sync_status_source',
        ], null);
    }

    /**
     * @return array<string, null>
     */
    private function emptyTargetFoundation(): array
    {
        return array_fill_keys([
            'current_revision_id',
            'last_submitted_revision_id',
            'current_editorial_revision',
            'delivery_status',
            'sync_status',
            'payload_hash',
        ], null);
    }

    /**
     * @param  array<string, mixed>  $before
     * @return array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:string,after_fingerprint:string}
     */
    private function updateLedgerEntry(
        string $entityType,
        int $entityId,
        int $workspaceId,
        array $before,
    ): array {
        return [
            'workspace_id' => $workspaceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'mutation' => SocialBackfillBatchLedgerService::MUTATION_UPDATE,
            'before_fingerprint' => $this->ledger->fingerprint($before),
            'after_fingerprint' => $this->ledger->fingerprint(
                $this->rowAttributes($entityType, $entityId)
            ),
        ];
    }

    /**
     * @return array{workspace_id:int,entity_type:string,entity_id:int,mutation:string,before_fingerprint:null,after_fingerprint:string}
     */
    private function insertLedgerEntry(string $entityType, int $entityId, int $workspaceId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'mutation' => SocialBackfillBatchLedgerService::MUTATION_INSERT,
            'before_fingerprint' => null,
            'after_fingerprint' => $this->ledger->fingerprint(
                $this->rowAttributes($entityType, $entityId)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowAttributes(string $entityType, int $entityId): array
    {
        $row = DB::table($this->entityTable($entityType))->find($entityId);

        if (! $row) {
            throw new LogicException('A Pulse editorial backfill ledger row is missing.');
        }

        return (array) $row;
    }

    private function entityTable(string $entityType): string
    {
        return match ($entityType) {
            self::ENTITY_APPROVAL => 'social_approval_requests',
            self::ENTITY_POST => 'social_posts',
            self::ENTITY_REVISION => 'social_post_revisions',
            self::ENTITY_TARGET => 'social_post_targets',
            default => throw new LogicException('The Pulse editorial backfill ledger entity is invalid.'),
        };
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertEditorialRollbackEntries(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            throw new LogicException('The Pulse editorial backfill batch ledger is empty.');
        }

        foreach ($entries as $entry) {
            $expectedMutation = (string) $entry->entity_type === self::ENTITY_REVISION
                ? SocialBackfillBatchLedgerService::MUTATION_INSERT
                : SocialBackfillBatchLedgerService::MUTATION_UPDATE;

            if (! in_array((string) $entry->entity_type, [
                self::ENTITY_APPROVAL,
                self::ENTITY_POST,
                self::ENTITY_REVISION,
                self::ENTITY_TARGET,
            ], true) || (string) $entry->mutation !== $expectedMutation) {
                throw new LogicException('The Pulse editorial backfill batch ledger contains an invalid entity.');
            }
        }

        if ($entries->where('entity_type', self::ENTITY_POST)->isEmpty()
            || $entries->where('entity_type', self::ENTITY_REVISION)->isEmpty()) {
            throw new LogicException('The Pulse editorial backfill batch ledger is incomplete.');
        }
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertLedgerRowsUnchanged(Collection $entries): void
    {
        foreach ($entries as $entry) {
            $entityType = (string) $entry->entity_type;
            $entityId = (int) $entry->entity_id;

            if ($this->workspaceIdForRow($entityType, $entityId) !== (int) $entry->workspace_id) {
                throw new LogicException(
                    'A Pulse editorial backfill ledger entry does not match the row tenant.'
                );
            }

            $currentFingerprint = $this->ledger->fingerprint(
                $this->rowAttributes($entityType, $entityId)
            );

            if (! hash_equals((string) $entry->after_fingerprint, $currentFingerprint)) {
                throw new LogicException('A Pulse editorial backfill row changed after its batch was applied.');
            }
        }
    }

    private function workspaceIdForRow(string $entityType, int $entityId): int
    {
        $workspaceId = match ($entityType) {
            self::ENTITY_POST => DB::table('social_posts')
                ->where('id', $entityId)
                ->value('user_id'),
            self::ENTITY_REVISION => DB::table('social_post_revisions')
                ->where('id', $entityId)
                ->value('user_id'),
            self::ENTITY_APPROVAL => DB::table('social_approval_requests')
                ->join('social_posts', 'social_posts.id', '=', 'social_approval_requests.social_post_id')
                ->where('social_approval_requests.id', $entityId)
                ->value('social_posts.user_id'),
            self::ENTITY_TARGET => DB::table('social_post_targets')
                ->join('social_posts', 'social_posts.id', '=', 'social_post_targets.social_post_id')
                ->where('social_post_targets.id', $entityId)
                ->value('social_posts.user_id'),
            default => throw new LogicException('The Pulse editorial backfill ledger entity is invalid.'),
        };

        if ($workspaceId === null) {
            throw new LogicException('A Pulse editorial backfill ledger tenant cannot be resolved.');
        }

        return (int) $workspaceId;
    }

    /**
     * @param  Collection<int, object>  $entries
     */
    private function assertNoNewEditorialConsumers(Collection $entries): void
    {
        $revisionIds = $entries->where('entity_type', self::ENTITY_REVISION)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $postIds = $entries->where('entity_type', self::ENTITY_POST)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $approvalIds = $entries->where('entity_type', self::ENTITY_APPROVAL)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $targetIds = $entries->where('entity_type', self::ENTITY_TARGET)
            ->pluck('entity_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $newPostConsumer = DB::table('social_posts')
            ->whereIn('approved_revision_id', $revisionIds)
            ->whereNotIn('id', $postIds)
            ->exists();
        $newApprovalConsumer = DB::table('social_approval_requests')
            ->whereIn('social_post_revision_id', $revisionIds)
            ->whereNotIn('id', $approvalIds)
            ->exists();
        $newTargetConsumer = DB::table('social_post_targets')
            ->whereNotIn('id', $targetIds)
            ->where(function ($query) use ($revisionIds): void {
                $query->whereIn('current_revision_id', $revisionIds)
                    ->orWhereIn('last_submitted_revision_id', $revisionIds);
            })
            ->exists();
        $newRevisionConsumer = DB::table('social_post_revisions')
            ->whereIn('social_post_id', $postIds)
            ->whereNotIn('id', $revisionIds)
            ->exists();

        if ($newPostConsumer || $newApprovalConsumer || $newTargetConsumer || $newRevisionConsumer) {
            throw new LogicException(
                'The Pulse editorial backfill cannot be rolled back after new consumers exist.'
            );
        }
    }

    /**
     * @param  array<string, null>  $attributes
     */
    private function restoreUpdatedLedgerEntry(object $entry, array $attributes): void
    {
        $updated = DB::table($this->entityTable((string) $entry->entity_type))
            ->where('id', $entry->entity_id)
            ->update($attributes);

        if ($updated !== 1) {
            throw new LogicException('A Pulse editorial backfill row changed during rollback.');
        }

        $restoredFingerprint = $this->ledger->fingerprint(
            $this->rowAttributes((string) $entry->entity_type, (int) $entry->entity_id)
        );

        if (! hash_equals((string) $entry->before_fingerprint, $restoredFingerprint)) {
            throw new LogicException('A Pulse editorial backfill row was not restored exactly.');
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function publicReport(array $analysis, string $mode): array
    {
        return ['mode' => $mode, ...$analysis['report']];
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function assertReady(array $analysis): void
    {
        if (($analysis['report']['ready'] ?? false) !== true) {
            $reasonCounts = collect($analysis['report']['anomalies']['by_reason'] ?? [])
                ->map(fn (mixed $count, string $reason): string => $reason.'='.(int) $count)
                ->implode(', ');

            throw new LogicException(
                'The Pulse editorial foundation preflight found aggregate anomalies: '.$reasonCounts.'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function recordAnomaly(array &$report, string $reason): void
    {
        $report['ready'] = false;
        $report['anomalies']['total']++;
        $report['anomalies']['by_reason'][$reason] =
            (int) ($report['anomalies']['by_reason'][$reason] ?? 0) + 1;
        ksort($report['anomalies']['by_reason']);
    }

    private function assertSchemaReady(): void
    {
        $this->ledger->assertSchemaReady();

        $required = [
            'social_posts' => ['current_editorial_revision', 'approved_revision_id', 'payload_hash'],
            'social_post_revisions' => ['social_post_id', 'revision_number', 'payload_hash', 'origin'],
            'social_approval_requests' => ['social_post_revision_id'],
            'social_post_targets' => ['current_revision_id', 'last_submitted_revision_id', 'payload_hash'],
        ];

        foreach ($required as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new LogicException("The Pulse editorial foundation table [{$table}] is missing.");
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new LogicException("The Pulse editorial foundation column [{$table}.{$column}] is missing.");
                }
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withExclusiveLock(callable $callback): mixed
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LogicException('Another Pulse editorial foundation operation is already running.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
