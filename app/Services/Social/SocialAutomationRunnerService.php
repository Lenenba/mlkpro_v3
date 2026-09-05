<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class SocialAutomationRunnerService
{
    private const AUTO_PAUSE_FAILURE_THRESHOLD = 3;

    private const EXECUTION_CLAIM_TTL_SECONDS = 600;

    public function __construct(
        private readonly SocialContentPlannerService $plannerService,
        private readonly SocialContentGeneratorService $generatorService,
        private readonly SocialContentQualityChecker $qualityChecker,
        private readonly SocialPostService $postService,
        private readonly SocialApprovalService $approvalService,
        private readonly SocialPublishingService $publishingService,
        private readonly SocialPostRevisionSnapshotService $revisionSnapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(?int $accountId = null, ?int $ruleId = null, bool $dryRun = false): array
    {
        $processed = 0;
        $generated = 0;
        $autoPublished = 0;
        $queuedForApproval = 0;
        $skipped = 0;
        $errors = 0;
        $results = [];

        foreach ($this->plannerService->dueRules($accountId, $ruleId) as $rule) {
            $processed++;
            $result = $this->runRule($rule, $dryRun);
            $results[] = $result;

            if (($result['status'] ?? null) === 'generated') {
                $generated++;

                if (($result['mode'] ?? null) === SocialAutomationRule::APPROVAL_AUTO_PUBLISH) {
                    $autoPublished++;
                } else {
                    $queuedForApproval++;
                }

                continue;
            }

            if (($result['status'] ?? null) === 'error') {
                $errors++;

                continue;
            }

            $skipped++;
        }

        return [
            'processed' => $processed,
            'generated' => $generated,
            'queued_for_approval' => $queuedForApproval,
            'auto_published' => $autoPublished,
            'skipped' => $skipped,
            'errors' => $errors,
            'dry_run' => $dryRun,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function runRuleById(int $ruleId, bool $dryRun = false): array
    {
        $rule = SocialAutomationRule::query()
            ->with(['user', 'createdBy'])
            ->findOrFail($ruleId);

        return $this->runRule($rule, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    public function runRule(SocialAutomationRule $rule, bool $dryRun = false): array
    {
        $rule->loadMissing(['user', 'createdBy']);
        $owner = $rule->user;
        $startedAt = now();

        if (! $owner instanceof User) {
            return [
                'rule_id' => $rule->id,
                'status' => 'error',
                'message' => 'This Pulse automation rule has no valid account owner.',
            ];
        }

        if (! $this->plannerService->isDue($rule)) {
            return [
                'rule_id' => $rule->id,
                'status' => 'skipped',
                'message' => 'This Pulse automation rule is not due yet.',
            ];
        }

        $claim = $this->acquireExecutionClaim($rule);
        if ($claim === null) {
            return [
                'rule_id' => $rule->id,
                'status' => 'skipped',
                'message' => 'This Pulse automation rule is no longer due or is already being processed.',
            ];
        }

        $rule = $claim['rule'];
        $claimToken = $claim['claim_token'];
        $expectedPolicy = $claim['policy'];
        $owner = $rule->user;

        if (! $owner instanceof User) {
            $this->releaseExecutionClaim((int) $rule->id, $claimToken);

            return [
                'rule_id' => $rule->id,
                'status' => 'error',
                'message' => 'This Pulse automation rule has no valid account owner.',
            ];
        }

        try {
            if (! $owner->hasCompanyFeature('social')) {
                return $this->skipRuleCycle(
                    $rule,
                    'Pulse is disabled for this workspace.',
                    $dryRun,
                    $owner,
                    $startedAt,
                    'feature_disabled',
                    false,
                    [],
                    $claimToken,
                    $expectedPolicy,
                );
            }

            $targetValidation = $this->qualityChecker->validateTargets($owner, $rule);
            if (! $targetValidation['passes']) {
                return $this->skipRuleCycle(
                    $rule,
                    (string) ($targetValidation['message'] ?? 'No publishable social account is available for this automation rule.'),
                    $dryRun,
                    $owner,
                    $startedAt,
                    'targets_unavailable',
                    true,
                    [],
                    $claimToken,
                    $expectedPolicy,
                );
            }

            $selectedSource = $this->plannerService->selectSource($owner, $rule);
            if (! is_array($selectedSource)) {
                return $this->skipRuleCycle(
                    $rule,
                    'No eligible content source is currently available for this Pulse automation rule.',
                    $dryRun,
                    $owner,
                    $startedAt,
                    'source_unavailable',
                    true,
                    [],
                    $claimToken,
                    $expectedPolicy,
                );
            }

            $candidate = $this->generatorService->generate($owner, $rule, $selectedSource);
            $candidateValidation = $this->qualityChecker->validateCandidate($owner, $rule, $candidate);
            if (! $candidateValidation['passes']) {
                return $this->skipRuleCycle(
                    $rule,
                    (string) ($candidateValidation['message'] ?? 'This Pulse automation candidate did not pass its quality checks.'),
                    $dryRun,
                    $owner,
                    $startedAt,
                    'quality_guard',
                    false,
                    [
                        'selected_source_type' => $selectedSource['source_type'] ?? null,
                        'selected_source_id' => $selectedSource['source_id'] ?? null,
                    ],
                    $claimToken,
                    $expectedPolicy,
                );
            }

            if ($dryRun) {
                return [
                    'rule_id' => $rule->id,
                    'status' => 'generated',
                    'mode' => $rule->approval_mode,
                    'message' => 'Pulse automation candidate is ready in dry-run mode.',
                    'source_type' => $selectedSource['source_type'] ?? null,
                    'source_id' => $selectedSource['source_id'] ?? null,
                ];
            }

            $targetConnections = $targetValidation['connections'];
            $committed = DB::transaction(function () use (
                $owner,
                $rule,
                $targetConnections,
                $candidate,
                $selectedSource,
                $startedAt,
                $claimToken,
                $expectedPolicy,
            ): array {
                $claimedRule = $this->claimedRuleForUpdate(
                    (int) $rule->id,
                    (int) $owner->id,
                    $claimToken,
                    $expectedPolicy,
                );
                $actor = $this->resolveActor($claimedRule, $owner);
                $mode = (string) $claimedRule->approval_mode;
                $automationMetadata = $this->automationMetadata($claimedRule, $candidate, $selectedSource);
                $draft = $this->postService->createAutomationDraft($owner, $actor, $claimedRule, $targetConnections, [
                    'source_type' => $candidate['source_type'],
                    'source_id' => $candidate['source_id'],
                    'content_payload' => $candidate['content_payload'],
                    'media_payload' => $candidate['media_payload'],
                    'link_url' => $candidate['link_url'],
                    'metadata' => array_filter([
                        'selected_target_count' => $targetConnections->count(),
                        'draft_saved_from' => 'social_autopilot',
                        'has_image' => data_get($candidate, 'media_payload.0.url') !== null,
                        'has_link' => trim((string) ($candidate['link_url'] ?? '')) !== '',
                        'source' => $candidate['metadata']['source'] ?? null,
                        'ai_generation' => $candidate['metadata']['ai_generation'] ?? null,
                        'automation' => $automationMetadata,
                    ], fn ($value) => $value !== null),
                ]);

                $post = $mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                    ? $this->publishingService->publishNowFromAutopilot(
                        $owner,
                        $actor,
                        $draft,
                        $claimedRule,
                        $expectedPolicy,
                        $claimToken,
                    )
                    : $this->approvalService->submit($owner, $actor, $draft, [
                        'note' => sprintf('Generated automatically by Pulse Autopilot rule "%s".', $claimedRule->name),
                    ]);

                $completedAt = now();
                $claimedRule->forceFill([
                    'last_generated_at' => $completedAt,
                    'next_generation_at' => $this->plannerService->nextGenerationAt($claimedRule, $completedAt),
                    'last_error' => null,
                    'metadata' => $this->markRuleHealthy($claimedRule->metadata, $completedAt),
                ])->save();

                $run = $this->recordRun($claimedRule, [
                    'user_id' => $owner->id,
                    'social_post_id' => $post->id,
                    'status' => SocialAutomationRun::STATUS_GENERATED,
                    'outcome_code' => $mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                        ? 'auto_published'
                        : 'queued_for_approval',
                    'message' => $mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                        ? 'Pulse automation candidate generated and queued for publication.'
                        : 'Pulse automation candidate generated and submitted for approval.',
                    'source_type' => $selectedSource['source_type'] ?? null,
                    'source_id' => $selectedSource['source_id'] ?? null,
                    'metadata' => array_filter([
                        'mode' => $rule->approval_mode,
                        'selected_source_label' => $selectedSource['source_label'] ?? null,
                        'content_fingerprint' => $candidate['content_fingerprint'] ?? null,
                        'ai_generation' => $this->runAiGenerationMetadata($candidate),
                    ], fn ($value) => $value !== null),
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                ]);

                return [
                    'mode' => $mode,
                    'post' => $post,
                    'run' => $run,
                ];
            });

            $post = $committed['post'];
            $run = $committed['run'];
            $mode = $committed['mode'];

            return [
                'rule_id' => $rule->id,
                'post_id' => $post->id,
                'run_id' => $run?->id,
                'status' => 'generated',
                'mode' => $mode,
                'message' => $mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                    ? 'Pulse automation candidate generated and queued for publication.'
                    : 'Pulse automation candidate generated and submitted for approval.',
                'source_type' => $selectedSource['source_type'] ?? null,
                'source_id' => $selectedSource['source_id'] ?? null,
            ];
        } catch (ValidationException $exception) {
            return $this->errorRuleCycle(
                $rule,
                $this->validationMessage($exception),
                $dryRun,
                $owner,
                $startedAt,
                'validation_error',
                $claimToken,
                $expectedPolicy,
            );
        } catch (\Throwable $exception) {
            return $this->errorRuleCycle(
                $rule,
                trim($exception->getMessage()) !== ''
                    ? trim($exception->getMessage())
                    : 'Pulse automation failed while generating this publication candidate.',
                $dryRun,
                $owner,
                $startedAt,
                'execution_error',
                $claimToken,
                $expectedPolicy,
            );
        } finally {
            $this->releaseExecutionClaim((int) $rule->id, $claimToken);
        }
    }

    public function regeneratePendingApproval(User $owner, User $actor, SocialPost $post): SocialPost
    {
        $post->loadMissing([
            'user',
            'automationRule.user',
            'targets.socialAccountConnection',
            'latestApprovalRequest.requestedBy',
            'latestApprovalRequest.resolvedBy',
        ]);

        if ((int) $post->user_id !== (int) $owner->id) {
            abort(404);
        }

        if ((string) $post->status !== SocialPost::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages([
                'post' => 'Only a pending Pulse approval can be regenerated from the approval inbox.',
            ]);
        }

        $rule = $post->automationRule;
        if (! $rule instanceof SocialAutomationRule) {
            throw ValidationException::withMessages([
                'post' => 'Only posts generated by Pulse Autopilot can be regenerated automatically.',
            ]);
        }

        $sourceType = trim((string) $post->source_type);
        $sourceId = (int) $post->source_id;
        if ($sourceType === '' || $sourceId <= 0) {
            throw ValidationException::withMessages([
                'post' => 'This automated Pulse post no longer has a valid content source to regenerate from.',
            ]);
        }

        $targetValidation = $this->qualityChecker->validateTargets($owner, $rule);
        if (! $targetValidation['passes']) {
            throw ValidationException::withMessages([
                'target_connection_ids' => (string) ($targetValidation['message'] ?? 'Reconnect at least one publishable social account before regenerating this Pulse candidate.'),
            ]);
        }

        $selectedSource = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => (string) data_get($post->metadata, 'source.label', data_get($post->metadata, 'automation.selected_source_label', '')),
        ];

        $candidate = $this->generatorService->generate($owner, $rule, $selectedSource);
        $candidateValidation = $this->qualityChecker->validateCandidate($owner, $rule, $candidate, null, $post->id);
        if (! $candidateValidation['passes']) {
            throw ValidationException::withMessages([
                'content' => (string) ($candidateValidation['message'] ?? 'This regenerated Pulse candidate did not pass its quality checks.'),
            ]);
        }

        $targetConnections = $targetValidation['connections'];

        return DB::transaction(function () use (
            $owner,
            $actor,
            $post,
            $rule,
            $targetConnections,
            $candidate,
            $selectedSource
        ): SocialPost {
            $this->approvalService->reject($owner, $actor, $post, [
                'note' => sprintf('Pulse Autopilot generated a replacement for rule "%s".', $rule->name),
            ]);

            $attempt = (int) data_get($post->metadata, 'automation.generation_attempt', 1) + 1;
            $automationMetadata = $this->automationMetadata($rule, $candidate, $selectedSource);
            $automationMetadata['generation_mode'] = 'manual_regeneration';
            $automationMetadata['generation_attempt'] = $attempt;

            $draft = $this->postService->createAutomationDraft($owner, $actor, $rule, $targetConnections, [
                'source_type' => $candidate['source_type'],
                'source_id' => $candidate['source_id'],
                'content_payload' => $candidate['content_payload'],
                'media_payload' => $candidate['media_payload'],
                'link_url' => $candidate['link_url'],
                'metadata' => array_filter([
                    'selected_target_count' => $targetConnections->count(),
                    'draft_saved_from' => 'social_autopilot_regeneration',
                    'has_image' => data_get($candidate, 'media_payload.0.url') !== null,
                    'has_link' => trim((string) ($candidate['link_url'] ?? '')) !== '',
                    'source' => $candidate['metadata']['source'] ?? null,
                    'ai_generation' => $candidate['metadata']['ai_generation'] ?? null,
                    'automation' => $automationMetadata,
                ], fn ($value) => $value !== null),
            ]);

            return $this->approvalService->submit($owner, $actor, $draft, [
                'note' => sprintf('Regenerated automatically by Pulse Autopilot rule "%s".', $rule->name),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $selectedSource
     * @return array<string, mixed>
     */
    private function automationMetadata(
        SocialAutomationRule $rule,
        array $candidate,
        array $selectedSource
    ): array {
        return array_filter([
            'rule_id' => $rule->id,
            'rule_name_snapshot' => $rule->name,
            'generated_at' => now()->toIso8601String(),
            'generation_mode' => 'scheduled_rule',
            'approval_mode' => $rule->approval_mode,
            'language' => $candidate['language'] ?? $rule->language,
            'source_pool_type' => 'configured_sources',
            'selected_source_type' => $selectedSource['source_type'] ?? null,
            'selected_source_id' => $selectedSource['source_id'] ?? null,
            'selected_source_label' => $selectedSource['source_label'] ?? null,
            'content_fingerprint' => $candidate['content_fingerprint'] ?? null,
            'generation_attempt' => 1,
            'ai_generation_mode' => data_get($candidate, 'metadata.ai_generation.generation_mode'),
            'ai_fallback_used' => data_get($candidate, 'metadata.ai_generation.fallback_used'),
            'ai_selected_score' => data_get($candidate, 'metadata.ai_generation.selected_score'),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function runAiGenerationMetadata(array $candidate): ?array
    {
        $metadata = data_get($candidate, 'metadata.ai_generation');
        if (! is_array($metadata)) {
            return null;
        }

        return array_filter([
            'generation_mode' => $metadata['generation_mode'] ?? null,
            'text_model' => $metadata['text_model'] ?? null,
            'selected_score' => $metadata['selected_score'] ?? null,
            'variant_count' => $metadata['variant_count'] ?? null,
            'fallback_used' => $metadata['fallback_used'] ?? null,
            'fallback_reason' => $metadata['fallback_reason'] ?? null,
            'image_generated' => data_get($metadata, 'image.generated'),
            'image_status' => data_get($metadata, 'image.status'),
            'image_model' => data_get($metadata, 'image.model'),
            'image_outcome_code' => data_get($metadata, 'image.outcome_code'),
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{
     *     rule:SocialAutomationRule,
     *     claim_token:string,
     *     policy:array{rule_id:int,approval_mode:string,policy_fingerprint:string,rule_updated_at:string}
     * }|null
     */
    private function acquireExecutionClaim(SocialAutomationRule $rule): ?array
    {
        return DB::transaction(function () use ($rule): ?array {
            $claimedAt = now();
            $claimedRule = SocialAutomationRule::query()
                ->whereKey($rule->id)
                ->where('user_id', $rule->user_id)
                ->lockForUpdate()
                ->first();

            if (! $claimedRule
                || ! $claimedRule->is_active
                || ! $this->plannerService->isDue($claimedRule, $claimedAt)) {
                return null;
            }

            $hasActiveClaim = is_string($claimedRule->execution_claim_token)
                && trim($claimedRule->execution_claim_token) !== ''
                && $claimedRule->execution_claimed_until instanceof Carbon
                && $claimedRule->execution_claimed_until->isAfter($claimedAt);

            if ($hasActiveClaim) {
                return null;
            }

            $claimToken = (string) Str::uuid();
            $usesTimestamps = $claimedRule->timestamps;
            $claimedRule->timestamps = false;

            try {
                $claimedRule->forceFill([
                    'execution_claim_token' => $claimToken,
                    'execution_claimed_until' => $claimedAt->copy()->addSeconds(self::EXECUTION_CLAIM_TTL_SECONDS),
                ])->save();
            } finally {
                $claimedRule->timestamps = $usesTimestamps;
            }

            $claimedRule->load(['user', 'createdBy']);

            return [
                'rule' => $claimedRule,
                'claim_token' => $claimToken,
                'policy' => $this->revisionSnapshots->autopilotPolicyForRule($claimedRule),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $expectedPolicy
     */
    private function claimedRuleForUpdate(
        int $ruleId,
        int $ownerId,
        string $claimToken,
        array $expectedPolicy,
    ): SocialAutomationRule {
        $claimedRule = SocialAutomationRule::query()
            ->whereKey($ruleId)
            ->where('user_id', $ownerId)
            ->lockForUpdate()
            ->first();

        if (! $claimedRule
            || trim($claimToken) === ''
            || ! is_string($claimedRule->execution_claim_token)
            || ! hash_equals($claimedRule->execution_claim_token, $claimToken)
            || ! $claimedRule->execution_claimed_until instanceof Carbon
            || ! $claimedRule->execution_claimed_until->isFuture()) {
            throw new LogicException('The Pulse Autopilot execution claim expired or was replaced before commit.');
        }

        $currentPolicy = $this->revisionSnapshots->autopilotPolicyForRule($claimedRule);
        if (! $this->revisionSnapshots->autopilotPoliciesMatch($currentPolicy, $expectedPolicy)) {
            throw new LogicException('The Pulse Autopilot policy changed after candidate generation.');
        }

        $claimedRule->load(['user', 'createdBy']);

        return $claimedRule;
    }

    private function releaseExecutionClaim(int $ruleId, string $claimToken): void
    {
        if ($ruleId <= 0 || trim($claimToken) === '') {
            return;
        }

        SocialAutomationRule::query()
            ->whereKey($ruleId)
            ->where('execution_claim_token', $claimToken)
            ->toBase()
            ->update([
                'execution_claim_token' => null,
                'execution_claimed_until' => null,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function staleExecutionResult(SocialAutomationRule $rule): array
    {
        return [
            'rule_id' => $rule->id,
            'status' => 'skipped',
            'message' => 'This Pulse automation execution lost its claim or its policy changed before commit.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skipRuleCycle(
        SocialAutomationRule $rule,
        string $message,
        bool $dryRun,
        ?User $owner = null,
        ?Carbon $startedAt = null,
        string $outcomeCode = 'skipped',
        bool $countsAsFailure = false,
        array $context = [],
        string $claimToken = '',
        array $expectedPolicy = [],
    ): array {
        if (! $dryRun) {
            try {
                DB::transaction(function () use (
                    $rule,
                    $message,
                    $owner,
                    $startedAt,
                    $outcomeCode,
                    $countsAsFailure,
                    $context,
                    $claimToken,
                    $expectedPolicy,
                ): void {
                    $claimedRule = $this->claimedRuleForUpdate(
                        (int) $rule->id,
                        (int) $rule->user_id,
                        $claimToken,
                        $expectedPolicy,
                    );
                    $completedAt = now();
                    $metadata = $countsAsFailure
                        ? $this->markRuleFailure($claimedRule->metadata, $message, $outcomeCode, $completedAt)
                        : $this->clearRuleFailureStreak($claimedRule->metadata, $completedAt);
                    $autoPaused = (bool) data_get($metadata, 'health.auto_paused', false);

                    $claimedRule->forceFill([
                        'next_generation_at' => $this->plannerService->nextGenerationAt($claimedRule, $completedAt),
                        'last_error' => $message,
                        'is_active' => $autoPaused ? false : $claimedRule->is_active,
                        'metadata' => $metadata,
                    ])->save();

                    if ($owner instanceof User) {
                        $this->recordRun($claimedRule, [
                            'user_id' => $owner->id,
                            'status' => SocialAutomationRun::STATUS_SKIPPED,
                            'outcome_code' => $autoPaused ? 'auto_paused' : $outcomeCode,
                            'message' => $autoPaused
                                ? sprintf('%s Pulse Autopilot paused this rule after repeated blocking runs.', $message)
                                : $message,
                            'source_type' => $context['selected_source_type'] ?? null,
                            'source_id' => $context['selected_source_id'] ?? null,
                            'metadata' => array_filter([
                                'counts_as_failure' => $countsAsFailure,
                                'auto_paused' => $autoPaused,
                                'selected_source_label' => $context['selected_source_label'] ?? null,
                            ], fn ($value) => $value !== null),
                            'started_at' => $startedAt ?? $completedAt,
                            'completed_at' => $completedAt,
                        ]);
                    }
                });
            } catch (LogicException) {
                return $this->staleExecutionResult($rule);
            }
        }

        return [
            'rule_id' => $rule->id,
            'status' => 'skipped',
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorRuleCycle(
        SocialAutomationRule $rule,
        string $message,
        bool $dryRun,
        ?User $owner = null,
        ?Carbon $startedAt = null,
        string $outcomeCode = 'execution_error',
        string $claimToken = '',
        array $expectedPolicy = [],
    ): array {
        if (! $dryRun) {
            try {
                DB::transaction(function () use (
                    $rule,
                    $message,
                    $owner,
                    $startedAt,
                    $outcomeCode,
                    $claimToken,
                    $expectedPolicy,
                ): void {
                    $claimedRule = $this->claimedRuleForUpdate(
                        (int) $rule->id,
                        (int) $rule->user_id,
                        $claimToken,
                        $expectedPolicy,
                    );
                    $completedAt = now();
                    $metadata = $this->markRuleFailure(
                        $claimedRule->metadata,
                        $message,
                        $outcomeCode,
                        $completedAt,
                    );
                    $autoPaused = (bool) data_get($metadata, 'health.auto_paused', false);

                    $claimedRule->forceFill([
                        'next_generation_at' => $this->plannerService->nextGenerationAt($claimedRule, $completedAt),
                        'last_error' => $message,
                        'is_active' => $autoPaused ? false : $claimedRule->is_active,
                        'metadata' => $metadata,
                    ])->save();

                    if ($owner instanceof User) {
                        $this->recordRun($claimedRule, [
                            'user_id' => $owner->id,
                            'status' => SocialAutomationRun::STATUS_ERROR,
                            'outcome_code' => $autoPaused ? 'auto_paused' : $outcomeCode,
                            'message' => $autoPaused
                                ? sprintf('%s Pulse Autopilot paused this rule after repeated execution errors.', $message)
                                : $message,
                            'metadata' => [
                                'auto_paused' => $autoPaused,
                            ],
                            'started_at' => $startedAt ?? $completedAt,
                            'completed_at' => $completedAt,
                        ]);
                    }
                });
            } catch (LogicException) {
                return $this->staleExecutionResult($rule);
            }
        }

        return [
            'rule_id' => $rule->id,
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function markRuleHealthy(?array $metadata, Carbon $completedAt): array
    {
        $next = is_array($metadata) ? $metadata : [];
        $health = is_array($next['health'] ?? null) ? $next['health'] : [];

        if (! empty($health['auto_paused'])) {
            $health['last_auto_pause'] = array_filter([
                'at' => $health['auto_paused_at'] ?? null,
                'reason' => $health['auto_pause_reason'] ?? null,
                'code' => $health['auto_pause_code'] ?? null,
            ], fn ($value) => $value !== null);
        }

        unset(
            $health['consecutive_failures'],
            $health['last_failure_at'],
            $health['last_failure_code'],
            $health['last_failure_message'],
            $health['auto_paused'],
            $health['auto_paused_at'],
            $health['auto_pause_reason'],
            $health['auto_pause_code'],
            $health['auto_pause_threshold']
        );

        $health['last_success_at'] = $completedAt->toIso8601String();
        $next['health'] = $health;

        return $next;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function clearRuleFailureStreak(?array $metadata, Carbon $completedAt): array
    {
        $next = is_array($metadata) ? $metadata : [];
        $health = is_array($next['health'] ?? null) ? $next['health'] : [];

        unset(
            $health['consecutive_failures'],
            $health['last_failure_at'],
            $health['last_failure_code'],
            $health['last_failure_message']
        );

        $health['last_guarded_skip_at'] = $completedAt->toIso8601String();
        $next['health'] = $health;

        return $next;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    private function markRuleFailure(?array $metadata, string $message, string $outcomeCode, Carbon $completedAt): array
    {
        $next = is_array($metadata) ? $metadata : [];
        $health = is_array($next['health'] ?? null) ? $next['health'] : [];
        $failureCount = max(0, (int) ($health['consecutive_failures'] ?? 0)) + 1;

        $health['consecutive_failures'] = $failureCount;
        $health['last_failure_at'] = $completedAt->toIso8601String();
        $health['last_failure_code'] = $outcomeCode;
        $health['last_failure_message'] = $message;

        if ($failureCount >= self::AUTO_PAUSE_FAILURE_THRESHOLD) {
            $health['auto_paused'] = true;
            $health['auto_paused_at'] = $completedAt->toIso8601String();
            $health['auto_pause_reason'] = $message;
            $health['auto_pause_code'] = $outcomeCode;
            $health['auto_pause_threshold'] = self::AUTO_PAUSE_FAILURE_THRESHOLD;
        }

        $next['health'] = $health;

        return $next;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordRun(SocialAutomationRule $rule, array $attributes): SocialAutomationRun
    {
        return SocialAutomationRun::query()->create([
            'user_id' => (int) $attributes['user_id'],
            'social_automation_rule_id' => $rule->id,
            'social_post_id' => $attributes['social_post_id'] ?? null,
            'status' => $attributes['status'],
            'outcome_code' => $attributes['outcome_code'] ?? null,
            'message' => $attributes['message'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'started_at' => $attributes['started_at'] ?? now(),
            'completed_at' => $attributes['completed_at'] ?? now(),
        ]);
    }

    private function resolveActor(SocialAutomationRule $rule, User $owner): User
    {
        $actor = $rule->createdBy;

        return $actor instanceof User ? $actor : $owner;
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim((string) $item))
            ->first();

        return is_string($message) && $message !== ''
            ? $message
            : 'Pulse automation failed while validating the generated post candidate.';
    }
}
