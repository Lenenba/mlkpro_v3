<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequests\StoreServiceRequestRequest;
use App\Models\ActivityLog;
use App\Models\ServiceRequest;
use App\Services\Prospects\ProspectDuplicateAlertService;
use App\Services\ServiceRequests\ServiceRequestIntakeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ServiceRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = (int) ($user->accountOwnerId() ?? Auth::id());
        $this->ensureServiceRequestReadAccess($user, $accountId);

        $filters = $this->normalizeIndexFilters($request);

        $indexQuery = ServiceRequest::query()
            ->byUser($accountId)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'prospect:id,customer_id,status,title,contact_name,contact_email,contact_phone',
            ]);
        $this->applyIndexFilters($indexQuery, $filters);
        $this->applyIndexSort($indexQuery, $filters);

        $serviceRequests = $indexQuery
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(fn (ServiceRequest $serviceRequest) => $this->serializeServiceRequest($serviceRequest));

        $summaryQuery = ServiceRequest::query()
            ->byUser($accountId);
        $this->applyIndexFilters($summaryQuery, $filters);

        $summaryRows = $summaryQuery->get([
            'id',
            'status',
            'source',
            'customer_id',
            'prospect_id',
        ]);

        return $this->inertiaOrJson('ServiceRequests/Index', [
            'serviceRequests' => $serviceRequests,
            'filters' => $filters,
            'stats' => $this->buildIndexStats($summaryRows),
            'sourceBreakdown' => $this->buildSourceBreakdown($summaryRows),
            'relationBreakdown' => $this->buildRelationBreakdown($summaryRows),
            'statusOptions' => ServiceRequest::STATUSES,
            'sourceOptions' => [
                'manual_admin',
                'customer_portal',
                'public_form',
                'campaign',
                'api',
                'import',
            ],
            'perPageOptions' => $this->dataTablePerPageOptions(),
        ]);
    }

    public function show(Request $request, ServiceRequest $serviceRequest)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = (int) ($user->accountOwnerId() ?? Auth::id());
        $this->ensureServiceRequestReadAccess($user, $accountId);

        if ((int) $serviceRequest->user_id !== $accountId) {
            abort(404);
        }

        $serviceRequest->load([
            'customer:id,company_name,first_name,last_name,email,phone',
            'prospect:id,customer_id,status,title,service_type,contact_name,contact_email,contact_phone,created_at,last_activity_at,next_follow_up_at',
            'prospect.customer:id,company_name,first_name,last_name,email,phone',
        ]);

        $activity = ActivityLog::query()
            ->where('subject_type', $serviceRequest->getMorphClass())
            ->where('subject_id', $serviceRequest->id)
            ->with('user:id,name')
            ->latest()
            ->take(25)
            ->get(['id', 'user_id', 'action', 'description', 'properties', 'created_at']);

        return $this->inertiaOrJson('ServiceRequests/Show', [
            'serviceRequest' => $this->serializeServiceRequest($serviceRequest),
            'activity' => $activity,
        ]);
    }

    public function store(
        StoreServiceRequestRequest $request,
        ServiceRequestIntakeService $serviceRequestIntakeService
    ) {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = (int) ($user->accountOwnerId() ?? Auth::id());
        $this->ensureServiceRequestWriteAccess($user, $accountId);

        $validated = $request->validated();
        $ignoreDuplicates = (bool) ($validated['ignore_duplicates'] ?? false);

        if (
            ($validated['relation_mode'] ?? null) === StoreServiceRequestRequest::RELATION_MODE_NEW_PROSPECT
            && $this->shouldReturnJson($request)
            && ! $ignoreDuplicates
        ) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forAttributes(
                accountId: $accountId,
                attributes: $serviceRequestIntakeService->prospectAttributesForDuplicateCheck($validated),
                context: 'service_request_create',
            );

            if ($duplicateAlert) {
                return response()->json([
                    'message' => 'A similar prospect may already exist. Review the warning before continuing.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        $result = $serviceRequestIntakeService->createManual($user, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Service request created successfully.',
                'service_request' => $result['service_request'],
                'customer' => $result['customer'],
                'prospect' => $result['prospect'],
            ], 201);
        }

        return redirect()->back()->with('success', 'Service request created successfully.');
    }

    private function normalizeIndexFilters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $source = trim((string) $request->query('source', ''));
        $relation = (string) $request->query('relation', '');
        $sort = (string) $request->query('sort', 'submitted_at');
        $direction = (string) $request->query('direction', 'desc');

        return [
            'search' => trim((string) $request->query('search', '')),
            'status' => in_array($status, ServiceRequest::STATUSES, true) ? $status : '',
            'source' => in_array($source, ['manual_admin', 'customer_portal', 'public_form', 'campaign', 'api', 'import'], true)
                ? $source
                : '',
            'relation' => in_array($relation, ['customer', 'prospect', 'unlinked'], true) ? $relation : '',
            'sort' => in_array($sort, ['submitted_at', 'created_at', 'status', 'source', 'title'], true) ? $sort : 'submitted_at',
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
            'per_page' => $this->resolveDataTablePerPage($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyIndexFilters(Builder $query, array $filters): void
    {
        $search = (string) ($filters['search'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $source = (string) ($filters['source'] ?? '');
        $relation = (string) ($filters['relation'] ?? '');

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('title', 'like', '%'.$search.'%')
                    ->orWhere('service_type', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('requester_name', 'like', '%'.$search.'%')
                    ->orWhere('requester_email', 'like', '%'.$search.'%')
                    ->orWhere('requester_phone', 'like', '%'.$search.'%')
                    ->orWhere('city', 'like', '%'.$search.'%')
                    ->orWhere('state', 'like', '%'.$search.'%')
                    ->orWhere('postal_code', 'like', '%'.$search.'%');
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($relation === 'customer') {
            $query->whereNotNull('customer_id');
        } elseif ($relation === 'prospect') {
            $query->whereNotNull('prospect_id');
        } elseif ($relation === 'unlinked') {
            $query->whereNull('customer_id')->whereNull('prospect_id');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyIndexSort(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? 'submitted_at');
        $direction = (string) ($filters['direction'] ?? 'desc');

        if ($sort === 'title') {
            $query->orderBy('title', $direction)->orderByDesc('created_at');

            return;
        }

        $query->orderBy($sort, $direction)->orderByDesc('created_at');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ServiceRequest>  $rows
     * @return array<string, int>
     */
    private function buildIndexStats(Collection $rows): array
    {
        $activeStatuses = [
            ServiceRequest::STATUS_NEW,
            ServiceRequest::STATUS_IN_PROGRESS,
            ServiceRequest::STATUS_PENDING,
        ];
        $resolvedStatuses = [
            ServiceRequest::STATUS_ACCEPTED,
            ServiceRequest::STATUS_COMPLETED,
        ];
        $closedStatuses = [
            ServiceRequest::STATUS_REFUSED,
            ServiceRequest::STATUS_CANCELLED,
        ];

        return [
            'total' => $rows->count(),
            'new' => $rows->where('status', ServiceRequest::STATUS_NEW)->count(),
            'active' => $rows->whereIn('status', $activeStatuses)->count(),
            'resolved' => $rows->whereIn('status', $resolvedStatuses)->count(),
            'closed' => $rows->whereIn('status', $closedStatuses)->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ServiceRequest>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildSourceBreakdown(Collection $rows): array
    {
        return $rows
            ->groupBy(fn (ServiceRequest $serviceRequest) => $serviceRequest->source ?: 'manual_admin')
            ->map(fn (Collection $group, string $source) => [
                'source' => $source,
                'total' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ServiceRequest>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildRelationBreakdown(Collection $rows): array
    {
        $counts = [
            'customer' => 0,
            'prospect' => 0,
            'unlinked' => 0,
        ];

        foreach ($rows as $row) {
            if ($row->customer_id) {
                $counts['customer']++;
            } elseif ($row->prospect_id) {
                $counts['prospect']++;
            } else {
                $counts['unlinked']++;
            }
        }

        return collect($counts)
            ->map(fn (int $total, string $relation) => [
                'relation' => $relation,
                'total' => $total,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeServiceRequest(ServiceRequest $serviceRequest): array
    {
        return [
            'id' => $serviceRequest->id,
            'customer_id' => $serviceRequest->customer_id,
            'prospect_id' => $serviceRequest->prospect_id,
            'source' => $serviceRequest->source,
            'channel' => $serviceRequest->channel,
            'status' => $serviceRequest->status,
            'request_type' => $serviceRequest->request_type,
            'service_type' => $serviceRequest->service_type,
            'title' => $serviceRequest->title,
            'description' => $serviceRequest->description,
            'requester_name' => $serviceRequest->requester_name,
            'requester_email' => $serviceRequest->requester_email,
            'requester_phone' => $serviceRequest->requester_phone,
            'street1' => $serviceRequest->street1,
            'street2' => $serviceRequest->street2,
            'city' => $serviceRequest->city,
            'state' => $serviceRequest->state,
            'postal_code' => $serviceRequest->postal_code,
            'country' => $serviceRequest->country,
            'source_ref' => $serviceRequest->source_ref,
            'source_meta' => $serviceRequest->source_meta,
            'submitted_at' => $serviceRequest->submitted_at,
            'accepted_at' => $serviceRequest->accepted_at,
            'completed_at' => $serviceRequest->completed_at,
            'cancelled_at' => $serviceRequest->cancelled_at,
            'created_at' => $serviceRequest->created_at,
            'updated_at' => $serviceRequest->updated_at,
            'meta' => $serviceRequest->meta,
            'customer' => $serviceRequest->customer ? [
                'id' => $serviceRequest->customer->id,
                'company_name' => $serviceRequest->customer->company_name,
                'first_name' => $serviceRequest->customer->first_name,
                'last_name' => $serviceRequest->customer->last_name,
                'email' => $serviceRequest->customer->email,
                'phone' => $serviceRequest->customer->phone,
            ] : null,
            'prospect' => $serviceRequest->prospect ? [
                'id' => $serviceRequest->prospect->id,
                'customer_id' => $serviceRequest->prospect->customer_id,
                'status' => $serviceRequest->prospect->status,
                'title' => $serviceRequest->prospect->title,
                'service_type' => $serviceRequest->prospect->service_type,
                'contact_name' => $serviceRequest->prospect->contact_name,
                'contact_email' => $serviceRequest->prospect->contact_email,
                'contact_phone' => $serviceRequest->prospect->contact_phone,
                'created_at' => $serviceRequest->prospect->created_at,
                'last_activity_at' => $serviceRequest->prospect->last_activity_at,
                'next_follow_up_at' => $serviceRequest->prospect->next_follow_up_at,
                'customer' => $serviceRequest->prospect->relationLoaded('customer') && $serviceRequest->prospect->customer ? [
                    'id' => $serviceRequest->prospect->customer->id,
                    'company_name' => $serviceRequest->prospect->customer->company_name,
                    'first_name' => $serviceRequest->prospect->customer->first_name,
                    'last_name' => $serviceRequest->prospect->customer->last_name,
                    'email' => $serviceRequest->prospect->customer->email,
                    'phone' => $serviceRequest->prospect->customer->phone,
                ] : null,
            ] : null,
        ];
    }

    private function ensureServiceRequestReadAccess($user, int $accountId): void
    {
        if (! $user) {
            abort(403);
        }

        if ((int) $user->id === $accountId) {
            return;
        }

        if (! $this->teamMemberCanManageProspects($user, $accountId)) {
            abort(403);
        }
    }

    private function ensureServiceRequestWriteAccess($user, int $accountId): void
    {
        $this->ensureServiceRequestReadAccess($user, $accountId);
    }
}
