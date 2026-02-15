<?php

namespace App\Http\Controllers;

use App\Jobs\RetryLeadQuoteEmailJob;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use App\Notifications\LeadCallRequestReceivedNotification;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\SendQuoteNotification;
use App\Services\CompanyFeatureService;
use App\Services\LeadServiceSuggestionService;
use App\Services\TrackingService;
use App\Services\UsageLimitService;
use App\Support\NotificationDispatcher;
use Illuminate\Http\Request;
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
        LeadServiceSuggestionService $suggestionService
    ): Response
    {
        $this->assertLeadIntakeEnabled($user);

        app(TrackingService::class)->record('lead_form_view', $user->id);

        return Inertia::render('Public/RequestForm', [
            'company' => [
                'id' => $user->id,
                'name' => $user->company_name ?: $user->name,
                'logo_url' => $user->company_logo_url,
                'phone' => $user->phone_number ?: config('app.support_phone'),
            ],
            'submit_url' => URL::signedRoute('public.requests.store', ['user' => $user->id]),
            'suggest_url' => URL::signedRoute('public.requests.suggest', ['user' => $user->id]),
            'catalog_services' => $suggestionService->catalogServices($user),
            'intent_options' => $suggestionService->intentOptions(),
            'quote_question_catalog' => $suggestionService->quoteQuestionCatalog(),
        ]);
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
        LeadServiceSuggestionService $suggestionService
    )
    {
        $this->assertLeadIntakeEnabled($user);

        $validated = $request->validate([
            'contact_name' => 'required|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'service_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'street1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'postal_code' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:120',
            'suggested_service_ids' => 'nullable|array|max:20',
            'suggested_service_ids.*' => 'integer',
            'services_sur_devis' => 'nullable|array|max:20',
            'services_sur_devis.*' => 'integer',
            'final_action' => ['nullable', 'string', Rule::in([
                self::FINAL_ACTION_REQUEST_CALL,
                self::FINAL_ACTION_RECEIVE_QUOTE,
            ])],
        ]);

        $finalAction = (string) ($validated['final_action'] ?? self::FINAL_ACTION_REQUEST_CALL);

        if ($finalAction === self::FINAL_ACTION_RECEIVE_QUOTE && empty($validated['contact_email'])) {
            throw ValidationException::withMessages([
                'contact_email' => ['Email is required to receive a quote.'],
            ]);
        }

        if (
            $finalAction !== self::FINAL_ACTION_RECEIVE_QUOTE
            && empty($validated['contact_email'])
            && empty($validated['contact_phone'])
        ) {
            throw ValidationException::withMessages([
                'contact_email' => ['Email or phone is required.'],
            ]);
        }

        if (
            $finalAction === self::FINAL_ACTION_RECEIVE_QUOTE
            && !app(CompanyFeatureService::class)->hasFeature($user, 'quotes')
        ) {
            throw ValidationException::withMessages([
                'final_action' => ['Quote generation is unavailable for this company.'],
            ]);
        }

        app(UsageLimitService::class)->enforceLimit($user, 'requests');

        $title = $validated['service_type']
            ?? $validated['contact_name'];

        $customerId = $this->resolveCustomerId(
            $user->id,
            $validated['contact_email'] ?? null,
            $validated['contact_phone'] ?? null
        );

        $meta = [];
        if ($finalAction === self::FINAL_ACTION_REQUEST_CALL) {
            $meta['lead_stage'] = 'call_requested';
        }

        $suggestedServiceIds = $suggestionService->filterValidServiceIds(
            $user,
            $validated['suggested_service_ids'] ?? []
        );
        if (!empty($suggestedServiceIds)) {
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
        if (!empty($servicesSurDevis)) {
            $selectedLookup = array_flip($suggestedServiceIds);
            $meta['services_sur_devis'] = array_values(array_filter(
                $servicesSurDevis,
                fn ($serviceId) => array_key_exists((int) $serviceId, $selectedLookup)
            ));
        }

        $intentTags = [];
        $qualificationAnswers = [];
        $missingInformation = [];
        $assumptions = '';
        $meta['final_action'] = $finalAction;

        $lead = LeadRequest::create([
            'user_id' => $user->id,
            'customer_id' => $customerId,
            'channel' => 'web_form',
            'status' => LeadRequest::STATUS_NEW,
            'status_updated_at' => now(),
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

        if ($finalAction === self::FINAL_ACTION_RECEIVE_QUOTE) {
            app(UsageLimitService::class)->enforceLimit($user, 'quotes');

            $customer = $this->resolveOrCreateCustomerForLead($user, $validated, $lead);
            if ((int) ($lead->customer_id ?? 0) !== (int) $customer->id) {
                $lead->update([
                    'customer_id' => $customer->id,
                ]);
            }

            $lead->update([
                'converted_at' => now(),
            ]);

            $quote = $this->createQuoteFromLead(
                owner: $user,
                lead: $lead,
                customer: $customer,
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

            $quoteEmailQueued = NotificationDispatcher::send($customer, new SendQuoteNotification($quote), [
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'email' => $customer->email,
                'source' => 'lead_form',
            ]);

            if ($quoteEmailQueued) {
                ActivityLog::record(null, $quote, 'email_sent', [
                    'email' => $customer->email,
                    'source' => 'lead_form',
                ], 'Quote email sent');
            } else {
                ActivityLog::record(null, $quote, 'email_failed', [
                    'email' => $customer->email,
                    'source' => 'lead_form',
                ], 'Quote email failed');
                ActivityLog::record(null, $lead, 'lead_email_failed', [
                    'quote_id' => $quote->id,
                    'email' => $customer->email,
                ], 'Quote email failed');
                $this->scheduleQuoteEmailRetry($quote, $lead, 1);
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

            NotificationDispatcher::send($user, new LeadFormOwnerNotification(
                event: 'quote_created_from_lead_form',
                lead: $lead,
                quote: $quote
            ), [
                'request_id' => $lead->id,
                'quote_id' => $quote->id,
                'source' => 'lead_form',
            ]);

            if (!$quoteEmailQueued) {
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
            ]);

            if (!$quoteEmailQueued) {
                return redirect()->back()->with('warning', 'Quote created, but email delivery failed.');
            }

            return redirect()->back()->with('success', 'Quote created and sent successfully.');
        }

        $lead->update([
            'status' => LeadRequest::STATUS_CALL_REQUESTED,
            'status_updated_at' => now(),
            'next_follow_up_at' => $lead->next_follow_up_at ?? now()->addDay(),
        ]);

        $task = $this->createLeadQualificationTask($user, $lead, $suggestedServiceIds);
        ActivityLog::record(null, $lead, 'lead_call_requested', [
            'task_id' => $task?->id,
        ], 'Call requested from lead form');

        $prospectEmailQueued = true;
        if (!empty($lead->contact_email)) {
            $prospectEmailQueued = NotificationDispatcher::sendToMail(
                $lead->contact_email,
                new LeadCallRequestReceivedNotification($user, $lead),
                [
                    'request_id' => $lead->id,
                    'event' => 'lead_call_requested',
                ]
            );
        }

        if ($prospectEmailQueued) {
            ActivityLog::record(null, $lead, 'email_sent', [
                'email' => $lead->contact_email,
                'event' => 'lead_call_requested',
            ], 'Lead call request email sent');
        } else {
            ActivityLog::record(null, $lead, 'lead_email_failed', [
                'email' => $lead->contact_email,
                'event' => 'lead_call_requested',
            ], 'Lead call request email failed');
        }

        NotificationDispatcher::send($user, new LeadFormOwnerNotification(
            event: 'lead_call_requested',
            lead: $lead
        ), [
            'request_id' => $lead->id,
            'event' => 'lead_call_requested',
        ]);

        if (!$prospectEmailQueued) {
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
        ]);

        if (!$prospectEmailQueued) {
            return redirect()->back()->with('warning', 'Call request recorded, but confirmation email failed.');
        }

        return redirect()->back()->with('success', 'Call request submitted successfully.');
    }

    private function assertLeadIntakeEnabled(User $user): void
    {
        if ($user->isSuspended()) {
            abort(404);
        }

        $hasFeature = app(CompanyFeatureService::class)->hasFeature($user, 'requests');
        if (!$hasFeature) {
            abort(404);
        }
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

    private function resolveOrCreateCustomerForLead(User $owner, array $validated, LeadRequest $lead): Customer
    {
        $accountId = $owner->id;
        $resolvedCustomerId = $this->resolveCustomerId(
            $accountId,
            $validated['contact_email'] ?? null,
            $validated['contact_phone'] ?? null
        );

        if ($resolvedCustomerId) {
            $customer = Customer::query()->byUser($accountId)->findOrFail($resolvedCustomerId);
            $email = trim((string) ($validated['contact_email'] ?? ''));
            $phone = trim((string) ($validated['contact_phone'] ?? ''));

            $updates = [];
            if ($email !== '' && empty($customer->email)) {
                $updates['email'] = $email;
            }
            if ($phone !== '' && empty($customer->phone)) {
                $updates['phone'] = $phone;
            }

            if (!empty($updates)) {
                $customer->update($updates);
            }

            return $customer;
        }

        [$firstName, $lastName] = $this->splitContactName((string) ($validated['contact_name'] ?? ''));

        $companyName = trim((string) ($validated['title'] ?? $validated['service_type'] ?? ''));
        if ($companyName === '') {
            $companyName = trim((string) ($validated['contact_name'] ?? ''));
        }

        return Customer::query()->create([
            'user_id' => $accountId,
            'company_name' => $companyName !== '' ? $companyName : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $validated['contact_email'] ?? null,
            'phone' => $validated['contact_phone'] ?? null,
            'description' => $lead->description,
        ]);
    }

    private function splitContactName(string $contactName): array
    {
        $normalized = trim($contactName);
        if ($normalized === '') {
            return ['Lead', 'Prospect'];
        }

        $parts = preg_split('/\s+/', $normalized, 2) ?: [];
        $firstName = trim((string) ($parts[0] ?? 'Lead'));
        $lastName = trim((string) ($parts[1] ?? 'Prospect'));

        if ($firstName === '') {
            $firstName = 'Lead';
        }
        if ($lastName === '') {
            $lastName = 'Prospect';
        }

        return [$firstName, $lastName];
    }

    private function createQuoteFromLead(
        User $owner,
        LeadRequest $lead,
        Customer $customer,
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
            if (!empty($qualificationAnswers)) {
                $sourceDetails['qualification_answers'] = $qualificationAnswers;
            }
            if (!empty($missingInformation)) {
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
                'description' => !empty($descriptionParts) ? implode(' | ', $descriptionParts) : null,
                'source_details' => $sourceDetails,
            ];
        })->values();

        $subtotal = round((float) $lineItems->sum('total'), 2);
        $jobTitle = trim((string) ($lead->title ?: $lead->service_type ?: ('Lead quote #' . $lead->id)));

        $quote = Quote::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
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

        $quote->products()->sync($pivotData);

        return $quote->fresh(['customer.user', 'products']);
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
            $lines[] = 'Need summary: ' . $summary;
        }

        if (!empty($intentTags)) {
            $lines[] = 'Detected intents: ' . implode(', ', $intentTags);
        }

        if (!empty($qualificationAnswers)) {
            $lines[] = 'Qualification answers:';
            foreach ($qualificationAnswers as $questionId => $answer) {
                $label = trim((string) $questionId);
                $value = trim((string) $answer);
                if ($label === '' || $value === '') {
                    continue;
                }

                $lines[] = $label . ': ' . $value;
            }
        }

        if (!empty($missingInformation)) {
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

            if (!empty($missingLabels)) {
                $lines[] = 'Missing information: ' . implode(', ', $missingLabels);
            }
        }

        $normalizedAssumptions = trim($assumptions);
        if ($normalizedAssumptions !== '') {
            $lines[] = 'Assumptions: ' . $normalizedAssumptions;
        }

        $payload = trim(implode("\n", $lines));

        return $payload !== '' ? $payload : null;
    }

    private function scheduleQuoteEmailRetry(Quote $quote, LeadRequest $lead, int $attempt): void
    {
        $delayMinutes = RetryLeadQuoteEmailJob::delayMinutesForAttempt($attempt);
        RetryLeadQuoteEmailJob::dispatch($quote->id, $lead->id, $attempt)
            ->delay(now()->addMinutes($delayMinutes));

        ActivityLog::record(null, $lead, 'lead_email_retry_scheduled', [
            'quote_id' => $quote->id,
            'attempt' => $attempt,
            'delay_minutes' => $delayMinutes,
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
            $lead->description ? 'Need summary: ' . $lead->description : null,
            !empty($serviceNames) ? 'Expected services: ' . implode(', ', $serviceNames) : null,
            $lead->contact_name ? 'Contact: ' . $lead->contact_name : null,
            $lead->contact_email ? 'Email: ' . $lead->contact_email : null,
            $lead->contact_phone ? 'Phone: ' . $lead->contact_phone : null,
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
