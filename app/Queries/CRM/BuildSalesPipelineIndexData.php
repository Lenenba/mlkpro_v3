<?php

namespace App\Queries\CRM;

use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Support\CRM\OpportunitySchema;
use App\Support\DataTablePagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildSalesPipelineIndexData
{
    /**
     * @var array<int, array{key: string, label: string, state: string, rank: int}>
     */
    public const STAGES = [
        ['key' => 'intake', 'label' => 'Intake', 'state' => 'open', 'rank' => 10],
        ['key' => 'contacted', 'label' => 'Contacted', 'state' => 'open', 'rank' => 20],
        ['key' => 'qualified', 'label' => 'Qualified', 'state' => 'open', 'rank' => 40],
        ['key' => 'quoted', 'label' => 'Quoted', 'state' => 'open', 'rank' => 60],
        ['key' => 'won', 'label' => 'Won', 'state' => 'won', 'rank' => 80],
        ['key' => 'lost', 'label' => 'Lost', 'state' => 'lost', 'rank' => 90],
    ];

    private const NEXT_ACTION_FILTERS = [
        'overdue',
        'scheduled',
        'none',
    ];

    private const SORT_OPTIONS = [
        'stage_rank',
        'next_action_at',
        'opened_at',
        'amount_total',
        'weighted_amount',
        'title',
    ];

    public function __construct(
        private readonly BuildSalesPipelineAnalyticsData $analyticsData,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(int $accountId, Request $request): array
    {
        $referenceTime = $this->resolveReferenceTime($request->query('reference_time'));
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'stage' => $this->normalizeStage($request->query('stage')),
            'state' => $this->normalizeState($request->query('state')),
            'customer_id' => $this->normalizeCustomerId($request->query('customer_id')),
            'next_action' => $this->normalizeNextActionFilter($request->query('next_action')),
            'amount_min' => $this->normalizeAmount($request->query('amount_min')),
            'amount_max' => $this->normalizeAmount($request->query('amount_max')),
            'sort' => $this->normalizeSort($request->query('sort')),
            'direction' => $this->normalizeDirection($request->query('direction')),
            'view' => in_array($request->query('view'), ['table', 'board'], true)
                ? (string) $request->query('view')
                : 'table',
            'per_page' => DataTablePagination::fromRequest($request),
        ];

        $items = $this->resolveCollection($accountId, $filters, $referenceTime);

        return [
            'opportunities' => $this->paginateOpportunities($items, $request, $filters),
            'filters' => $filters,
            'stats' => $this->analyticsData->execute($items),
            'board' => $this->buildBoard($items),
            'reference_time' => $referenceTime->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveCollection(int $accountId, array $filters = [], ?Carbon $referenceTime = null): Collection
    {
        $referenceTime = $referenceTime?->copy() ?? now();
        $normalizedFilters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'stage' => $this->normalizeStage($filters['stage'] ?? null),
            'state' => $this->normalizeState($filters['state'] ?? null),
            'customer_id' => $this->normalizeCustomerId($filters['customer_id'] ?? null),
            'next_action' => $this->normalizeNextActionFilter($filters['next_action'] ?? null),
            'amount_min' => $this->normalizeAmount($filters['amount_min'] ?? null),
            'amount_max' => $this->normalizeAmount($filters['amount_max'] ?? null),
            'sort' => $this->normalizeSort($filters['sort'] ?? null),
            'direction' => $this->normalizeDirection($filters['direction'] ?? null),
        ];

        $requestItems = $this->requestQuery($accountId, $normalizedFilters)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'assignee:id,user_id,account_id',
                'assignee.user:id,name',
                'quote' => function ($query) {
                    $query->select([
                        'id',
                        'request_id',
                        'customer_id',
                        'work_id',
                        'status',
                        'number',
                        'job_title',
                        'subtotal',
                        'total',
                        'currency_code',
                        'created_at',
                        'accepted_at',
                        'next_follow_up_at',
                    ])->orderByDesc('created_at')
                        ->with([
                            'work:id,quote_id,status',
                            'work.invoice:id,work_id,status,total,created_at',
                        ]);
                },
            ])
            ->get([
                'id',
                'user_id',
                'customer_id',
                'assigned_team_member_id',
                'status',
                'title',
                'service_type',
                'created_at',
                'converted_at',
                'next_follow_up_at',
            ])
            ->map(fn (LeadRequest $lead): array => $this->buildRequestItem($lead, $referenceTime));

        $quoteItems = $this->quoteOnlyQuery($accountId, $normalizedFilters)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'work:id,quote_id,status',
                'work.invoice:id,work_id,status,total,created_at',
            ])
            ->get([
                'id',
                'user_id',
                'customer_id',
                'request_id',
                'work_id',
                'parent_id',
                'status',
                'number',
                'job_title',
                'subtotal',
                'total',
                'currency_code',
                'created_at',
                'accepted_at',
                'next_follow_up_at',
            ])
            ->map(fn (Quote $quote): array => $this->buildQuoteOnlyItem($quote, $referenceTime));

        return $this->sortItems(
            $this->filterItems($requestItems->merge($quoteItems)->values(), $normalizedFilters),
            $normalizedFilters
        )->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function requestQuery(int $accountId, array $filters): Builder
    {
        return LeadRequest::query()
            ->where('user_id', $accountId)
            ->when(
                $filters['customer_id'] ?? null,
                fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function quoteOnlyQuery(int $accountId, array $filters): Builder
    {
        return Quote::query()
            ->byUser($accountId)
            ->where(function (Builder $query): void {
                $query->whereNull('request_id')
                    ->orWhereDoesntHave('request');
            })
            ->when(
                $filters['customer_id'] ?? null,
                fn (Builder $query, int $customerId) => $query->where('customer_id', $customerId)
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRequestItem(LeadRequest $lead, Carbon $referenceTime): array
    {
        $quote = $lead->quote;
        $work = $quote?->work;
        $invoice = $work?->invoice;

        return $this->buildOpportunityItem(
            OpportunitySchema::present($lead, $quote, $work, $invoice),
            $referenceTime,
            $lead->customer,
            $lead->assignee,
            [
                'id' => $lead->id,
                'status' => $lead->status,
                'title' => $lead->title,
                'service_type' => $lead->service_type,
            ],
            $quote ? $this->quotePayload($quote) : null,
            $work ? $this->workPayload($work) : null,
            $invoice ? $this->invoicePayload($invoice) : null,
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuoteOnlyItem(Quote $quote, Carbon $referenceTime): array
    {
        $work = $quote->work;
        $invoice = $work?->invoice;

        return $this->buildOpportunityItem(
            OpportunitySchema::present(null, $quote, $work, $invoice),
            $referenceTime,
            $quote->customer,
            null,
            null,
            $this->quotePayload($quote),
            $work ? $this->workPayload($work) : null,
            $invoice ? $this->invoicePayload($invoice) : null,
            true
        );
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $quote
     * @param  array<string, mixed>|null  $job
     * @param  array<string, mixed>|null  $invoice
     * @return array<string, mixed>
     */
    private function buildOpportunityItem(
        array $opportunity,
        Carbon $referenceTime,
        mixed $customer,
        mixed $assignee,
        ?array $request,
        ?array $quote,
        ?array $job,
        ?array $invoice,
        bool $isQuoteOnly
    ): array {
        $openedAt = $this->dateValue(data_get($opportunity, 'timestamps.opened_at'));
        $nextActionAt = $this->dateValue(data_get($opportunity, 'next_action.at'));
        $amountTotal = $this->floatOrNull(data_get($opportunity, 'amount.total'));
        $weightedAmount = $this->floatOrNull(data_get($opportunity, 'forecast.weighted_amount'));
        $ageDays = $openedAt ? $referenceTime->diffInDays($openedAt) : null;
        $hasOverdueNextAction = $nextActionAt ? $nextActionAt->lte($referenceTime) : false;
        $crmLinks = data_get($opportunity, 'crm_links', []);
        $primarySubjectType = data_get($crmLinks, 'subject.type');
        $primarySubjectId = $this->nullableInt(data_get($crmLinks, 'subject.id'));

        data_set($opportunity, 'next_action.is_overdue', $hasOverdueNextAction);

        return [
            'id' => (string) ($opportunity['key'] ?? ''),
            'key' => (string) ($opportunity['key'] ?? ''),
            'title' => $opportunity['title'] ?? null,
            'stage_key' => data_get($opportunity, 'stage.key'),
            'stage_label' => data_get($opportunity, 'stage.label'),
            'stage_state' => data_get($opportunity, 'stage.state'),
            'stage_rank' => (int) data_get($opportunity, 'stage.rank', 999),
            'forecast_category' => data_get($opportunity, 'forecast.category'),
            'probability_percent' => (int) data_get($opportunity, 'forecast.probability_percent', 0),
            'amount_total' => $amountTotal,
            'weighted_amount' => $weightedAmount,
            'next_action_at' => $nextActionAt?->toIso8601String(),
            'opened_at' => $openedAt?->toIso8601String(),
            'age_days' => $ageDays,
            'customer' => $this->customerPayload($customer),
            'assignee' => $this->assigneePayload($assignee),
            'request' => $request,
            'quote' => $quote,
            'job' => $job,
            'invoice' => $invoice,
            'crm_links' => $crmLinks,
            'primary_subject_type' => $primarySubjectType,
            'primary_subject_id' => $primarySubjectId,
            'signals' => [
                'has_quote' => (bool) data_get($opportunity, 'anchors.quote_id'),
                'is_quote_only' => $isQuoteOnly,
                'has_overdue_next_action' => $hasOverdueNextAction,
                'age_days' => $ageDays,
            ],
            'opportunity' => $opportunity,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function filterItems(Collection $items, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $items
            ->filter(function (array $item) use ($filters, $search): bool {
                if (($filters['stage'] ?? null) && $item['stage_key'] !== $filters['stage']) {
                    return false;
                }

                if (($filters['state'] ?? null) && $item['stage_state'] !== $filters['state']) {
                    return false;
                }

                if (($filters['customer_id'] ?? null) && (int) data_get($item, 'customer.id', 0) !== (int) $filters['customer_id']) {
                    return false;
                }

                if (($filters['next_action'] ?? null) && ! $this->matchesNextActionFilter($item, (string) $filters['next_action'])) {
                    return false;
                }

                if (($filters['amount_min'] ?? null) !== null && (float) ($item['amount_total'] ?? 0) < (float) $filters['amount_min']) {
                    return false;
                }

                if (($filters['amount_max'] ?? null) !== null && (float) ($item['amount_total'] ?? 0) > (float) $filters['amount_max']) {
                    return false;
                }

                if ($search !== '' && ! $this->matchesSearch($item, $search)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortItems(Collection $items, array $filters): Collection
    {
        $sort = (string) ($filters['sort'] ?? 'stage_rank');
        $direction = (string) ($filters['direction'] ?? 'asc');

        return $items
            ->sort(function (array $left, array $right) use ($sort, $direction): int {
                $comparison = $this->compareSortValues(
                    $sort,
                    $this->sortableValue($left, $sort),
                    $this->sortableValue($right, $sort)
                );

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }

                $overdueComparison = $this->compareBooleans(
                    (bool) data_get($right, 'signals.has_overdue_next_action', false),
                    (bool) data_get($left, 'signals.has_overdue_next_action', false),
                );
                if ($overdueComparison !== 0) {
                    return $overdueComparison;
                }

                $nextActionComparison = $this->compareNullableDates(
                    $left['next_action_at'] ?? null,
                    $right['next_action_at'] ?? null,
                );
                if ($nextActionComparison !== 0) {
                    return $nextActionComparison;
                }

                $amountComparison = $this->compareNumericValues(
                    $right['amount_total'] ?? null,
                    $left['amount_total'] ?? null,
                );
                if ($amountComparison !== 0) {
                    return $amountComparison;
                }

                $openedComparison = $this->compareNullableDates(
                    $left['opened_at'] ?? null,
                    $right['opened_at'] ?? null,
                );
                if ($openedComparison !== 0) {
                    return $openedComparison;
                }

                return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function paginateOpportunities(Collection $items, Request $request, array $filters): LengthAwarePaginator
    {
        if (($filters['view'] ?? 'table') === 'board') {
            $perPage = max($items->count(), 1);

            return new LengthAwarePaginator(
                $items->values(),
                $items->count(),
                $perPage,
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        }

        $perPage = max(1, (int) ($filters['per_page'] ?? DataTablePagination::defaultPerPage()));
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildBoard(Collection $items): array
    {
        return collect(self::STAGES)
            ->map(function (array $stage) use ($items): array {
                $stageItems = $items
                    ->filter(fn (array $item): bool => $item['stage_key'] === $stage['key'])
                    ->values();

                return [
                    'key' => $stage['key'],
                    'label' => $stage['label'],
                    'state' => $stage['state'],
                    'rank' => $stage['rank'],
                    'count' => $stageItems->count(),
                    'amount_total' => round((float) $stageItems->sum(fn (array $item): float => (float) ($item['amount_total'] ?? 0)), 2),
                    'weighted_amount' => round((float) $stageItems->sum(fn (array $item): float => (float) ($item['weighted_amount'] ?? 0)), 2),
                    'overdue_next_actions' => $stageItems->filter(fn (array $item): bool => (bool) data_get($item, 'signals.has_overdue_next_action', false))->count(),
                    'items' => $stageItems->all(),
                ];
            })
            ->all();
    }

    private function matchesSearch(array $item, string $search): bool
    {
        $needle = mb_strtolower($search);

        $haystacks = array_filter([
            $item['title'] ?? null,
            data_get($item, 'customer.name'),
            data_get($item, 'customer.email'),
            data_get($item, 'request.title'),
            data_get($item, 'request.service_type'),
            data_get($item, 'quote.job_title'),
            data_get($item, 'quote.number'),
            data_get($item, 'key'),
        ], fn (mixed $value): bool => filled($value));

        foreach ($haystacks as $value) {
            if (str_contains(mb_strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function matchesNextActionFilter(array $item, string $filter): bool
    {
        $hasNextAction = filled($item['next_action_at'] ?? null);
        $isOverdue = (bool) data_get($item, 'signals.has_overdue_next_action', false);

        return match ($filter) {
            'overdue' => $isOverdue,
            'scheduled' => $hasNextAction && ! $isOverdue,
            'none' => ! $hasNextAction,
            default => true,
        };
    }

    private function normalizeStage(mixed $stage): ?string
    {
        $normalized = is_string($stage) ? trim($stage) : null;
        $allowed = collect(self::STAGES)->pluck('key')->all();

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function normalizeState(mixed $state): ?string
    {
        $normalized = is_string($state) ? trim($state) : null;

        return in_array($normalized, ['open', 'won', 'lost'], true) ? $normalized : null;
    }

    private function normalizeNextActionFilter(mixed $filter): ?string
    {
        $normalized = is_string($filter) ? trim($filter) : null;

        return in_array($normalized, self::NEXT_ACTION_FILTERS, true) ? $normalized : null;
    }

    private function normalizeSort(mixed $sort): string
    {
        return in_array($sort, self::SORT_OPTIONS, true) ? (string) $sort : 'stage_rank';
    }

    private function normalizeDirection(mixed $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }

    private function normalizeCustomerId(mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $value = (int) $customerId;

        return $value > 0 ? $value : null;
    }

    private function normalizeAmount(mixed $amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return is_numeric($amount) ? (float) $amount : null;
    }

    private function resolveReferenceTime(mixed $value): Carbon
    {
        if (blank($value)) {
            return now();
        }

        return Carbon::parse((string) $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerPayload(mixed $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        $name = $customer->company_name
            ?: trim(collect([$customer->first_name, $customer->last_name])->filter()->implode(' '));

        return [
            'id' => $customer->id,
            'name' => $name !== '' ? $name : 'Customer #'.$customer->id,
            'email' => $customer->email,
            'phone' => $customer->phone,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assigneePayload(mixed $assignee): ?array
    {
        if (! $assignee) {
            return null;
        }

        return [
            'id' => $assignee->id,
            'name' => $assignee->user?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'status' => $quote->status,
            'number' => $quote->number,
            'job_title' => $quote->job_title,
            'total' => $this->floatOrNull($quote->total),
            'currency_code' => $quote->currency_code,
            'next_follow_up_at' => optional($quote->next_follow_up_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workPayload(mixed $work): array
    {
        return [
            'id' => $work->id,
            'status' => $work->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(mixed $invoice): array
    {
        return [
            'id' => $invoice->id,
            'status' => $invoice->status,
            'total' => $this->floatOrNull($invoice->total),
        ];
    }

    private function sortableValue(array $item, string $sort): mixed
    {
        return match ($sort) {
            'amount_total' => $item['amount_total'] ?? null,
            'next_action_at' => $item['next_action_at'] ?? null,
            'opened_at' => $item['opened_at'] ?? null,
            'stage_rank' => $item['stage_rank'] ?? null,
            'title' => mb_strtolower((string) ($item['title'] ?? '')),
            'weighted_amount' => $item['weighted_amount'] ?? null,
            default => $item['stage_rank'] ?? null,
        };
    }

    private function compareSortValues(string $sort, mixed $left, mixed $right): int
    {
        return match ($sort) {
            'amount_total', 'weighted_amount', 'stage_rank' => $this->compareNumericValues($left, $right),
            'next_action_at', 'opened_at' => $this->compareNullableDates($left, $right),
            'title' => strcmp((string) $left, (string) $right),
            default => $this->compareNumericValues($left, $right),
        };
    }

    private function compareNumericValues(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        return (float) $left <=> (float) $right;
    }

    private function compareBooleans(bool $left, bool $right): int
    {
        return ($left ? 1 : 0) <=> ($right ? 1 : 0);
    }

    private function compareNullableDates(mixed $left, mixed $right): int
    {
        $leftTimestamp = $this->timestamp($left);
        $rightTimestamp = $this->timestamp($right);

        if ($leftTimestamp === null && $rightTimestamp === null) {
            return 0;
        }

        if ($leftTimestamp === null) {
            return 1;
        }

        if ($rightTimestamp === null) {
            return -1;
        }

        return $leftTimestamp <=> $rightTimestamp;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->getTimestamp();
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
