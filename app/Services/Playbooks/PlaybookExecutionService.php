<?php

namespace App\Services\Playbooks;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\ProspectStatusHistoryService;
use App\Services\Segments\SegmentResolverRegistry;
use App\Services\UsageLimitService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class PlaybookExecutionService
{
    public function __construct(
        private readonly SegmentResolverRegistry $segmentResolverRegistry,
        private readonly CompanyFeatureService $companyFeatureService,
    ) {}

    public function executeManual(Playbook $playbook, User $actor): PlaybookRun
    {
        return $this->execute($playbook, $actor, PlaybookRun::ORIGIN_MANUAL);
    }

    public function reserve(
        Playbook $playbook,
        User $actor,
        string $origin = PlaybookRun::ORIGIN_MANUAL,
        ?Carbon $scheduledFor = null,
    ): PlaybookRun {
        $playbook->loadMissing('savedSegment');
        $this->ensureActorCanRun($playbook, $actor);

        return PlaybookRun::create([
            'user_id' => $playbook->user_id,
            'playbook_id' => $playbook->id,
            'saved_segment_id' => $playbook->saved_segment_id,
            'requested_by_user_id' => $actor->id,
            'module' => (string) $playbook->module,
            'action_key' => (string) $playbook->action_key,
            'origin' => $origin,
            'status' => PlaybookRun::STATUS_PENDING,
            'scheduled_for' => $scheduledFor,
            'summary' => [
                'message' => 'Playbook queued.',
            ],
        ]);
    }

    public function execute(
        Playbook $playbook,
        User $actor,
        string $origin = PlaybookRun::ORIGIN_MANUAL,
        ?Carbon $scheduledFor = null,
    ): PlaybookRun {
        $run = $this->reserve($playbook, $actor, $origin, $scheduledFor);

        return $this->executeReserved($playbook, $actor, $run);
    }

    public function executeReserved(Playbook $playbook, User $actor, PlaybookRun $run): PlaybookRun
    {
        $playbook->loadMissing('savedSegment');
        $this->ensureActorCanRun($playbook, $actor);

        if ((int) ($run->playbook_id ?? 0) !== (int) $playbook->id) {
            throw new InvalidArgumentException('Reserved playbook run does not match the target playbook.');
        }

        $startedAt = now();

        try {
            $resolvedSegment = $this->resolvePlaybookSegment($playbook);
            $selectedIds = collect($resolvedSegment['ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            $run->update([
                'status' => PlaybookRun::STATUS_RUNNING,
                'selected_count' => $selectedIds->count(),
                'started_at' => $startedAt,
                'summary' => [
                    'message' => 'Playbook running.',
                    'ids' => $selectedIds->all(),
                    'selected_count' => $selectedIds->count(),
                ],
            ]);

            $result = $this->executeResolvedAction($playbook, $actor, $selectedIds);
            $summary = $this->bulkActionResult(
                (string) ($result['message'] ?? 'Playbook executed.'),
                $selectedIds->all(),
                $result['processed_ids'] ?? [],
                [
                    'success_count' => (int) ($result['success_count'] ?? 0),
                    'failed_count' => (int) ($result['failed_count'] ?? 0),
                    'skipped_count' => (int) ($result['skipped_count'] ?? 0),
                    'errors' => $result['errors'] ?? [],
                ]
            );

            $finishedAt = now();
            $run->update([
                'status' => (string) ($result['status'] ?? PlaybookRun::STATUS_COMPLETED),
                'processed_count' => (int) $summary['processed_count'],
                'success_count' => (int) $summary['success_count'],
                'failed_count' => (int) $summary['failed_count'],
                'skipped_count' => (int) $summary['skipped_count'],
                'finished_at' => $finishedAt,
                'summary' => $summary,
            ]);

            $playbook->forceFill([
                'last_run_at' => $finishedAt,
                'updated_by_user_id' => $actor->id,
            ])->save();
        } catch (Throwable $exception) {
            $finishedAt = now();
            $summary = $this->bulkActionResult(
                'Playbook execution failed.',
                [],
                [],
                [
                    'errors' => [$exception->getMessage()],
                ]
            );

            $run->update([
                'status' => PlaybookRun::STATUS_FAILED,
                'finished_at' => $finishedAt,
                'summary' => $summary,
            ]);

            $playbook->forceFill([
                'last_run_at' => $finishedAt,
                'updated_by_user_id' => $actor->id,
            ])->save();
        }

        return $run->fresh() ?? $run;
    }

    private function ensureActorCanRun(Playbook $playbook, User $actor): void
    {
        if ((int) $actor->accountOwnerId() !== (int) $playbook->user_id) {
            throw new AuthorizationException('You are not allowed to run this playbook.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePlaybookSegment(Playbook $playbook): array
    {
        $segment = $playbook->savedSegment;
        if (! $segment) {
            throw new InvalidArgumentException('Playbook segment is missing.');
        }

        if ((int) $segment->user_id !== (int) $playbook->user_id) {
            throw new InvalidArgumentException('Playbook segment tenant does not match playbook tenant.');
        }

        if ((string) $segment->module !== (string) $playbook->module) {
            throw new InvalidArgumentException('Playbook module does not match its saved segment module.');
        }

        $resolved = $this->segmentResolverRegistry->resolve($segment);
        $segment->forceFill([
            'cached_count' => (int) ($resolved['selected_count'] ?? 0),
            'last_resolved_at' => now(),
            'updated_by_user_id' => $playbook->updated_by_user_id ?? $playbook->created_by_user_id,
        ])->save();

        return $resolved;
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function executeResolvedAction(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        return match ((string) $playbook->module) {
            SavedSegment::MODULE_REQUEST => $this->executeRequestAction($playbook, $actor, $selectedIds),
            SavedSegment::MODULE_CUSTOMER => $this->executeCustomerAction($playbook, $actor, $selectedIds),
            SavedSegment::MODULE_QUOTE => $this->executeQuoteAction($playbook, $actor, $selectedIds),
            default => $this->fatalActionResult(
                sprintf('Unsupported playbook module [%s].', $playbook->module),
                $selectedIds
            ),
        };
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function executeRequestAction(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $owner = $this->resolveOwner($playbook->user_id);
        if (! $this->companyFeatureService->hasFeature($owner, 'requests')) {
            return $this->fatalActionResult('Requests module is unavailable for this account.', $selectedIds);
        }

        if ((int) $actor->id !== (int) $owner->id) {
            return $this->fatalActionResult('Only the account owner can run request playbooks.', $selectedIds);
        }

        return match ((string) $playbook->action_key) {
            'update_status' => $this->runRequestStatusUpdate($playbook, $actor, $selectedIds),
            'assign_selected' => $this->runRequestAssignment($playbook, $actor, $selectedIds),
            default => $this->fatalActionResult(
                sprintf('Unsupported request playbook action [%s].', $playbook->action_key),
                $selectedIds
            ),
        };
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function executeCustomerAction(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        return match ((string) $playbook->action_key) {
            'portal_enable' => $this->runCustomerBulkUpdate($playbook, $actor, $selectedIds, [
                'column' => 'portal_access',
                'value' => true,
                'message' => 'Portal access enabled.',
                'ability' => 'update',
            ]),
            'portal_disable' => $this->runCustomerBulkUpdate($playbook, $actor, $selectedIds, [
                'column' => 'portal_access',
                'value' => false,
                'message' => 'Portal access disabled.',
                'ability' => 'update',
            ]),
            'archive' => $this->runCustomerBulkUpdate($playbook, $actor, $selectedIds, [
                'column' => 'is_active',
                'value' => false,
                'message' => 'Customers archived.',
                'ability' => 'update',
            ]),
            'restore' => $this->runCustomerBulkUpdate($playbook, $actor, $selectedIds, [
                'column' => 'is_active',
                'value' => true,
                'message' => 'Customers restored.',
                'ability' => 'update',
            ]),
            default => $this->fatalActionResult(
                sprintf('Unsupported customer playbook action [%s].', $playbook->action_key),
                $selectedIds
            ),
        };
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function executeQuoteAction(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $owner = $this->resolveOwner($playbook->user_id);
        if (! $this->companyFeatureService->hasFeature($owner, 'quotes')) {
            return $this->fatalActionResult('Quotes module is unavailable for this account.', $selectedIds);
        }

        return match ((string) $playbook->action_key) {
            'schedule_follow_up' => $this->runQuoteScheduleFollowUp($playbook, $actor, $selectedIds),
            'mark_followed_up' => $this->runQuoteMarkFollowedUp($playbook, $actor, $selectedIds),
            'create_follow_up_task' => $this->runQuoteCreateFollowUpTask($playbook, $actor, $selectedIds),
            'archive' => $this->runQuoteArchive($playbook, $actor, $selectedIds),
            default => $this->fatalActionResult(
                sprintf('Unsupported quote playbook action [%s].', $playbook->action_key),
                $selectedIds
            ),
        };
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runRequestStatusUpdate(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $payload = $this->payload($playbook);
        $status = (string) ($payload['status'] ?? '');

        if (! in_array($status, LeadRequest::STATUSES, true)) {
            return $this->fatalActionResult('Request playbook status payload is invalid.', $selectedIds);
        }

        $lostReason = $payload['lost_reason'] ?? null;
        if ($status === LeadRequest::STATUS_LOST && blank($lostReason)) {
            return $this->fatalActionResult('Lost reason is required for lost request playbooks.', $selectedIds);
        }

        $updates = [
            'status' => $status,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'lost_reason' => $status === LeadRequest::STATUS_LOST ? $lostReason : null,
        ];

        return $this->runRequestUpdate($playbook, $actor, $selectedIds, $updates);
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runRequestAssignment(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $payload = $this->payload($playbook);
        if (! array_key_exists('assigned_team_member_id', $payload)) {
            return $this->fatalActionResult('Request playbook assignee payload is missing.', $selectedIds);
        }

        $assigneeId = $payload['assigned_team_member_id'];
        if ($assigneeId !== null) {
            $assigneeExists = TeamMember::query()
                ->forAccount((int) $playbook->user_id)
                ->whereKey((int) $assigneeId)
                ->exists();

            if (! $assigneeExists) {
                return $this->fatalActionResult('Request playbook assignee is invalid.', $selectedIds);
            }
        }

        return $this->runRequestUpdate($playbook, $actor, $selectedIds, [
            'assigned_team_member_id' => $assigneeId !== null ? (int) $assigneeId : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runRequestUpdate(
        Playbook $playbook,
        User $actor,
        Collection $selectedIds,
        array $updates,
    ): array {
        $leads = LeadRequest::query()
            ->where('user_id', $playbook->user_id)
            ->whereIn('id', $selectedIds->all())
            ->get()
            ->keyBy('id');

        $processedIds = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            /** @var LeadRequest|null $lead */
            $lead = $leads->get($selectedId);
            if (! $lead) {
                continue;
            }

            $processedIds[] = $lead->id;

            try {
                $previousStatus = $lead->status;
                $lead->update($updates);

                if (array_key_exists('status', $updates) && $previousStatus !== $lead->status) {
                    app(ProspectStatusHistoryService::class)->record($lead, $actor, [
                        'from_status' => $previousStatus,
                        'to_status' => $lead->status,
                        'comment' => $updates['status'] === LeadRequest::STATUS_LOST ? ($updates['lost_reason'] ?? null) : null,
                        'metadata' => ['source' => 'playbook'],
                    ]);
                }

                ActivityLog::record($actor, $lead, 'bulk_updated', [
                    'from' => $previousStatus,
                    'to' => $lead->status,
                    'assigned_team_member_id' => $lead->assigned_team_member_id,
                ], 'Request updated');

                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = sprintf('Request %d: %s', $lead->id, $exception->getMessage());
            }
        }

        return $this->completedActionResult(
            'Requests updated.',
            $processedIds,
            $selectedIds,
            $successCount,
            $failedCount,
            $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runCustomerBulkUpdate(
        Playbook $playbook,
        User $actor,
        Collection $selectedIds,
        array $definition,
    ): array {
        $customers = Customer::query()
            ->byUser((int) $playbook->user_id)
            ->whereIn('id', $selectedIds->all())
            ->get()
            ->keyBy('id');

        $matchedCustomers = $selectedIds
            ->map(fn (int $selectedId): ?Customer => $customers->get($selectedId))
            ->filter()
            ->values();

        $ability = (string) ($definition['ability'] ?? 'update');
        foreach ($matchedCustomers as $customer) {
            $inspection = Gate::forUser($actor)->inspect($ability, $customer);
            if (! $inspection->allowed()) {
                return $this->fatalActionResult(
                    $inspection->message() ?: 'You are not allowed to run this customer playbook.',
                    $selectedIds
                );
            }
        }

        Customer::query()
            ->byUser((int) $playbook->user_id)
            ->whereIn('id', $matchedCustomers->pluck('id')->all())
            ->update([
                (string) $definition['column'] => $definition['value'],
            ]);

        return $this->completedActionResult(
            (string) $definition['message'],
            $matchedCustomers->pluck('id')->all(),
            $selectedIds,
            $matchedCustomers->count(),
            0,
            [],
        );
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runQuoteScheduleFollowUp(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $payload = $this->payload($playbook);
        $nextFollowUpAt = $this->parseOptionalCarbon($payload['next_follow_up_at'] ?? null);

        if (! $nextFollowUpAt) {
            return $this->fatalActionResult('Quote playbook follow-up date is required.', $selectedIds);
        }

        $quotes = $this->quotesForPlaybook($playbook, $selectedIds);
        $processedIds = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            /** @var Quote|null $quote */
            $quote = $quotes->get($selectedId);
            if (! $quote) {
                continue;
            }

            $inspection = Gate::forUser($actor)->inspect('edit', $quote);
            if (! $inspection->allowed()) {
                $errors[] = sprintf(
                    'Quote %d: %s',
                    $quote->id,
                    $inspection->message() ?: 'Edit access denied.'
                );

                continue;
            }

            if (! $this->canManageRecovery($quote)) {
                $errors[] = sprintf('Quote %d: Recovery actions are only available on open quotes.', $quote->id);

                continue;
            }

            $processedIds[] = $quote->id;

            try {
                $previousNextFollowUpAt = $quote->next_follow_up_at?->copy();
                $previousFollowUpState = $quote->follow_up_state;
                $previousFollowUpCount = (int) ($quote->follow_up_count ?? 0);
                $effectiveNextFollowUpAt = $nextFollowUpAt->copy();

                $quote->update([
                    'next_follow_up_at' => $effectiveNextFollowUpAt,
                    'follow_up_state' => $effectiveNextFollowUpAt->lte(now()) ? 'due' : 'scheduled',
                ]);

                $this->recordQuoteRecoveryActivity(
                    $actor,
                    $quote,
                    $previousNextFollowUpAt,
                    $previousFollowUpState,
                    $previousFollowUpCount,
                    false,
                    true,
                );

                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = sprintf('Quote %d: %s', $quote->id, $exception->getMessage());
            }
        }

        return $this->completedActionResult(
            'Quote follow-up updated.',
            $processedIds,
            $selectedIds,
            $successCount,
            $failedCount,
            $errors,
        );
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runQuoteMarkFollowedUp(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $payload = $this->payload($playbook);
        $nextFollowUpAt = $this->parseOptionalCarbon($payload['next_follow_up_at'] ?? null);

        $quotes = $this->quotesForPlaybook($playbook, $selectedIds);
        $processedIds = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            /** @var Quote|null $quote */
            $quote = $quotes->get($selectedId);
            if (! $quote) {
                continue;
            }

            $inspection = Gate::forUser($actor)->inspect('edit', $quote);
            if (! $inspection->allowed()) {
                $errors[] = sprintf(
                    'Quote %d: %s',
                    $quote->id,
                    $inspection->message() ?: 'Edit access denied.'
                );

                continue;
            }

            if (! $this->canManageRecovery($quote)) {
                $errors[] = sprintf('Quote %d: Recovery actions are only available on open quotes.', $quote->id);

                continue;
            }

            $processedIds[] = $quote->id;

            try {
                $previousNextFollowUpAt = $quote->next_follow_up_at?->copy();
                $previousFollowUpState = $quote->follow_up_state;
                $previousFollowUpCount = (int) ($quote->follow_up_count ?? 0);

                $quote->update([
                    'last_followed_up_at' => now(),
                    'follow_up_count' => $previousFollowUpCount + 1,
                    'next_follow_up_at' => $nextFollowUpAt?->copy(),
                    'follow_up_state' => $nextFollowUpAt
                        ? ($nextFollowUpAt->lte(now()) ? 'due' : 'scheduled')
                        : 'completed',
                ]);

                $this->recordQuoteRecoveryActivity(
                    $actor,
                    $quote,
                    $previousNextFollowUpAt,
                    $previousFollowUpState,
                    $previousFollowUpCount,
                    true,
                    true,
                );

                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = sprintf('Quote %d: %s', $quote->id, $exception->getMessage());
            }
        }

        return $this->completedActionResult(
            'Quote follow-up completed.',
            $processedIds,
            $selectedIds,
            $successCount,
            $failedCount,
            $errors,
        );
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runQuoteCreateFollowUpTask(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $owner = $this->resolveOwner($playbook->user_id);
        if (! $this->companyFeatureService->hasFeature($owner, 'tasks')) {
            return $this->fatalActionResult('Tasks module is unavailable for this account.', $selectedIds);
        }

        $payload = $this->payload($playbook);
        $dueDate = isset($payload['due_date']) && $payload['due_date']
            ? Carbon::parse((string) $payload['due_date'])->toDateString()
            : null;

        $quotes = $this->quotesForPlaybook($playbook, $selectedIds);
        $processedIds = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            /** @var Quote|null $quote */
            $quote = $quotes->get($selectedId);
            if (! $quote) {
                continue;
            }

            $inspection = Gate::forUser($actor)->inspect('edit', $quote);
            if (! $inspection->allowed()) {
                $errors[] = sprintf(
                    'Quote %d: %s',
                    $quote->id,
                    $inspection->message() ?: 'Edit access denied.'
                );

                continue;
            }

            if (! $this->canManageRecovery($quote)) {
                $errors[] = sprintf('Quote %d: Recovery actions are only available on open quotes.', $quote->id);

                continue;
            }

            $processedIds[] = $quote->id;

            try {
                app(UsageLimitService::class)->enforceLimit($actor, 'tasks');

                $taskDueDate = $dueDate
                    ?: ($quote->next_follow_up_at?->toDateString() ?? now()->addDay()->toDateString());
                $quoteNumber = $quote->number ?: 'Quote';

                $task = Task::create([
                    'account_id' => (int) ($actor->accountOwnerId() ?? $actor->id),
                    'created_by_user_id' => (int) $actor->id,
                    'customer_id' => $quote->customer_id,
                    'work_id' => $quote->work_id,
                    'request_id' => $quote->request_id,
                    'title' => "Follow up {$quoteNumber}",
                    'description' => $quote->job_title
                        ? "Recovery follow-up for {$quoteNumber}: {$quote->job_title}"
                        : "Recovery follow-up for {$quoteNumber}",
                    'status' => 'todo',
                    'due_date' => $taskDueDate,
                ]);

                ActivityLog::record($actor, $quote, 'quote_follow_up_task_created', [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'task_due_date' => $task->due_date?->toDateString(),
                    'follow_up_state' => $quote->follow_up_state,
                    'follow_up_count' => (int) ($quote->follow_up_count ?? 0),
                ], 'Recovery task created from quote');

                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = sprintf('Quote %d: %s', $quote->id, $exception->getMessage());
            }
        }

        return $this->completedActionResult(
            'Recovery tasks created.',
            $processedIds,
            $selectedIds,
            $successCount,
            $failedCount,
            $errors,
        );
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function runQuoteArchive(Playbook $playbook, User $actor, Collection $selectedIds): array
    {
        $quotes = $this->quotesForPlaybook($playbook, $selectedIds);
        $processedIds = [];
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($selectedIds as $selectedId) {
            /** @var Quote|null $quote */
            $quote = $quotes->get($selectedId);
            if (! $quote) {
                continue;
            }

            $inspection = Gate::forUser($actor)->inspect('destroy', $quote);
            if (! $inspection->allowed()) {
                $errors[] = sprintf(
                    'Quote %d: %s',
                    $quote->id,
                    $inspection->message() ?: 'Archive access denied.'
                );

                continue;
            }

            $processedIds[] = $quote->id;

            try {
                $quote->update(['archived_at' => now()]);

                ActivityLog::record($actor, $quote, 'archived', [
                    'status' => $quote->status,
                    'total' => $quote->total,
                ], 'Quote archived');

                $successCount++;
            } catch (Throwable $exception) {
                $failedCount++;
                $errors[] = sprintf('Quote %d: %s', $quote->id, $exception->getMessage());
            }
        }

        return $this->completedActionResult(
            'Quotes archived.',
            $processedIds,
            $selectedIds,
            $successCount,
            $failedCount,
            $errors,
        );
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @param  array<int, int>  $processedIds
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private function completedActionResult(
        string $message,
        array $processedIds,
        Collection $selectedIds,
        int $successCount,
        int $failedCount,
        array $errors,
    ): array {
        return [
            'status' => PlaybookRun::STATUS_COMPLETED,
            'message' => $message,
            'processed_ids' => $processedIds,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => max(0, $selectedIds->count() - count($processedIds)),
            'errors' => $errors,
        ];
    }

    /**
     * @param  Collection<int, int>  $selectedIds
     * @return array<string, mixed>
     */
    private function fatalActionResult(string $message, Collection $selectedIds): array
    {
        return [
            'status' => PlaybookRun::STATUS_FAILED,
            'message' => $message,
            'processed_ids' => [],
            'success_count' => 0,
            'failed_count' => 0,
            'skipped_count' => $selectedIds->count(),
            'errors' => [$message],
        ];
    }

    /**
     * @return Collection<int, Quote>
     */
    private function quotesForPlaybook(Playbook $playbook, Collection $selectedIds): Collection
    {
        return Quote::query()
            ->byUserWithArchived((int) $playbook->user_id)
            ->whereIn('id', $selectedIds->all())
            ->get()
            ->keyBy('id');
    }

    private function resolveOwner(int $ownerId): User
    {
        $owner = User::query()->find($ownerId);
        if (! $owner) {
            throw new InvalidArgumentException('Playbook account owner is missing.');
        }

        return $owner;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Playbook $playbook): array
    {
        return is_array($playbook->action_payload) ? $playbook->action_payload : [];
    }

    private function parseOptionalCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function canManageRecovery(Quote $quote): bool
    {
        return ! $quote->isArchived()
            && in_array((string) $quote->status, ['draft', 'sent'], true);
    }

    private function recordQuoteRecoveryActivity(
        ?User $user,
        Quote $quote,
        ?Carbon $previousNextFollowUpAt,
        ?string $previousFollowUpState,
        int $previousFollowUpCount,
        bool $markFollowedUp,
        bool $hasFollowUpField,
    ): void {
        $currentNextFollowUpAt = $quote->next_follow_up_at;
        $nextFollowUpChanged = ! $this->sameCarbonMoment($previousNextFollowUpAt, $currentNextFollowUpAt);
        $properties = [
            'previous_next_follow_up_at' => $previousNextFollowUpAt?->toIso8601String(),
            'next_follow_up_at' => $currentNextFollowUpAt?->toIso8601String(),
            'previous_follow_up_state' => $previousFollowUpState,
            'follow_up_state' => $quote->follow_up_state,
            'previous_follow_up_count' => $previousFollowUpCount,
            'follow_up_count' => (int) ($quote->follow_up_count ?? 0),
            'last_followed_up_at' => $quote->last_followed_up_at?->toIso8601String(),
        ];

        if ($markFollowedUp) {
            ActivityLog::record(
                $user,
                $quote,
                $currentNextFollowUpAt ? 'quote_follow_up_completed_and_rescheduled' : 'quote_follow_up_completed',
                $properties,
                $currentNextFollowUpAt
                    ? 'Quote follow-up completed and rescheduled'
                    : 'Quote follow-up completed',
            );

            return;
        }

        if (! $hasFollowUpField || ! $nextFollowUpChanged) {
            return;
        }

        if ($currentNextFollowUpAt) {
            ActivityLog::record(
                $user,
                $quote,
                'quote_follow_up_scheduled',
                $properties,
                'Quote follow-up scheduled',
            );

            return;
        }

        if ($previousNextFollowUpAt) {
            ActivityLog::record(
                $user,
                $quote,
                'quote_follow_up_cleared',
                $properties,
                'Quote follow-up cleared',
            );
        }
    }

    private function sameCarbonMoment(?Carbon $first, ?Carbon $second): bool
    {
        if ($first === null && $second === null) {
            return true;
        }

        if ($first === null || $second === null) {
            return false;
        }

        return $first->equalTo($second);
    }

    /**
     * @param  array<int, mixed>  $selectedIds
     * @param  array<int, mixed>  $processedIds
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function bulkActionResult(
        string $message,
        array $selectedIds,
        array $processedIds,
        array $extra = [],
    ): array {
        $selected = collect($selectedIds)
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $processed = collect($processedIds)
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $failedCount = max(0, (int) ($extra['failed_count'] ?? 0));
        $successCount = array_key_exists('success_count', $extra)
            ? max(0, (int) $extra['success_count'])
            : $processed->count();
        $skippedCount = array_key_exists('skipped_count', $extra)
            ? max(0, (int) $extra['skipped_count'])
            : max(0, $selected->count() - $processed->count() - $failedCount);
        $errors = $extra['errors'] ?? [];

        unset(
            $extra['success_count'],
            $extra['failed_count'],
            $extra['skipped_count'],
            $extra['errors']
        );

        return array_merge([
            'message' => $message,
            'ids' => $selected->all(),
            'processed_ids' => $processed->all(),
            'selected_count' => $selected->count(),
            'processed_count' => $processed->count(),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'errors' => is_array($errors) ? array_values($errors) : [],
        ], $extra);
    }
}
