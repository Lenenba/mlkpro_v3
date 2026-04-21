<?php

namespace App\Services\CRM;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MyNextActionsService
{
    private const REQUEST_OPEN_STATUSES = [
        LeadRequest::STATUS_NEW,
        LeadRequest::STATUS_CALL_REQUESTED,
        LeadRequest::STATUS_CONTACTED,
        LeadRequest::STATUS_QUALIFIED,
        LeadRequest::STATUS_QUOTE_SENT,
    ];

    private const QUOTE_OPEN_STATUSES = [
        'draft',
        'sent',
    ];

    private const SOURCE_RANKS = [
        'sales_activity' => 0,
        'task' => 1,
        'request_follow_up' => 2,
        'quote_follow_up' => 3,
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     count: int,
     *     stats: array<string, mixed>,
     *     reference_time: string
     * }
     */
    public function execute(User $user, array $filters = []): array
    {
        $referenceTime = $this->resolveReferenceTime($filters['reference_time'] ?? null);
        $context = $this->resolveContext($user);

        $salesActivityItems = $this->buildSalesActivityItems($context, $referenceTime);
        $dedupeKeys = $this->salesActivityDedupeKeys($salesActivityItems);

        $items = collect()
            ->merge($this->buildRequestFollowUpItems($context, $referenceTime, $dedupeKeys))
            ->merge($this->buildQuoteFollowUpItems($context, $referenceTime, $dedupeKeys))
            ->merge($this->buildTaskItems($context, $referenceTime))
            ->merge($salesActivityItems);

        $items = $this->applyFilters($items, $filters)
            ->sort(fn (array $left, array $right): int => $this->compareItems($left, $right))
            ->values();

        return [
            'items' => $items->all(),
            'count' => $items->count(),
            'stats' => $this->buildStats($items),
            'reference_time' => $referenceTime->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     account_id: int,
     *     is_owner: bool,
     *     membership: TeamMember|null,
     *     can_view_requests: bool,
     *     can_view_quotes: bool,
     *     can_view_tasks: bool,
     *     can_view_customers: bool
     * }
     */
    private function resolveContext(User $user): array
    {
        $accountId = (int) $user->accountOwnerId();
        $isOwner = (int) $user->id === $accountId;
        $membership = $isOwner
            ? null
            : TeamMember::query()
                ->forAccount($accountId)
                ->active()
                ->where('user_id', $user->id)
                ->first();

        $owner = $isOwner
            ? $user
            : User::query()->select(['id', 'company_type'])->find($accountId);

        return [
            'account_id' => $accountId,
            'is_owner' => $isOwner,
            'membership' => $membership,
            'can_view_requests' => $isOwner,
            'can_view_quotes' => $isOwner || $this->membershipHasAnyPermission($membership, ['quotes.view', 'quotes.edit']),
            'can_view_tasks' => $isOwner || $this->membershipHasAnyPermission($membership, ['tasks.view', 'tasks.edit']),
            'can_view_customers' => $isOwner || (
                $membership !== null
                && (
                    ($owner?->company_type ?? null) !== 'products'
                    || $membership->hasPermission('sales.manage')
                )
            ),
        ];
    }

    /**
     * @param  array{account_id: int, is_owner: bool, membership: TeamMember|null, can_view_requests: bool, can_view_quotes: bool, can_view_tasks: bool, can_view_customers: bool}  $context
     * @param  array<int, string>  $dedupeKeys
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRequestFollowUpItems(array $context, Carbon $referenceTime, array $dedupeKeys): Collection
    {
        if (! $context['can_view_requests']) {
            return collect();
        }

        return LeadRequest::query()
            ->where('user_id', $context['account_id'])
            ->whereIn('status', self::REQUEST_OPEN_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'assignee.user:id,name',
            ])
            ->orderBy('next_follow_up_at')
            ->get([
                'id',
                'customer_id',
                'assigned_team_member_id',
                'status',
                'title',
                'contact_name',
                'next_follow_up_at',
            ])
            ->filter(function (LeadRequest $lead) use ($dedupeKeys): bool {
                $key = $this->subjectDueKey(
                    $lead->getMorphClass(),
                    (int) $lead->id,
                    $lead->next_follow_up_at,
                );

                return $key === null || ! in_array($key, $dedupeKeys, true);
            })
            ->map(function (LeadRequest $lead) use ($referenceTime): array {
                $dueAt = $lead->next_follow_up_at?->copy();

                return [
                    'id' => 'request-'.$lead->id.'-follow-up',
                    'source' => 'request_follow_up',
                    'source_label' => 'Lead follow-up',
                    'due_at' => $dueAt?->toIso8601String(),
                    'due_date' => $dueAt?->toDateString(),
                    'is_overdue' => $dueAt?->lt($referenceTime) ?? false,
                    'is_due_today' => $dueAt?->isSameDay($referenceTime) ?? false,
                    'is_all_day' => false,
                    'subject_type' => 'request',
                    'subject_id' => (int) $lead->id,
                    'subject_title' => $lead->title ?: ($lead->contact_name ?: 'Request #'.$lead->id),
                    'status' => $lead->status,
                    'customer' => $this->customerPayload($lead->customer),
                    'assignee' => $this->teamMemberPayload($lead->assignee),
                    'activity' => null,
                ];
            })
            ->values();
    }

    /**
     * @param  array{account_id: int, is_owner: bool, membership: TeamMember|null, can_view_requests: bool, can_view_quotes: bool, can_view_tasks: bool, can_view_customers: bool}  $context
     * @param  array<int, string>  $dedupeKeys
     * @return Collection<int, array<string, mixed>>
     */
    private function buildQuoteFollowUpItems(array $context, Carbon $referenceTime, array $dedupeKeys): Collection
    {
        if (! $context['can_view_quotes']) {
            return collect();
        }

        return Quote::query()
            ->byUser($context['account_id'])
            ->whereIn('status', self::QUOTE_OPEN_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->with(['customer:id,company_name,first_name,last_name,email,phone'])
            ->orderBy('next_follow_up_at')
            ->get([
                'id',
                'customer_id',
                'status',
                'number',
                'job_title',
                'next_follow_up_at',
            ])
            ->filter(function (Quote $quote) use ($dedupeKeys): bool {
                $key = $this->subjectDueKey(
                    $quote->getMorphClass(),
                    (int) $quote->id,
                    $quote->next_follow_up_at,
                );

                return $key === null || ! in_array($key, $dedupeKeys, true);
            })
            ->map(function (Quote $quote) use ($referenceTime): array {
                $dueAt = $quote->next_follow_up_at?->copy();

                return [
                    'id' => 'quote-'.$quote->id.'-follow-up',
                    'source' => 'quote_follow_up',
                    'source_label' => 'Quote follow-up',
                    'due_at' => $dueAt?->toIso8601String(),
                    'due_date' => $dueAt?->toDateString(),
                    'is_overdue' => $dueAt?->lt($referenceTime) ?? false,
                    'is_due_today' => $dueAt?->isSameDay($referenceTime) ?? false,
                    'is_all_day' => false,
                    'subject_type' => 'quote',
                    'subject_id' => (int) $quote->id,
                    'subject_title' => $this->quoteTitle($quote),
                    'status' => $quote->status,
                    'customer' => $this->customerPayload($quote->customer),
                    'assignee' => null,
                    'activity' => null,
                ];
            })
            ->values();
    }

    /**
     * @param  array{account_id: int, is_owner: bool, membership: TeamMember|null, can_view_requests: bool, can_view_quotes: bool, can_view_tasks: bool, can_view_customers: bool}  $context
     * @return Collection<int, array<string, mixed>>
     */
    private function buildTaskItems(array $context, Carbon $referenceTime): Collection
    {
        if (! $context['can_view_tasks']) {
            return collect();
        }

        $query = Task::query()
            ->forAccount($context['account_id'])
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'assignee.user:id,name',
            ])
            ->orderBy('due_date')
            ->orderByDesc('created_at');

        $membership = $context['membership'];
        if ($membership && $membership->role !== 'admin') {
            $query->where('assigned_team_member_id', $membership->id);
        }

        return $query
            ->get([
                'id',
                'customer_id',
                'assigned_team_member_id',
                'request_id',
                'title',
                'description',
                'status',
                'due_date',
            ])
            ->map(function (Task $task) use ($referenceTime): array {
                $dueAt = $task->due_date?->copy()->startOfDay();

                return [
                    'id' => 'task-'.$task->id,
                    'source' => 'task',
                    'source_label' => 'Task',
                    'due_at' => $dueAt?->toIso8601String(),
                    'due_date' => $dueAt?->toDateString(),
                    'is_overdue' => $dueAt?->lt($referenceTime->copy()->startOfDay()) ?? false,
                    'is_due_today' => $dueAt?->isSameDay($referenceTime) ?? false,
                    'is_all_day' => true,
                    'subject_type' => 'task',
                    'subject_id' => (int) $task->id,
                    'subject_title' => $task->title ?: 'Task #'.$task->id,
                    'status' => $task->status,
                    'customer' => $this->customerPayload($task->customer),
                    'assignee' => $this->teamMemberPayload($task->assignee),
                    'activity' => null,
                ];
            })
            ->values();
    }

    /**
     * @param  array{account_id: int, is_owner: bool, membership: TeamMember|null, can_view_requests: bool, can_view_quotes: bool, can_view_tasks: bool, can_view_customers: bool}  $context
     * @return Collection<int, array<string, mixed>>
     */
    private function buildSalesActivityItems(array $context, Carbon $referenceTime): Collection
    {
        $activities = new EloquentCollection();

        if ($context['can_view_requests']) {
            $activities = $activities->merge(
                ActivityLog::query()
                    ->salesActivity()
                    ->where('subject_type', (new LeadRequest())->getMorphClass())
                    ->whereIn('subject_id', LeadRequest::query()
                        ->where('user_id', $context['account_id'])
                        ->select('id'))
                    ->with('user:id,name')
                    ->get()
            );
        }

        if ($context['can_view_quotes']) {
            $activities = $activities->merge(
                ActivityLog::query()
                    ->salesActivity()
                    ->where('subject_type', (new Quote())->getMorphClass())
                    ->whereIn('subject_id', Quote::query()
                        ->byUser($context['account_id'])
                        ->select('id'))
                    ->with('user:id,name')
                    ->get()
            );
        }

        if ($context['can_view_customers']) {
            $activities = $activities->merge(
                ActivityLog::query()
                    ->salesActivity()
                    ->where('subject_type', (new Customer())->getMorphClass())
                    ->whereIn('subject_id', Customer::query()
                        ->where('user_id', $context['account_id'])
                        ->select('id'))
                    ->with('user:id,name')
                    ->get()
            );
        }

        if ($activities->isEmpty()) {
            return collect();
        }

        $activities->loadMorph('subject', [
            LeadRequest::class => [
                'customer:id,company_name,first_name,last_name,email,phone',
                'assignee.user:id,name',
            ],
            Quote::class => [
                'customer:id,company_name,first_name,last_name,email,phone',
            ],
            Customer::class => [],
        ]);

        return $activities
            ->filter(fn (ActivityLog $activity): bool => $this->isRelevantNextActionActivity($activity))
            ->groupBy(fn (ActivityLog $activity): string => $activity->subject_type.'|'.$activity->subject_id)
            ->map(function (Collection $group) use ($referenceTime): ?array {
                /** @var ActivityLog|null $latest */
                $latest = $group
                    ->sortByDesc(fn (ActivityLog $activity): string => $this->activityRecencyKey($activity))
                    ->first();

                if (! $latest) {
                    return null;
                }

                $salesActivity = $latest->sales_activity;
                $dueAt = data_get($salesActivity, 'due_at');

                if (! is_array($salesActivity) || blank($dueAt) || ! data_get($salesActivity, 'opens_next_action')) {
                    return null;
                }

                if (! $latest->subject || ! empty($latest->properties['task_id'])) {
                    return null;
                }

                return $this->formatSalesActivityItem($latest, $salesActivity, $referenceTime);
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilters(Collection $items, array $filters): Collection
    {
        $filtered = $items;

        if (! empty($filters['search']) && is_string($filters['search'])) {
            $search = $this->normalizeSearchValue($filters['search']);

            if ($search !== '') {
                $filtered = $filtered->filter(
                    fn (array $item): bool => $this->itemMatchesSearch($item, $search)
                );
            }
        }

        if (! empty($filters['source'])) {
            $allowed = collect(is_array($filters['source']) ? $filters['source'] : [$filters['source']])
                ->filter(fn ($value) => is_string($value) && $value !== '')
                ->values()
                ->all();

            $filtered = $filtered->filter(
                fn (array $item): bool => in_array($item['source'], $allowed, true)
            );
        }

        if (($filters['only_overdue'] ?? false) === true) {
            $filtered = $filtered->filter(fn (array $item): bool => (bool) ($item['is_overdue'] ?? false));
        }

        if (! empty($filters['due_state']) && is_string($filters['due_state'])) {
            $filtered = match ($filters['due_state']) {
                'overdue' => $filtered->filter(fn (array $item): bool => (bool) ($item['is_overdue'] ?? false)),
                'today' => $filtered->filter(fn (array $item): bool => (bool) ($item['is_due_today'] ?? false)),
                'upcoming' => $filtered->filter(
                    fn (array $item): bool => ! ($item['is_overdue'] ?? false) && ! ($item['is_due_today'] ?? false)
                ),
                default => $filtered,
            };
        }

        if (! empty($filters['subject_type']) && is_string($filters['subject_type'])) {
            $filtered = $filtered->filter(
                fn (array $item): bool => (string) ($item['subject_type'] ?? '') === $filters['subject_type']
            );
        }

        if (! empty($filters['limit'])) {
            $filtered = $filtered->take(max(1, (int) $filters['limit']));
        }

        return $filtered->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildStats(Collection $items): array
    {
        return [
            'total' => $items->count(),
            'overdue' => $items->where('is_overdue', true)->count(),
            'due_today' => $items->where('is_due_today', true)->count(),
            'upcoming' => $items->filter(
                fn (array $item): bool => ! ($item['is_overdue'] ?? false) && ! ($item['is_due_today'] ?? false)
            )->count(),
            'by_source' => $items->countBy('source')->all(),
        ];
    }

    private function normalizeSearchValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function itemMatchesSearch(array $item, string $search): bool
    {
        $haystacks = [
            $item['subject_title'] ?? null,
            $item['source_label'] ?? null,
            data_get($item, 'customer.name'),
            data_get($item, 'assignee.name'),
            data_get($item, 'activity.label'),
            data_get($item, 'activity.description'),
            data_get($item, 'activity.actor'),
            data_get($item, 'activity.activity_key'),
        ];

        foreach ($haystacks as $haystack) {
            if (! is_string($haystack) || $haystack === '') {
                continue;
            }

            if (str_contains(mb_strtolower($haystack), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $salesActivity
     * @return array<string, mixed>
     */
    private function formatSalesActivityItem(ActivityLog $activity, array $salesActivity, Carbon $referenceTime): array
    {
        $dueAt = Carbon::parse((string) $salesActivity['due_at']);

        return [
            'id' => 'activity-'.$activity->id,
            'source' => 'sales_activity',
            'source_label' => (string) ($salesActivity['label'] ?? 'Sales activity'),
            'due_at' => $dueAt->toIso8601String(),
            'due_date' => $dueAt->toDateString(),
            'is_overdue' => $dueAt->lt($referenceTime),
            'is_due_today' => $dueAt->isSameDay($referenceTime),
            'is_all_day' => false,
            'subject_type' => $this->subjectAlias($activity->subject),
            'subject_id' => (int) $activity->subject_id,
            'subject_title' => $this->subjectTitle($activity->subject),
            'status' => $this->subjectStatus($activity->subject),
            'customer' => $this->subjectCustomerPayload($activity->subject),
            'assignee' => $this->subjectAssigneePayload($activity->subject),
            'activity' => [
                'id' => (int) $activity->id,
                'action' => $activity->action,
                'activity_key' => $salesActivity['activity_key'] ?? null,
                'type' => $salesActivity['type'] ?? null,
                'label' => $salesActivity['label'] ?? null,
                'outcome' => $salesActivity['outcome'] ?? null,
                'logged_at' => $activity->created_at?->toIso8601String(),
                'description' => $activity->description,
                'actor' => $activity->user?->name,
            ],
        ];
    }

    private function isRelevantNextActionActivity(ActivityLog $activity): bool
    {
        $salesActivity = $activity->sales_activity;

        if (! is_array($salesActivity)) {
            return false;
        }

        return ! blank($salesActivity['due_at'] ?? null)
            || (bool) ($salesActivity['opens_next_action'] ?? false)
            || (bool) ($salesActivity['closes_next_action'] ?? false);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $salesActivityItems
     * @return array<int, string>
     */
    private function salesActivityDedupeKeys(Collection $salesActivityItems): array
    {
        return $salesActivityItems
            ->filter(fn (array $item): bool => in_array($item['subject_type'] ?? null, ['request', 'quote'], true))
            ->map(function (array $item): ?string {
                $subjectType = $item['subject_type'] ?? null;
                $subjectId = $item['subject_id'] ?? null;
                $dueAt = $item['due_at'] ?? null;

                if (! is_string($subjectType) || ! is_numeric($subjectId)) {
                    return null;
                }

                $morphClass = match ($subjectType) {
                    'request' => (new LeadRequest())->getMorphClass(),
                    'quote' => (new Quote())->getMorphClass(),
                    default => null,
                };

                if ($morphClass === null) {
                    return null;
                }

                return $this->subjectDueKey($morphClass, (int) $subjectId, $dueAt);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function compareItems(array $left, array $right): int
    {
        $leftDueAt = Carbon::parse((string) $left['due_at']);
        $rightDueAt = Carbon::parse((string) $right['due_at']);

        $dueComparison = $leftDueAt->getTimestamp() <=> $rightDueAt->getTimestamp();
        if ($dueComparison !== 0) {
            return $dueComparison;
        }

        $sourceComparison = ($this->sourceRank($left['source'] ?? null))
            <=> ($this->sourceRank($right['source'] ?? null));
        if ($sourceComparison !== 0) {
            return $sourceComparison;
        }

        $titleComparison = strcasecmp(
            (string) ($left['subject_title'] ?? ''),
            (string) ($right['subject_title'] ?? ''),
        );
        if ($titleComparison !== 0) {
            return $titleComparison;
        }

        return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
    }

    private function sourceRank(mixed $source): int
    {
        return self::SOURCE_RANKS[(string) $source] ?? 99;
    }

    private function resolveReferenceTime(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return now();
    }

    private function membershipHasAnyPermission(?TeamMember $membership, array $permissions): bool
    {
        if (! $membership) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($membership->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function activityRecencyKey(ActivityLog $activity): string
    {
        return ($activity->created_at?->copy()->utc()->format('YmdHis.u') ?? '00000000000000.000000')
            .'-'
            .str_pad((string) $activity->id, 10, '0', STR_PAD_LEFT);
    }

    private function subjectDueKey(string $subjectMorphClass, int $subjectId, mixed $dueAt): ?string
    {
        if ($dueAt === null || $dueAt === '') {
            return null;
        }

        $normalizedDueAt = $dueAt instanceof Carbon
            ? $dueAt->copy()
            : Carbon::parse((string) $dueAt);

        return $subjectMorphClass
            .'|'
            .$subjectId
            .'|'
            .$normalizedDueAt->utc()->toIso8601String();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerPayload(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => (int) $customer->id,
            'name' => $this->customerName($customer),
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function teamMemberPayload(?TeamMember $member): ?array
    {
        if (! $member) {
            return null;
        }

        return [
            'id' => (int) $member->id,
            'name' => $member->user?->name ?? 'Team member',
            'role' => $member->role,
        ];
    }

    private function customerName(Customer $customer): string
    {
        return $customer->company_name
            ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
            ?: 'Customer #'.$customer->id;
    }

    private function quoteTitle(Quote $quote): string
    {
        if ($quote->number && $quote->job_title) {
            return $quote->number.' - '.$quote->job_title;
        }

        return $quote->number ?: ($quote->job_title ?: 'Quote #'.$quote->id);
    }

    private function subjectAlias(mixed $subject): string
    {
        return match (true) {
            $subject instanceof LeadRequest => 'request',
            $subject instanceof Quote => 'quote',
            $subject instanceof Customer => 'customer',
            default => 'activity',
        };
    }

    private function subjectTitle(mixed $subject): string
    {
        return match (true) {
            $subject instanceof LeadRequest => $subject->title ?: ($subject->contact_name ?: 'Request #'.$subject->id),
            $subject instanceof Quote => $this->quoteTitle($subject),
            $subject instanceof Customer => $this->customerName($subject),
            default => 'Sales activity',
        };
    }

    private function subjectStatus(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof LeadRequest => $subject->status,
            $subject instanceof Quote => $subject->status,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subjectCustomerPayload(mixed $subject): ?array
    {
        return match (true) {
            $subject instanceof LeadRequest => $this->customerPayload($subject->customer),
            $subject instanceof Quote => $this->customerPayload($subject->customer),
            $subject instanceof Customer => $this->customerPayload($subject),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function subjectAssigneePayload(mixed $subject): ?array
    {
        return match (true) {
            $subject instanceof LeadRequest => $this->teamMemberPayload($subject->assignee),
            default => null,
        };
    }
}
