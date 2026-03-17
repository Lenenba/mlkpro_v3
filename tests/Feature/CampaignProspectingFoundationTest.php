<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function prospectingOwner(array $overrides = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'email' => 'prospecting-owner-'.Str::lower(Str::random(10)).'@example.com',
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

function prospectingProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Prospecting Category '.Str::upper(Str::random(4)),
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Prospecting Offer '.Str::upper(Str::random(5)),
        'description' => 'Prospecting offer',
        'price' => 49.99,
        'stock' => 20,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('campaign can be created in prospecting mode', function () {
    $owner = prospectingOwner();
    $product = prospectingProduct($owner);

    $response = $this->actingAs($owner)->post(route('campaigns.store'), [
        'name' => 'Outbound Prospecting',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'prospecting_enabled' => true,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_EN,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'offers' => [
            [
                'offer_type' => 'product',
                'offer_id' => $product->id,
            ],
        ],
        'channels' => [
            [
                'channel' => Campaign::CHANNEL_EMAIL,
                'is_enabled' => true,
            ],
        ],
    ]);

    $campaign = Campaign::query()->latest('id')->first();

    $response->assertRedirect(route('campaigns.edit', $campaign));

    expect($campaign)->not->toBeNull()
        ->and($campaign->prospecting_enabled)->toBeTrue()
        ->and($campaign->campaign_direction)->toBe(Campaign::DIRECTION_PROSPECTING_OUTBOUND);
});

test('campaign prospecting foundation relations can be persisted', function () {
    $owner = prospectingOwner();

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospecting Foundation',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Jane',
        'last_name' => 'Lead',
        'email' => 'matched-'.Str::lower(Str::random(6)).'@example.com',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'source_type' => 'csv',
        'batch_number' => 1,
        'input_count' => 100,
        'accepted_count' => 60,
        'status' => CampaignProspectBatch::STATUS_ANALYZED,
    ]);

    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => 'csv',
        'company_name' => 'Acme Prospect',
        'contact_name' => 'Alex Prospect',
        'email' => 'alex@example.com',
        'email_normalized' => 'alex@example.com',
        'status' => CampaignProspect::STATUS_SCORED,
        'match_status' => CampaignProspect::MATCH_CUSTOMER,
        'matched_customer_id' => $customer->id,
        'fit_score' => 81,
        'intent_score' => 44,
        'priority_score' => 72,
        'metadata' => [
            'source' => 'prospecting',
        ],
    ]);

    $activity = CampaignProspectActivity::query()->create([
        'campaign_prospect_id' => $prospect->id,
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'actor_user_id' => $owner->id,
        'activity_type' => 'scored',
        'summary' => 'Prospect scored and ready for review',
        'payload' => [
            'fit_score' => 81,
        ],
        'occurred_at' => now(),
    ]);

    expect($campaign->fresh()->prospectBatches)->toHaveCount(1)
        ->and($campaign->fresh()->prospects)->toHaveCount(1)
        ->and($campaign->fresh()->prospectActivities)->toHaveCount(1)
        ->and($batch->fresh()->prospects)->toHaveCount(1)
        ->and($prospect->fresh()->matchedCustomer?->is($customer))->toBeTrue()
        ->and($prospect->fresh()->activities->first()?->is($activity))->toBeTrue();
});
