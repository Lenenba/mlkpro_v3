<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\CampaignOffer;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\Customer;
use App\Models\CustomerOptOut;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function batchOwner(array $overrides = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'email' => 'batch-owner-'.Str::lower(Str::random(10)).'@example.com',
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

function batchProduct(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Outbound Growth',
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Growth Suite',
        'description' => 'Outbound growth services and automation',
        'tags' => ['growth', 'automation', 'campaign'],
        'price' => 79.99,
        'stock' => 10,
        'minimum_stock' => 1,
        'is_active' => true,
    ], $overrides));
}

function prospectingCampaign(User $owner, Product $product): Campaign
{
    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospecting Batch Campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    CampaignOffer::query()->create([
        'campaign_id' => $campaign->id,
        'offer_type' => 'product',
        'offer_id' => $product->id,
    ]);

    CampaignChannel::query()->create([
        'campaign_id' => $campaign->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
    ]);

    return $campaign;
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

test('prospect import splits rows into analyzed batches of 100', function () {
    $owner = batchOwner();
    $product = batchProduct($owner);
    $campaign = prospectingCampaign($owner, $product);

    $prospects = collect(range(1, 105))->map(function (int $index): array {
        return [
            'company_name' => 'Company '.$index,
            'contact_name' => 'Prospect '.$index,
            'email' => 'prospect-'.$index.'@example.com',
            'website' => 'https://company'.$index.'.example.com',
            'industry' => 'growth automation',
        ];
    })->all();

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'prospects' => $prospects,
    ]);

    $response->assertCreated()
        ->assertJsonPath('total_imported', 105)
        ->assertJsonCount(2, 'batches')
        ->assertJsonPath('batches.0.input_count', 100)
        ->assertJsonPath('batches.0.status', CampaignProspectBatch::STATUS_ANALYZED)
        ->assertJsonPath('batches.1.input_count', 5);

    expect(CampaignProspectBatch::query()->where('campaign_id', $campaign->id)->count())->toBe(2)
        ->and(CampaignProspect::query()->where('campaign_id', $campaign->id)->count())->toBe(105);
});

test('prospect import analyzes duplicates blocked contacts and scoring', function () {
    $owner = batchOwner();
    $product = batchProduct($owner);
    $campaign = prospectingCampaign($owner, $product);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Duplicate',
        'last_name' => 'Customer',
        'email' => 'customer-match@example.com',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Existing lead',
        'contact_name' => 'Lead Match',
        'contact_phone' => '+15145551234',
        'status_updated_at' => now(),
    ]);

    $existingBatch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_ANALYZED,
    ]);

    CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $existingBatch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'email' => 'existing-prospect@example.com',
        'email_normalized' => 'existing-prospect@example.com',
        'status' => CampaignProspect::STATUS_SCORED,
        'match_status' => CampaignProspect::MATCH_NONE,
    ]);

    CustomerOptOut::query()->create([
        'user_id' => $owner->id,
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => 'blocked@example.com',
        'destination_hash' => hash('sha256', 'blocked@example.com'),
        'opted_out_at' => now(),
    ]);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'prospects' => [
            [
                'company_name' => 'Growth Agency',
                'contact_name' => 'Alice Growth',
                'email' => 'alice@example.com',
                'website' => 'growthagency.com',
                'industry' => 'growth automation',
                'owner_notes' => 'needs quote and demo',
            ],
            [
                'company_name' => 'Customer Match Inc',
                'contact_name' => 'Bob Existing',
                'email' => 'customer-match@example.com',
            ],
            [
                'company_name' => 'Lead Match Inc',
                'contact_name' => 'Charlie Lead',
                'phone' => '+1 (514) 555-1234',
            ],
            [
                'company_name' => 'Prospect Match Inc',
                'contact_name' => 'Dana Prospect',
                'email' => 'existing-prospect@example.com',
            ],
            [
                'company_name' => 'Blocked Mail Inc',
                'contact_name' => 'Eve Blocked',
                'email' => 'blocked@example.com',
            ],
            [
                'company_name' => '',
                'contact_name' => '',
                'email' => '',
                'phone' => '',
            ],
        ],
    ]);

    $batchId = (int) $response->json('batches.0.id');

    $response->assertCreated()
        ->assertJsonPath('batches.0.accepted_count', 1)
        ->assertJsonPath('batches.0.duplicate_count', 3)
        ->assertJsonPath('batches.0.blocked_count', 1)
        ->assertJsonPath('batches.0.rejected_count', 1)
        ->assertJsonPath('batches.0.scored_count', 5);

    $show = $this->actingAs($owner)->getJson(route('campaigns.prospect-batches.show', [$campaign, $batchId]));
    $show->assertOk()
        ->assertJsonPath('batch.id', $batchId)
        ->assertJsonCount(6, 'prospects.data');

    $accepted = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('email_normalized', 'alice@example.com')->first();
    $duplicateCustomer = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('email_normalized', 'customer-match@example.com')->first();
    $duplicateLead = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('phone_normalized', '+15145551234')->first();
    $duplicateProspect = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('email_normalized', 'existing-prospect@example.com')->first();
    $blocked = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('email_normalized', 'blocked@example.com')->first();
    $rejected = CampaignProspect::query()->where('campaign_prospect_batch_id', $batchId)->where('blocked_reason', 'insufficient_identity')->first();

    expect($accepted?->status)->toBe(CampaignProspect::STATUS_SCORED)
        ->and($accepted?->priority_score)->toBeGreaterThan(0)
        ->and($duplicateCustomer?->match_status)->toBe(CampaignProspect::MATCH_CUSTOMER)
        ->and($duplicateCustomer?->matched_customer_id)->toBe($customer->id)
        ->and($duplicateLead?->match_status)->toBe(CampaignProspect::MATCH_LEAD)
        ->and($duplicateLead?->matched_lead_id)->toBe($lead->id)
        ->and($duplicateProspect?->match_status)->toBe(CampaignProspect::MATCH_PROSPECT)
        ->and($blocked?->status)->toBe(CampaignProspect::STATUS_BLOCKED)
        ->and($blocked?->do_not_contact)->toBeTrue()
        ->and($rejected?->status)->toBe(CampaignProspect::STATUS_DISQUALIFIED);
});

test('prospect import boosts system change and operational buyer signals from provider metadata', function () {
    $owner = batchOwner();
    $product = batchProduct($owner);
    $campaign = prospectingCampaign($owner, $product);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_CONNECTOR,
        'source_reference' => 'Apollo system-change search',
        'prospects' => [
            [
                'company_name' => 'Workflow Rescue',
                'contact_name' => 'Nora Blais',
                'email' => 'nora@workflow-rescue.example',
                'website' => 'workflow-rescue.example',
                'industry' => 'Home services',
                'company_size' => '11-50',
                'tags' => ['Operations Manager', 'Apollo'],
                'metadata' => [
                    'provider_key' => 'apollo_api',
                    'provider_query' => 'companies in Montreal that want to change system',
                    'provider_query_label' => 'Systeme instable',
                    'apollo_title' => 'Operations Manager',
                ],
            ],
        ],
    ]);

    $batchId = (int) $response->json('batches.0.id');

    $response->assertCreated()
        ->assertJsonPath('batches.0.accepted_count', 1)
        ->assertJsonPath('batches.0.scored_count', 1);

    $prospect = CampaignProspect::query()
        ->where('campaign_prospect_batch_id', $batchId)
        ->firstOrFail();

    expect($prospect->status)->toBe(CampaignProspect::STATUS_SCORED)
        ->and($prospect->fit_score)->toBeGreaterThanOrEqual(70)
        ->and($prospect->intent_score)->toBeGreaterThanOrEqual(70)
        ->and(data_get($prospect->metadata, 'score_reasons'))->toContain('system_change_signal')
        ->and(data_get($prospect->metadata, 'score_reasons'))->toContain('operational_buyer_signal')
        ->and(data_get($prospect->metadata, 'score_reasons'))->toContain('low_software_maturity_signal');
});

test('analyzed batch can be listed and approved for outreach', function () {
    $owner = batchOwner();
    $product = batchProduct($owner);
    $campaign = prospectingCampaign($owner, $product);

    $import = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'prospects' => [
            [
                'company_name' => 'Approved One',
                'contact_name' => 'Alice Approved',
                'email' => 'approved-one@example.com',
                'website' => 'approved-one.example.com',
                'industry' => 'growth automation',
            ],
            [
                'company_name' => 'Approved Two',
                'contact_name' => 'Bob Approved',
                'email' => 'approved-two@example.com',
                'website' => 'approved-two.example.com',
                'industry' => 'growth automation',
            ],
        ],
    ]);

    $batchId = (int) $import->json('batches.0.id');

    $this->actingAs($owner)->getJson(route('campaigns.prospect-batches.index', $campaign))
        ->assertOk()
        ->assertJsonCount(1, 'batches')
        ->assertJsonPath('batches.0.id', $batchId);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.approve', [$campaign, $batchId]));

    $response->assertOk()
        ->assertJsonPath('batch.id', $batchId)
        ->assertJsonPath('batch.status', CampaignProspectBatch::STATUS_APPROVED)
        ->assertJsonPath('batch.approved_by_user_id', $owner->id);

    $batch = CampaignProspectBatch::query()->findOrFail($batchId);
    $approvedProspects = CampaignProspect::query()
        ->where('campaign_prospect_batch_id', $batchId)
        ->pluck('status')
        ->all();

    expect($batch->status)->toBe(CampaignProspectBatch::STATUS_APPROVED)
        ->and($batch->approved_by_user_id)->toBe($owner->id)
        ->and($batch->approved_at)->not->toBeNull()
        ->and($batch->analysis_summary['review_decision'] ?? null)->toBe('approved')
        ->and($batch->analysis_summary['review_required_count'] ?? null)->toBe(0)
        ->and($approvedProspects)->toBe([
            CampaignProspect::STATUS_APPROVED,
            CampaignProspect::STATUS_APPROVED,
        ])
        ->and(CampaignProspectActivity::query()
            ->where('campaign_id', $campaign->id)
            ->where('activity_type', 'approved')
            ->count())->toBe(2);
});

test('analyzed batch can be rejected before outreach', function () {
    $owner = batchOwner();
    $product = batchProduct($owner);
    $campaign = prospectingCampaign($owner, $product);

    $import = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_MANUAL,
        'prospects' => [
            [
                'company_name' => 'Rejected Co',
                'contact_name' => 'Rita Review',
                'email' => 'rejected@example.com',
                'website' => 'rejected.example.com',
                'industry' => 'growth automation',
            ],
        ],
    ]);

    $batchId = (int) $import->json('batches.0.id');

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.reject', [$campaign, $batchId]));

    $response->assertOk()
        ->assertJsonPath('batch.id', $batchId)
        ->assertJsonPath('batch.status', CampaignProspectBatch::STATUS_CANCELED);

    $batch = CampaignProspectBatch::query()->findOrFail($batchId);
    $prospect = CampaignProspect::query()
        ->where('campaign_prospect_batch_id', $batchId)
        ->firstOrFail();

    expect($batch->status)->toBe(CampaignProspectBatch::STATUS_CANCELED)
        ->and($batch->approved_at)->toBeNull()
        ->and($batch->approved_by_user_id)->toBeNull()
        ->and($batch->analysis_summary['review_decision'] ?? null)->toBe('rejected')
        ->and($batch->analysis_summary['review_required_count'] ?? null)->toBe(0)
        ->and($prospect->status)->toBe(CampaignProspect::STATUS_DISQUALIFIED)
        ->and($prospect->blocked_reason)->toBe('batch_rejected')
        ->and($prospect->metadata['review']['decision'] ?? null)->toBe('rejected')
        ->and(CampaignProspectActivity::query()
            ->where('campaign_prospect_id', $prospect->id)
            ->where('activity_type', 'rejected')
            ->count())->toBe(1);
});
