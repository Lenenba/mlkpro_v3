<?php

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Campaign;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulsePrefillRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulsePrefillOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulsePrefillRoleId('owner'),
        'email' => 'pulse-prefill-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'company_features' => [
            'social' => true,
            'promotions' => true,
            'products' => true,
            'services' => true,
            'campaigns' => true,
        ],
        'onboarding_completed_at' => now(),
        'currency_code' => 'CAD',
        'locale' => 'fr',
    ], $overrides));
}

function pulsePrefillCategory(User $owner, array $overrides = []): ProductCategory
{
    return ProductCategory::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Pulse Prefill Category '.Str::upper(Str::random(4)),
    ], $overrides));
}

function pulsePrefillItem(User $owner, string $itemType = Product::ITEM_TYPE_PRODUCT, array $overrides = []): Product
{
    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => pulsePrefillCategory($owner)->id,
        'item_type' => $itemType,
        'name' => ucfirst($itemType).' '.Str::upper(Str::random(4)),
        'description' => 'Pulse prefill item description',
        'price' => 100,
        'stock' => 20,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('loads pulse composer prefills from supported business modules', function () {
    $owner = pulsePrefillOwner();

    $product = pulsePrefillItem($owner, Product::ITEM_TYPE_PRODUCT, [
        'name' => 'Laser Cleaner',
        'description' => 'Deep-clean finish for high-traffic interiors and client-facing spaces.',
        'price' => 129.99,
        'image' => 'https://example.com/images/laser-cleaner.jpg',
    ]);

    $service = pulsePrefillItem($owner, Product::ITEM_TYPE_SERVICE, [
        'name' => 'Premium Detail',
        'description' => 'Complete detailing session for premium bookings.',
        'price' => 89.00,
        'image' => 'https://example.com/images/premium-detail.jpg',
    ]);

    $promotion = Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Spring Flash',
        'code' => 'SPRING20',
        'target_type' => PromotionTargetType::PRODUCT->value,
        'target_id' => $product->id,
        'discount_type' => PromotionDiscountType::PERCENTAGE->value,
        'discount_value' => 20,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(7)->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
        'minimum_order_amount' => 50,
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Summer Launch',
        'type' => Campaign::TYPE_ANNOUNCEMENT,
        'campaign_type' => Campaign::TYPE_ANNOUNCEMENT,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_SCHEDULED,
        'scheduled_at' => now()->addDay(),
        'cta_url' => 'https://example.com/campaigns/summer-launch',
        'is_marketing' => true,
        'settings' => [],
    ]);
    $campaign->products()->attach($product->id, ['metadata' => json_encode([])]);

    $productResponse = $this->actingAs($owner)
        ->getJson(route('social.composer', [
            'source_type' => 'product',
            'source_id' => $product->id,
        ]))
        ->assertOk()
        ->assertJsonPath('prefill.source_type', 'product')
        ->assertJsonPath('prefill.source_id', $product->id)
        ->assertJsonPath('prefill.source_label', 'Laser Cleaner')
        ->assertJsonPath('prefill.image_url', 'https://example.com/images/laser-cleaner.jpg');

    expect((string) $productResponse->json('prefill.text'))->toContain('Laser Cleaner');

    $serviceResponse = $this->actingAs($owner)
        ->getJson(route('social.composer', [
            'source_type' => 'service',
            'source_id' => $service->id,
        ]))
        ->assertOk()
        ->assertJsonPath('prefill.source_type', 'service')
        ->assertJsonPath('prefill.source_id', $service->id)
        ->assertJsonPath('prefill.source_label', 'Premium Detail')
        ->assertJsonPath('prefill.image_url', 'https://example.com/images/premium-detail.jpg');

    expect((string) $serviceResponse->json('prefill.text'))->toContain('Premium Detail');

    $promotionResponse = $this->actingAs($owner)
        ->getJson(route('social.composer', [
            'source_type' => 'promotion',
            'source_id' => $promotion->id,
        ]))
        ->assertOk()
        ->assertJsonPath('prefill.source_type', 'promotion')
        ->assertJsonPath('prefill.source_id', $promotion->id)
        ->assertJsonPath('prefill.source_label', 'Spring Flash')
        ->assertJsonPath('prefill.image_url', 'https://example.com/images/laser-cleaner.jpg');

    expect((string) $promotionResponse->json('prefill.text'))
        ->toContain('Spring Flash')
        ->toContain('Code: SPRING20');

    $campaignResponse = $this->actingAs($owner)
        ->getJson(route('social.composer', [
            'source_type' => 'campaign',
            'source_id' => $campaign->id,
        ]))
        ->assertOk()
        ->assertJsonPath('prefill.source_type', 'campaign')
        ->assertJsonPath('prefill.source_id', $campaign->id)
        ->assertJsonPath('prefill.source_label', 'Summer Launch')
        ->assertJsonPath('prefill.link_url', 'https://example.com/campaigns/summer-launch')
        ->assertJsonPath('prefill.image_url', 'https://example.com/images/laser-cleaner.jpg');

    expect((string) $campaignResponse->json('prefill.text'))
        ->toContain('Summer Launch')
        ->toContain('Highlights: Laser Cleaner');
});

it('persists source references on pulse drafts created from prefills', function () {
    $owner = pulsePrefillOwner();
    $product = pulsePrefillItem($owner, Product::ITEM_TYPE_PRODUCT, [
        'name' => 'Launch Bundle',
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-north-page',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'Launch Bundle is ready.',
            'source_type' => 'product',
            'source_id' => $product->id,
            'target_connection_ids' => [$connection->id],
        ]);

    $response->assertCreated()
        ->assertJsonPath('draft.source_type', 'product')
        ->assertJsonPath('draft.source_id', $product->id)
        ->assertJsonPath('draft.source_label', 'Launch Bundle');

    $draft = SocialPost::query()->firstOrFail();

    expect($draft->source_type)->toBe('product')
        ->and($draft->source_id)->toBe($product->id)
        ->and(data_get($draft->metadata, 'source.type'))->toBe('product')
        ->and(data_get($draft->metadata, 'source.id'))->toBe($product->id)
        ->and(data_get($draft->metadata, 'source.label'))->toBe('Launch Bundle');
});

it('rejects pulse drafts that reference sources from another workspace', function () {
    $owner = pulsePrefillOwner();
    $otherOwner = pulsePrefillOwner([
        'email' => 'pulse-prefill-other-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    $foreignProduct = pulsePrefillItem($otherOwner, Product::ITEM_TYPE_PRODUCT, [
        'name' => 'Foreign Product',
    ]);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Studio IG',
        'external_account_id' => 'ig-studio',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.posts.store'), [
            'text' => 'Should fail',
            'source_type' => 'product',
            'source_id' => $foreignProduct->id,
            'target_connection_ids' => [$connection->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_id']);

    expect(SocialPost::query()->count())->toBe(0);
});

it('does not expose prefills when the source module is disabled', function () {
    $owner = pulsePrefillOwner([
        'company_features' => [
            'social' => true,
            'promotions' => false,
            'products' => true,
            'services' => true,
            'campaigns' => true,
        ],
    ]);

    $promotion = Promotion::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Disabled Promotion',
        'target_type' => PromotionTargetType::GLOBAL->value,
        'discount_type' => PromotionDiscountType::PERCENTAGE->value,
        'discount_value' => 10,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
        'status' => PromotionStatus::ACTIVE->value,
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.composer', [
            'source_type' => 'promotion',
            'source_id' => $promotion->id,
        ]))
        ->assertOk()
        ->assertJsonPath('prefill', null);
});

it('exposes pulse entry state on supported source pages only when social is available', function () {
    $owner = pulsePrefillOwner();
    $product = pulsePrefillItem($owner, Product::ITEM_TYPE_PRODUCT, [
        'name' => 'Visibility Product',
    ]);
    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Visibility Campaign',
        'type' => Campaign::TYPE_ANNOUNCEMENT,
        'campaign_type' => Campaign::TYPE_ANNOUNCEMENT,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
        'settings' => [],
    ]);

    $this->actingAs($owner)
        ->get(route('promotions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Promotions/Index')
            ->where('pulse.can_open', true)
        );

    $this->actingAs($owner)
        ->get(route('product.show', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Product/Show')
            ->where('pulse.can_open', true)
        );

    $this->actingAs($owner)
        ->get(route('service.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Service/Index')
            ->where('pulse.can_open', true)
        );

    $this->actingAs($owner)
        ->get(route('campaigns.show', $campaign))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Campaigns/Show')
            ->where('pulse.can_open', true)
        );

    $socialOffOwner = pulsePrefillOwner([
        'email' => 'pulse-prefill-social-off-'.Str::lower(Str::random(8)).'@example.com',
        'company_features' => [
            'social' => false,
            'promotions' => true,
            'products' => true,
            'services' => true,
            'campaigns' => true,
        ],
    ]);
    $socialOffProduct = pulsePrefillItem($socialOffOwner, Product::ITEM_TYPE_PRODUCT, [
        'name' => 'No Social Product',
    ]);

    $this->actingAs($socialOffOwner)
        ->get(route('product.show', $socialOffProduct))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Product/Show')
            ->where('pulse.can_open', false)
        );
});
