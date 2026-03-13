<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\MailingList;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\FatigueLimiter;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\TemplateLibraryService;
use App\Services\Campaigns\TemplateSeederService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function marketingOwner(array $overrides = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'email' => 'owner-'.Str::lower(Str::random(12)).'@example.com',
        'company_features' => [
            'campaigns' => true,
            'products' => true,
            'services' => true,
            'sales' => true,
        ],
        'onboarding_completed_at' => now(),
        'company_type' => 'products',
    ], $overrides));
}

function marketingProduct(User $owner, array $overrides = []): Product
{
    $categoryId = $overrides['category_id'] ?? ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Campaign Category '.Str::upper(Str::random(4)),
    ])->id;

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $categoryId,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Offer '.Str::upper(Str::random(6)),
        'description' => 'Campaign offer',
        'price' => 19.99,
        'stock' => 15,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

function marketingCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'company_name' => 'Acme',
        'email' => 'customer-'.Str::lower(Str::random(12)).'@example.com',
        'phone' => '+1514555'.random_int(1000, 9999),
        'is_active' => true,
    ], $overrides));
}

function disableMarketingQuietHours(User $owner): void
{
    /** @var MarketingSettingsService $service */
    $service = app(MarketingSettingsService::class);
    $service->update($owner, [
        'channels' => [
            'quiet_hours' => [
                'timezone' => 'UTC',
                'start' => '00:00',
                'end' => '00:00',
            ],
        ],
    ]);
}

function forceCurrentTimeWithinMarketingQuietHours(User $owner): void
{
    $now = now()->copy()->setTimezone('UTC');

    /** @var MarketingSettingsService $service */
    $service = app(MarketingSettingsService::class);
    $service->update($owner, [
        'channels' => [
            'quiet_hours' => [
                'timezone' => 'UTC',
                'start' => $now->copy()->subMinute()->format('H:i'),
                'end' => $now->copy()->addMinutes(2)->format('H:i'),
            ],
        ],
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('offer search supports cursor pagination', function () {
    $owner = marketingOwner();

    for ($index = 0; $index < 25; $index++) {
        marketingProduct($owner, [
            'name' => 'Product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'created_at' => now()->subMinutes($index),
        ]);
    }

    $first = $this->actingAs($owner)->getJson(route('offers.search', [
        'type' => 'product',
        'sort' => 'newest',
        'limit' => 10,
    ]));

    $first->assertOk()
        ->assertJsonCount(10, 'items')
        ->assertJsonPath('meta.type', 'product');

    $firstCursor = $first->json('nextCursor');
    expect($firstCursor)->not->toBeNull();

    $firstIds = collect($first->json('items'))->pluck('id');

    $second = $this->actingAs($owner)->getJson(route('offers.search', [
        'type' => 'product',
        'sort' => 'newest',
        'limit' => 10,
        'cursor' => $firstCursor,
    ]));

    $second->assertOk()->assertJsonCount(10, 'items');
    $secondIds = collect($second->json('items'))->pluck('id');

    expect($secondIds->intersect($firstIds))->toHaveCount(0);
});

test('segment can be saved loaded and counted', function () {
    $owner = marketingOwner();
    $customer = marketingCustomer($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    $create = $this->actingAs($owner)->postJson(route('marketing.segments.store'), [
        'name' => 'VIP Segment',
        'description' => 'Customers with base filters',
        'filters' => [
            'operator' => 'AND',
            'rules' => [],
        ],
        'exclusions' => [
            'operator' => 'AND',
            'rules' => [],
        ],
        'tags' => ['vip'],
    ]);

    $create->assertCreated()->assertJsonPath('segment.name', 'VIP Segment');
    $segmentId = (int) $create->json('segment.id');

    $show = $this->actingAs($owner)->getJson(route('marketing.segments.show', $segmentId));
    $show->assertOk()->assertJsonPath('segment.id', $segmentId);

    $count = $this->actingAs($owner)->getJson(route('marketing.segments.count', $segmentId));
    $count->assertOk()->assertJsonStructure([
        'segment_id',
        'counts' => [
            'total_eligible',
            'eligible_by_channel',
            'blocked_by_channel',
            'blocked_by_reason',
        ],
    ]);
});

test('template library resolves most specific default template', function () {
    $owner = marketingOwner();
    $service = app(TemplateLibraryService::class);

    $fallback = $service->save($owner, $owner, [
        'name' => 'Fallback',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => null,
        'language' => null,
        'is_default' => true,
        'content' => [
            'subject' => 'Fallback',
            'html' => 'Base body',
        ],
    ]);

    $promotionDefault = $service->save($owner, $owner, [
        'name' => 'Promotion default',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'language' => null,
        'is_default' => true,
        'content' => [
            'subject' => 'Promotion',
            'html' => 'Promotion body',
        ],
    ]);

    $promotionFr = $service->save($owner, $owner, [
        'name' => 'Promotion FR',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'language' => 'FR',
        'is_default' => true,
        'content' => [
            'subject' => 'Promotion FR',
            'html' => 'Promotion FR body',
        ],
    ]);

    $resolvedFr = $service->resolveDefault($owner, Campaign::CHANNEL_EMAIL, Campaign::TYPE_PROMOTION, 'FR');
    $resolvedEn = $service->resolveDefault($owner, Campaign::CHANNEL_EMAIL, Campaign::TYPE_PROMOTION, 'EN');
    $resolvedOther = $service->resolveDefault($owner, Campaign::CHANNEL_EMAIL, Campaign::TYPE_WINBACK, 'EN');

    expect($resolvedFr?->id)->toBe($promotionFr->id);
    expect($resolvedEn?->id)->toBe($promotionDefault->id);
    expect($resolvedOther?->id)->toBe($fallback->id);
});

test('consent and fatigue rules are enforced', function () {
    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $customer = marketingCustomer($owner, [
        'email' => 'fatigue-'.Str::lower(Str::random(10)).'@example.com',
    ]);

    /** @var ConsentService $consent */
    $consent = app(ConsentService::class);
    /** @var FatigueLimiter $fatigue */
    $fatigue = app(FatigueLimiter::class);

    $blocked = $consent->canReceive($owner, $customer, Campaign::CHANNEL_EMAIL, $customer->email);
    expect($blocked['allowed'])->toBeFalse();
    expect($blocked['reason'])->toBe('consent_missing');

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    $allowed = $consent->canReceive($owner, $customer, Campaign::CHANNEL_EMAIL, $customer->email);
    expect($allowed['allowed'])->toBeTrue();

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Fatigue campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);

    for ($index = 0; $index < 2; $index++) {
        $priorRun = CampaignRun::query()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'trigger_type' => CampaignRun::TRIGGER_MANUAL,
            'status' => CampaignRun::STATUS_COMPLETED,
            'idempotency_key' => Str::uuid()->toString(),
        ]);

        CampaignRecipient::query()->create([
            'campaign_run_id' => $priorRun->id,
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'destination' => $customer->email,
            'destination_hash' => CampaignRecipient::destinationHash($customer->email),
            'status' => CampaignRecipient::STATUS_SENT,
            'sent_at' => now()->subHours(2),
        ]);
    }

    $fatigueDecision = $fatigue->canSend($owner, $customer, Campaign::CHANNEL_EMAIL, $campaign);
    expect($fatigueDecision['allowed'])->toBeFalse();
    expect($fatigueDecision['reason'])->toBe('fatigue_limit');
});

test('manual campaign audience estimate ignores current quiet hours', function () {
    $owner = marketingOwner([
        'company_timezone' => 'UTC',
    ]);
    forceCurrentTimeWithinMarketingQuietHours($owner);

    $customer = marketingCustomer($owner, [
        'email' => 'manual-quiet-'.Str::lower(Str::random(10)).'@example.com',
    ]);
    $offer = marketingProduct($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'Manual quiet hours estimate',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_PREFERRED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'offers' => [
            [
                'offer_type' => 'product',
                'offer_id' => $offer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Sujet',
                'body_template' => '<p>Body</p>',
            ],
        ],
        'audience' => [
            'source_logic' => 'UNION',
            'manual_customer_ids' => [$customer->id],
        ],
    ]);

    $estimate = $campaignService->estimateAudience($campaign->fresh(['audience', 'channels', 'user']));

    expect((int) ($estimate['total_eligible'] ?? 0))->toBe(1)
        ->and(data_get($estimate, 'blocked_by_reason.quiet_hours'))->toBeNull();
});

test('scheduled campaign audience estimate still respects quiet hours', function () {
    $owner = marketingOwner([
        'company_timezone' => 'UTC',
    ]);
    forceCurrentTimeWithinMarketingQuietHours($owner);

    $customer = marketingCustomer($owner, [
        'email' => 'scheduled-quiet-'.Str::lower(Str::random(10)).'@example.com',
    ]);
    $offer = marketingProduct($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'Scheduled quiet hours estimate',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_PREFERRED,
        'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
        'scheduled_at' => now()->copy()->utc()->addMinute()->toISOString(),
        'offers' => [
            [
                'offer_type' => 'product',
                'offer_id' => $offer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Sujet',
                'body_template' => '<p>Body</p>',
            ],
        ],
        'audience' => [
            'source_logic' => 'UNION',
            'manual_customer_ids' => [$customer->id],
        ],
    ]);

    $estimate = $campaignService->estimateAudience($campaign->fresh(['audience', 'channels', 'user']));

    expect((int) ($estimate['total_eligible'] ?? 0))->toBe(0)
        ->and((int) data_get($estimate, 'blocked_by_reason.quiet_hours'))->toBe(1);
});

test('campaign update accepts service offers when legacy product ids payload is empty', function () {
    $owner = marketingOwner();
    disableMarketingQuietHours($owner);

    $serviceOffer = marketingProduct($owner, [
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'name' => 'Pressure wash',
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'Service campaign',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_SERVICES,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'offers' => [
            [
                'offer_type' => 'service',
                'offer_id' => $serviceOffer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Sujet',
                'body_template' => '<p>Body</p>',
            ],
        ],
    ]);

    $response = $this->actingAs($owner)->putJson(route('campaigns.update', $campaign), [
        'name' => 'Service campaign updated',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_SERVICES,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'product_ids' => [],
        'offers' => [
            [
                'offer_type' => 'service',
                'offer_id' => $serviceOffer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Sujet',
                'body_template' => '<p>Body</p>',
            ],
        ],
        'audience' => [
            'source_logic' => 'UNION',
            'manual_customer_ids' => [],
            'include_mailing_list_ids' => [],
            'exclude_mailing_list_ids' => [],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('campaign.offer_mode', Campaign::OFFER_MODE_SERVICES);

    $campaign->refresh()->load('offers.offer');

    expect($campaign->offers)->toHaveCount(1)
        ->and((int) $campaign->offers->first()->offer_id)->toBe($serviceOffer->id)
        ->and((string) $campaign->offers->first()->offer_type)->toBe('service');
});

test('sending workflow queues dispatch and recipient jobs', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $customer = marketingCustomer($owner, [
        'email' => 'send-'.Str::lower(Str::random(10)).'@example.com',
    ]);
    $offer = marketingProduct($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'Queue workflow campaign',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_PREFERRED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'offers' => [
            [
                'offer_type' => 'product',
                'offer_id' => $offer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Hello {firstName}',
                'body_template' => 'Offer {offerName}',
            ],
        ],
        'audience' => [
            'manual_customer_ids' => [$customer->id],
            'manual_contacts' => [],
        ],
    ]);

    $run = $campaignService->queueRun($campaign, $owner);
    Queue::assertPushed(DispatchCampaignRunJob::class, fn (DispatchCampaignRunJob $job) => $job->campaignRunId === $run->id);

    $dispatchJob = new DispatchCampaignRunJob($run->id);
    $dispatchJob->handle(
        app(AudienceResolver::class),
        app(CampaignTrackingService::class),
        app(CampaignRunProgressService::class),
    );

    Queue::assertPushed(SendCampaignRecipientJob::class);
    expect(CampaignRecipient::query()->where('campaign_run_id', $run->id)->count())->toBeGreaterThan(0);
});

test('dispatch assigns ab variant metadata and stores run summary', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $customer = marketingCustomer($owner, [
        'email' => 'ab-'.Str::lower(Str::random(10)).'@example.com',
    ]);
    $offer = marketingProduct($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'AB assignment campaign',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_PREFERRED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'offers' => [
            [
                'offer_type' => 'product',
                'offer_id' => $offer->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
                'subject_template' => 'Base subject',
                'body_template' => 'Base body',
                'metadata' => [
                    'ab_testing' => [
                        'enabled' => true,
                        'split_a_percent' => 100,
                        'variant_a' => [
                            'subject_template' => 'Variant A subject',
                            'body_template' => 'Variant A body',
                        ],
                        'variant_b' => [
                            'subject_template' => 'Variant B subject',
                            'body_template' => 'Variant B body',
                        ],
                    ],
                ],
            ],
        ],
        'audience' => [
            'manual_customer_ids' => [$customer->id],
            'manual_contacts' => [],
        ],
        'settings' => [
            'holdout' => [
                'enabled' => false,
                'percent' => 0,
            ],
        ],
    ]);

    $run = CampaignRun::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'triggered_by_user_id' => $owner->id,
        'trigger_type' => CampaignRun::TRIGGER_MANUAL,
        'status' => CampaignRun::STATUS_PENDING,
        'idempotency_key' => Str::uuid()->toString(),
    ]);
    (new DispatchCampaignRunJob($run->id))->handle(
        app(AudienceResolver::class),
        app(CampaignTrackingService::class),
        app(CampaignRunProgressService::class),
    );

    $recipient = CampaignRecipient::query()
        ->where('campaign_run_id', $run->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->where('status', CampaignRecipient::STATUS_QUEUED)
        ->first();

    expect($recipient)->not->toBeNull();
    expect(data_get($recipient?->metadata, 'ab_test.variant'))->toBe('A');

    $run->refresh();
    expect(data_get($run->summary, 'ab_assignments.A'))->toBe(1);
    expect(data_get($run->summary, 'ab_assignments.B'))->toBe(0);
});

test('send job queues fallback recipient when primary provider fails', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $customer = marketingCustomer($owner, [
        'email' => 'fallback-'.Str::lower(Str::random(10)).'@example.com',
        'phone' => '+1514555'.random_int(1000, 9999),
    ]);
    $offer = marketingProduct($owner);

    CustomerConsent::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'status' => CustomerConsent::STATUS_GRANTED,
        'granted_at' => now(),
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Fallback campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_RUNNING,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'settings' => [
            'channel_fallback' => [
                'enabled' => true,
                'max_depth' => 1,
                'map' => [
                    Campaign::CHANNEL_SMS => [Campaign::CHANNEL_EMAIL],
                ],
            ],
        ],
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);

    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_SMS,
        'is_enabled' => true,
        'body_template' => '',
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Fallback subject',
        'body_template' => 'Fallback body',
    ]);

    $run = CampaignRun::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'triggered_by_user_id' => $owner->id,
        'trigger_type' => CampaignRun::TRIGGER_MANUAL,
        'status' => CampaignRun::STATUS_RUNNING,
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    $recipient = CampaignRecipient::query()->create([
        'campaign_run_id' => $run->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_SMS,
        'destination' => $customer->phone,
        'destination_hash' => CampaignRecipient::destinationHash($customer->phone),
        'status' => CampaignRecipient::STATUS_QUEUED,
        'queued_at' => now(),
    ]);

    (new SendCampaignRecipientJob($recipient->id))->handle(
        app(\App\Services\Campaigns\TemplateRenderer::class),
        app(CampaignTrackingService::class),
        app(\App\Services\Campaigns\Providers\CampaignProviderManager::class),
        app(CampaignRunProgressService::class),
        app(ConsentService::class),
        app(FatigueLimiter::class),
    );

    $recipient->refresh();
    expect($recipient->status)->toBe(CampaignRecipient::STATUS_FAILED);
    expect($recipient->failure_reason)->toBe('empty_body');

    $fallbackRecipient = CampaignRecipient::query()
        ->where('campaign_run_id', $run->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->where('destination_hash', CampaignRecipient::destinationHash($customer->email))
        ->first();

    expect($fallbackRecipient)->not->toBeNull();
    expect($fallbackRecipient?->status)->toBe(CampaignRecipient::STATUS_QUEUED);
    expect(data_get($fallbackRecipient?->metadata, 'fallback.parent_recipient_id'))->toBe($recipient->id);
    expect(data_get($fallbackRecipient?->metadata, 'fallback.from_channel'))->toBe(Campaign::CHANNEL_SMS);
    expect(data_get($fallbackRecipient?->metadata, 'fallback.to_channel'))->toBe(Campaign::CHANNEL_EMAIL);

    Queue::assertPushed(SendCampaignRecipientJob::class, function (SendCampaignRecipientJob $job) use ($fallbackRecipient) {
        return $job->campaignRecipientId === $fallbackRecipient->id;
    });
});

test('campaign show exposes delivery insights for ab holdout and fallback', function () {
    $owner = marketingOwner();

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Insights campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_COMPLETED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);

    $run = CampaignRun::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'triggered_by_user_id' => $owner->id,
        'trigger_type' => CampaignRun::TRIGGER_MANUAL,
        'status' => CampaignRun::STATUS_COMPLETED,
        'idempotency_key' => Str::uuid()->toString(),
        'summary' => [
            'ab_assignments' => ['A' => 8, 'B' => 2],
            'holdout_count' => 3,
            'failed' => 4,
        ],
    ]);

    $customer = marketingCustomer($owner, [
        'email' => 'insights-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    CampaignRecipient::query()->create([
        'campaign_run_id' => $run->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => $customer->email,
        'destination_hash' => CampaignRecipient::destinationHash($customer->email),
        'status' => CampaignRecipient::STATUS_QUEUED,
        'metadata' => [
            'fallback' => [
                'parent_recipient_id' => 999,
            ],
        ],
    ]);

    CampaignRecipient::query()->create([
        'campaign_run_id' => $run->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_SMS,
        'destination' => $customer->phone,
        'destination_hash' => CampaignRecipient::destinationHash($customer->phone),
        'status' => CampaignRecipient::STATUS_FAILED,
    ]);

    $response = $this->actingAs($owner)->getJson(route('campaigns.show', $campaign));

    $response->assertOk()
        ->assertJsonPath('deliveryInsights.latest_run_id', $run->id)
        ->assertJsonPath('deliveryInsights.ab_assignments.A', 8)
        ->assertJsonPath('deliveryInsights.ab_assignments.B', 2)
        ->assertJsonPath('deliveryInsights.ab_assignments.total', 10)
        ->assertJsonPath('deliveryInsights.ab_assignments.split_a_percent', 80)
        ->assertJsonPath('deliveryInsights.holdout_count', 3)
        ->assertJsonPath('deliveryInsights.fallback.count', 1)
        ->assertJsonPath('deliveryInsights.fallback.failed_count', 4)
        ->assertJsonPath('deliveryInsights.fallback.rate_percent', 25)
        ->assertJsonPath('deliveryInsights.channels.EMAIL.targeted', 1)
        ->assertJsonPath('deliveryInsights.channels.EMAIL.fallback_count', 1)
        ->assertJsonPath('deliveryInsights.channels.SMS.targeted', 1)
        ->assertJsonPath('deliveryInsights.channels.SMS.failed', 1);
});

test('mailing list can be created imported and cleaned', function () {
    $owner = marketingOwner();
    $first = marketingCustomer($owner);
    $second = marketingCustomer($owner);
    $third = marketingCustomer($owner);

    $create = $this->actingAs($owner)->postJson(route('marketing.mailing-lists.store'), [
        'name' => 'VIP List',
        'description' => 'Priority customers',
        'tags' => ['vip'],
        'customer_ids' => [$first->id],
    ]);

    $create->assertCreated()->assertJsonPath('mailing_list.name', 'VIP List');
    $listId = (int) $create->json('mailing_list.id');

    $show = $this->actingAs($owner)->getJson(route('marketing.mailing-lists.show', $listId));
    $show->assertOk()->assertJsonCount(1, 'customers.data');

    $import = $this->actingAs($owner)->postJson(route('marketing.mailing-lists.import', $listId), [
        'paste' => implode("\n", [
            (string) $second->id,
            $third->email,
            $third->phone,
        ]),
    ]);
    $import->assertOk()->assertJsonPath('result.total', 3);

    $remove = $this->actingAs($owner)->postJson(route('marketing.mailing-lists.remove-customers', $listId), [
        'customer_ids' => [$second->id],
    ]);
    $remove->assertOk();

    $updated = $this->actingAs($owner)->getJson(route('marketing.mailing-lists.show', $listId));
    $updated->assertOk()->assertJsonCount(2, 'customers.data');
});

test('available mailing list customers can be searched and imported by selected ids', function () {
    $owner = marketingOwner();
    $inList = marketingCustomer($owner, [
        'first_name' => 'Alice',
        'last_name' => 'Durand',
    ]);
    $candidate = marketingCustomer($owner, [
        'first_name' => 'Nadia',
        'last_name' => 'Martin',
        'email' => 'nadia-'.Str::lower(Str::random(8)).'@example.com',
    ]);
    $other = marketingCustomer($owner, [
        'first_name' => 'Louis',
        'last_name' => 'Bernard',
        'email' => 'louis-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    $list = MailingList::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Search List',
    ]);
    $list->customers()->attach($inList->id, [
        'added_by_user_id' => $owner->id,
        'added_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $available = $this->actingAs($owner)->getJson(route('marketing.mailing-lists.available-customers', [
        'mailingList' => $list->id,
        'search' => 'Nadia Martin',
    ]));

    $available->assertOk()
        ->assertJsonCount(1, 'customers.data')
        ->assertJsonPath('customers.data.0.id', $candidate->id);
    expect(collect($available->json('customers.data'))->pluck('id')->all())->not->toContain($inList->id);

    $availableByEmail = $this->actingAs($owner)->getJson(route('marketing.mailing-lists.available-customers', [
        'mailingList' => $list->id,
        'search' => 'louis',
    ]));
    $availableByEmail->assertOk()->assertJsonPath('customers.data.0.id', $other->id);

    $import = $this->actingAs($owner)->postJson(route('marketing.mailing-lists.import', $list->id), [
        'customer_ids' => [$candidate->id, $other->id],
    ]);
    $import->assertOk()
        ->assertJsonPath('result.added', 2)
        ->assertJsonPath('result.total', 3);

    $showFiltered = $this->actingAs($owner)->getJson(route('marketing.mailing-lists.show', [
        'mailingList' => $list->id,
        'search' => 'Nadia Martin',
    ]));
    $showFiltered->assertOk()
        ->assertJsonCount(1, 'customers.data')
        ->assertJsonPath('customers.data.0.id', $candidate->id);
});

test('vip tier assignment is reflected in audience resolver filters', function () {
    $owner = marketingOwner();
    disableMarketingQuietHours($owner);

    $vipCustomer = marketingCustomer($owner, [
        'email' => 'vip-'.Str::lower(Str::random(8)).'@example.com',
    ]);
    $regularCustomer = marketingCustomer($owner, [
        'email' => 'regular-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    $tierResponse = $this->actingAs($owner)->postJson(route('marketing.vip.store'), [
        'code' => 'GOLD',
        'name' => 'Gold',
        'perks' => ['Early access'],
        'is_active' => true,
    ]);
    $tierResponse->assertCreated();
    $tierId = (int) $tierResponse->json('vip_tier.id');

    $assignResponse = $this->actingAs($owner)->patchJson(route('marketing.vip.customer.update', $vipCustomer->id), [
        'is_vip' => true,
        'vip_tier_id' => $tierId,
    ]);
    $assignResponse->assertOk()->assertJsonPath('customer.is_vip', true);

    foreach ([$vipCustomer, $regularCustomer] as $customer) {
        CustomerConsent::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => Campaign::CHANNEL_EMAIL,
            'status' => CustomerConsent::STATUS_GRANTED,
            'granted_at' => now(),
        ]);
    }

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'VIP target campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);

    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'VIP hello',
        'body_template' => 'VIP offer',
    ]);

    $campaign->audience()->create([
        'smart_filters' => [
            'operator' => 'AND',
            'rules' => [
                [
                    'field' => 'is_vip',
                    'operator' => 'equals',
                    'value' => true,
                ],
            ],
        ],
        'manual_contacts' => [],
    ]);

    $resolved = app(AudienceResolver::class)->resolveForCampaign($campaign->fresh());
    $eligibleCustomerIds = collect($resolved['eligible'])
        ->pluck('customer_id')
        ->filter()
        ->unique()
        ->values()
        ->all();

    expect($eligibleCustomerIds)->toBe([$vipCustomer->id]);
});

test('vip automation upgrades and downgrades customers from paid purchases', function () {
    $owner = marketingOwner();
    $service = app(\App\Services\Campaigns\VipService::class);
    $settings = app(MarketingSettingsService::class);

    $goldTier = $service->saveTier($owner, $owner, [
        'code' => 'GOLD',
        'name' => 'Gold',
        'perks' => ['Priority support'],
        'is_active' => true,
    ]);

    $eligibleCustomer = marketingCustomer($owner, [
        'email' => 'eligible-'.Str::lower(Str::random(8)).'@example.com',
    ]);
    $downgradedCustomer = marketingCustomer($owner, [
        'email' => 'downgrade-'.Str::lower(Str::random(8)).'@example.com',
        'is_vip' => true,
        'vip_tier_id' => $goldTier->id,
        'vip_tier_code' => 'GOLD',
        'vip_since_at' => now()->subMonths(4),
    ]);
    $untouchedCustomer = marketingCustomer($owner, [
        'email' => 'untouched-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $eligibleCustomer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 650,
        'tax_total' => 0,
        'total' => 650,
        'paid_at' => now()->subDays(3),
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    $settings->update($owner, [
        'vip' => [
            'automation' => [
                'enabled' => true,
                'evaluation_window_days' => 365,
                'minimum_total_spend' => 500,
                'minimum_paid_orders' => 1,
                'default_tier_code' => 'GOLD',
                'preserve_existing_tier' => true,
                'downgrade_when_not_eligible' => true,
            ],
        ],
    ]);

    $result = $service->runAutomationForAccount($owner);

    expect($result['automation_enabled'])->toBeTrue();
    expect($result['customers_processed'])->toBe(3);
    expect($result['customers_updated'])->toBe(2);
    expect($result['customers_promoted'])->toBe(1);
    expect($result['customers_downgraded'])->toBe(1);

    $eligibleCustomer->refresh();
    $downgradedCustomer->refresh();
    $untouchedCustomer->refresh();

    expect($eligibleCustomer->is_vip)->toBeTrue();
    expect($eligibleCustomer->vip_tier_code)->toBe('GOLD');
    expect($eligibleCustomer->vip_since_at)->not->toBeNull();

    expect($downgradedCustomer->is_vip)->toBeFalse();
    expect($downgradedCustomer->vip_tier_id)->toBeNull();
    expect($downgradedCustomer->vip_tier_code)->toBeNull();

    expect($untouchedCustomer->is_vip)->toBeFalse();
});

test('vip automation supports per-tier rules with priorities', function () {
    $owner = marketingOwner();
    $service = app(\App\Services\Campaigns\VipService::class);
    $settings = app(MarketingSettingsService::class);

    $service->saveTier($owner, $owner, [
        'code' => 'SILVER',
        'name' => 'Silver',
        'is_active' => true,
    ]);
    $service->saveTier($owner, $owner, [
        'code' => 'GOLD',
        'name' => 'Gold',
        'is_active' => true,
    ]);
    $service->saveTier($owner, $owner, [
        'code' => 'PLATINUM',
        'name' => 'Platinum',
        'is_active' => true,
    ]);

    $silverCustomer = marketingCustomer($owner, [
        'email' => 'silver-'.Str::lower(Str::random(8)).'@example.com',
    ]);
    $goldCustomer = marketingCustomer($owner, [
        'email' => 'gold-'.Str::lower(Str::random(8)).'@example.com',
    ]);
    $platinumCustomer = marketingCustomer($owner, [
        'email' => 'platinum-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $silverCustomer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 350,
        'tax_total' => 0,
        'total' => 350,
        'paid_at' => now()->subDays(4),
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $goldCustomer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 1400,
        'tax_total' => 0,
        'total' => 1400,
        'paid_at' => now()->subDays(5),
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $platinumCustomer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 6200,
        'tax_total' => 0,
        'total' => 6200,
        'paid_at' => now()->subDays(7),
        'created_at' => now()->subDays(7),
        'updated_at' => now()->subDays(7),
    ]);

    $settings->update($owner, [
        'vip' => [
            'automation' => [
                'enabled' => true,
                'downgrade_when_not_eligible' => true,
                'preserve_existing_tier' => false,
                'tier_rules' => [
                    [
                        'tier_code' => 'PLATINUM',
                        'minimum_total_spend' => 5000,
                        'minimum_paid_orders' => 1,
                        'evaluation_window_days' => 365,
                        'priority' => 300,
                    ],
                    [
                        'tier_code' => 'GOLD',
                        'minimum_total_spend' => 1000,
                        'minimum_paid_orders' => 1,
                        'evaluation_window_days' => 365,
                        'priority' => 200,
                    ],
                    [
                        'tier_code' => 'SILVER',
                        'minimum_total_spend' => 200,
                        'minimum_paid_orders' => 1,
                        'evaluation_window_days' => 365,
                        'priority' => 100,
                    ],
                ],
            ],
        ],
    ]);

    $result = $service->runAutomationForAccount($owner);
    expect($result['automation_mode'])->toBe('tier_rules');
    expect($result['tier_rules_active'])->toBe(3);
    expect($result['customers_promoted'])->toBe(3);

    $silverCustomer->refresh();
    $goldCustomer->refresh();
    $platinumCustomer->refresh();

    expect($silverCustomer->is_vip)->toBeTrue();
    expect($silverCustomer->vip_tier_code)->toBe('SILVER');
    expect($goldCustomer->is_vip)->toBeTrue();
    expect($goldCustomer->vip_tier_code)->toBe('GOLD');
    expect($platinumCustomer->is_vip)->toBeTrue();
    expect($platinumCustomer->vip_tier_code)->toBe('PLATINUM');
});

test('dashboard kpi endpoint returns aggregated marketing metrics', function () {
    $owner = marketingOwner();
    $customer = marketingCustomer($owner, [
        'is_vip' => true,
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'KPI campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_COMPLETED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);

    $run = CampaignRun::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'trigger_type' => CampaignRun::TRIGGER_MANUAL,
        'status' => CampaignRun::STATUS_COMPLETED,
        'idempotency_key' => Str::uuid()->toString(),
    ]);

    CampaignRecipient::query()->create([
        'campaign_run_id' => $run->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => $customer->email,
        'destination_hash' => CampaignRecipient::destinationHash($customer->email),
        'status' => CampaignRecipient::STATUS_DELIVERED,
        'sent_at' => now()->subMinutes(10),
        'delivered_at' => now()->subMinutes(8),
        'clicked_at' => now()->subMinutes(5),
        'converted_at' => now()->subMinutes(1),
    ]);

    $mailingList = MailingList::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'KPI list',
    ]);
    $mailingList->customers()->attach($customer->id, [
        'added_by_user_id' => $owner->id,
        'added_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson(route('marketing.dashboard.kpis', [
        'range' => '30',
    ]));

    $response->assertOk()
        ->assertJsonPath('kpis.marketing.campaigns_sent', 1)
        ->assertJsonPath('kpis.marketing.delivery_success_rate', 100)
        ->assertJsonPath('kpis.marketing.conversions_attributed', 1)
        ->assertJsonPath('kpis.marketing.vip_count', 1)
        ->assertJsonPath('kpis.marketing.mailing_lists.count', 1);
});

test('template seeder is idempotent for tenant defaults', function () {
    $owner = marketingOwner();

    /** @var TemplateSeederService $templateSeeder */
    $templateSeeder = app(TemplateSeederService::class);
    $templateSeeder->seedDefaultsForTenant($owner, $owner);
    $templateSeeder->seedDefaultsForTenant($owner, $owner);

    $templates = MessageTemplate::query()->where('user_id', $owner->id)->get();
    expect($templates->count())->toBe(36);

    $duplicates = DB::table('message_templates')
        ->select(['campaign_type', 'channel', 'language'])
        ->where('user_id', $owner->id)
        ->where('is_default', true)
        ->groupBy('campaign_type', 'channel', 'language')
        ->havingRaw('COUNT(*) > 1')
        ->count();

    expect($duplicates)->toBe(0);
});
