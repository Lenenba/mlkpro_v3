<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\FatigueLimiter;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\TemplateLibraryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'email' => 'owner-' . Str::lower(Str::random(12)) . '@example.com',
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
        'name' => 'Campaign Category ' . Str::upper(Str::random(4)),
    ])->id;

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $categoryId,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Offer ' . Str::upper(Str::random(6)),
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
        'email' => 'customer-' . Str::lower(Str::random(12)) . '@example.com',
        'phone' => '+1514555' . random_int(1000, 9999),
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

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('offer search supports cursor pagination', function () {
    $owner = marketingOwner();

    for ($index = 0; $index < 25; $index++) {
        marketingProduct($owner, [
            'name' => 'Product ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
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
        'email' => 'fatigue-' . Str::lower(Str::random(10)) . '@example.com',
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

test('sending workflow queues dispatch and recipient jobs', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $customer = marketingCustomer($owner, [
        'email' => 'send-' . Str::lower(Str::random(10)) . '@example.com',
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
