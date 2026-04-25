<?php

namespace App\Http\Controllers;

use App\Actions\Leads\ConvertLeadRequestToQuoteAction;
use App\Http\Requests\Leads\BulkUpdateLeadRequest;
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
use App\Models\TeamMember;
use App\Queries\Requests\BuildRequestAnalyticsData;
use App\Queries\Requests\BuildRequestInboxIndexData;
use App\Services\Campaigns\CampaignLeadAttributionService;
use App\Services\UsageLimitService;
use App\Support\Prospects\ProspectIntakeMeta;
use App\Support\BulkActions\BulkActionRegistry;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

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

        $savedSegments = SavedSegment::query()
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
            ]);

        return $this->inertiaOrJson('Request/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => $stats,
            'customers' => $customers,
            'statuses' => $statuses,
            'assignees' => $assignees,
            'bulkActions' => app(BulkActionRegistry::class)->definitionFor('request', [
                'statuses' => $statuses,
                'assignees' => $assignees,
            ]),
            'savedSegments' => $savedSegments,
            'canManageSavedSegments' => true,
            'lead_intake' => $leadIntake,
            'analytics' => app(BuildRequestAnalyticsData::class)->execute($accountId),
        ]);
    }

    public function show(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
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

        $statuses = Prospect::statusOptions();

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

        $campaignOrigin = app(CampaignLeadAttributionService::class)->campaignOriginForLead($lead, $user);

        return $this->inertiaOrJson('Request/Show', [
            'lead' => $lead,
            'activity' => $activity,
            'statuses' => $statuses,
            'assignees' => $assignees,
            'duplicates' => $duplicates,
            'campaignOrigin' => $campaignOrigin,
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

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $validated = $request->validated();
        $customerId = $validated['customer_id'] ?? null;
        $channel = $this->normalizeChannel($validated['channel'] ?? null) ?? 'manual';
        $meta = ProspectIntakeMeta::merge(
            $validated['meta'] ?? null,
            source: $channel,
            requestType: data_get($validated, 'meta.request_type') ?? 'manual_entry',
            contactConsent: data_get($validated, 'meta.contact_consent'),
            marketingConsent: data_get($validated, 'meta.marketing_consent')
        );

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
    public function import(ImportLeadRequestsRequest $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }
        $accountId = $user->accountOwnerId();

        $data = $request->validated();

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
    public function convert(ConvertLeadToQuoteRequest $request, LeadRequest $lead, ConvertLeadRequestToQuoteAction $convertLead)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $result = $convertLead->execute($lead, $request->validated(), $user);
        $quote = $result['quote'];
        $lead = $result['lead'];

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Request converted to quote.',
                'quote' => $quote,
                'request' => $lead->fresh(),
            ]);
        }

        return redirect()->route('customer.quote.edit', $quote)->with('success', 'Request converted to quote.');
    }

    public function update(UpdateLeadRequest $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(403);
        }

        $validated = $request->validated();

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

    public function bulkUpdate(BulkUpdateLeadRequest $request)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        $validated = $request->validated();

        $status = $validated['status'] ?? null;
        $hasAssignee = array_key_exists('assigned_team_member_id', $validated);

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

        $leadIds = collect($validated['ids'])->unique()->values();
        $leads = LeadRequest::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $leadIds)
            ->get();
        $processedIds = $leads->pluck('id')->all();

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
            return response()->json($this->bulkActionResult(
                'Requests updated.',
                $leadIds->all(),
                $processedIds,
                ['updated' => $leads->count()]
            ));
        }

        return redirect()->back()->with('success', 'Requests updated.');
    }

    public function merge(MergeLeadRequest $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
            abort(403);
        }

        if ($lead->user_id !== $accountId) {
            abort(404);
        }

        $validated = $request->validated();
        $sourceId = (int) $validated['source_id'];

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

        if ($source->quote && ! $lead->quote) {
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

        return redirect()->route('prospects.show', $lead)->with('success', 'Request merged.');
    }

    public function destroy(Request $request, LeadRequest $lead)
    {
        $user = $request->user();
        $accountId = $user?->accountOwnerId() ?? Auth::id();

        if (! $user || $user->id !== $accountId) {
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

}
