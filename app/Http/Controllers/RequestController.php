<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\TrackingEvent;
use App\Services\UsageLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $windowDays = 30;
        $windowStart = now()->subDays($windowDays);

        $firstResponseSub = ActivityLog::query()
            ->selectRaw('subject_id, MIN(created_at) as first_response_at')
            ->where('subject_type', LeadRequest::class)
            ->where('action', '!=', 'created')
            ->groupBy('subject_id');

        $kpiLeads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->where('created_at', '>=', $windowStart)
            ->leftJoinSub($firstResponseSub, 'first_responses', function ($join) {
                $join->on('requests.id', '=', 'first_responses.subject_id');
            })
            ->get([
                'requests.id',
                'requests.status',
                'requests.channel',
                'requests.created_at',
                'first_responses.first_response_at',
            ]);

        $firstResponseSeconds = $kpiLeads
            ->filter(fn($lead) => !empty($lead->first_response_at))
            ->map(function ($lead) {
                $createdAt = Carbon::parse($lead->created_at);
                $responseAt = Carbon::parse($lead->first_response_at);
                return $responseAt->greaterThan($createdAt)
                    ? $responseAt->diffInSeconds($createdAt)
                    : 0;
            });

        $avgResponseSeconds = $firstResponseSeconds->count()
            ? $firstResponseSeconds->avg()
            : null;
        $avgResponseHours = $avgResponseSeconds !== null
            ? round($avgResponseSeconds / 3600, 1)
            : null;

        $kpiTotal = $kpiLeads->count();
        $kpiWon = $kpiLeads->where('status', LeadRequest::STATUS_WON)->count();
        $conversionRate = $kpiTotal > 0 ? round(($kpiWon / $kpiTotal) * 100, 1) : 0;

        $conversionBySource = $kpiLeads
            ->groupBy(function ($lead) {
                return $this->normalizeChannel($lead->channel) ?: 'unknown';
            })
            ->map(function ($items, $channel) {
                $total = $items->count();
                $won = $items->where('status', LeadRequest::STATUS_WON)->count();
                return [
                    'source' => $channel,
                    'total' => $total,
                    'won' => $won,
                    'rate' => $total > 0 ? round(($won / $total) * 100, 1) : 0,
                ];
            })
            ->values()
            ->sortByDesc('total')
            ->values();

        $formWindowDays = 30;
        $formWindowStart = now()->subDays($formWindowDays)->startOfDay();
        $viewsQuery = TrackingEvent::query()
            ->where('event_type', 'lead_form_view')
            ->where('user_id', $accountId);
        $submitsQuery = TrackingEvent::query()
            ->where('event_type', 'lead_form_submit')
            ->where('user_id', $accountId);

        $formViews = (clone $viewsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->count();
        $formUniqueViews = (clone $viewsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->whereNotNull('visitor_hash')
            ->distinct('visitor_hash')
            ->count('visitor_hash');
        $formSubmits = (clone $submitsQuery)
            ->where('created_at', '>=', $formWindowStart)
            ->count();
        $formConversion = $formViews > 0 ? round(($formSubmits / $formViews) * 100, 1) : 0;
        $lastFormView = (clone $viewsQuery)->latest('created_at')->first(['created_at']);
        $lastFormSubmit = (clone $submitsQuery)->latest('created_at')->first(['created_at']);

        $lastActivitySub = ActivityLog::query()
            ->selectRaw('subject_id, MAX(created_at) as last_activity_at')
            ->where('subject_type', LeadRequest::class)
            ->groupBy('subject_id');

        $riskCandidates = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereIn('status', $openStatuses)
            ->leftJoinSub($lastActivitySub, 'last_activity', function ($join) {
                $join->on('requests.id', '=', 'last_activity.subject_id');
            })
            ->with(['assignee.user:id,name', 'customer:id,company_name,first_name,last_name'])
            ->orderByRaw('COALESCE(last_activity.last_activity_at, requests.updated_at, requests.created_at) ASC')
            ->limit(30)
            ->get(['requests.*', 'last_activity.last_activity_at']);

        $now = now();
        $riskLeads = $riskCandidates
            ->map(function ($lead) use ($now) {
                $lastActivity = $lead->last_activity_at ?? $lead->updated_at ?? $lead->created_at;
                $lastActivityAt = $lastActivity ? Carbon::parse($lastActivity) : null;
                $days = $lastActivityAt ? $now->diffInDays($lastActivityAt) : 0;
                $customerName = $lead->customer
                    ? ($lead->customer->company_name
                        ?: trim(($lead->customer->first_name ?? '') . ' ' . ($lead->customer->last_name ?? '')))
                    : null;

                return [
                    'id' => $lead->id,
                    'title' => $lead->title,
                    'service_type' => $lead->service_type,
                    'status' => $lead->status,
                    'channel' => $lead->channel,
                    'last_activity_at' => $lastActivityAt,
                    'next_follow_up_at' => $lead->next_follow_up_at,
                    'assignee_name' => $lead->assignee?->user?->name ?? $lead->assignee?->name,
                    'customer_name' => $customerName,
                    'days_since_activity' => $days,
                ];
            })
            ->filter(fn($lead) => $lead['days_since_activity'] >= 7)
            ->values()
            ->take(10);

        $leadIntake = [
            'public_form_url' => URL::signedRoute('public.requests.form', ['user' => $accountId]),
            'api_endpoint' => route('api.integrations.requests.store'),
        ];

        return $this->inertiaOrJson('Request/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
            'statuses' => $statuses,
            'assignees' => $assignees,
            'lead_intake' => $leadIntake,
            'analytics' => [
                'window_days' => $windowDays,
                'total' => $kpiTotal,
                'won' => $kpiWon,
                'avg_first_response_hours' => $avgResponseHours,
                'conversion_rate' => $conversionRate,
                'conversion_by_source' => $conversionBySource,
                'lead_form' => [
                    'window_days' => $formWindowDays,
                    'views' => $formViews,
                    'unique_views' => $formUniqueViews,
                    'submits' => $formSubmits,
                    'conversion_rate' => $formConversion,
                    'last_view_at' => $lastFormView?->created_at?->toJSON(),
                    'last_submit_at' => $lastFormSubmit?->created_at?->toJSON(),
                ],
                'risk_leads' => $riskLeads,
            ],
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

        $duplicates = collect();
        if ($lead->contact_email || $lead->contact_phone) {
            $duplicates = LeadRequest::query()
                ->where('user_id', $accountId)
                ->where('id', '!=', $lead->id)
                ->where(function ($query) use ($lead) {
                    if ($lead->contact_email) {
                        $query->orWhere('contact_email', $lead->contact_email);
                    }
                    if ($lead->contact_phone) {
                        $query->orWhere('contact_phone', $lead->contact_phone);
                    }
                })
                ->with(['assignee.user:id,name'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
        }

        return $this->inertiaOrJson('Request/Show', [
            'lead' => $lead,
            'activity' => $activity,
            'statuses' => $statuses,
            'assignees' => $assignees,
            'duplicates' => $duplicates,
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
            'meta.budget' => 'nullable|numeric',
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
     * Import lead requests from CSV.
     */
    public function import(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(403);
        }
        $accountId = $user->accountOwnerId();

        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10000',
            'mapping' => 'nullable|array',
        ]);

        $file = $data['file'];
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Unable to read import file.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Unable to read import file.');
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Import file is empty.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Import file is empty.');
        }

        $headers = array_map('trim', $headers);
        $mapping = $data['mapping'] ?? [];
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($headers), null);
            $rowData = array_combine($headers, $row);
            if (!$rowData) {
                continue;
            }
            $rows[] = $rowData;
        }

        fclose($handle);

        if (empty($rows)) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No data rows found.',
                ], 422);
            }

            return redirect()->back()->with('error', 'No data rows found.');
        }

        $payloads = [];
        foreach ($rows as $row) {
            $rowLower = array_change_key_case($row, CASE_LOWER);

            $contactName = $this->resolveImportValue($rowLower, $mapping, 'contact_name', [
                'name', 'contact', 'full_name', 'client', 'customer',
            ]);
            $contactEmail = $this->resolveImportValue($rowLower, $mapping, 'contact_email', [
                'email', 'e-mail',
            ]);
            $contactPhone = $this->resolveImportValue($rowLower, $mapping, 'contact_phone', [
                'phone', 'telephone', 'tel', 'mobile', 'cell',
            ]);
            $title = $this->resolveImportValue($rowLower, $mapping, 'title', [
                'title', 'subject', 'request',
            ]);
            $serviceType = $this->resolveImportValue($rowLower, $mapping, 'service_type', [
                'service', 'service_type', 'job', 'category',
            ]);
            $description = $this->resolveImportValue($rowLower, $mapping, 'description', [
                'description', 'details', 'notes', 'message',
            ]);

            if (!$contactName && !$contactEmail && !$contactPhone && !$title && !$serviceType) {
                continue;
            }

            $channel = $this->normalizeChannel(
                $this->resolveImportValue($rowLower, $mapping, 'channel', ['channel', 'source'])
            ) ?? 'import';

            $urgency = $this->normalizeUrgency(
                $this->resolveImportValue($rowLower, $mapping, 'urgency', ['urgency', 'priority', 'urgence'])
            );

            $budgetRaw = $this->resolveImportValue($rowLower, $mapping, 'budget', [
                'budget', 'amount', 'estimate', 'price',
            ]);
            $budget = is_numeric($budgetRaw) ? (float) $budgetRaw : null;

            $nextFollowRaw = $this->resolveImportValue($rowLower, $mapping, 'next_follow_up_at', [
                'next_follow_up', 'follow_up', 'followup_date',
            ]);
            $nextFollowUpAt = null;
            if ($nextFollowRaw) {
                try {
                    $nextFollowUpAt = Carbon::parse($nextFollowRaw);
                } catch (\Throwable $exception) {
                    $nextFollowUpAt = null;
                }
            }

            $isServiceable = $this->normalizeBoolean(
                $this->resolveImportValue($rowLower, $mapping, 'is_serviceable', [
                    'serviceable', 'is_serviceable', 'service_area',
                ])
            );

            $payloads[] = [
                'contact_name' => $contactName,
                'contact_email' => $contactEmail,
                'contact_phone' => $contactPhone,
                'title' => $title ?: $serviceType ?: $contactName,
                'service_type' => $serviceType,
                'description' => $description,
                'channel' => $channel,
                'urgency' => $urgency,
                'external_customer_id' => $this->resolveImportValue($rowLower, $mapping, 'external_customer_id', [
                    'external_customer_id', 'external_id', 'customer_id',
                ]),
                'street1' => $this->resolveImportValue($rowLower, $mapping, 'street1', [
                    'street1', 'address', 'street', 'adresse',
                ]),
                'street2' => $this->resolveImportValue($rowLower, $mapping, 'street2', [
                    'street2', 'address2', 'suite', 'apt',
                ]),
                'city' => $this->resolveImportValue($rowLower, $mapping, 'city', [
                    'city', 'ville',
                ]),
                'state' => $this->resolveImportValue($rowLower, $mapping, 'state', [
                    'state', 'province', 'region',
                ]),
                'postal_code' => $this->resolveImportValue($rowLower, $mapping, 'postal_code', [
                    'postal', 'postal_code', 'postcode', 'zip',
                ]),
                'country' => $this->resolveImportValue($rowLower, $mapping, 'country', [
                    'country', 'pays',
                ]),
                'next_follow_up_at' => $nextFollowUpAt,
                'is_serviceable' => $isServiceable,
                'meta' => $budget !== null ? ['budget' => $budget] : null,
            ];
        }

        if (empty($payloads)) {
            throw ValidationException::withMessages([
                'file' => ['No valid rows found in the CSV file.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests', count($payloads));

        $imported = 0;
        foreach ($payloads as $payload) {
            $customerId = $this->resolveCustomerId(
                $accountId,
                $payload['contact_email'] ?? null,
                $payload['contact_phone'] ?? null
            );

            $lead = LeadRequest::create([
                ...array_filter($payload, static fn($value) => $value !== null && $value !== ''),
                'user_id' => $accountId,
                'customer_id' => $customerId,
                'status' => LeadRequest::STATUS_NEW,
                'status_updated_at' => now(),
            ]);

            ActivityLog::record($user, $lead, 'created', [
                'channel' => $payload['channel'] ?? 'import',
            ], 'Request imported');

            $imported++;
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Requests imported successfully.',
                'imported' => $imported,
            ]);
        }

        return redirect()->back()->with('success', "{$imported} requests imported.");
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
            'channel' => ['nullable', 'string', 'max:50'],
            'urgency' => ['nullable', 'string', 'max:50'],
            'is_serviceable' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
            'meta.budget' => ['nullable', 'numeric'],
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

        if (array_key_exists('channel', $validated)) {
            $updates['channel'] = $validated['channel'];
        }

        if (array_key_exists('urgency', $validated)) {
            $updates['urgency'] = $validated['urgency'];
        }

        if (array_key_exists('is_serviceable', $validated)) {
            $updates['is_serviceable'] = $validated['is_serviceable'];
        }

        if (array_key_exists('meta', $validated)) {
            $updates['meta'] = $validated['meta'];
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

    public function merge(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (!$user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(404);
        }

        $validated = $request->validate([
            'source_id' => [
                'required',
                'integer',
                Rule::exists('requests', 'id')->where('user_id', $accountId),
            ],
        ]);

        $sourceId = (int) $validated['source_id'];
        if ($sourceId === $lead->id) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Cannot merge the same request.',
                ], 422);
            }

            return redirect()->back()->withErrors(['source_id' => 'Cannot merge the same request.']);
        }

        $source = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereKey($sourceId)
            ->firstOrFail();

        $lead->loadMissing('quote');
        $source->loadMissing('quote');
        if ($lead->quote && $source->quote) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Both leads already have quotes. Merge is blocked.',
                ], 422);
            }

            return redirect()->back()->withErrors([
                'source_id' => 'Both leads already have quotes. Merge is blocked.',
            ]);
        }

        $leadMeta = $lead->meta ?? [];
        $sourceMeta = $source->meta ?? [];
        $mergedMeta = array_replace($sourceMeta, $leadMeta);

        $updates = [
            'customer_id' => $lead->customer_id ?: $source->customer_id,
            'assigned_team_member_id' => $lead->assigned_team_member_id ?: $source->assigned_team_member_id,
            'external_customer_id' => $lead->external_customer_id ?: $source->external_customer_id,
            'channel' => $lead->channel ?: $source->channel,
            'service_type' => $lead->service_type ?: $source->service_type,
            'urgency' => $lead->urgency ?: $source->urgency,
            'title' => $lead->title ?: $source->title,
            'description' => $lead->description ?: $source->description,
            'contact_name' => $lead->contact_name ?: $source->contact_name,
            'contact_email' => $lead->contact_email ?: $source->contact_email,
            'contact_phone' => $lead->contact_phone ?: $source->contact_phone,
            'country' => $lead->country ?: $source->country,
            'state' => $lead->state ?: $source->state,
            'city' => $lead->city ?: $source->city,
            'street1' => $lead->street1 ?: $source->street1,
            'street2' => $lead->street2 ?: $source->street2,
            'postal_code' => $lead->postal_code ?: $source->postal_code,
            'lat' => $lead->lat ?: $source->lat,
            'lng' => $lead->lng ?: $source->lng,
            'is_serviceable' => $lead->is_serviceable ?? $source->is_serviceable,
            'next_follow_up_at' => $lead->next_follow_up_at ?: $source->next_follow_up_at,
            'lost_reason' => $lead->lost_reason ?: $source->lost_reason,
            'meta' => $mergedMeta,
        ];

        $lead->update($updates);

        if ($source->quote && !$lead->quote) {
            $source->quote->update(['request_id' => $lead->id]);
        }

        $source->notes()->update(['request_id' => $lead->id]);
        $source->media()->update(['request_id' => $lead->id]);
        $source->tasks()->update(['request_id' => $lead->id]);

        ActivityLog::record($user, $lead, 'merged', [
            'source_id' => $source->id,
        ], 'Request merged');

        ActivityLog::record($user, $source, 'merged_into', [
            'target_id' => $lead->id,
        ], 'Request merged into another');

        $source->delete();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request merged.',
                'lead' => $lead->fresh(['assignee.user', 'customer', 'quote']),
            ]);
        }

        return redirect()->route('request.show', $lead)->with('success', 'Request merged.');
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

    private function resolveImportValue(array $row, array $mapping, string $field, array $aliases = []): ?string
    {
        $value = null;
        $mapped = $mapping[$field] ?? null;
        if ($mapped) {
            $key = strtolower(trim((string) $mapped));
            $value = $row[$key] ?? null;
        }

        if (($value === null || $value === '') && $aliases) {
            foreach ($aliases as $alias) {
                $aliasKey = strtolower(trim((string) $alias));
                $value = $row[$aliasKey] ?? null;
                if ($value !== null && $value !== '') {
                    break;
                }
            }
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        return $value === '' ? null : $value;
    }

    private function normalizeChannel(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $map = [
            'web' => 'web_form',
            'website' => 'web_form',
            'form' => 'web_form',
            'phone' => 'phone',
            'call' => 'phone',
            'email' => 'email',
            'mail' => 'email',
            'whatsapp' => 'whatsapp',
            'wa' => 'whatsapp',
            'sms' => 'sms',
            'text' => 'sms',
            'qr' => 'qr',
            'api' => 'api',
            'webhook' => 'api',
            'import' => 'import',
            'csv' => 'import',
            'referral' => 'referral',
            'ads' => 'ads',
            'portal' => 'portal',
        ];

        return $map[$normalized] ?? ($normalized !== '' ? $normalized : null);
    }

    private function normalizeUrgency(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $map = [
            'urgent' => 'urgent',
            'high' => 'high',
            'haute' => 'high',
            'medium' => 'medium',
            'moyenne' => 'medium',
            'low' => 'low',
            'basse' => 'low',
        ];

        return $map[$normalized] ?? null;
    }

    private function normalizeBoolean($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'oui'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'non'], true)) {
            return false;
        }

        return null;
    }

    private function resolveCustomerId(int $accountId, ?string $email, ?string $phone): ?int
    {
        $query = Customer::query()->byUser($accountId);

        if ($email) {
            $customer = (clone $query)->where('email', $email)->first();
            if ($customer) {
                return $customer->id;
            }
        }

        if ($phone) {
            $customer = (clone $query)->where('phone', $phone)->first();
            if ($customer) {
                return $customer->id;
            }
        }

        return null;
    }
}
