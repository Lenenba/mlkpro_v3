<?php

namespace App\Services\Social;

use App\Models\SocialApprovalRequest;
use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\SocialPostRevision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SocialPostRevisionService
{
    public function __construct(
        private readonly SocialPostRevisionSnapshotService $snapshots,
    ) {}

    public function capture(
        SocialPost $post,
        ?User $actor,
        string $origin = SocialPostRevision::ORIGIN_COMPOSER,
    ): SocialPostRevision {
        return DB::transaction(function () use ($post, $actor, $origin): SocialPostRevision {
            $lockedPost = SocialPost::query()
                ->whereKey($post->id)
                ->where('user_id', $post->user_id)
                ->lockForUpdate()
                ->first();

            if (! $lockedPost) {
                throw new LogicException('The Pulse post no longer belongs to its workspace.');
            }

            $this->assertActorBelongsToWorkspace($actor, $lockedPost);
            $lockedPost->loadMissing('user');

            $timezone = $this->timezoneFor($lockedPost->user);
            $snapshot = $this->snapshots->forPost($lockedPost, $timezone);
            $revisionNumber = (int) SocialPostRevision::query()
                ->where('social_post_id', $lockedPost->id)
                ->max('revision_number') + 1;
            $revision = SocialPostRevision::query()->create([
                'user_id' => $lockedPost->user_id,
                'social_post_id' => $lockedPost->id,
                'revision_number' => $revisionNumber,
                ...$snapshot,
                'created_by_user_id' => $actor?->id,
                'origin' => $origin,
            ]);

            $this->moveCurrentPointers(
                $lockedPost,
                $revision,
                SocialPost::STATUS_SOURCE_EXPLICIT,
            );

            $post->setRawAttributes($lockedPost->getAttributes(), true);

            return $revision;
        });
    }

    public function ensureCurrent(
        SocialPost $post,
        ?User $actor = null,
        string $origin = SocialPostRevision::ORIGIN_COMPOSER,
    ): SocialPostRevision {
        $this->assertActorBelongsToWorkspace($actor, $post);
        $post->loadMissing('user');
        $snapshot = $this->snapshots->forPost($post, $this->timezoneFor($post->user));

        if ($post->current_editorial_revision !== null && $post->payload_hash !== null) {
            $revision = SocialPostRevision::query()
                ->where('social_post_id', $post->id)
                ->where('user_id', $post->user_id)
                ->where('revision_number', $post->current_editorial_revision)
                ->where('payload_hash', $snapshot['payload_hash'])
                ->first();

            if ($revision) {
                return $revision;
            }
        }

        return $this->capture($post, $actor, $origin);
    }

    public function approve(
        SocialPost $post,
        SocialApprovalRequest $approvalRequest,
        User $actor,
        Carbon $approvedAt,
    ): SocialPostRevision {
        $this->assertActorBelongsToWorkspace($actor, $post);

        $revision = SocialPostRevision::query()
            ->whereKey($approvalRequest->social_post_revision_id)
            ->where('social_post_id', $post->id)
            ->where('user_id', $post->user_id)
            ->lockForUpdate()
            ->first();

        if (! $revision
            || (int) $revision->revision_number !== (int) $post->current_editorial_revision
            || ! hash_equals((string) $revision->payload_hash, (string) $post->payload_hash)) {
            throw new LogicException('The Pulse approval no longer targets the current editorial revision.');
        }

        if ($revision->approved_at !== null) {
            throw new LogicException('The Pulse editorial revision is already approved.');
        }

        $revision->forceFill([
            'approved_by_user_id' => $actor->id,
            'approved_at' => $approvedAt,
            'approval_provenance' => SocialPostRevision::APPROVAL_TYPE_EXPLICIT,
        ])->save();

        $post->forceFill([
            'approved_revision_id' => $revision->id,
            'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
            'editorial_status_source' => SocialPost::STATUS_SOURCE_EXPLICIT,
        ])->save();

        return $revision;
    }

    public function approveDirectly(
        SocialPost $post,
        User $actor,
        Carbon $approvedAt,
    ): SocialPostRevision {
        return DB::transaction(function () use ($post, $actor, $approvedAt): SocialPostRevision {
            $this->assertActorBelongsToWorkspace($actor, $post);

            $revision = $this->ensureCurrent($post, $actor);

            return $this->approveImplicitRevision(
                $post,
                $revision,
                $actor,
                $approvedAt,
                SocialPostRevision::APPROVAL_TYPE_DIRECT_IMPLICIT,
            );
        });
    }

    public function approveByAutopilotPolicy(
        SocialPost $post,
        User $actor,
        Carbon $approvedAt,
        int $ruleId,
        array $expectedPolicy,
        string $claimToken,
    ): SocialPostRevision {
        return DB::transaction(function () use (
            $post,
            $actor,
            $approvedAt,
            $ruleId,
            $expectedPolicy,
            $claimToken,
        ): SocialPostRevision {
            $this->assertActorBelongsToWorkspace($actor, $post);

            $rule = SocialAutomationRule::query()
                ->whereKey($ruleId)
                ->where('user_id', $post->user_id)
                ->lockForUpdate()
                ->first();

            if (! $rule
                || ! $rule->is_active
                || $rule->approval_mode !== SocialAutomationRule::APPROVAL_AUTO_PUBLISH) {
                throw new LogicException('The Pulse Autopilot policy no longer authorizes automatic publication.');
            }

            if (trim($claimToken) === ''
                || ! is_string($rule->execution_claim_token)
                || ! hash_equals($rule->execution_claim_token, $claimToken)
                || ! $rule->execution_claimed_until instanceof Carbon
                || ! $rule->execution_claimed_until->isFuture()) {
                throw new LogicException('The Pulse Autopilot execution claim is stale and cannot authorize publication.');
            }

            $currentPolicy = $this->snapshots->autopilotPolicyForRule($rule);
            if (! $this->snapshots->autopilotPoliciesMatch($currentPolicy, $expectedPolicy)) {
                throw new LogicException('The Pulse Autopilot policy changed after candidate generation.');
            }

            $post->setRelation('automationRule', $rule);
            $revision = $this->currentUnmodifiedRevision($post);
            $policy = data_get($revision->source_snapshot, 'autopilot_policy');

            if ($revision->origin !== SocialPostRevision::ORIGIN_AUTOMATION
                || ! is_array($policy)
                || ! $this->snapshots->autopilotPoliciesMatch($policy, $expectedPolicy)) {
                throw new LogicException('The Pulse Autopilot policy changed after this editorial revision was created.');
            }

            return $this->approveImplicitRevision(
                $post,
                $revision,
                $actor,
                $approvedAt,
                SocialPostRevision::APPROVAL_TYPE_AUTOPILOT_POLICY,
            );
        });
    }

    private function approveImplicitRevision(
        SocialPost $post,
        SocialPostRevision $revision,
        User $actor,
        Carbon $approvedAt,
        string $approvalType,
    ): SocialPostRevision {
        $this->assertActorBelongsToWorkspace($actor, $post);

        if ($revision->approved_at !== null) {
            if ((int) $post->approved_revision_id !== (int) $revision->id) {
                $post->forceFill([
                    'approved_revision_id' => $revision->id,
                    'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
                    'editorial_status_source' => SocialPost::STATUS_SOURCE_EXPLICIT,
                ])->save();
            }

            return $revision;
        }

        $revision->forceFill([
            'approved_by_user_id' => $actor->id,
            'approved_at' => $approvedAt,
            'approval_provenance' => $approvalType,
        ])->save();

        SocialApprovalRequest::query()->create([
            'social_post_id' => $post->id,
            'social_post_revision_id' => $revision->id,
            'requested_by_user_id' => $actor->id,
            'resolved_by_user_id' => $actor->id,
            'status' => SocialApprovalRequest::STATUS_APPROVED,
            'requested_at' => $approvedAt,
            'approved_at' => $approvedAt,
            'metadata' => $this->implicitApprovalMetadata($revision, $approvalType),
        ]);

        $post->forceFill([
            'approved_revision_id' => $revision->id,
            'editorial_status' => SocialPost::EDITORIAL_STATUS_APPROVED,
            'editorial_status_source' => SocialPost::STATUS_SOURCE_EXPLICIT,
        ])->save();

        return $revision;
    }

    private function currentUnmodifiedRevision(SocialPost $post): SocialPostRevision
    {
        $post->loadMissing('user');
        $revision = SocialPostRevision::query()
            ->where('social_post_id', $post->id)
            ->where('user_id', $post->user_id)
            ->where('revision_number', $post->current_editorial_revision)
            ->where('payload_hash', $post->payload_hash)
            ->lockForUpdate()
            ->first();
        $snapshot = $this->snapshots->forPost($post, $this->timezoneFor($post->user));

        if (! $revision || ! hash_equals((string) $revision->payload_hash, $snapshot['payload_hash'])) {
            throw new LogicException('The Pulse editorial revision changed before Autopilot approval.');
        }

        return $revision;
    }

    public function reject(SocialPost $post): void
    {
        $post->forceFill([
            'approved_revision_id' => null,
            'editorial_status' => SocialPost::EDITORIAL_STATUS_REJECTED,
            'editorial_status_source' => SocialPost::STATUS_SOURCE_EXPLICIT,
        ])->save();
    }

    public function moveCurrentPointers(
        SocialPost $post,
        SocialPostRevision $revision,
        string $statusSource,
    ): void {
        if ((int) $post->id !== (int) $revision->social_post_id
            || (int) $post->user_id !== (int) $revision->user_id) {
            throw new LogicException('A Pulse revision cannot be assigned outside its post workspace.');
        }

        $post->forceFill([
            'editorial_status' => $this->editorialStatus($post),
            'delivery_status' => SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
            'sync_status' => SocialPost::SYNC_STATUS_PENDING,
            'current_editorial_revision' => $revision->revision_number,
            'approved_revision_id' => null,
            'scheduled_timezone' => $revision->scheduled_timezone,
            'scheduled_local_time' => $revision->scheduled_local_time,
            'payload_hash' => $revision->payload_hash,
            'delivery_aggregated_at' => now(),
            'editorial_status_source' => $statusSource,
            'delivery_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
            'sync_status_source' => SocialPost::STATUS_SOURCE_DERIVED,
        ])->save();

        foreach ($post->targets()->lockForUpdate()->get() as $target) {
            $target->forceFill([
                'current_revision_id' => $revision->id,
                'current_editorial_revision' => $revision->revision_number,
                'delivery_status' => SocialPost::DELIVERY_STATUS_NOT_SUBMITTED,
                'sync_status' => SocialPost::SYNC_STATUS_PENDING,
                'payload_hash' => $revision->payload_hash,
            ])->save();
        }
    }

    public function timezoneFor(?User $owner): string
    {
        $fallback = trim((string) config('app.timezone', 'UTC'));
        if (! in_array($fallback, timezone_identifiers_list(), true)) {
            $fallback = 'UTC';
        }

        $timezone = trim((string) ($owner?->company_timezone ?: $fallback));

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : $fallback;
    }

    private function editorialStatus(SocialPost $post): string
    {
        return match ((string) $post->status) {
            SocialPost::STATUS_PENDING_APPROVAL => SocialPost::EDITORIAL_STATUS_PENDING_APPROVAL,
            default => SocialPost::EDITORIAL_STATUS_DRAFT,
        };
    }

    private function assertActorBelongsToWorkspace(?User $actor, SocialPost $post): void
    {
        if ($actor !== null && $actor->accountOwnerId() !== (int) $post->user_id) {
            throw new LogicException('A Pulse revision actor must belong to the post workspace.');
        }
    }

    /**
     * @return array{
     *     approval_type:string,
     *     revision_number:int,
     *     autopilot_policy?:array{rule_id:int,approval_mode:string,policy_fingerprint:string,rule_updated_at:string}
     * }
     */
    private function implicitApprovalMetadata(SocialPostRevision $revision, string $approvalType): array
    {
        $metadata = [
            'approval_type' => $approvalType,
            'revision_number' => (int) $revision->revision_number,
        ];

        if ($approvalType !== SocialPostRevision::APPROVAL_TYPE_AUTOPILOT_POLICY) {
            return $metadata;
        }

        $policy = data_get($revision->source_snapshot, 'autopilot_policy');

        if (! is_array($policy)
            || ! is_numeric($policy['rule_id'] ?? null)
            || ! is_string($policy['approval_mode'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', (string) ($policy['policy_fingerprint'] ?? '')) !== 1
            || ! is_string($policy['rule_updated_at'] ?? null)) {
            throw new LogicException('A Pulse Autopilot approval requires its immutable policy snapshot.');
        }

        $metadata['autopilot_policy'] = [
            'rule_id' => (int) $policy['rule_id'],
            'approval_mode' => $policy['approval_mode'],
            'policy_fingerprint' => $policy['policy_fingerprint'],
            'rule_updated_at' => $policy['rule_updated_at'],
        ];

        return $metadata;
    }
}
