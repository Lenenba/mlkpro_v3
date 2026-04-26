<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialAutomationRunnerService
{
    private const AUTO_PAUSE_FAILURE_THRESHOLD = 3;

    public function __construct(
        private readonly SocialContentPlannerService $plannerService,
        private readonly SocialContentGeneratorService $generatorService,
        private readonly SocialContentQualityChecker $qualityChecker,
        private readonly SocialPostService $postService,
        private readonly SocialApprovalService $approvalService,
        private readonly SocialPublishingService $publishingService,
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

        if (! $owner->hasCompanyFeature('social')) {
            return $this->skipRuleCycle(
                $rule,
                'Malikia Pulse is disabled for this workspace.',
                $dryRun,
                $owner,
                $startedAt,
                'feature_disabled'
            );
        }

        if (! $this->plannerService->isDue($rule)) {
            return [
                'rule_id' => $rule->id,
                'status' => 'skipped',
                'message' => 'This Pulse automation rule is not due yet.',
            ];
        }

        $lock = Cache::lock('social-automation-rule:'.$rule->id, 30);
        if (! $lock->get()) {
            return [
                'rule_id' => $rule->id,
                'status' => 'skipped',
                'message' => 'This Pulse automation rule is already being processed.',
            ];
        }

        try {
            $targetValidation = $this->qualityChecker->validateTargets($owner, $rule);
            if (! $targetValidation['passes']) {
                return $this->skipRuleCycle(
                    $rule,
                    (string) ($targetValidation['message'] ?? 'No publishable social account is available for this automation rule.'),
                    $dryRun,
                    $owner,
                    $startedAt,
                    'targets_unavailable',
                    true
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
                    true
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
                    ]
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

            $actor = $this->resolveActor($rule, $owner);
            $targetConnections = $targetValidation['connections'];
            $nextGenerationAt = $this->plannerService->nextGenerationAt($rule, now());
            $automationMetadata = $this->automationMetadata($rule, $candidate, $selectedSource);

            $post = DB::transaction(function () use (
                $owner,
                $actor,
                $rule,
                $targetConnections,
                $candidate,
                $automationMetadata
            ): SocialPost {
                $draft = $this->postService->createAutomationDraft($owner, $actor, $rule, $targetConnections, [
                    'source_type' => $candidate['source_type'],
                    'source_id' => $candidate['source_id'],
                    'content_payload' => $candidate['content_payload'],
                    'media_payload' => $candidate['media_payload'],
                    'link_url' => $candidate['link_url'],
                    'metadata' => [
                        'selected_target_count' => $targetConnections->count(),
                        'draft_saved_from' => 'social_autopilot',
                        'has_image' => data_get($candidate, 'media_payload.0.url') !== null,
                        'has_link' => trim((string) ($candidate['link_url'] ?? '')) !== '',
                        'source' => $candidate['metadata']['source'] ?? null,
                        'automation' => $automationMetadata,
                    ],
                ]);

                return $rule->approval_mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                    ? $this->publishingService->publishNow($owner, $actor, $draft)
                    : $this->approvalService->submit($owner, $actor, $draft, [
                        'note' => sprintf('Generated automatically by Pulse Autopilot rule "%s".', $rule->name),
                    ]);
            });

            $completedAt = now();
            $rule->forceFill([
                'last_generated_at' => $completedAt,
                'next_generation_at' => $nextGenerationAt,
                'last_error' => null,
                'metadata' => $this->markRuleHealthy($rule->metadata, $completedAt),
            ])->save();

            $run = null;
            if (! $dryRun) {
                $run = $this->recordRun($rule, [
                    'user_id' => $owner->id,
                    'social_post_id' => $post->id,
                    'status' => SocialAutomationRun::STATUS_GENERATED,
                    'outcome_code' => $rule->approval_mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                        ? 'auto_published'
                        : 'queued_for_approval',
                    'message' => $rule->approval_mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
                        ? 'Pulse automation candidate generated and queued for publication.'
                        : 'Pulse automation candidate generated and submitted for approval.',
                    'source_type' => $selectedSource['source_type'] ?? null,
                    'source_id' => $selectedSource['source_id'] ?? null,
                    'metadata' => [
                        'mode' => $rule->approval_mode,
                        'selected_source_label' => $selectedSource['source_label'] ?? null,
                        'content_fingerprint' => $candidate['content_fingerprint'] ?? null,
                    ],
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                ]);
            }

            return [
                'rule_id' => $rule->id,
                'post_id' => $post->id,
                'run_id' => $run?->id,
                'status' => 'generated',
                'mode' => $rule->approval_mode,
                'message' => $rule->approval_mode === SocialAutomationRule::APPROVAL_AUTO_PUBLISH
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
                'validation_error'
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
                'execution_error'
            );
        } finally {
            optional($lock)->release();
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
                'metadata' => [
                    'selected_target_count' => $targetConnections->count(),
                    'draft_saved_from' => 'social_autopilot_regeneration',
                    'has_image' => data_get($candidate, 'media_payload.0.url') !== null,
                    'has_link' => trim((string) ($candidate['link_url'] ?? '')) !== '',
                    'source' => $candidate['metadata']['source'] ?? null,
                    'automation' => $automationMetadata,
                ],
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
        ], fn ($value) => $value !== null);
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
        array $context = []
    ): array
    {
        if (! $dryRun) {
            $completedAt = now();
            $metadata = $countsAsFailure
                ? $this->markRuleFailure($rule->metadata, $message, $outcomeCode, $completedAt)
                : $this->clearRuleFailureStreak($rule->metadata, $completedAt);

            $autoPaused = (bool) data_get($metadata, 'health.auto_paused', false);
            $rule->forceFill([
                'next_generation_at' => $this->plannerService->nextGenerationAt($rule, now()),
                'last_error' => $message,
                'is_active' => $autoPaused ? false : $rule->is_active,
                'metadata' => $metadata,
            ])->save();

            if ($owner instanceof User) {
                $this->recordRun($rule, [
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
        string $outcomeCode = 'execution_error'
    ): array
    {
        if (! $dryRun) {
            $completedAt = now();
            $metadata = $this->markRuleFailure($rule->metadata, $message, $outcomeCode, $completedAt);
            $autoPaused = (bool) data_get($metadata, 'health.auto_paused', false);

            $rule->forceFill([
                'next_generation_at' => $this->plannerService->nextGenerationAt($rule, now()),
                'last_error' => $message,
                'is_active' => $autoPaused ? false : $rule->is_active,
                'metadata' => $metadata,
            ])->save();

            if ($owner instanceof User) {
                $this->recordRun($rule, [
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
