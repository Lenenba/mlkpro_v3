<?php

namespace App\Http\Controllers;

use App\Actions\Leads\AnonymizeLeadRequestAction;
use App\Actions\Leads\ConvertLeadRequestToQuoteAction;
use App\Http\Requests\Leads\BulkUpdateLeadRequest;
use App\Http\Requests\Leads\ConvertLeadToCustomerRequest;
use App\Http\Requests\Leads\ConvertLeadToQuoteRequest;
use App\Http\Requests\Leads\ImportLeadRequestsRequest;
use App\Http\Requests\Leads\MergeLeadRequest;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Prospect;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Queries\Requests\BuildRequestAnalyticsData;
use App\Queries\Requests\BuildRequestInboxIndexData;
use App\Services\Campaigns\CampaignLeadAttributionService;
use App\Services\ProspectNotificationService;
use App\Services\Prospects\ProspectConversionService;
use App\Services\Prospects\ProspectDuplicateAlertService;
use App\Services\Prospects\ProspectDuplicateDetectionService;
use App\Services\Prospects\ProspectMergeService;
use App\Services\ProspectStatusHistoryService;
use App\Services\TaskStatusHistoryService;
use App\Services\UsageLimitService;
use App\Support\Prospects\ProspectIntakeMeta;
use App\Support\BulkActions\BulkActionRegistry;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function options(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $this->ensureProspectWorkspaceReadAccess($user, $accountId, $request);

        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 25);

        $prospects = LeadRequest::query()
            ->where('user_id', $accountId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('title', 'like', '%'.$search.'%')
                        ->orWhere('service_type', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%')
                        ->orWhere('contact_email', 'like', '%'.$search.'%')
                        ->orWhere('contact_phone', 'like', '%'.$search.'%');
                });
            })
            ->latest('created_at')
            ->limit(max(1, min($limit, 50)))
            ->get([
                'id',
                'customer_id',
                'status',
                'title',
                'service_type',
                'contact_name',
                'contact_email',
                'contact_phone',
                'meta',
            ])
            ->map(function (LeadRequest $lead) {
                return [
                    'id' => $lead->id,
                    'customer_id' => $lead->customer_id,
                    'status' => $lead->status,
                    'title' => $lead->title,
                    'service_type' => $lead->service_type,
                    'contact_name' => $lead->contact_name,
                    'contact_email' => $lead->contact_email,
                    'contact_phone' => $lead->contact_phone,
                    'company_name' => $lead->companyName(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'prospects' => $prospects,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceReadAccess($user, $accountId, $request);

        $indexData = app(BuildRequestInboxIndexData::class)->execute($accountId, $request);
        $filters = $indexData['filters'];
        $requests = $indexData['requests'];
        $stats = $indexData['stats'];

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

        $statuses = Prospect::statusOptions();
        $lostReasonOptions = LeadRequest::lostReasonOptions();

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

        $leadIntake = [
            'public_form_url' => URL::signedRoute('public.requests.form', ['user' => $accountId]),
            'api_endpoint' => route('api.integrations.requests.store'),
        ];

        $canManageSavedSegments = (int) $user->id === (int) $accountId;
        $savedSegments = $canManageSavedSegments
            ? SavedSegment::query()
                ->byUser($accountId)
                ->where('module', SavedSegment::MODULE_REQUEST)
                ->orderByDesc('updated_at')
                ->orderBy('name')
                ->get([
                    'id',
                    'module',
                    'name',
                    'description',
                    'filters',
                    'sort',
                    'search_term',
                    'is_shared',
                    'cached_count',
                    'last_resolved_at',
                    'updated_at',
                ])
            : collect();

        return $this->inertiaOrJson('Request/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
            'statuses' => $statuses,
            'lostReasonOptions' => $lostReasonOptions,
            'assignees' => $assignees,
            'bulkActions' => app(BulkActionRegistry::class)->definitionFor('request', [
                'statuses' => $statuses,
                'assignees' => $assignees,
            ]),
            'savedSegments' => $savedSegments,
            'canManageSavedSegments' => $canManageSavedSegments,
            'canExport' => $this->canExportProspects($user, $accountId),
            'lead_intake' => $leadIntake,
            'analytics' => $this->buildAnalyticsData($accountId),
        ]);
    }

    public function show(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceReadAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(404);
        }

        $lead->load([
            'customer:id,company_name,first_name,last_name,email,phone',
            'assignee:id,user_id,account_id',
            'assignee.user:id,name',
            'archivedBy:id,name',
            'statusHistories.user:id,name',
            'prospectInteractions.user:id,name',
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

        $statuses = Prospect::statusOptions();
        $lostReasonOptions = LeadRequest::lostReasonOptions();

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

        $duplicates = app(ProspectDuplicateDetectionService::class)->forLead($lead);

        $campaignOrigin = app(CampaignLeadAttributionService::class)->campaignOriginForLead($lead, $user);
        $customerConversion = $this->buildCustomerConversionPayload($lead, $accountId, $user);

        return $this->inertiaOrJson('Request/Show', [
            'lead' => $lead,
            'activity' => $activity,
            'statuses' => $statuses,
            'lostReasonOptions' => $lostReasonOptions,
            'assignees' => $assignees,
            'duplicates' => $duplicates,
            'campaignOrigin' => $campaignOrigin,
            'customerConversion' => $customerConversion,
            'canLogSalesActivity' => true,
            'salesActivityQuickActions' => array_values(SalesActivityTaxonomy::quickActions()),
            'salesActivityManualActions' => SalesActivityTaxonomy::manualActionDefinitions(),
        ]);
    }

    /**
     * Store a new lead request.
     */
    public function store(StoreLeadRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        $validated = $request->validated();
        $ignoreDuplicates = (bool) ($validated['ignore_duplicates'] ?? false);
        unset($validated['ignore_duplicates']);

        $customerId = $validated['customer_id'] ?? null;
        $channel = $this->normalizeChannel($validated['channel'] ?? null) ?? 'manual';
        $meta = ProspectIntakeMeta::merge(
            $validated['meta'] ?? null,
            source: $channel,
            requestType: data_get($validated, 'meta.request_type') ?? 'manual_entry',
            contactConsent: data_get($validated, 'meta.contact_consent'),
            marketingConsent: data_get($validated, 'meta.marketing_consent')
        );

        if ($this->shouldReturnJson($request) && ! $ignoreDuplicates) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forAttributes(
                accountId: $accountId,
                attributes: [
                    ...$validated,
                    'channel' => $channel,
                    'meta' => $meta,
                ],
                context: 'create',
            );

            if ($duplicateAlert) {
                return response()->json([
                    'message' => 'Potential duplicate prospects found.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $lead = LeadRequest::create([
            ...$validated,
            'user_id' => $accountId,
            'channel' => $channel,
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'meta' => $meta,
        ]);

        ActivityLog::record($user, $lead, 'created', [
            'customer_id' => $customerId,
            'title' => $lead->title,
            'service_type' => $lead->service_type,
        ], 'Prospect created');
        app(ProspectStatusHistoryService::class)->record($lead, $user, [
            'to_status' => $lead->status,
            'metadata' => ['source' => 'manual'],
        ]);
        app(ProspectNotificationService::class)->notifyCreated($lead, $user);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect created successfully.',
                'request' => $lead,
            ], 201);
        }

        return redirect()->back()->with('success', 'Prospect created successfully.');
    }

    /**
     * Import lead requests from CSV.
     */
    public function import(ImportLeadRequestsRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        $accountId = $user->accountOwnerId();
        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        $data = $request->validated();
        $ignoreDuplicates = (bool) ($data['ignore_duplicates'] ?? false);
        unset($data['ignore_duplicates']);

        $file = $data['file'];
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Unable to read import file.',
                ], 422);
            }

            return redirect()->back()->with('error', 'Unable to read import file.');
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
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
            if (! $rowData) {
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

            if (! $contactName && ! $contactEmail && ! $contactPhone && ! $title && ! $serviceType) {
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
            $contactConsent = $this->normalizeBoolean(
                $this->resolveImportValue($rowLower, $mapping, 'contact_consent', [
                    'contact_consent', 'consent_contact', 'can_contact', 'consent_to_contact',
                ])
            );
            $marketingConsent = $this->normalizeBoolean(
                $this->resolveImportValue($rowLower, $mapping, 'marketing_consent', [
                    'marketing_consent', 'consent_marketing', 'marketing_opt_in', 'opt_in',
                ])
            );
            $requestType = $this->resolveImportValue($rowLower, $mapping, 'request_type', [
                'request_type', 'lead_type', 'type',
            ]);

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
            $meta = [];
            if ($budget !== null) {
                $meta['budget'] = $budget;
            }

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
                'meta' => ProspectIntakeMeta::merge(
                    $meta,
                    source: $channel,
                    requestType: $requestType ?: 'csv_import',
                    contactConsent: $contactConsent,
                    marketingConsent: $marketingConsent
                ),
            ];
        }

        if (empty($payloads)) {
            throw ValidationException::withMessages([
                'file' => ['No valid rows found in the CSV file.'],
            ]);
        }

        if ($this->shouldReturnJson($request) && ! $ignoreDuplicates) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forImportPayloads($accountId, $payloads);

            if ($duplicateAlert) {
                return response()->json([
                    'message' => 'Potential duplicate prospects found in the import file.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests', count($payloads));

        $imported = 0;
        foreach ($payloads as $payload) {
            $lead = LeadRequest::create([
                ...array_filter($payload, static fn ($value) => $value !== null && $value !== ''),
                'user_id' => $accountId,
                'status' => LeadRequest::STATUS_NEW,
                'status_updated_at' => now(),
                'last_activity_at' => now(),
            ]);

            ActivityLog::record($user, $lead, 'created', [
                'channel' => $payload['channel'] ?? 'import',
            ], 'Prospect imported');
            app(ProspectStatusHistoryService::class)->record($lead, $user, [
                'to_status' => $lead->status,
                'metadata' => ['source' => 'import'],
            ]);

            $imported++;
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospects imported successfully.',
                'imported' => $imported,
            ]);
        }

        return redirect()->back()->with('success', "{$imported} prospects imported.");
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->currentAccessToken() && ! $user->tokenCan('exports:read')) {
            abort(403);
        }

        $accountId = (int) ($user->accountOwnerId() ?? 0);

        if (! $this->canExportProspects($user, $accountId)) {
            abort(403);
        }

        $filters = $request->only([
            'search',
            'status',
            'customer_id',
            'queue',
            'assigned_team_member_id',
            'source',
            'request_type',
            'priority',
            'follow_up',
            'unassigned',
            'archived',
        ]);

        $leads = app(BuildRequestInboxIndexData::class)->resolveCollection($accountId, $filters);
        $filename = 'prospects-'.now()->format('Ymd-His').'.csv';

        ActivityLog::record($user, $user, 'prospect_export', [
            'filters' => $filters,
            'row_count' => $leads->count(),
            'exported_ids' => $leads->pluck('id')->values()->all(),
        ], 'Prospects exported');

        return response()->streamDownload(function () use ($leads): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id',
                'status',
                'channel',
                'service_type',
                'title',
                'contact_name',
                'contact_email',
                'contact_phone',
                'company_name',
                'customer_id',
                'customer_name',
                'quote_id',
                'quote_number',
                'assigned_team_member_id',
                'assigned_to',
                'next_follow_up_at',
                'last_activity_at',
                'created_at',
                'archived_at',
                'lost_reason',
                'is_serviceable',
                'is_anonymized',
                'city',
                'state',
                'country',
            ]);

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->id,
                    $lead->status,
                    $lead->channel,
                    $lead->service_type,
                    $lead->title,
                    $lead->contact_name,
                    $lead->contact_email,
                    $lead->contact_phone,
                    $lead->companyName(),
                    $lead->customer_id,
                    $this->customerDisplayName($lead->customer),
                    $lead->quote?->id,
                    $lead->quote?->number,
                    $lead->assigned_team_member_id,
                    $lead->assignee?->user?->name,
                    optional($lead->next_follow_up_at)->toDateTimeString(),
                    optional($lead->last_activity_at)->toDateTimeString(),
                    optional($lead->created_at)->toDateTimeString(),
                    optional($lead->archived_at)->toDateTimeString(),
                    $lead->lost_reason,
                    $lead->is_serviceable === null ? null : ($lead->is_serviceable ? '1' : '0'),
                    $lead->isAnonymized() ? '1' : '0',
                    $lead->city,
                    $lead->state,
                    $lead->country,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Convert a request into a draft quote.
     */
    public function convert(ConvertLeadToQuoteRequest $request, LeadRequest $lead, ConvertLeadRequestToQuoteAction $convertLead)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();
        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $this->ensureLeadIsMutable(
            $lead,
            'lead',
            'Archived prospects must be restored before they can be converted.'
        );

        $validated = $request->validated();
        $ignoreDuplicates = (bool) ($validated['ignore_duplicates'] ?? false);
        unset($validated['ignore_duplicates']);

        if ($this->shouldReturnJson($request) && ! $ignoreDuplicates) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forLead($lead, 'convert');

            if ($duplicateAlert) {
                return response()->json([
                    'message' => 'Potential duplicate prospects found.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        $result = $convertLead->execute($lead, $validated, $user);
        $quote = $result['quote'];
        $lead = $result['lead'];

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect converted to quote.',
                'quote' => $quote,
                'request' => $lead->fresh(),
            ]);
        }

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Prospect converted to quote.');
    }

    /**
     * Convert a prospect into a customer by linking an existing customer or creating a new one.
     */
    public function convertToCustomer(
        ConvertLeadToCustomerRequest $request,
        LeadRequest $lead,
        ProspectConversionService $convertProspect
    )
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $this->canConvertLeadToCustomer($user, $accountId, $lead)) {
            abort(403);
        }

        $this->ensureLeadIsMutable(
            $lead,
            'lead',
            'Archived prospects must be restored before they can be converted to customers.'
        );

        $validated = $request->validated();
        $matches = $this->detectPotentialCustomerMatches($lead, $accountId);
        $matchedCustomerIds = collect($matches)->pluck('id')->values()->all();
        $result = $convertProspect->execute($lead, $validated, $user, [
            'matched_customer_ids' => $matchedCustomerIds,
        ]);
        $customer = $result['customer'];
        $lead = $result['lead'];
        $quoteIds = $result['quote_ids'];

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect converted to customer.',
                'customer' => $customer,
                'request' => $lead,
                'quote_ids' => $quoteIds,
            ]);
        }

        return redirect()->route('prospects.show', $lead)->with('success', 'Prospect converted to customer.');
    }

    public function update(UpdateLeadRequest $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $this->ensureLeadIsMutable($lead);

        $validated = $request->validated();

        $updates = [];
        $previousStatus = $lead->status;
        $previousAssigneeId = $lead->assigned_team_member_id;
        $previousLossMeta = $lead->lossMeta();
        $lostComment = $this->normalizeNullableText($validated['lost_comment'] ?? null);

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
        if ($nextStatus === LeadRequest::STATUS_LOST) {
            $updates['next_follow_up_at'] = null;
            $updates['meta'] = $this->buildLossMeta(
                $lead,
                $updates['meta'] ?? null,
                $user,
                $updates['lost_reason'] ?? $lead->lost_reason,
                $lostComment,
                $previousStatus
            );
        } else {
            $updates['lost_reason'] = null;
            $updates['meta'] = $this->clearLossMeta($lead, $updates['meta'] ?? null);
        }

        if (! empty($updates)) {
            $updates['last_activity_at'] = now();
        }

        $lead->update($updates);
        $closedOpenTaskCount = $lead->status === LeadRequest::STATUS_LOST && ($validated['close_open_tasks'] ?? false)
            ? $this->closeOpenTasksForLostLead($lead, $user)
            : 0;

        if ($previousStatus !== $lead->status) {
            app(ProspectStatusHistoryService::class)->record($lead, $user, [
                'from_status' => $previousStatus,
                'to_status' => $lead->status,
                'comment' => $lead->status === LeadRequest::STATUS_LOST
                    ? ($lostComment ?? $lead->lost_reason)
                    : ($validated['status_comment'] ?? null),
                'metadata' => $lead->status === LeadRequest::STATUS_LOST
                    ? [
                        'source' => 'manual_status_change',
                        'lost_reason' => $lead->lost_reason,
                        'lost_comment' => $lead->lostReasonComment(),
                        'closed_open_tasks' => (bool) ($validated['close_open_tasks'] ?? false),
                        'closed_open_task_count' => $closedOpenTaskCount,
                    ]
                    : null,
            ]);

            $this->recordLeadStatusAudit($user, $lead, $previousStatus, $lead->status, [
                'source' => 'manual_update',
                'lost_reason' => $lead->lost_reason,
                'lost_comment' => $lead->lostReasonComment(),
                'closed_open_tasks' => (bool) ($validated['close_open_tasks'] ?? false),
                'closed_open_task_count' => $closedOpenTaskCount,
            ]);
        }

        $this->recordLeadAssignmentAudit($user, $lead, $previousAssigneeId, $lead->assigned_team_member_id, [
            'source' => 'manual_update',
        ]);

        if ($previousStatus !== $lead->status && $lead->status === LeadRequest::STATUS_LOST) {
            app(ProspectNotificationService::class)->notifyLost($lead, $user);
        }

        app(ProspectNotificationService::class)->notifyAssigned($lead, $user, $previousAssigneeId);

        ActivityLog::record($user, $lead, 'updated', [
            'from' => $previousStatus,
            'to' => $lead->status,
            'next_follow_up_at' => $lead->next_follow_up_at,
            'assigned_team_member_id' => $lead->assigned_team_member_id,
            'lost_reason' => $lead->lost_reason,
            'lost_comment' => $lead->lostReasonComment(),
            'closed_open_tasks' => (bool) ($validated['close_open_tasks'] ?? false),
            'closed_open_task_count' => $closedOpenTaskCount,
            'previous_lost_reason' => $previousLossMeta['code'] ?? null,
        ], $previousStatus !== LeadRequest::STATUS_LOST && $lead->status === LeadRequest::STATUS_LOST
            ? 'Prospect marked as lost'
            : 'Prospect updated');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect updated successfully.',
                'request' => $lead->fresh(['assignee.user', 'customer', 'quote']),
            ]);
        }

        return redirect()->back()->with('success', 'Prospect updated successfully.');
    }

    public function bulkUpdate(BulkUpdateLeadRequest $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        $validated = $request->validated();

        $status = $validated['status'] ?? null;
        $hasAssignee = array_key_exists('assigned_team_member_id', $validated);
        $lostComment = $this->normalizeNullableText($validated['lost_comment'] ?? null);
        $closeOpenTasks = (bool) ($validated['close_open_tasks'] ?? false);

        $updates = [];
        if ($status) {
            $updates['status'] = $status;
            $updates['status_updated_at'] = now();
            $updates['lost_reason'] = $status === LeadRequest::STATUS_LOST
                ? $validated['lost_reason']
                : null;
            if ($status === LeadRequest::STATUS_LOST) {
                $updates['next_follow_up_at'] = null;
            }
        }

        if ($hasAssignee) {
            $updates['assigned_team_member_id'] = $validated['assigned_team_member_id'];
        }

        if (! empty($updates)) {
            $updates['last_activity_at'] = now();
        }

        $leadIds = collect($validated['ids'])->unique()->values();
        $leads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $leadIds)
            ->whereNull('archived_at')
            ->get();
        $processedIds = $leads->pluck('id')->all();

        foreach ($leads as $lead) {
            $previousStatus = $lead->status;
            $previousAssigneeId = $lead->assigned_team_member_id;
            $previousLossMeta = $lead->lossMeta();
            $leadUpdates = $updates;

            if (($leadUpdates['status'] ?? null) === LeadRequest::STATUS_LOST) {
                $leadUpdates['meta'] = $this->buildLossMeta(
                    $lead,
                    $leadUpdates['meta'] ?? null,
                    $user,
                    $leadUpdates['lost_reason'] ?? $lead->lost_reason,
                    $lostComment,
                    $previousStatus
                );
            } else {
                $leadUpdates['meta'] = $this->clearLossMeta($lead, $leadUpdates['meta'] ?? null);
            }

            $lead->update($leadUpdates);
            $closedOpenTaskCount = $lead->status === LeadRequest::STATUS_LOST && $closeOpenTasks
                ? $this->closeOpenTasksForLostLead($lead, $user)
                : 0;

            if ($previousStatus !== $lead->status) {
                app(ProspectStatusHistoryService::class)->record($lead, $user, [
                    'from_status' => $previousStatus,
                    'to_status' => $lead->status,
                    'comment' => $validated['status'] === LeadRequest::STATUS_LOST
                        ? ($lostComment ?? ($validated['lost_reason'] ?? null))
                        : null,
                    'metadata' => $validated['status'] === LeadRequest::STATUS_LOST
                        ? [
                            'source' => 'bulk_update',
                            'lost_reason' => $lead->lost_reason,
                            'lost_comment' => $lead->lostReasonComment(),
                            'closed_open_tasks' => $closeOpenTasks,
                            'closed_open_task_count' => $closedOpenTaskCount,
                        ]
                        : ['source' => 'bulk_update'],
                ]);

                $this->recordLeadStatusAudit($user, $lead, $previousStatus, $lead->status, [
                    'source' => 'bulk_update',
                    'lost_reason' => $lead->lost_reason,
                    'lost_comment' => $lead->lostReasonComment(),
                    'closed_open_tasks' => $closeOpenTasks,
                    'closed_open_task_count' => $closedOpenTaskCount,
                ]);
            }

            $this->recordLeadAssignmentAudit($user, $lead, $previousAssigneeId, $lead->assigned_team_member_id, [
                'source' => 'bulk_update',
            ]);

            if ($previousStatus !== $lead->status && $lead->status === LeadRequest::STATUS_LOST) {
                app(ProspectNotificationService::class)->notifyLost($lead, $user);
            }

            app(ProspectNotificationService::class)->notifyAssigned($lead, $user, $previousAssigneeId);

            ActivityLog::record($user, $lead, 'bulk_updated', [
                'from' => $previousStatus,
                'to' => $lead->status,
                'assigned_team_member_id' => $lead->assigned_team_member_id,
                'lost_reason' => $lead->lost_reason,
                'lost_comment' => $lead->lostReasonComment(),
                'closed_open_tasks' => $closeOpenTasks,
                'closed_open_task_count' => $closedOpenTaskCount,
                'previous_lost_reason' => $previousLossMeta['code'] ?? null,
            ], $previousStatus !== LeadRequest::STATUS_LOST && $lead->status === LeadRequest::STATUS_LOST
                ? 'Prospect marked as lost'
                : 'Prospect updated');
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json($this->bulkActionResult(
                'Prospects updated.',
                $leadIds->all(),
                $processedIds,
                [
                    'updated' => $leads->count(),
                    'skipped_count' => max(0, $leadIds->count() - $leads->count()),
                ]
            ));
        }

        return redirect()->back()->with('success', 'Prospects updated.');
    }

    public function merge(MergeLeadRequest $request, LeadRequest $lead, ProspectMergeService $mergeProspects)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(404);
        }

        $validated = $request->validated();
        $sourceId = (int) $validated['source_id'];

        $source = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereKey($sourceId)
            ->firstOrFail();

        $this->ensureLeadIsMutable(
            $lead,
            'lead',
            'Archived prospects must be restored before they can be merged.'
        );
        $this->ensureLeadIsMutable(
            $source,
            'source_id',
            'Archived prospects must be restored before they can be merged.'
        );

        $result = $mergeProspects->execute($lead, $source, $user);
        $lead = $result['lead'];

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect merged.',
                'lead' => $lead->fresh(['assignee.user', 'customer', 'quote', 'archivedBy']),
                'summary' => $result['summary'],
            ]);
        }

        return redirect()->route('prospects.show', $lead)->with('success', 'Prospect merged.');
    }

    public function archive(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        if ($lead->isAnonymized()) {
            throw ValidationException::withMessages([
                'lead' => ['Anonymized prospects cannot be modified.'],
            ]);
        }

        $validated = $request->validate([
            'archive_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lead->update([
            'archived_at' => now(),
            'archived_by_user_id' => $user->id,
            'archive_reason' => $validated['archive_reason'] ?? null,
            'last_activity_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'archived', [
            'status' => $lead->status,
            'archive_reason' => $lead->archive_reason,
        ], 'Prospect archived');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect archived.',
                'request' => $lead->fresh(['assignee.user', 'customer', 'quote', 'archivedBy']),
            ]);
        }

        return redirect()->back()->with('success', 'Prospect archived.');
    }

    public function restore(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        if ($lead->isAnonymized()) {
            throw ValidationException::withMessages([
                'lead' => ['Anonymized prospects cannot be restored.'],
            ]);
        }

        $lead->update([
            'archived_at' => null,
            'archived_by_user_id' => null,
            'archive_reason' => null,
            'last_activity_at' => now(),
        ]);

        ActivityLog::record($user, $lead, 'restored', [
            'status' => $lead->status,
        ], 'Prospect restored');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect restored.',
                'request' => $lead->fresh(['assignee.user', 'customer', 'quote', 'archivedBy']),
            ]);
        }

        return redirect()->back()->with('success', 'Prospect restored.');
    }

    public function anonymize(Request $request, LeadRequest $lead, AnonymizeLeadRequestAction $anonymizeLead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validate([
            'anonymization_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = $anonymizeLead->execute($lead, $user, $validated);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect anonymized.',
                'request' => $lead,
            ]);
        }

        return redirect()->back()->with('success', 'Prospect anonymized.');
    }

    public function destroy(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        $this->ensureProspectWorkspaceWriteAccess($user, $accountId, $request);

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $lead->loadMissing('quote');

        if (! $lead->isArchived()) {
            throw ValidationException::withMessages([
                'lead' => ['Prospects must be archived before they can be deleted.'],
            ]);
        }

        if (! $lead->isAnonymized()) {
            throw ValidationException::withMessages([
                'lead' => ['Prospects must be anonymized before they can be deleted.'],
            ]);
        }

        if ($lead->customer_id !== null || $lead->quote !== null) {
            throw ValidationException::withMessages([
                'lead' => ['Prospects linked to a customer or quote require a dedicated retention workflow before deletion.'],
            ]);
        }

        $lead->update([
            'deleted_by_user_id' => $user->id,
            'last_activity_at' => now(),
        ]);

        $lead->delete();

        ActivityLog::record($user, $lead, 'deleted', [
            'status' => $lead->status,
            'archived_at' => optional($lead->archived_at)->toIso8601String(),
            'anonymized_at' => data_get($lead->meta, 'privacy.anonymized_at'),
            'deleted_at' => optional($lead->deleted_at)->toIso8601String(),
            'deleted_by_user_id' => $lead->deleted_by_user_id,
        ], 'Prospect deleted');

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Prospect deleted.',
            ]);
        }

        return redirect()->back()->with('success', 'Prospect deleted.');
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
        if (! $value) {
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
        if (! $value) {
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

    /**
     * @return array{
     *     can_convert:bool,
     *     default_mode:string,
     *     matches:array<int, array<string, mixed>>,
     *     preview:array<string, mixed>,
     *     submit_url:string
     * }
     */
    private function buildCustomerConversionPayload(LeadRequest $lead, int $accountId, ?User $user): array
    {
        $matches = $this->detectPotentialCustomerMatches($lead, $accountId);

        return [
            'can_convert' => $user ? $this->canConvertLeadToCustomer($user, $accountId, $lead) : false,
            'default_mode' => count($matches) > 0 ? 'link_existing' : 'create_new',
            'matches' => $matches,
            'preview' => [
                'contact_name' => $lead->contact_name,
                'contact_email' => $lead->contact_email,
                'contact_phone' => $lead->contact_phone,
                'company_name' => $lead->companyName(),
                'street1' => $lead->street1,
                'street2' => $lead->street2,
                'city' => $lead->city,
                'state' => $lead->state,
                'postal_code' => $lead->postal_code,
                'country' => $lead->country,
                'quote' => $lead->quote
                    ? [
                        'id' => $lead->quote->id,
                        'number' => $lead->quote->number,
                        'status' => $lead->quote->status,
                    ]
                    : null,
            ],
            'submit_url' => route('prospects.convert-customer', $lead),
        ];
    }

    private function canConvertLeadToCustomer(User $user, int $accountId, LeadRequest $lead): bool
    {
        if ($lead->isArchived() || $lead->customer_id || (int) $lead->user_id !== $accountId) {
            return false;
        }

        if ((int) $user->id === $accountId) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) $membership
            && (int) $membership->account_id === $accountId
            && (
                $membership->hasPermission(Prospect::PERMISSION_CONVERT)
                || $membership->hasPermission('sales.manage')
            );
    }

    private function canExportProspects(User $user, int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }

        if ((int) $user->id === $accountId) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) $membership
            && (int) $membership->account_id === $accountId
            && $membership->hasPermission(Prospect::PERMISSION_EXPORT);
    }

    protected function buildAnalyticsData(int $accountId): array
    {
        return app(BuildRequestAnalyticsData::class)->execute($accountId);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordLeadStatusAudit(User $actor, LeadRequest $lead, ?string $fromStatus, ?string $toStatus, array $properties = []): void
    {
        if ($fromStatus === $toStatus) {
            return;
        }

        ActivityLog::record($actor, $lead, 'status_changed', [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            ...$properties,
        ], 'Prospect status changed');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordLeadAssignmentAudit(User $actor, LeadRequest $lead, mixed $fromAssigneeId, mixed $toAssigneeId, array $properties = []): void
    {
        $normalizedFrom = is_numeric($fromAssigneeId) ? (int) $fromAssigneeId : null;
        $normalizedTo = is_numeric($toAssigneeId) ? (int) $toAssigneeId : null;

        if ($normalizedFrom === $normalizedTo) {
            return;
        }

        ActivityLog::record($actor, $lead, 'assignment_changed', [
            'from_assigned_team_member_id' => $normalizedFrom,
            'to_assigned_team_member_id' => $normalizedTo,
            ...$properties,
        ], 'Prospect assignment changed');
    }

    private function customerDisplayName(?Customer $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        $displayName = trim((string) ($customer->company_name ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $displayName = trim(implode(' ', array_filter([
            $customer->first_name,
            $customer->last_name,
        ])));

        return $displayName !== '' ? $displayName : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function buildLossMeta(
        LeadRequest $lead,
        ?array $meta,
        User $actor,
        ?string $lostReason,
        ?string $lostComment,
        ?string $previousStatus
    ): array {
        $workingLead = clone $lead;
        $workingLead->meta = is_array($meta) ? $meta : (array) ($lead->meta ?? []);

        return $workingLead->mergeLossMeta([
            'code' => $lostReason,
            'comment' => $lostComment,
            'lost_at' => $previousStatus === LeadRequest::STATUS_LOST
                ? (data_get($workingLead->meta, 'loss.lost_at') ?? now()->toISOString())
                : now()->toISOString(),
            'lost_by_user_id' => $previousStatus === LeadRequest::STATUS_LOST
                ? (data_get($workingLead->meta, 'loss.lost_by_user_id') ?? $actor->id)
                : $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function clearLossMeta(LeadRequest $lead, ?array $meta): array
    {
        $workingLead = clone $lead;
        $workingLead->meta = is_array($meta) ? $meta : (array) ($lead->meta ?? []);

        return $workingLead->clearLossMeta();
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function closeOpenTasksForLostLead(LeadRequest $lead, User $actor): int
    {
        $openTasks = Task::query()
            ->where('request_id', $lead->id)
            ->whereIn('status', Task::OPEN_STATUSES)
            ->get();

        if ($openTasks->isEmpty()) {
            return 0;
        }

        $cancelledAt = now();
        $cancellationReason = $this->buildLostTaskCancellationReason($lead);

        foreach ($openTasks as $task) {
            $previousStatus = $task->status;

            $task->update([
                'status' => Task::STATUS_CANCELLED,
                'completed_at' => null,
                'completion_reason' => null,
                'cancelled_at' => $cancelledAt,
                'cancellation_reason' => $cancellationReason,
                'delay_started_at' => null,
            ]);

            app(TaskStatusHistoryService::class)->record($task, $actor, [
                'from_status' => $previousStatus,
                'to_status' => $task->status,
                'action' => 'cancelled',
                'note' => $task->cancellation_reason,
                'metadata' => [
                    'source' => 'prospect_lost',
                    'request_id' => $lead->id,
                    'lost_reason' => $lead->lost_reason,
                    'lost_comment' => $lead->lostReasonComment(),
                    'cancellation_reason' => $task->cancellation_reason,
                ],
            ]);
        }

        return $openTasks->count();
    }

    private function buildLostTaskCancellationReason(LeadRequest $lead): string
    {
        $segments = ['Prospect marked as lost'];

        if ($lead->lost_reason) {
            $segments[] = 'Loss reason: '.$lead->lost_reason;
        }

        if ($lead->lostReasonComment()) {
            $segments[] = $lead->lostReasonComment();
        }

        return Str::limit(implode(' | ', $segments), 255, '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function detectPotentialCustomerMatches(LeadRequest $lead, int $accountId): array
    {
        $normalizedEmail = $this->normalizeEmail($lead->contact_email);
        $normalizedPhone = $this->normalizePhone($lead->contact_phone);
        $normalizedContactName = $this->normalizeText($lead->contact_name);
        $normalizedCompanyName = $this->normalizeText($lead->companyName());
        $normalizedStreet = $this->normalizeText(implode(' ', array_filter([$lead->street1, $lead->street2])));
        $normalizedCity = $this->normalizeText($lead->city);
        $normalizedPostalCode = $this->normalizeText($lead->postal_code);

        if (
            ! $normalizedEmail
            && ! $normalizedPhone
            && ! $normalizedContactName
            && ! $normalizedCompanyName
            && ! $normalizedStreet
            && ! $normalizedCity
            && ! $normalizedPostalCode
        ) {
            return [];
        }

        $nameTokens = array_values(array_filter(preg_split('/\s+/', (string) $normalizedContactName) ?: []));
        $phoneTail = $normalizedPhone ? substr($normalizedPhone, -7) : null;

        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->with(['defaultProperty:id,customer_id,street1,street2,city,state,zip,country,is_default'])
            ->where(function ($query) use ($nameTokens, $normalizedCity, $normalizedCompanyName, $normalizedEmail, $normalizedPostalCode, $normalizedStreet, $phoneTail) {
                if ($normalizedEmail) {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }

                if ($phoneTail) {
                    $query->orWhere('phone', 'like', '%'.$phoneTail.'%');
                }

                if ($normalizedCompanyName) {
                    $companySearch = str_replace(' ', '%', $normalizedCompanyName);
                    $query->orWhere('company_name', 'like', '%'.$companySearch.'%');
                }

                if ($nameTokens !== []) {
                    $query->orWhere(function ($nameQuery) use ($nameTokens) {
                        foreach ($nameTokens as $token) {
                            $nameQuery->where(function ($nested) use ($token) {
                                $nested->where('first_name', 'like', '%'.$token.'%')
                                    ->orWhere('last_name', 'like', '%'.$token.'%');
                            });
                        }
                    });
                }

                if ($normalizedStreet || $normalizedCity || $normalizedPostalCode) {
                    $query->orWhereHas('defaultProperty', function ($propertyQuery) use ($normalizedCity, $normalizedPostalCode, $normalizedStreet) {
                        if ($normalizedStreet) {
                            $streetSearch = str_replace(' ', '%', $normalizedStreet);
                            $propertyQuery->where(function ($nested) use ($streetSearch) {
                                $nested->where('street1', 'like', '%'.$streetSearch.'%')
                                    ->orWhere('street2', 'like', '%'.$streetSearch.'%');
                            });
                        }

                        if ($normalizedCity) {
                            $propertyQuery->orWhere('city', 'like', '%'.$normalizedCity.'%');
                        }

                        if ($normalizedPostalCode) {
                            $propertyQuery->orWhere('zip', 'like', '%'.$normalizedPostalCode.'%');
                        }
                    });
                }
            })
            ->limit(12)
            ->get([
                'id',
                'user_id',
                'number',
                'company_name',
                'first_name',
                'last_name',
                'email',
                'phone',
            ]);

        return $customers
            ->map(function (Customer $customer) use ($lead) {
                $match = $this->buildCustomerMatchPayload($lead, $customer);

                return $match ? $match : null;
            })
            ->filter()
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildCustomerMatchPayload(LeadRequest $lead, Customer $customer): ?array
    {
        $score = 0;
        $reasons = [];

        $leadEmail = $this->normalizeEmail($lead->contact_email);
        $leadPhone = $this->normalizePhone($lead->contact_phone);
        $leadContactName = $this->normalizeText($lead->contact_name);
        $leadCompanyName = $this->normalizeText($lead->companyName());
        $leadStreet = $this->normalizeText(implode(' ', array_filter([$lead->street1, $lead->street2])));
        $leadCity = $this->normalizeText($lead->city);
        $leadPostalCode = $this->normalizeText($lead->postal_code);

        $customerEmail = $this->normalizeEmail($customer->email);
        $customerPhone = $this->normalizePhone($customer->phone);
        $customerFullName = $this->normalizeText(trim($customer->first_name.' '.$customer->last_name));
        $customerCompanyName = $this->normalizeText($customer->company_name);
        $property = $customer->defaultProperty;
        $customerStreet = $this->normalizeText(implode(' ', array_filter([$property?->street1, $property?->street2])));
        $customerCity = $this->normalizeText($property?->city);
        $customerPostalCode = $this->normalizeText($property?->zip);

        if ($leadEmail && $customerEmail && $leadEmail === $customerEmail) {
            $score += 100;
            $reasons[] = $this->conversionMatchReason('email_exact', 'Email exact', 100);
        }

        if ($leadPhone && $customerPhone && $leadPhone === $customerPhone) {
            $score += 90;
            $reasons[] = $this->conversionMatchReason('phone_exact', 'Telephone exact', 90);
        }

        if ($leadCompanyName && $customerCompanyName && $leadCompanyName === $customerCompanyName) {
            $score += 70;
            $reasons[] = $this->conversionMatchReason('company_exact', 'Entreprise exacte', 70);
        }

        if ($leadContactName && $customerFullName && $leadContactName === $customerFullName) {
            $score += 60;
            $reasons[] = $this->conversionMatchReason('name_exact', 'Contact exact', 60);
        }

        if ($leadStreet && $customerStreet && $leadStreet === $customerStreet) {
            $score += 35;
            $reasons[] = $this->conversionMatchReason('street_exact', 'Adresse exacte', 35);
        }

        if ($leadPostalCode && $customerPostalCode && $leadPostalCode === $customerPostalCode) {
            $score += 25;
            $reasons[] = $this->conversionMatchReason('postal_exact', 'Code postal exact', 25);
        }

        if ($leadCity && $customerCity && $leadCity === $customerCity) {
            $score += 20;
            $reasons[] = $this->conversionMatchReason('city_exact', 'Ville exacte', 20);
        }

        if ($score === 0) {
            return null;
        }

        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'display_name' => $this->displayCustomerName($customer),
            'company_name' => $customer->company_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'score' => $score,
            'match_reasons' => $reasons,
            'default_property' => $property ? [
                'id' => $property->id,
                'street1' => $property->street1,
                'street2' => $property->street2,
                'city' => $property->city,
                'state' => $property->state,
                'zip' => $property->zip,
                'country' => $property->country,
                'full_address' => implode(', ', array_filter([
                    $property->street1,
                    $property->street2,
                    $property->city,
                    $property->state,
                    $property->zip,
                    $property->country,
                ])),
            ] : null,
        ];
    }

    /**
     * @return array{code:string,label:string,weight:int}
     */
    private function conversionMatchReason(string $code, string $label, int $weight): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'weight' => $weight,
        ];
    }

    private function displayCustomerName(Customer $customer): string
    {
        return $customer->company_name
            ?: trim($customer->first_name.' '.$customer->last_name)
            ?: 'Customer';
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits !== '' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    private function normalizeText(mixed $value): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $value))) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

}
