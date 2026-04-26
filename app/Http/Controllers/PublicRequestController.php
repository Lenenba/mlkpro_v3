<?php

namespace App\Http\Controllers;

use App\Jobs\RetryLeadQuoteEmailJob;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\LeadCallRequestReceivedNotification;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\LeadQuoteRequestReceivedNotification;
use App\Notifications\SendQuoteNotification;
use App\Services\Campaigns\CampaignLeadAttributionService;
use App\Services\CompanyFeatureService;
use App\Services\CRM\OutgoingEmailLogService;
use App\Services\LeadServiceSuggestionService;
use App\Services\Prospects\ProspectDuplicateAlertService;
use App\Services\ProspectStatusHistoryService;
use App\Services\ServiceRequests\ServiceRequestIntakeService;
use App\Services\TrackingService;
use App\Services\UsageLimitService;
use App\Support\NotificationDispatcher;
use App\Support\Prospects\ProspectIntakeMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PublicRequestController extends Controller
{
    private const FINAL_ACTION_REQUEST_CALL = 'request_call';

    private const FINAL_ACTION_RECEIVE_QUOTE = 'receive_quote';

    public function show(
        Request $request,
        User $user,
        LeadServiceSuggestionService $suggestionService,
        CampaignLeadAttributionService $leadAttributionService,
    ): Response {
        $this->assertLeadIntakeEnabled($user);
        $leadAttributionService->syncPublicFormAttribution($request, $user);

        app(TrackingService::class)->record('lead_form_view', $user->id);

        return Inertia::render('Public/RequestForm', [
            'company' => [
                'id' => $user->id,
                'name' => $user->company_name ?: $user->name,
                'logo_url' => $user->company_logo_url,
                'currency_code' => $user->businessCurrencyCode(),
                'phone' => $user->phone_number ?: config('app.support_phone'),
            ],
            'embed' => $request->boolean('embed'),
            'submit_url' => URL::signedRoute('public.requests.store', ['user' => $user->id]),
            'suggest_url' => URL::signedRoute('public.requests.suggest', ['user' => $user->id]),
            'address_search_url' => URL::signedRoute('public.requests.address-search', ['user' => $user->id]),
            'catalog_services' => $suggestionService->catalogServices($user),
            'intent_options' => $suggestionService->intentOptions(),
            'quote_question_catalog' => $suggestionService->quoteQuestionCatalog(),
        ]);
    }

    public function addressSearch(Request $request, User $user)
    {
        $this->assertLeadIntakeEnabled($user);

        $validated = $request->validate([
            'text' => 'required|string|min:2|max:255',
            'limit' => 'nullable|integer|min:1|max:10',
        ]);

        $apiKey = trim((string) config('services.geoapify.key', ''));
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Geoapify key missing.',
                'suggestions' => [],
            ], 422);
        }

        $text = trim((string) ($validated['text'] ?? ''));
        $limit = (int) ($validated['limit'] ?? 5);
        $endpoint = 'https://api.geoapify.com/v1/geocode/autocomplete';

        $attempts = [
            [
                'text' => $text,
                'apiKey' => $apiKey,
                'limit' => (string) $limit,
                'filter' => 'countrycode:ca,us,fr,be,ch,ma,tn',
            ],
            [
                'text' => $text,
                'apiKey' => $apiKey,
                'limit' => (string) $limit,
            ],
        ];

        foreach ($attempts as $index => $params) {
            try {
                $response = Http::timeout(8)->acceptJson()->get($endpoint, $params);
            } catch (\Throwable $exception) {
                if ($index < count($attempts) - 1) {
                    continue;
                }

                return response()->json([
                    'message' => 'Geoapify lookup failed.',
                    'suggestions' => [],
                ], 502);
            }

            if (! $response->ok()) {
                if ($index < count($attempts) - 1) {
                    continue;
                }

                return response()->json([
                    'message' => 'Geoapify lookup failed.',
                    'status' => $response->status(),
                    'suggestions' => [],
                ], 502);
            }

            $features = (array) $response->json('features', []);
            if (empty($features) && $index < count($attempts) - 1) {
                continue;
            }

            $suggestions = collect($features)
                ->map(function ($feature) {
                    $details = (array) data_get($feature, 'properties', []);
                    $label = trim((string) ($details['formatted'] ?? $details['name'] ?? ''));

                    if ($label === '') {
                        return null;
                    }

                    return [
                        'id' => $details['place_id'] ?? $label,
                        'label' => $label,
                        'details' => $details,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            return response()->json([
                'suggestions' => $suggestions,
            ]);
        }

        return response()->json([
            'message' => 'Address lookup unavailable.',
            'suggestions' => [],
        ], 502);
    }

    public function suggest(
        Request $request,
        User $user,
        LeadServiceSuggestionService $suggestionService
    ) {
        $this->assertLeadIntakeEnabled($user);

        $validated = $request->validate([
            'service_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'intent_tags' => 'nullable|array|max:10',
            'intent_tags.*' => 'nullable|string|max:40',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        return response()->json(
            $suggestionService->suggest(
                owner: $user,
                serviceType: $validated['service_type'] ?? null,
                description: $validated['description'] ?? null,
                intentTags: $validated['intent_tags'] ?? [],
                limit: (int) ($validated['limit'] ?? 8),
            )
        );
    }

    public function store(
        Request $request,
        User $user,
        LeadServiceSuggestionService $suggestionService,
        CampaignLeadAttributionService $leadAttributionService,
        ServiceRequestIntakeService $serviceRequestIntakeService,
    ) {
        $this->assertLeadIntakeEnabled($user);

        $validated = $request->validate([
            'ignore_duplicates' => 'nullable|boolean',
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'service_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'street1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:120',
            'intent_tags' => 'nullable|array|max:10',
            'intent_tags.*' => 'nullable|string|max:40',
            'suggested_service_ids' => 'nullable|array|max:20',
            'suggested_service_ids.*' => 'integer',
            'services_sur_devis' => 'nullable|array|max:20',
            'services_sur_devis.*' => 'integer',
            'qualification_answers' => 'nullable|array|max:50',
            'qualification_answers.*' => 'nullable|string|max:2000',
            'quote_assumptions' => 'nullable|string|max:5000',
            'final_action' => ['nullable', 'string', Rule::in([
                self::FINAL_ACTION_REQUEST_CALL,
                self::FINAL_ACTION_RECEIVE_QUOTE,
            ])],
        ]);

        $ignoreDuplicates = (bool) ($validated['ignore_duplicates'] ?? false);
        $finalAction = (string) ($validated['final_action'] ?? self::FINAL_ACTION_REQUEST_CALL);

        if (
            $finalAction === self::FINAL_ACTION_RECEIVE_QUOTE
            && ! app(CompanyFeatureService::class)->hasFeature($user, 'quotes')
        ) {
            throw ValidationException::withMessages([
                'final_action' => ['Quote generation is unavailable for this company.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $title = $validated['service_type']
            ?? $validated['contact_name'];

        $meta = [];
        if ($finalAction === self::FINAL_ACTION_REQUEST_CALL) {
            $meta['lead_stage'] = 'call_requested';
        }

        $suggestedServiceIds = $suggestionService->filterValidServiceIds(
            $user,
            $validated['suggested_service_ids'] ?? []
        );
        if (! empty($suggestedServiceIds)) {
            $meta['suggested_service_ids'] = $suggestedServiceIds;
        }

        if ($finalAction === self::FINAL_ACTION_RECEIVE_QUOTE && empty($suggestedServiceIds)) {
            throw ValidationException::withMessages([
                'suggested_service_ids' => ['Select at least one service to receive a quote.'],
            ]);
        }

        $servicesSurDevis = $suggestionService->filterValidServiceIds(
            $user,
            $validated['services_sur_devis'] ?? []
        );
        if (! empty($servicesSurDevis)) {
            $selectedLookup = array_flip($suggestedServiceIds);
            $meta['services_sur_devis'] = array_values(array_filter(
                $servicesSurDevis,
                fn ($serviceId) => array_key_exists((int) $serviceId, $selectedLookup)
            ));
        }

        $intentTags = $suggestionService->sanitizeIntentTags($validated['intent_tags'] ?? []);
        $qualificationAnswers = $suggestionService->sanitizeQualificationAnswers(
            $validated['qualification_answers'] ?? [],
            $intentTags
        );
        $missingInformation = $suggestionService->missingQuoteInformation(
            $intentTags,
            $qualificationAnswers
        );
        $assumptions = trim((string) ($validated['quote_assumptions'] ?? ''));

        if (! empty($intentTags)) {
            $meta['intent_tags'] = $intentTags;
        }
        if (! empty($qualificationAnswers)) {
            $meta['qualification_answers'] = $qualificationAnswers;
        }
        if (! empty($missingInformation)) {
            $meta['missing_information'] = $missingInformation;
        }
        if ($assumptions !== '') {
            $meta['quote_assumptions'] = $assumptions;
        }

        $meta['final_action'] = $finalAction;
        $meta = $leadAttributionService->mergeLeadMeta(
            $meta,
            $leadAttributionService->buildInboundAttributionMeta($request, $user)
        );
        $meta = ProspectIntakeMeta::merge(
            $meta,
            source: 'web_form',
            requestType: $finalAction === self::FINAL_ACTION_RECEIVE_QUOTE ? 'quote_request' : 'contact_request',
            contactConsent: true,
            marketingConsent: false
        );

        if ($this->shouldReturnJson($request) && ! $ignoreDuplicates) {
            $duplicateAlert = app(ProspectDuplicateAlertService::class)->forAttributes(
                accountId: $user->id,
                attributes: [
                    'title' => $title,
                    'service_type' => $validated['service_type'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_email' => $validated['contact_email'] ?? null,
                    'contact_phone' => $validated['contact_phone'] ?? null,
                    'street1' => $validated['street1'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'state' => $validated['state'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'country' => $validated['country'] ?? null,
                    'meta' => $meta,
                ],
                context: 'public_create',
            );

            if ($duplicateAlert) {
                $duplicateAlert['entries'] = collect($duplicateAlert['entries'] ?? [])
                    ->map(fn (array $entry) => [
                        'key' => $entry['key'] ?? uniqid('public-duplicate-', false),
                        'row_number' => $entry['row_number'] ?? null,
                        'label' => $entry['label'] ?? 'Request draft',
                        'subtitle' => $entry['subtitle'] ?? null,
                        'match_count' => (int) ($entry['match_count'] ?? 0),
                        'strongest_score' => (int) ($entry['strongest_score'] ?? 0),
                        'duplicates' => [],
                    ])
                    ->values()
                    ->all();

                return response()->json([
                    'message' => 'A similar request may already exist. Review the warning before continuing.',
                    'duplicate_alert' => $duplicateAlert,
                ], 409);
            }
        }

        $lead = LeadRequest::create([
            'user_id' => $user->id,
            'customer_id' => null,
            'channel' => 'web_form',
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'title' => $title,
            'service_type' => $validated['service_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'contact_name' => $validated['contact_name'],
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'street1' => $validated['street1'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $validated['state'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'country' => $validated['country'] ?? null,
            'meta' => $meta ?: null,
        ]);

        ActivityLog::record(null, $lead, 'created', [
            'channel' => 'web_form',
        ], 'Public lead created');
        app(ProspectStatusHistoryService::class)->record($lead, null, [
            'to_status' => $lead->status,
            'metadata' => ['source' => 'public_form'],
        ]);

        if ($finalAction === self::FINAL_ACTION_RECEIVE_QUOTE) {
            app(UsageLimitService::class)->enforceLimit($user, 'quotes');

            $quote = $this->createQuoteFromLead(
                owner: $user,
                lead: $lead,
                selectedServiceIds: $suggestedServiceIds,
                servicesSurDevis: $meta['services_sur_devis'] ?? [],
                intentTags: $intentTags,
                qualificationAnswers: $qualificationAnswers,
                missingInformation: $missingInformation,
                assumptions: $assumptions
            );

            ActivityLog::record(null, $quote, 'created', [
                'source' => 'lead_form',
                'request_id' => $lead->id,
                'customer_id' => $quote->customer_id,
            ], 'Quote created from public lead form');

            ActivityLog::record(null, $lead, 'quote_created_from_lead_form', [
                'quote_id' => $quote->id,
            ], 'Quote created from lead form');

            $quoteEmailQueued = NotificationDispatcher::sendToMail($lead->contact_email, new SendQuoteNotification($quote), [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'email' => $lead->contact_email,
                'source' => 'lead_form',
            ]);

            $emailLogger = app(OutgoingEmailLogService::class);
            if ($quoteEmailQueued) {
                $emailLogger->logSent(null, $quote, [
                    'email' => $lead->contact_email,
                    'source' => 'lead_form',
                    'notification' => SendQuoteNotification::class,
                ], 'Quote email sent');
            } else {
                $emailLogger->logFailed(null, $quote, [
                    'email' => $lead->contact_email,
                    'source' => 'lead_form',
                    'notification' => SendQuoteNotification::class,
                ], 'Quote email failed');
                $emailLogger->logFailed(null, $lead, [
                    'quote_id' => $quote->id,
                    'customer_id' => $quote->customer_id,
                    'email' => $lead->contact_email,
                    'source' => 'lead_form',
                    'notification' => SendQuoteNotification::class,
                ], 'Quote email failed');
                $this->scheduleQuoteEmailRetry($quote, $lead, 1);
            }

            $prospectSummaryEmailQueued = NotificationDispatcher::sendToMail(
                $lead->contact_email,
                new LeadQuoteRequestReceivedNotification($user, $lead, $quote, $quoteEmailQueued),
                [
                    'request_id' => $lead->id,
                    'quote_id' => $quote->id,
                    'event' => 'lead_quote_request_received',
                ]
            );

            if ($prospectSummaryEmailQueued) {
                $emailLogger->logSent(null, $lead, [
                    'email' => $lead->contact_email,
                    'quote_id' => $quote->id,
                    'customer_id' => $quote->customer_id,
                    'source' => 'lead_form',
                    'event' => 'lead_quote_request_received',
                    'notification' => LeadQuoteRequestReceivedNotification::class,
                ], 'Lead quote request summary email sent');
            } else {
                $emailLogger->logFailed(null, $lead, [
                    'email' => $lead->contact_email,
                    'quote_id' => $quote->id,
                    'customer_id' => $quote->customer_id,
                    'source' => 'lead_form',
                    'event' => 'lead_quote_request_received',
                    'notification' => LeadQuoteRequestReceivedNotification::class,
                ], 'Lead quote request summary email failed');
            }

            if ($quote->status === 'draft') {
                $quote->update(['status' => 'sent']);
                ActivityLog::record(null, $quote, 'status_changed', [
                    'from' => 'draft',
                    'to' => 'sent',
                ], 'Quote status updated');
            }

            $quote->refresh();
            $quote->syncRequestStatusFromQuote();
            $lead->refresh();
            $serviceRequest = $serviceRequestIntakeService->createFromLead($lead, [
                'source' => 'public_form',
                'channel' => 'web',
                'request_type' => $finalAction === self::FINAL_ACTION_RECEIVE_QUOTE ? 'quote_request' : 'contact_request',
                'meta' => [
                    'final_action' => $finalAction,
                    'quote_id' => $quote->id,
                ],
            ]);

            NotificationDispatcher::send($user, new LeadFormOwnerNotification(
                event: 'quote_created_from_lead_form',
                lead: $lead,
                quote: $quote
            ), [
                'request_id' => $lead->id,
                'quote_id' => $quote->id,
                'source' => 'lead_form',
            ]);

            if (! $quoteEmailQueued) {
                NotificationDispatcher::send($user, new LeadFormOwnerNotification(
                    event: 'lead_email_failed',
                    lead: $lead,
                    quote: $quote,
                    sendMail: false
                ), [
                    'request_id' => $lead->id,
                    'quote_id' => $quote->id,
                    'source' => 'lead_form',
                ]);
            }

            app(TrackingService::class)->record('lead_form_submit', $user->id, [
                'lead_id' => $lead->id,
                'quote_id' => $quote->id,
                'final_action' => $finalAction,
                'campaign_id' => data_get($lead->meta, 'source_campaign_id'),
            ]);
            $leadAttributionService->forgetAttribution($request, $user);

            $responseTone = 'success';
            $responseMessage = 'Quote created and confirmation sent successfully.';

            if (! $quoteEmailQueued && ! $prospectSummaryEmailQueued) {
                $responseTone = 'warning';
                $responseMessage = 'Quote created, but both quote and confirmation emails failed.';
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => $responseMessage,
                        'tone' => $responseTone,
                        'request' => $lead->fresh(),
                        'quote' => $quote,
                        'service_request' => $serviceRequest,
                    ], 201);
                }

                return redirect()->back()->with('warning', 'Quote created, but both quote and confirmation emails failed.');
            }

            if (! $quoteEmailQueued) {
                $responseTone = 'warning';
                $responseMessage = 'Quote created. Confirmation email sent, but quote email failed.';
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => $responseMessage,
                        'tone' => $responseTone,
                        'request' => $lead->fresh(),
                        'quote' => $quote,
                        'service_request' => $serviceRequest,
                    ], 201);
                }

                return redirect()->back()->with('warning', 'Quote created. Confirmation email sent, but quote email failed.');
            }

            if (! $prospectSummaryEmailQueued) {
                $responseTone = 'warning';
                $responseMessage = 'Quote created and sent, but confirmation email failed.';
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => $responseMessage,
                        'tone' => $responseTone,
                        'request' => $lead->fresh(),
                        'quote' => $quote,
                        'service_request' => $serviceRequest,
                    ], 201);
                }

                return redirect()->back()->with('warning', 'Quote created and sent, but confirmation email failed.');
            }

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => $responseMessage,
                    'tone' => $responseTone,
                    'request' => $lead->fresh(),
                    'quote' => $quote,
                    'service_request' => $serviceRequest,
                ], 201);
            }

            return redirect()->back()->with('success', 'Quote created and confirmation sent successfully.');
        }

        $previousStatus = $lead->status;
        $lead->update([
            'status' => LeadRequest::STATUS_CALL_REQUESTED,
            'status_updated_at' => now(),
            'last_activity_at' => now(),
            'next_follow_up_at' => $lead->next_follow_up_at ?? now()->addDay(),
        ]);
        app(ProspectStatusHistoryService::class)->record($lead, null, [
            'from_status' => $previousStatus,
            'to_status' => $lead->status,
            'metadata' => ['source' => 'public_form'],
        ]);

        $task = $this->createLeadQualificationTask($user, $lead, $suggestedServiceIds);
        ActivityLog::record(null, $lead, 'lead_call_requested', [
            'task_id' => $task?->id,
        ], 'Call requested from lead form');

        $prospectEmailQueued = NotificationDispatcher::sendToMail(
            $lead->contact_email,
            new LeadCallRequestReceivedNotification($user, $lead),
            [
                'request_id' => $lead->id,
                'event' => 'lead_call_requested',
            ]
        );

        if ($prospectEmailQueued) {
            app(OutgoingEmailLogService::class)->logSent(null, $lead, [
                'email' => $lead->contact_email,
                'source' => 'lead_form',
                'event' => 'lead_call_requested',
                'notification' => LeadCallRequestReceivedNotification::class,
            ], 'Lead call request email sent');
        } else {
            app(OutgoingEmailLogService::class)->logFailed(null, $lead, [
                'email' => $lead->contact_email,
                'source' => 'lead_form',
                'event' => 'lead_call_requested',
                'notification' => LeadCallRequestReceivedNotification::class,
            ], 'Lead call request email failed');
        }

        NotificationDispatcher::send($user, new LeadFormOwnerNotification(
            event: 'lead_call_requested',
            lead: $lead
        ), [
            'request_id' => $lead->id,
            'event' => 'lead_call_requested',
        ]);

        if (! $prospectEmailQueued) {
            NotificationDispatcher::send($user, new LeadFormOwnerNotification(
                event: 'lead_email_failed',
                lead: $lead,
                sendMail: false
            ), [
                'request_id' => $lead->id,
                'event' => 'lead_call_requested',
            ]);
        }

        app(TrackingService::class)->record('lead_form_submit', $user->id, [
            'lead_id' => $lead->id,
            'final_action' => $finalAction,
            'campaign_id' => data_get($lead->meta, 'source_campaign_id'),
        ]);
        $leadAttributionService->forgetAttribution($request, $user);
        $serviceRequest = $serviceRequestIntakeService->createFromLead($lead, [
            'source' => 'public_form',
            'channel' => 'web',
            'request_type' => 'contact_request',
            'meta' => [
                'final_action' => $finalAction,
                'task_id' => $task?->id,
            ],
        ]);

        if (! $prospectEmailQueued) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'Call request recorded, but confirmation email failed.',
                    'tone' => 'warning',
                    'request' => $lead->fresh(),
                    'service_request' => $serviceRequest,
                ], 201);
            }

            return redirect()->back()->with('warning', 'Call request recorded, but confirmation email failed.');
        }

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Call request submitted successfully.',
                'tone' => 'success',
                'request' => $lead->fresh(),
                'service_request' => $serviceRequest,
            ], 201);
        }

        return redirect()->back()->with('success', 'Call request submitted successfully.');
    }

    private function assertLeadIntakeEnabled(User $user): void
    {
        if ($user->isSuspended()) {
            abort(404);
        }

        $hasFeature = app(CompanyFeatureService::class)->hasFeature($user, 'requests');
        if (! $hasFeature) {
            abort(404);
        }
    }

    private function createQuoteFromLead(
        User $owner,
        LeadRequest $lead,
        array $selectedServiceIds,
        array $servicesSurDevis,
        array $intentTags,
        array $qualificationAnswers,
        array $missingInformation,
        string $assumptions
    ): Quote {
        $services = Product::query()
            ->services()
            ->byUser($owner->id)
            ->where('is_active', true)
            ->whereIn('id', $selectedServiceIds)
            ->get(['id', 'name', 'description', 'price'])
            ->keyBy('id');

        $orderedServices = collect($selectedServiceIds)
            ->map(fn ($id) => $services->get((int) $id))
            ->filter();

        if ($orderedServices->isEmpty()) {
            throw ValidationException::withMessages([
                'suggested_service_ids' => ['No valid services are available to build this quote.'],
            ]);
        }

        $surDevisLookup = array_flip(array_map(fn ($id) => (int) $id, $servicesSurDevis));

        $lineItems = $orderedServices->map(function (Product $service) use (
            $lead,
            $intentTags,
            $qualificationAnswers,
            $missingInformation,
            $assumptions,
            $surDevisLookup
        ) {
            $serviceId = (int) $service->id;
            $isSurDevis = array_key_exists($serviceId, $surDevisLookup);
            $price = $isSurDevis ? 0.0 : (float) ($service->price ?? 0);
            $descriptionParts = array_filter([
                trim((string) ($service->description ?? '')),
                $isSurDevis ? 'Sur devis' : null,
            ]);

            $sourceDetails = [
                'origin' => 'lead_form',
                'lead_id' => $lead->id,
                'service_id' => $serviceId,
                'service_name' => (string) $service->name,
                'intent_tags' => array_values($intentTags),
                'sur_devis' => $isSurDevis,
            ];
            if (! empty($qualificationAnswers)) {
                $sourceDetails['qualification_answers'] = $qualificationAnswers;
            }
            if (! empty($missingInformation)) {
                $sourceDetails['missing_information'] = collect($missingInformation)
                    ->map(fn ($row) => (string) ($row['id'] ?? ''))
                    ->filter()
                    ->values()
                    ->all();
            }
            if ($assumptions !== '') {
                $sourceDetails['quote_assumptions'] = $assumptions;
            }

            return [
                'id' => $serviceId,
                'quantity' => 1,
                'price' => round($price, 2),
                'total' => round($price, 2),
                'description' => ! empty($descriptionParts) ? implode(' | ', $descriptionParts) : null,
                'source_details' => $sourceDetails,
            ];
        })->values();

        $subtotal = round((float) $lineItems->sum('total'), 2);
        $jobTitle = trim((string) ($lead->title ?: $lead->service_type ?: ('Lead quote #'.$lead->id)));

        $quote = Quote::query()->create([
            'user_id' => $owner->id,
            'customer_id' => null,
            'prospect_id' => $lead->id,
            'job_title' => $jobTitle !== '' ? $jobTitle : 'New Quote',
            'status' => 'draft',
            'request_id' => $lead->id,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'initial_deposit' => 0,
            'notes' => $lead->description,
            'messages' => $this->buildLeadQuoteContext(
                lead: $lead,
                intentTags: $intentTags,
                qualificationAnswers: $qualificationAnswers,
                missingInformation: $missingInformation,
                assumptions: $assumptions
            ),
        ]);

        $pivotData = $lineItems->mapWithKeys(function (array $item) {
            return [
                $item['id'] => [
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['total'],
                    'description' => $item['description'],
                    'source_details' => $item['source_details']
                        ? json_encode($item['source_details'])
                        : null,
                ],
            ];
        })->all();

        $quote->syncProductLines($pivotData);

        return $quote->fresh(['prospect.user', 'products']);
    }

    private function buildLeadQuoteContext(
        LeadRequest $lead,
        array $intentTags,
        array $qualificationAnswers,
        array $missingInformation,
        string $assumptions
    ): ?string {
        $lines = ['Source: public adaptive lead form'];

        $summary = trim((string) ($lead->description ?? ''));
        if ($summary !== '') {
            $lines[] = 'Need summary: '.$summary;
        }

        if (! empty($intentTags)) {
            $lines[] = 'Detected intents: '.implode(', ', $intentTags);
        }

        if (! empty($qualificationAnswers)) {
            $lines[] = 'Qualification answers:';
            foreach ($qualificationAnswers as $questionId => $answer) {
                $label = trim((string) $questionId);
                $value = trim((string) $answer);
                if ($label === '' || $value === '') {
                    continue;
                }

                $lines[] = $label.': '.$value;
            }
        }

        if (! empty($missingInformation)) {
            $missingLabels = collect($missingInformation)
                ->map(function (array $question) {
                    $label = trim((string) ($question['label'] ?? ''));
                    if ($label !== '') {
                        return $label;
                    }

                    return trim((string) ($question['id'] ?? ''));
                })
                ->filter()
                ->values()
                ->all();

            if (! empty($missingLabels)) {
                $lines[] = 'Missing information: '.implode(', ', $missingLabels);
            }
        }

        $normalizedAssumptions = trim($assumptions);
        if ($normalizedAssumptions !== '') {
            $lines[] = 'Assumptions: '.$normalizedAssumptions;
        }

        $payload = trim(implode("\n", $lines));

        return $payload !== '' ? $payload : null;
    }

    private function scheduleQuoteEmailRetry(Quote $quote, LeadRequest $lead, int $attempt): void
    {
        $delayMinutes = RetryLeadQuoteEmailJob::delayMinutesForAttempt($attempt);
        RetryLeadQuoteEmailJob::dispatch($quote->id, $lead->id, $attempt)
            ->delay(now()->addMinutes($delayMinutes));

        app(OutgoingEmailLogService::class)->logRetryScheduled(null, $lead, [
            'quote_id' => $quote->id,
            'customer_id' => $quote->customer_id,
            'attempt' => $attempt,
            'delay_minutes' => $delayMinutes,
            'source' => 'lead_form_retry',
        ], 'Quote email retry scheduled');
    }

    private function createLeadQualificationTask(User $owner, LeadRequest $lead, array $selectedServiceIds): ?Task
    {
        $serviceNames = Product::query()
            ->services()
            ->byUser($owner->id)
            ->whereIn('id', $selectedServiceIds)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        $descriptionLines = array_filter([
            'Lead submitted via public form and requested a call.',
            $lead->description ? 'Need summary: '.$lead->description : null,
            ! empty($serviceNames) ? 'Expected services: '.implode(', ', $serviceNames) : null,
            $lead->contact_name ? 'Contact: '.$lead->contact_name : null,
            $lead->contact_email ? 'Email: '.$lead->contact_email : null,
            $lead->contact_phone ? 'Phone: '.$lead->contact_phone : null,
        ]);

        $task = Task::query()->create([
            'account_id' => $owner->id,
            'created_by_user_id' => null,
            'customer_id' => $lead->customer_id,
            'request_id' => $lead->id,
            'title' => 'Qualifier le lead / Planifier appel',
            'description' => implode("\n", $descriptionLines),
            'status' => 'todo',
            'due_date' => now()->toDateString(),
        ]);

        ActivityLog::record(null, $lead, 'task_created', [
            'task_id' => $task->id,
        ], 'Lead qualification task created');

        return $task;
    }
}
