<?php

use App\Jobs\RetryLeadQuoteEmailJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Task;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeadCallRequestReceivedNotification;
use App\Notifications\LeadFormOwnerNotification;
use App\Notifications\LeadQuoteRequestReceivedNotification;
use App\Notifications\SendQuoteNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function publicLeadOwnerRoleId(): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;
}

function createPublicLeadOwner(array $attributes = []): User
{
    $defaults = [
        'name' => 'Lead Owner',
        'email' => 'lead-owner-' . Str::lower(Str::random(8)) . '@example.com',
        'password' => 'password',
        'role_id' => publicLeadOwnerRoleId(),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'requests' => true,
        ],
    ];

    return User::query()->create(array_merge($defaults, $attributes));
}

function createServiceFor(
    User $owner,
    string $name,
    string $description,
    bool $isActive,
    ?int $categoryId = null
): Product {
    return Product::query()->create([
        'user_id' => $owner->id,
        'name' => $name,
        'description' => $description,
        'category_id' => $categoryId,
        'price' => 1000,
        'stock' => 0,
        'minimum_stock' => 0,
        'is_active' => $isActive,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('suggests only active services from the current tenant catalog', function () {
    $owner = createPublicLeadOwner();
    $otherOwner = createPublicLeadOwner();

    $websiteCategory = ProductCategory::query()->create([
        'name' => 'Website',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $matching = createServiceFor(
        $owner,
        'Site vitrine pro',
        'Creation de site web avec paiement en ligne',
        true,
        $websiteCategory->id
    );

    $inactive = createServiceFor(
        $owner,
        'CRM legacy',
        'Ancien module CRM',
        false,
        $websiteCategory->id
    );

    $foreign = createServiceFor(
        $otherOwner,
        'Paiement Stripe setup',
        'Configuration paiement stripe',
        true,
        $websiteCategory->id
    );

    $response = $this->postJson(
        URL::signedRoute('public.requests.suggest', ['user' => $owner->id]),
        [
            'service_type' => 'Site web',
            'description' => 'Je veux un site vitrine avec reservation et paiement stripe.',
            'intent_tags' => ['booking', 'payment'],
        ]
    )->assertOk();

    $suggestedIds = collect($response->json('suggestions'))->pluck('id')->all();
    $detected = collect($response->json('detected_categories'))->pluck('id')->all();

    expect($suggestedIds)->toContain($matching->id);
    expect($suggestedIds)->not->toContain($inactive->id);
    expect($suggestedIds)->not->toContain($foreign->id);
    expect($detected)->toContain('website');
    expect($detected)->toContain('booking');
    expect($detected)->toContain('payment');
});

it('exposes only active services from the current tenant on public lead form page', function () {
    $owner = createPublicLeadOwner();
    $otherOwner = createPublicLeadOwner();

    $category = ProductCategory::query()->create([
        'name' => 'Services',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $active = createServiceFor(
        $owner,
        'Window cleaning',
        'Exterior and interior windows',
        true,
        $category->id
    );
    $inactive = createServiceFor(
        $owner,
        'Deep clean package',
        'Archived package',
        false,
        $category->id
    );
    $foreign = createServiceFor(
        $otherOwner,
        'Pressure wash',
        'Other tenant service',
        true,
        $category->id
    );

    $this->get(URL::signedRoute('public.requests.form', ['user' => $owner->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/RequestForm')
            ->where('catalog_services', function ($services) use ($active, $inactive, $foreign) {
                $ids = collect($services)->pluck('id')->map(fn ($id) => (int) $id)->all();

                return in_array($active->id, $ids, true)
                    && !in_array($inactive->id, $ids, true)
                    && !in_array($foreign->id, $ids, true);
            }));
});

it('exposes suggestion metadata on public lead form page', function () {
    $owner = createPublicLeadOwner();

    $this->get(URL::signedRoute('public.requests.form', ['user' => $owner->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/RequestForm')
            ->where('intent_options', fn ($options) => collect($options)->contains('website')
                && collect($options)->contains('integration'))
            ->where('quote_question_catalog', fn ($catalog) => collect($catalog)->has('common')
                && collect($catalog)->has('website'))
            ->where('suggest_url', fn ($url) => is_string($url)
                && str_contains($url, '/public/requests/' . $owner->id . '/suggest-services'))
            ->where('address_search_url', fn ($url) => is_string($url)
                && str_contains($url, '/public/requests/' . $owner->id . '/address-search')));
});

it('searches address suggestions through backend proxy on public lead form', function () {
    Http::fake([
        'https://api.geoapify.com/v1/geocode/autocomplete*' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'place_id' => 'geo-1',
                        'formatted' => '641 Rue Mil, Terrebonne, QC, Canada',
                        'house_number' => '641',
                        'street' => 'Rue Mil',
                        'city' => 'Terrebonne',
                        'state' => 'QC',
                        'postcode' => 'J6Y 1A1',
                        'country' => 'Canada',
                    ],
                ],
            ],
        ], 200),
    ]);

    $owner = createPublicLeadOwner();

    $response = $this->postJson(
        URL::signedRoute('public.requests.address-search', ['user' => $owner->id]),
        [
            'text' => '641 rue mil',
        ]
    );

    $response->assertOk()
        ->assertJsonPath('suggestions.0.id', 'geo-1')
        ->assertJsonPath('suggestions.0.label', '641 Rue Mil, Terrebonne, QC, Canada');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.geoapify.com/v1/geocode/autocomplete'));
});

it('stores sanitized quote qualification payload and missing information', function () {
    $owner = createPublicLeadOwner();
    $category = ProductCategory::query()->create([
        'name' => 'Operations',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $activeService = createServiceFor(
        $owner,
        'Reservation setup',
        'Booking module',
        true,
        $category->id
    );
    $inactiveService = createServiceFor(
        $owner,
        'Old payment bridge',
        'Deprecated payment bridge',
        false,
        $category->id
    );

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Lead Prospect',
        'contact_email' => 'lead.prospect@example.com',
        'service_type' => 'Need help',
        'description' => 'Please help setup booking and payment.',
        'intent_tags' => ['website', 'invalid_tag', 'payment'],
        'suggested_service_ids' => [$activeService->id, $inactiveService->id, 999999],
        'services_sur_devis' => [$activeService->id, $inactiveService->id, 555555],
        'qualification_answers' => [
            'business_goal' => 'Increase online bookings',
            'payment_flows' => 'Online payment + deposits',
            'invalid_field' => 'Should be ignored',
        ],
        'quote_assumptions' => 'Client will provide visual assets.',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect(data_get($lead->meta, 'intent_tags'))->toBe(['website', 'payment']);
    expect(data_get($lead->meta, 'suggested_service_ids'))->toBe([$activeService->id]);
    expect(data_get($lead->meta, 'services_sur_devis'))->toBe([$activeService->id]);
    $answers = data_get($lead->meta, 'qualification_answers');
    expect($answers)->toBeArray();
    expect($answers['business_goal'] ?? null)->toBe('Increase online bookings');
    expect($answers['payment_flows'] ?? null)->toBe('Online payment + deposits');
    expect($answers)->not->toHaveKey('invalid_field');
    expect(data_get($lead->meta, 'quote_assumptions'))->toBe('Client will provide visual assets.');

    $missingIds = collect(data_get($lead->meta, 'missing_information', []))
        ->pluck('id')
        ->all();
    expect($missingIds)->toContain('desired_deadline');
    expect($missingIds)->toContain('budget_range');
    expect($missingIds)->toContain('website_pages');
    expect($missingIds)->toContain('payment_provider');
});

it('creates and sends a quote when receive_quote is selected', function () {
    Notification::fake();

    $owner = createPublicLeadOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    $category = ProductCategory::query()->create([
        'name' => 'Website',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $serviceA = createServiceFor(
        $owner,
        'Website package',
        'Build the website core',
        true,
        $category->id
    );
    $serviceB = createServiceFor(
        $owner,
        'Payment setup',
        'Configure payment flows',
        true,
        $category->id
    );

    Product::query()->whereKey($serviceA->id)->update(['price' => 1200]);
    Product::query()->whereKey($serviceB->id)->update(['price' => 800]);

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Prospect Quote',
        'contact_email' => 'prospect.quote@example.com',
        'service_type' => 'Website and payment',
        'description' => 'Need a site with checkout.',
        'intent_tags' => ['website', 'payment'],
        'suggested_service_ids' => [$serviceA->id, $serviceB->id],
        'services_sur_devis' => [$serviceB->id],
        'qualification_answers' => [
            'business_goal' => 'Increase online sales',
            'payment_flows' => 'Stripe checkout and deposits',
        ],
        'quote_assumptions' => 'Client provides brand assets.',
        'final_action' => 'receive_quote',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    $quote = Quote::query()
        ->where('request_id', $lead->id)
        ->firstOrFail();
    $quote->load('products', 'customer');

    expect($lead->status)->toBe(LeadRequest::STATUS_QUOTE_SENT);
    expect($quote->status)->toBe('sent');
    expect($quote->customer)->not->toBeNull();
    expect($quote->customer->email)->toBe('prospect.quote@example.com');
    expect($quote->products->pluck('id')->all())->toContain($serviceA->id);
    expect($quote->products->pluck('id')->all())->toContain($serviceB->id);

    $lineA = $quote->products->firstWhere('id', $serviceA->id)?->pivot;
    $lineB = $quote->products->firstWhere('id', $serviceB->id)?->pivot;

    expect((float) ($lineA?->price ?? 0))->toBe(1200.0);
    expect((float) ($lineA?->total ?? 0))->toBe(1200.0);
    expect((float) ($lineB?->price ?? 0))->toBe(0.0);
    expect((float) ($lineB?->total ?? 0))->toBe(0.0);
    expect((float) $quote->subtotal)->toBe(1200.0);
    expect((float) $quote->total)->toBe(1200.0);

    Notification::assertSentTo($quote->customer, SendQuoteNotification::class);
    Notification::assertSentOnDemand(
        LeadQuoteRequestReceivedNotification::class,
        function (LeadQuoteRequestReceivedNotification $notification, array $channels, object $notifiable) use ($lead, $quote) {
            $mail = data_get($notifiable, 'routes.mail');
            return $notification->lead->is($lead)
                && $notification->quote->is($quote)
                && in_array('mail', $channels, true)
                && $mail === 'prospect.quote@example.com';
        }
    );
    Notification::assertSentTo(
        $owner,
        LeadFormOwnerNotification::class,
        function (LeadFormOwnerNotification $notification, array $channels) use ($lead, $quote) {
            return $notification->event === 'quote_created_from_lead_form'
                && $notification->lead->is($lead)
                && $notification->quote?->is($quote)
                && in_array('mail', $channels, true)
                && in_array('database', $channels, true);
        }
    );

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $lead->getMorphClass(),
        'subject_id' => $lead->id,
        'action' => 'quote_created_from_lead_form',
    ]);
});

it('requires email and at least one selected service for receive_quote', function () {
    $owner = createPublicLeadOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    $category = ProductCategory::query()->create([
        'name' => 'Ops',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $service = createServiceFor(
        $owner,
        'Booking setup',
        'Set up booking service',
        true,
        $category->id
    );

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Prospect Missing Email',
        'contact_phone' => '+1 555 0100',
        'service_type' => 'Booking',
        'description' => 'Need booking setup.',
        'suggested_service_ids' => [$service->id],
        'final_action' => 'receive_quote',
    ])->assertSessionHasErrors(['contact_email']);

    expect(LeadRequest::query()->count())->toBe(0);
    expect(Quote::query()->count())->toBe(0);

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Prospect Missing Services',
        'contact_email' => 'prospect.no.service@example.com',
        'service_type' => 'Booking',
        'description' => 'Need booking setup.',
        'suggested_service_ids' => [],
        'final_action' => 'receive_quote',
    ])->assertSessionHasErrors(['suggested_service_ids']);

    expect(LeadRequest::query()->count())->toBe(0);
    expect(Quote::query()->count())->toBe(0);
});

it('records a call request without creating a quote and creates a qualification task', function () {
    Notification::fake();

    $owner = createPublicLeadOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    $category = ProductCategory::query()->create([
        'name' => 'CRM',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $service = createServiceFor(
        $owner,
        'CRM onboarding',
        'CRM setup and qualification',
        true,
        $category->id
    );

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Prospect Call',
        'contact_email' => 'prospect.call@example.com',
        'contact_phone' => '+1 555 9900',
        'service_type' => 'CRM setup',
        'description' => 'Need a qualification call.',
        'intent_tags' => ['crm'],
        'suggested_service_ids' => [$service->id],
        'final_action' => 'request_call',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect($lead->status)->toBe(LeadRequest::STATUS_CALL_REQUESTED);
    expect(data_get($lead->meta, 'lead_stage'))->toBe('call_requested');
    expect(Quote::query()->where('request_id', $lead->id)->exists())->toBeFalse();

    $task = Task::query()->where('request_id', $lead->id)->first();
    expect($task)->not->toBeNull();
    expect($task->title)->toBe('Qualifier le lead / Planifier appel');
    expect($task->status)->toBe('todo');

    Notification::assertSentOnDemand(
        LeadCallRequestReceivedNotification::class,
        function (LeadCallRequestReceivedNotification $notification, array $channels, object $notifiable) use ($lead) {
            $mail = data_get($notifiable, 'routes.mail');
            return $notification->lead->is($lead)
                && in_array('mail', $channels, true)
                && $mail === 'prospect.call@example.com';
        }
    );

    Notification::assertSentTo(
        $owner,
        LeadFormOwnerNotification::class,
        function (LeadFormOwnerNotification $notification, array $channels) use ($lead) {
            return $notification->event === 'lead_call_requested'
                && $notification->lead->is($lead)
                && in_array('mail', $channels, true)
                && in_array('database', $channels, true);
        }
    );

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $lead->getMorphClass(),
        'subject_id' => $lead->id,
        'action' => 'lead_call_requested',
    ]);
});

it('schedules quote email retry when initial quote email fails', function () {
    Queue::fake();
    config(['queue.default' => 'sync']);

    $owner = createPublicLeadOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
    ]);

    $category = ProductCategory::query()->create([
        'name' => 'Website',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    $service = createServiceFor(
        $owner,
        'Website package',
        'Build website',
        true,
        $category->id
    );

    Product::query()->whereKey($service->id)->update(['price' => 1000]);

    Notification::shouldReceive('sendNow')
        ->once()
        ->ordered()
        ->andThrow(new RuntimeException('mail down'));
    Notification::shouldReceive('sendNow')
        ->times(3)
        ->ordered()
        ->andReturnNull();
    Notification::shouldReceive('send')
        ->zeroOrMoreTimes()
        ->andReturnNull();

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Prospect Retry',
        'contact_email' => 'prospect.retry@example.com',
        'service_type' => 'Website',
        'description' => 'Need website quote.',
        'suggested_service_ids' => [$service->id],
        'final_action' => 'receive_quote',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();
    $quote = Quote::query()
        ->where('request_id', $lead->id)
        ->firstOrFail();

    Queue::assertPushed(
        RetryLeadQuoteEmailJob::class,
        fn (RetryLeadQuoteEmailJob $job) => $job->quoteId === $quote->id
            && $job->leadId === $lead->id
            && $job->attempt === 1
    );

    $this->assertDatabaseHas('activity_logs', [
        'subject_type' => $lead->getMorphClass(),
        'subject_id' => $lead->id,
        'action' => 'lead_email_retry_scheduled',
    ]);
});
