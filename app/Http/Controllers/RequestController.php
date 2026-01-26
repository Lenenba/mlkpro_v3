<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'view',
        ]);

        $allowedViews = ['table', 'board'];
        $filters['view'] = in_array($filters['view'] ?? null, $allowedViews, true)
            ? $filters['view']
            : 'table';

        $allowedStatuses = LeadRequest::STATUSES;
        $baseQuery = LeadRequest::query()
            ->where('user_id', $accountId)
            ->when(
                $filters['search'] ?? null,
                function ($query, $search) {
                    $query->where(function ($sub) use ($search) {
                        $sub->where('title', 'like', '%' . $search . '%')
                            ->orWhere('service_type', 'like', '%' . $search . '%')
                            ->orWhere('description', 'like', '%' . $search . '%')
                            ->orWhere('contact_name', 'like', '%' . $search . '%')
                            ->orWhere('contact_email', 'like', '%' . $search . '%')
                            ->orWhere('contact_phone', 'like', '%' . $search . '%')
                            ->orWhere('external_customer_id', 'like', '%' . $search . '%');
                    });
                }
            )
            ->when(
                $filters['status'] ?? null,
                function ($query, $status) {
                    $allowed = LeadRequest::STATUSES;
                    if (!in_array($status, $allowed, true)) {
                        return;
                    }
                    $query->where('status', $status);
                }
            )
            ->when(
                $filters['customer_id'] ?? null,
                fn($query, $customerId) => $query->where('customer_id', $customerId)
            );

        $requestsQuery = (clone $baseQuery)
            ->with([
                'customer:id,company_name,first_name,last_name,email,phone',
                'quote:id,number,status,customer_id,request_id',
                'assignee:id,user_id,account_id',
                'assignee.user:id,name',
            ])
            ->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_follow_up_at')
            ->latest();

        if ($filters['view'] === 'board') {
            $items = $requestsQuery->get();
            $perPage = max($items->count(), 1);
            $requests = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $items->count(),
                $perPage,
                1,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        } else {
            $requests = $requestsQuery
                ->simplePaginate(15)
                ->withQueryString();
        }

        $openStatuses = [
            LeadRequest::STATUS_NEW,
            LeadRequest::STATUS_CONTACTED,
            LeadRequest::STATUS_QUALIFIED,
            LeadRequest::STATUS_QUOTE_SENT,
        ];

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'new' => (clone $baseQuery)->where('status', LeadRequest::STATUS_NEW)->count(),
            'in_progress' => (clone $baseQuery)->whereIn('status', $openStatuses)->count(),
            'won' => (clone $baseQuery)->where('status', LeadRequest::STATUS_WON)->count(),
            'lost' => (clone $baseQuery)->where('status', LeadRequest::STATUS_LOST)->count(),
            'unassigned' => (clone $baseQuery)->whereNull('assigned_team_member_id')->count(),
        ];

        $customers = Customer::byUser($accountId)
            ->with(['properties' => function ($query) {
                $query->orderByDesc('is_default')->orderBy('id');
            }])
            ->orderBy('company_name')
            ->orderBy('last_name')
            ->get(['id', 'company_name', 'first_name', 'last_name', 'email', 'phone'])
            ->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'company_name' => $customer->company_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'properties' => $customer->properties->map(function ($property) {
                        return [
                            'id' => $property->id,
                            'type' => $property->type,
                            'is_default' => (bool) $property->is_default,
                            'street1' => $property->street1,
                            'street2' => $property->street2,
                            'city' => $property->city,
                            'state' => $property->state,
                            'zip' => $property->zip,
                            'country' => $property->country,
                        ];
                    })->values(),
                ];
            })
            ->values();

        $statuses = collect([
            ['id' => LeadRequest::STATUS_NEW, 'name' => 'New'],
            ['id' => LeadRequest::STATUS_CONTACTED, 'name' => 'Contacted'],
            ['id' => LeadRequest::STATUS_QUALIFIED, 'name' => 'Qualified'],
            ['id' => LeadRequest::STATUS_QUOTE_SENT, 'name' => 'Quote sent'],
            ['id' => LeadRequest::STATUS_WON, 'name' => 'Won'],
            ['id' => LeadRequest::STATUS_LOST, 'name' => 'Lost'],
        ])->values()->all();

        $assignees = TeamMember::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'account_id', 'user_id', 'role'])
            ->map(function (TeamMember $member) {
                return [
                    'id' => $member->id,
                    'name' => $member->user?->name ?? 'Team member',
                    'role' => $member->role,
                ];
            })
            ->values();

        return $this->inertiaOrJson('Request/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
            'statuses' => $statuses,
            'assignees' => $assignees,
        ]);
    }

    public function show(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(404);
        }

        $lead->load([
            'customer:id,company_name,first_name,last_name,email,phone',
            'assignee:id,user_id,account_id',
            'assignee.user:id,name',
            'quote:id,number,status,customer_id,request_id',
            'notes.user:id,name',
            'media.user:id,name',
            'tasks' => function ($query) {
                $query->latest('created_at')
                    ->with('assignee.user:id,name')
                    ->take(40);
            },
        ]);

        $activity = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->with('user:id,name')
            ->latest()
            ->take(50)
            ->get();

        $statuses = collect([
            ['id' => LeadRequest::STATUS_NEW, 'name' => 'New'],
            ['id' => LeadRequest::STATUS_CONTACTED, 'name' => 'Contacted'],
            ['id' => LeadRequest::STATUS_QUALIFIED, 'name' => 'Qualified'],
            ['id' => LeadRequest::STATUS_QUOTE_SENT, 'name' => 'Quote sent'],
            ['id' => LeadRequest::STATUS_WON, 'name' => 'Won'],
            ['id' => LeadRequest::STATUS_LOST, 'name' => 'Lost'],
        ])->values()->all();

        $assignees = TeamMember::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('id')
            ->get(['id', 'account_id', 'user_id', 'role'])
            ->map(function (TeamMember $member) {
                return [
                    'id' => $member->id,
                    'name' => $member->user?->name ?? 'Team member',
                    'role' => $member->role,
                ];
            })
            ->values();

        return $this->inertiaOrJson('Request/Show', [
            'lead' => $lead,
            'activity' => $activity,
            'statuses' => $statuses,
            'assignees' => $assignees,
        ]);
    }

    /**
     * Store a new lead request.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'requests');
        }

        $validated = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'assigned_team_member_id' => ['nullable', Rule::exists('team_members', 'id')],
            'external_customer_id' => 'nullable|string|max:100',
            'channel' => 'nullable|string|max:50',
            'service_type' => 'nullable|string|max:255',
            'urgency' => 'nullable|string|max:50',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'street1' => 'nullable|string|max:255',
            'street2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:30',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_serviceable' => 'nullable|boolean',
            'next_follow_up_at' => 'nullable|date',
            'meta' => 'nullable|array',
        ]);

        $customerId = $validated['customer_id'] ?? null;
        if ($customerId) {
            Customer::byUser($accountId)->findOrFail($customerId);
        }

        $assigneeId = $validated['assigned_team_member_id'] ?? null;
        if ($assigneeId) {
            TeamMember::query()
                ->where('account_id', $accountId)
                ->whereKey($assigneeId)
                ->firstOrFail();
        }

        $lead = LeadRequest::create([
            ...$validated,
            'user_id' => $accountId,
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'created', [
            'customer_id' => $customerId,
            'title' => $lead->title,
            'service_type' => $lead->service_type,
        ], 'Request created');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request created successfully.',
                'request' => $lead,
            ], 201);
        }

        return redirect()->back()->with('success', 'Request created successfully.');
    }

    /**
     * Convert a request into a draft quote.
     */
    public function convert(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'create_customer' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'property_id' => ['nullable', Rule::exists('properties', 'id')],
            'job_title' => 'nullable|string|max:255',
        ]);

        $createCustomer = (bool) ($validated['create_customer'] ?? false);
        $customerId = $validated['customer_id'] ?? $lead->customer_id;
        $propertyId = $validated['property_id'] ?? null;

        if ($createCustomer || !$customerId) {
            $contactName = trim($validated['contact_name'] ?? $lead->contact_name ?? '');
            $contactEmail = $validated['contact_email'] ?? $lead->contact_email;
            $contactPhone = $validated['contact_phone'] ?? $lead->contact_phone;
            $customerName = trim($validated['customer_name'] ?? '');
            if ($customerName === '') {
                $customerName = trim($lead->title ?? $lead->service_type ?? '');
            }
            if ($customerName === '' && $contactName !== '') {
                $customerName = $contactName;
            }

            $firstName = null;
            $lastName = null;
            if ($contactName !== '') {
                $parts = preg_split('/\s+/', $contactName, 2);
                $firstName = $parts[0] ?? null;
                $lastName = $parts[1] ?? null;
            }

            $customer = Customer::create([
                'user_id' => $accountId,
                'company_name' => $customerName !== '' ? $customerName : null,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $contactEmail,
                'phone' => $contactPhone,
                'description' => $lead->description,
            ]);

            $customerId = $customer->id;

            if ($lead->city) {
                $property = $customer->properties()->create([
                    'type' => 'physical',
                    'is_default' => true,
                    'street1' => $lead->street1,
                    'street2' => $lead->street2,
                    'city' => $lead->city,
                    'state' => $lead->state,
                    'zip' => $lead->postal_code,
                    'country' => $lead->country,
                ]);
                $propertyId = $property->id;
            }
        }

        if (!$customerId) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'customer_id' => ['Customer is required.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors(['customer_id' => 'Customer is required.']);
        }

        $customer = Customer::byUser($accountId)->findOrFail($customerId);
        if ($propertyId && !$customer->properties()->whereKey($propertyId)->exists()) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'property_id' => ['Invalid property for this customer.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors(['property_id' => 'Invalid property for this customer.']);
        }

        $jobTitle = $validated['job_title'] ?? $lead->title ?? $lead->service_type ?? 'New Quote';

        if ($user) {
            app(UsageLimitService::class)->enforceLimit($user, 'quotes');
        }

        $quote = Quote::create([
            'user_id' => $accountId,
            'customer_id' => $customer->id,
            'property_id' => $propertyId,
            'job_title' => $jobTitle,
            'status' => 'draft',
            'request_id' => $lead->id,
            'notes' => $lead->description,
        ]);

        $lead->update([
            'customer_id' => $customer->id,
            'status' => LeadRequest::STATUS_QUALIFIED,
            'status_updated_at' => now(),
            'converted_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'converted', [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
        ], 'Request converted to quote');

        ActivityLog::record($user, $quote, 'created', [
            'request_id' => $lead->id,
            'customer_id' => $quote->customer_id,
        ], 'Quote created from request');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request converted to quote.',
                'quote' => $quote,
                'request' => $lead->fresh(),
            ]);
        }

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Request converted to quote.');
    }

    public function update(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(LeadRequest::STATUSES)],
            'assigned_team_member_id' => ['nullable', Rule::exists('team_members', 'id')],
            'next_follow_up_at' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $assigneeId = $validated['assigned_team_member_id'] ?? null;
        if ($assigneeId) {
            TeamMember::query()
                ->where('account_id', $accountId)
                ->whereKey($assigneeId)
                ->firstOrFail();
        }

        $updates = [];
        $previousStatus = $lead->status;

        if (array_key_exists('status', $validated) && $validated['status']) {
            $updates['status'] = $validated['status'];
            $updates['status_updated_at'] = now();
        }

        if (array_key_exists('assigned_team_member_id', $validated)) {
            $updates['assigned_team_member_id'] = $validated['assigned_team_member_id'];
        }

        if (array_key_exists('next_follow_up_at', $validated)) {
            $updates['next_follow_up_at'] = $validated['next_follow_up_at'];
        }

        if (array_key_exists('lost_reason', $validated)) {
            $updates['lost_reason'] = $validated['lost_reason'];
        }

        $nextStatus = $updates['status'] ?? $lead->status;
        if ($nextStatus === LeadRequest::STATUS_LOST && empty($updates['lost_reason']) && !$lead->lost_reason) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'lost_reason' => ['Lost reason is required.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors(['lost_reason' => 'Lost reason is required.']);
        }

        if ($nextStatus !== LeadRequest::STATUS_LOST) {
            $updates['lost_reason'] = null;
        }

        $lead->update($updates);

        ActivityLog::record($user, $lead, 'updated', [
            'from' => $previousStatus,
            'to' => $lead->status,
            'next_follow_up_at' => $lead->next_follow_up_at,
            'assigned_team_member_id' => $lead->assigned_team_member_id,
        ], 'Request updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request updated successfully.',
                'request' => $lead->fresh(['assignee.user', 'customer', 'quote']),
            ]);
        }

        return redirect()->back()->with('success', 'Request updated successfully.');
    }

    public function bulkUpdate(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('requests', 'id')->where('user_id', $accountId),
            ],
            'status' => ['nullable', Rule::in(LeadRequest::STATUSES)],
            'assigned_team_member_id' => [
                'nullable',
                Rule::exists('team_members', 'id')->where('account_id', $accountId),
            ],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = $validated['status'] ?? null;
        $hasAssignee = array_key_exists('assigned_team_member_id', $validated);

        if (!$status && !$hasAssignee) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No bulk updates specified.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'No bulk updates specified.',
            ]);
        }

        if ($status === LeadRequest::STATUS_LOST && empty($validated['lost_reason'])) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Validation error.',
                    'errors' => [
                        'lost_reason' => ['Lost reason is required.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors(['lost_reason' => 'Lost reason is required.']);
        }

        $updates = [];
        if ($status) {
            $updates['status'] = $status;
            $updates['status_updated_at'] = now();
            $updates['lost_reason'] = $status === LeadRequest::STATUS_LOST
                ? $validated['lost_reason']
                : null;
        }

        if ($hasAssignee) {
            $updates['assigned_team_member_id'] = $validated['assigned_team_member_id'];
        }

        if (empty($updates)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No bulk updates specified.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'status' => 'No bulk updates specified.',
            ]);
        }

        $leadIds = collect($validated['ids'])->unique()->values();
        $leads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $leadIds)
            ->get();

        foreach ($leads as $lead) {
            $previousStatus = $lead->status;
            $lead->update($updates);

            ActivityLog::record($user, $lead, 'bulk_updated', [
                'from' => $previousStatus,
                'to' => $lead->status,
                'assigned_team_member_id' => $lead->assigned_team_member_id,
            ], 'Request updated');
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Requests updated.',
                'updated' => $leads->count(),
            ]);
        }

        return redirect()->back()->with('success', 'Requests updated.');
    }

    public function destroy(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        ActivityLog::record($user, $lead, 'deleted', [
            'status' => $lead->status,
        ], 'Request deleted');

        $lead->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Request deleted.');
    }
}
