<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Jobs\DispatchCampaignRunJob;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\RenderedTemplatePreviewMail;
use App\Models\Campaign;
use App\Models\CampaignProspect;
use App\Models\CampaignProspectActivity;
use App\Models\CampaignProspectBatch;
use App\Models\CampaignProspectProviderConnection;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerOptOut;
use App\Models\MailingList;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Campaigns\AudienceResolver;
use App\Services\Campaigns\CampaignRunProgressService;
use App\Services\Campaigns\CampaignService;
use App\Services\Campaigns\CampaignTrackingService;
use App\Services\Campaigns\ConsentService;
use App\Services\Campaigns\EmailTemplateComposer;
use App\Services\Campaigns\FatigueLimiter;
use App\Services\Campaigns\MarketingSettingsService;
use App\Services\Campaigns\TemplateLibraryService;
use App\Services\Campaigns\TemplateRenderer;
use App\Services\Campaigns\TemplateSeederService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

function marketingTeamMember(User $owner, array $permissions = [], array $userOverrides = [], array $membershipOverrides = []): User
{
    $member = User::factory()->create(array_merge([
        'email' => 'member-'.Str::lower(Str::random(12)).'@example.com',
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
        'company_type' => $owner->company_type,
    ], $userOverrides));

    TeamMember::query()->create(array_merge([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => $permissions,
        'is_active' => true,
    ], $membershipOverrides));

    return $member;
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

function allowColdOutbound(User $owner): void
{
    /** @var MarketingSettingsService $service */
    $service = app(MarketingSettingsService::class);
    $service->update($owner, [
        'consent' => [
            'default_behavior' => 'allow_without_explicit',
            'require_explicit' => false,
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

function fakeApolloHealthCheck(int $status = 200, array $body = ['ok' => true]): void
{
    Http::fake([
        'https://api.apollo.io/v1/auth/health' => Http::response($body, $status),
        '*' => Http::response(['message' => 'Unexpected Apollo request'], 500),
    ]);
}

function fakeApolloPreviewResponses(array $people, array $matches): void
{
    Http::fake([
        'https://api.apollo.io/api/v1/mixed_people/api_search' => Http::response([
            'people' => $people,
        ], 200),
        'https://api.apollo.io/api/v1/people/bulk_match' => Http::response([
            'matches' => $matches,
        ], 200),
        '*' => Http::response(['message' => 'Unexpected Apollo request'], 500),
    ]);
}

function fakeLushaValidationResponse(int $status = 200, array $body = ['items' => [['value' => 'sales']]]): void
{
    Http::fake([
        'https://api.lusha.com/prospecting/filters/contacts/departments' => Http::response($body, $status),
        '*' => Http::response(['message' => 'Unexpected Lusha request'], 500),
    ]);
}

function fakeLushaPreviewResponses(array $contacts, array $enrichedContacts, string $requestId = 'lusha-request-1'): void
{
    Http::fake([
        'https://api.lusha.com/prospecting/contact/search' => Http::response([
            'requestId' => $requestId,
            'contacts' => $contacts,
        ], 200),
        'https://api.lusha.com/prospecting/contact/enrich' => Http::response([
            'contacts' => $enrichedContacts,
        ], 200),
        '*' => Http::response(['message' => 'Unexpected Lusha request'], 500),
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    $dispatcher = DB::connection()->getEventDispatcher();
    DB::connection()->unsetEventDispatcher();
    try {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }
    } finally {
        DB::connection()->setEventDispatcher($dispatcher);
    }
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

test('marketing owner can create validate and disconnect prospect provider connections', function () {
    $owner = marketingOwner();
    fakeApolloHealthCheck();

    $create = $this->actingAs($owner)->postJson(route('marketing.prospect-providers.store'), [
        'provider_key' => 'apollo',
        'label' => 'Apollo main',
        'credentials' => [
            'api_key' => 'apollo-secret-12345',
        ],
    ]);

    $create->assertCreated()
        ->assertJsonPath('provider_connection.provider_key', 'apollo')
        ->assertJsonPath('provider_connection.label', 'Apollo main')
        ->assertJsonMissingPath('provider_connection.credentials');

    $connectionId = (int) $create->json('provider_connection.id');
    $connection = CampaignProspectProviderConnection::query()->findOrFail($connectionId);

    expect($connection->status)->toBe(CampaignProspectProviderConnection::STATUS_DRAFT);
    expect($connection->credentials)->toBe(['api_key' => 'apollo-secret-12345']);
    expect((string) $connection->getRawOriginal('credentials'))->not->toContain('apollo-secret-12345');

    $validated = $this->actingAs($owner)->postJson(route('marketing.prospect-providers.validate', $connectionId));

    $validated->assertOk()
        ->assertJsonPath('provider_connection.status', CampaignProspectProviderConnection::STATUS_CONNECTED)
        ->assertJsonPath('provider_connection.is_active', true);

    $connection->refresh();
    expect($connection->last_validated_at)->not->toBeNull();

    $disconnected = $this->actingAs($owner)->postJson(route('marketing.prospect-providers.disconnect', $connectionId));

    $disconnected->assertOk()
        ->assertJsonPath('provider_connection.status', CampaignProspectProviderConnection::STATUS_DISCONNECTED)
        ->assertJsonPath('provider_connection.is_active', false);
});

test('marketing owner can update prospect provider connection without replacing saved secret', function () {
    $owner = marketingOwner();

    $connection = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha primary',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => true,
    ]);

    $update = $this->actingAs($owner)->putJson(route('marketing.prospect-providers.update', $connection), [
        'label' => 'Lusha updated',
        'credentials' => [
            'api_key' => '',
        ],
    ]);

    $update->assertOk()
        ->assertJsonPath('provider_connection.label', 'Lusha updated')
        ->assertJsonPath('provider_connection.provider_key', CampaignProspectProviderConnection::PROVIDER_LUSHA);

    $connection->refresh();
    expect($connection->label)->toBe('Lusha updated');
    expect($connection->credentials)->toBe(['api_key' => 'lusha-secret-12345']);
});

test('marketing meta exposes prospect provider definitions and connections', function () {
    $owner = marketingOwner();

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_UPLEAD,
        'label' => 'UpLead account',
        'credentials' => ['api_key' => 'uplead-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    $response = $this->actingAs($owner)->getJson(route('marketing.meta'));

    $response->assertOk()
        ->assertJsonPath('prospect_provider_connections.0.provider_key', CampaignProspectProviderConnection::PROVIDER_UPLEAD)
        ->assertJsonPath('prospect_provider_connections.0.label', 'UpLead account');

    $providerKeys = collect($response->json('prospect_providers'))->pluck('key');
    expect($providerKeys)->toContain('apollo');
    expect($providerKeys)->toContain('lusha');
    expect($providerKeys)->toContain('uplead');
});

test('marketing owner can validate lusha prospect provider connection', function () {
    $owner = marketingOwner();
    fakeLushaValidationResponse();

    $connection = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha main',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => true,
    ]);

    $validated = $this->actingAs($owner)->postJson(route('marketing.prospect-providers.validate', $connection));

    $validated->assertOk()
        ->assertJsonPath('provider_connection.status', CampaignProspectProviderConnection::STATUS_CONNECTED)
        ->assertJsonPath('provider_connection.is_active', true);
});

test('campaign team member can view prospect provider workspace in read only mode', function () {
    $owner = marketingOwner();
    $member = marketingTeamMember($owner, ['campaigns.view']);

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_APOLLO,
        'label' => 'Apollo workspace',
        'credentials' => ['api_key' => 'apollo-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    $page = $this->actingAs($member)->getJson(route('campaigns.prospect-providers.manage'));

    $page->assertOk()
        ->assertJsonPath('access.can_view', true)
        ->assertJsonPath('access.can_manage_secrets', false)
        ->assertJsonPath('provider_connections.0.label', 'Apollo workspace')
        ->assertJsonPath('provider_summary.connected', 1);

    $index = $this->actingAs($member)->getJson(route('marketing.prospect-providers.index'));

    $index->assertOk()
        ->assertJsonPath('access.can_manage_secrets', false)
        ->assertJsonPath('provider_connections.0.provider_key', CampaignProspectProviderConnection::PROVIDER_APOLLO);
});

test('campaign team member cannot mutate prospect provider credentials', function () {
    $owner = marketingOwner();
    $member = marketingTeamMember($owner, ['campaigns.view']);

    $connection = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha readonly',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => true,
    ]);

    $this->actingAs($member)->postJson(route('marketing.prospect-providers.store'), [
        'provider_key' => 'apollo',
        'label' => 'Should fail',
        'credentials' => ['api_key' => 'secret-12345'],
    ])->assertForbidden();

    $this->actingAs($member)->putJson(route('marketing.prospect-providers.update', $connection), [
        'label' => 'Should fail',
    ])->assertForbidden();

    $this->actingAs($member)->postJson(route('marketing.prospect-providers.validate', $connection))
        ->assertForbidden();

    $this->actingAs($member)->postJson(route('marketing.prospect-providers.disconnect', $connection))
        ->assertForbidden();
});

test('campaign index exposes prospect provider summary', function () {
    $owner = marketingOwner();

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_APOLLO,
        'label' => 'Apollo connected',
        'credentials' => ['api_key' => 'apollo-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_UPLEAD,
        'label' => 'UpLead draft',
        'credentials' => ['api_key' => 'uplead-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => false,
    ]);

    $response = $this->actingAs($owner)->getJson(route('campaigns.index'));

    $response->assertOk()
        ->assertJsonPath('prospectProviderSummary.configured', 2)
        ->assertJsonPath('prospectProviderSummary.connected', 1)
        ->assertJsonPath('prospectProviderSummary.attention', 1);
});

test('campaign wizard exposes only connected prospect providers for audience selection', function () {
    $owner = marketingOwner();

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_APOLLO,
        'label' => 'Apollo ready',
        'credentials' => ['api_key' => 'apollo-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha draft',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => false,
    ]);

    $response = $this->actingAs($owner)->getJson(route('campaigns.create'));

    $response->assertOk()
        ->assertJsonCount(1, 'availableProspectProviders')
        ->assertJsonPath('availableProspectProviders.0.provider_key', CampaignProspectProviderConnection::PROVIDER_APOLLO)
        ->assertJsonPath('availableProspectProviders.0.label', 'Apollo ready');
});

test('campaign update persists provider audience selection context', function () {
    $owner = marketingOwner();
    $offer = marketingProduct($owner);

    $provider = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_UPLEAD,
        'label' => 'UpLead ICP',
        'credentials' => ['api_key' => 'uplead-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);

    $campaign = $campaignService->saveCampaign($owner, $owner, [
        'name' => 'Provider audience campaign',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
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
                'subject_template' => 'Subject',
                'body_template' => '<p>Body</p>',
            ],
        ],
        'audience' => [
            'source_logic' => 'UNION',
            'manual_customer_ids' => [],
        ],
    ]);

    $response = $this->actingAs($owner)->putJson(route('campaigns.update', $campaign), [
        'name' => 'Provider audience campaign updated',
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
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
                'subject_template' => 'Subject',
                'body_template' => '<p>Body</p>',
            ],
        ],
        'audience' => [
            'source_logic' => 'UNION',
            'manual_customer_ids' => [],
            'include_mailing_list_ids' => [],
            'exclude_mailing_list_ids' => [],
            'source_summary' => [
                'import_mode' => 'provider',
                'source_type' => 'connector',
                'source_reference' => 'UpLead ICP',
                'provider_connection_id' => $provider->id,
                'provider_key' => CampaignProspectProviderConnection::PROVIDER_UPLEAD,
                'provider_label' => 'UpLead',
                'provider_connection_label' => 'UpLead ICP',
                'provider_query_label' => 'Quebec manufacturing',
                'provider_query' => 'Manufacturing companies in Quebec, procurement, 20-200 employees',
            ],
        ],
    ]);

    $response->assertOk();

    $campaign->refresh()->load('audience');
    $sourceSummary = $campaign->audience?->source_summary ?? [];

    expect($sourceSummary['import_mode'] ?? null)->toBe('provider')
        ->and($sourceSummary['source_type'] ?? null)->toBe('connector')
        ->and($sourceSummary['provider_connection_id'] ?? null)->toBe($provider->id)
        ->and($sourceSummary['provider_key'] ?? null)->toBe(CampaignProspectProviderConnection::PROVIDER_UPLEAD)
        ->and($sourceSummary['provider_connection_label'] ?? null)->toBe('UpLead ICP')
        ->and($sourceSummary['provider_query_label'] ?? null)->toBe('Quebec manufacturing')
        ->and($sourceSummary['provider_query'] ?? null)->toContain('Manufacturing companies in Quebec');
});

test('campaign provider preview returns normalized rows without creating batches', function () {
    $owner = marketingOwner();
    fakeApolloPreviewResponses(
        people: [
            [
                'id' => 'apollo-person-1',
                'first_name' => 'Mia',
                'last_name' => 'Stone',
                'name' => 'Mia Stone',
                'title' => 'VP Operations',
                'linkedin_url' => 'https://linkedin.com/in/mia-stone',
                'organization' => [
                    'id' => 'apollo-org-1',
                    'name' => 'North Retail Group',
                    'website_url' => 'https://north-retail.example',
                    'city' => 'Toronto',
                    'state' => 'Ontario',
                    'country' => 'Canada',
                    'industry' => 'Retail',
                    'estimated_num_employees' => 42,
                ],
            ],
            [
                'id' => 'apollo-person-2',
                'first_name' => 'Noah',
                'last_name' => 'Grant',
                'name' => 'Noah Grant',
                'title' => 'Head of Sales',
                'linkedin_url' => 'https://linkedin.com/in/noah-grant',
                'organization' => [
                    'id' => 'apollo-org-2',
                    'name' => 'Summit Supply',
                    'website_url' => 'https://summit-supply.example',
                    'city' => 'Montreal',
                    'state' => 'Quebec',
                    'country' => 'Canada',
                    'industry' => 'Logistics',
                    'estimated_num_employees' => 120,
                ],
            ],
        ],
        matches: [
            [
                'id' => 'apollo-person-1',
                'email' => 'mia.stone@north-retail.example',
                'phone_numbers' => [
                    ['sanitized_number' => '+14165550111'],
                ],
            ],
            [
                'id' => 'apollo-person-2',
                'email' => 'noah.grant@summit-supply.example',
                'phone_numbers' => [
                    ['sanitized_number' => '+15145550112'],
                ],
            ],
        ],
    );

    $provider = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_APOLLO,
        'label' => 'Apollo outbound',
        'credentials' => ['api_key' => 'apollo-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Provider preview campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-provider-preview', $campaign), [
        'provider_connection_id' => $provider->id,
        'query_label' => 'Toronto retail',
        'query' => 'Retail companies in Toronto, operations, 11-50 employees',
        'limit' => 2,
    ]);

    $response->assertOk()
        ->assertJsonPath('provider_connection.id', $provider->id)
        ->assertJsonPath('provider_connection.provider_key', CampaignProspectProviderConnection::PROVIDER_APOLLO)
        ->assertJsonPath('preview.count', 2)
        ->assertJsonPath('rows.0.provider_key', CampaignProspectProviderConnection::PROVIDER_APOLLO)
        ->assertJsonPath('rows.0.source_type', CampaignProspect::SOURCE_CONNECTOR)
        ->assertJsonPath('rows.0.source_reference', 'Apollo outbound')
        ->assertJsonPath('rows.0.metadata.provider_preview', true)
        ->assertJsonPath('rows.0.metadata.apollo_person_id', 'apollo-person-1')
        ->assertJsonPath('rows.0.metadata.apollo_organization_id', 'apollo-org-1')
        ->assertJsonPath('rows.0.email', 'mia.stone@north-retail.example')
        ->assertJsonPath('rows.0.phone', '+14165550111');

    expect((string) data_get($response->json(), 'rows.0.preview_ref'))->not->toBe('')
        ->and(CampaignProspectBatch::query()->count())->toBe(0);
});

test('campaign provider preview rejects disconnected provider connection', function () {
    $owner = marketingOwner();

    $provider = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha pending',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_DRAFT,
        'is_active' => false,
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Provider preview blocked',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-provider-preview', $campaign), [
        'provider_connection_id' => $provider->id,
        'query' => 'Construction, Quebec',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['provider_connection_id']);
});

test('campaign provider selection import reuses prospect batch analysis pipeline', function () {
    $owner = marketingOwner();
    allowColdOutbound($owner);

    $existingCustomer = marketingCustomer($owner, [
        'email' => 'duplicate.provider@example.com',
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Provider import campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Hello {firstName}',
        'body_template' => 'Body',
    ]);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_CONNECTOR,
        'source_reference' => 'Apollo outbound',
        'batch_size' => 100,
        'prospects' => [
            [
                'external_ref' => 'APOLLO-ROW-001',
                'source_reference' => 'Apollo outbound',
                'company_name' => 'North Retail Group',
                'contact_name' => 'Mia Stone',
                'first_name' => 'Mia',
                'last_name' => 'Stone',
                'email' => 'mia.stone@north-retail.example',
                'website' => 'north-retail.example',
                'city' => 'Toronto',
                'country' => 'Canada',
                'industry' => 'Retail',
                'tags' => ['Apollo', 'Retail'],
                'metadata' => [
                    'provider_key' => 'apollo',
                    'provider_preview_ref' => 'APOLLO-PREVIEW-001',
                ],
            ],
            [
                'external_ref' => 'APOLLO-ROW-002',
                'source_reference' => 'Apollo outbound',
                'company_name' => 'Duplicate Retail Group',
                'contact_name' => 'Dup Prospect',
                'email' => $existingCustomer->email,
                'website' => 'duplicate-retail.example',
                'city' => 'Toronto',
                'country' => 'Canada',
                'industry' => 'Retail',
                'metadata' => [
                    'provider_key' => 'apollo',
                    'provider_preview_ref' => 'APOLLO-PREVIEW-002',
                ],
            ],
            [
                'external_ref' => 'APOLLO-ROW-003',
                'source_reference' => 'Apollo outbound',
                'company_name' => 'No Destination Inc',
                'contact_name' => 'Blocked Prospect',
                'city' => 'Montreal',
                'country' => 'Canada',
                'industry' => 'Services',
                'metadata' => [
                    'provider_key' => 'apollo',
                    'provider_preview_ref' => 'APOLLO-PREVIEW-003',
                ],
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('batches.0.source_type', CampaignProspect::SOURCE_CONNECTOR)
        ->assertJsonPath('batches.0.source_reference', 'Apollo outbound')
        ->assertJsonPath('batches.0.input_count', 3)
        ->assertJsonPath('batches.0.accepted_count', 1)
        ->assertJsonPath('batches.0.duplicate_count', 1)
        ->assertJsonPath('batches.0.blocked_count', 1)
        ->assertJsonPath('total_imported', 3);

    $batch = CampaignProspectBatch::query()->firstOrFail();
    $accepted = CampaignProspect::query()->where('external_ref', 'APOLLO-ROW-001')->firstOrFail();
    $duplicate = CampaignProspect::query()->where('external_ref', 'APOLLO-ROW-002')->firstOrFail();
    $blocked = CampaignProspect::query()->where('external_ref', 'APOLLO-ROW-003')->firstOrFail();

    expect($batch->status)->toBe(CampaignProspectBatch::STATUS_ANALYZED)
        ->and((int) $batch->accepted_count)->toBe(1)
        ->and((int) $batch->duplicate_count)->toBe(1)
        ->and((int) $batch->blocked_count)->toBe(1)
        ->and($accepted->status)->toBe(CampaignProspect::STATUS_SCORED)
        ->and($accepted->source_reference)->toBe('Apollo outbound')
        ->and(data_get($accepted->metadata, 'provider_key'))->toBe('apollo')
        ->and(data_get($accepted->metadata, 'provider_preview_ref'))->toBe('APOLLO-PREVIEW-001')
        ->and($duplicate->status)->toBe(CampaignProspect::STATUS_DUPLICATE)
        ->and($duplicate->match_status)->toBe(CampaignProspect::MATCH_CUSTOMER)
        ->and((int) $duplicate->matched_customer_id)->toBe($existingCustomer->id)
        ->and($blocked->status)->toBe(CampaignProspect::STATUS_BLOCKED)
        ->and($blocked->blocked_reason)->toBe('no_destination_for_enabled_channels');
});

test('campaign lusha preview returns enriched rows and imported prospects keep lusha metadata', function () {
    $owner = marketingOwner();
    allowColdOutbound($owner);

    fakeLushaPreviewResponses(
        contacts: [
            [
                'id' => 'lusha-contact-1',
                'fullName' => 'Ava Martin',
                'firstName' => 'Ava',
                'lastName' => 'Martin',
                'jobTitle' => 'Head of Sales',
                'companyId' => 'lusha-company-1',
                'companyName' => 'Bright Logistics',
                'companyWebsite' => 'https://bright-logistics.example',
                'city' => 'Montreal',
                'country' => 'Canada',
                'linkedinUrl' => 'https://linkedin.com/in/ava-martin',
            ],
        ],
        enrichedContacts: [
            [
                'id' => 'lusha-contact-1',
                'emails' => [
                    ['email' => 'ava.martin@bright-logistics.example'],
                ],
                'phones' => [
                    ['number' => '+15145550222'],
                ],
                'company' => [
                    'id' => 'lusha-company-1',
                    'name' => 'Bright Logistics',
                    'website' => 'https://bright-logistics.example',
                    'industry' => 'Logistics',
                    'employeeCountRange' => '51-200',
                    'city' => 'Montreal',
                    'country' => 'Canada',
                ],
            ],
        ],
    );

    $provider = CampaignProspectProviderConnection::query()->create([
        'user_id' => $owner->id,
        'provider_key' => CampaignProspectProviderConnection::PROVIDER_LUSHA,
        'label' => 'Lusha outbound',
        'credentials' => ['api_key' => 'lusha-secret-12345'],
        'status' => CampaignProspectProviderConnection::STATUS_CONNECTED,
        'is_active' => true,
        'last_validated_at' => now(),
    ]);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Lusha preview campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);

    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Hello {firstName}',
        'body_template' => 'Body',
    ]);

    $preview = $this->actingAs($owner)->postJson(route('campaigns.prospect-provider-preview', $campaign), [
        'provider_connection_id' => $provider->id,
        'query_label' => 'Montreal logistics',
        'query' => 'Logistics companies in Montreal, head of sales',
        'limit' => 1,
    ]);

    $preview->assertOk()
        ->assertJsonPath('rows.0.provider_key', CampaignProspectProviderConnection::PROVIDER_LUSHA)
        ->assertJsonPath('rows.0.email', 'ava.martin@bright-logistics.example')
        ->assertJsonPath('rows.0.phone', '+15145550222')
        ->assertJsonPath('rows.0.metadata.lusha_contact_id', 'lusha-contact-1')
        ->assertJsonPath('rows.0.metadata.lusha_company_id', 'lusha-company-1');

    $previewRow = $preview->json('rows.0');

    $import = $this->actingAs($owner)->postJson(route('campaigns.prospect-batches.import', $campaign), [
        'source_type' => CampaignProspect::SOURCE_CONNECTOR,
        'source_reference' => 'Lusha outbound',
        'prospects' => [[
            'external_ref' => $previewRow['external_ref'],
            'source_reference' => $previewRow['source_reference'],
            'company_name' => $previewRow['company_name'],
            'contact_name' => $previewRow['contact_name'],
            'first_name' => $previewRow['first_name'],
            'last_name' => $previewRow['last_name'],
            'email' => $previewRow['email'],
            'phone' => $previewRow['phone'],
            'website' => $previewRow['website'],
            'city' => $previewRow['city'],
            'state' => $previewRow['state'],
            'country' => $previewRow['country'],
            'industry' => $previewRow['industry'],
            'company_size' => $previewRow['company_size'],
            'tags' => $previewRow['tags'],
            'metadata' => $previewRow['metadata'],
        ]],
    ]);

    $import->assertCreated()
        ->assertJsonPath('batches.0.accepted_count', 1)
        ->assertJsonPath('batches.0.source_reference', 'Lusha outbound');

    $prospect = CampaignProspect::query()->where('external_ref', 'lusha-contact-1')->firstOrFail();

    expect($prospect->status)->toBe(CampaignProspect::STATUS_SCORED)
        ->and(data_get($prospect->metadata, 'lusha_contact_id'))->toBe('lusha-contact-1')
        ->and(data_get($prospect->metadata, 'lusha_company_id'))->toBe('lusha-company-1')
        ->and(data_get($prospect->metadata, 'lusha_search_result'))->toBeTrue();
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

test('template renderer resolves campaign id token in context', function () {
    $owner = marketingOwner();
    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Context campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
    ]);
    $campaign->setRelation('user', $owner);

    /** @var TemplateRenderer $renderer */
    $renderer = app(TemplateRenderer::class);
    $context = $renderer->buildContext($campaign);

    expect($renderer->render('/campaigns/{campaignId}', $context))->toBe('/campaigns/'.$campaign->id);
});

test('email template composer compiles simple three-section schema', function () {
    /** @var EmailTemplateComposer $composer */
    $composer = app(EmailTemplateComposer::class);

    $html = $composer->compile([
        'editorMode' => 'builder',
        'schema' => [
            'sections' => [
                [
                    'key' => 'header',
                    'background_mode' => 'highlight',
                    'column_count' => 2,
                    'columns' => [
                        [
                            'kicker' => 'Promotion',
                            'title' => 'Hello {firstName}',
                            'body' => 'Discover {offerName}',
                            'button_label' => 'Shop now',
                            'button_url' => '{trackedCtaUrl}',
                        ],
                        [
                            'title' => 'Save {promoPercent}%',
                            'body' => 'Use code {promoCode}',
                            'image_url' => '{offerImageUrl}',
                        ],
                    ],
                ],
                [
                    'key' => 'body',
                    'background_mode' => 'soft',
                    'text_align' => 'center',
                    'spacing_top' => 'compact',
                    'spacing_bottom' => 'spacious',
                    'cta_style' => 'outline',
                    'column_count' => 1,
                    'columns' => [
                        [
                            'title' => 'Main details',
                            'body' => 'Amount {amountFormatted}',
                            'button_label' => 'Learn more',
                            'button_url' => '{ctaUrl}',
                        ],
                    ],
                ],
                [
                    'key' => 'footer',
                    'enabled' => false,
                    'column_count' => 1,
                    'columns' => [
                        [
                            'title' => 'Need help?',
                            'body' => 'Call {brandPhone}',
                            'button_label' => 'Contact us',
                            'button_url' => '{brandContactUrl}',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    expect($html)->toContain('Hello {firstName}');
    expect($html)->toContain('Save {promoPercent}%');
    expect($html)->toContain('Main details');
    expect($html)->not->toContain('Need help?');
    expect($html)->toContain('{brandLogoUrl}');
    expect($html)->toContain('border-top:3px solid {brandPrimaryColor};');
    expect($html)->toContain('background:{brandSurfaceColor};');
    expect($html)->toContain('text-align:center;');
    expect($html)->toContain('border:1px solid {brandPrimaryColor};');
    expect($html)->not->toContain('linear-gradient');
});

test('marketing template image upload stores file and returns public url', function () {
    Storage::fake('public');

    $owner = marketingOwner();

    $response = $this->actingAs($owner)->post(route('marketing.templates.upload-image'), [
        'image' => UploadedFile::fake()->image('hero-banner.png', 1200, 800),
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Image uploaded.');

    $path = $response->json('path');
    expect($path)->toBeString()->not->toBe('');
    Storage::disk('public')->assertExists($path);
    expect($response->json('url'))->toBe(Storage::disk('public')->url($path));
});

test('marketing template test send delivers rendered email to controlled recipient', function () {
    Mail::fake();

    $owner = marketingOwner([
        'email' => 'owner-test@example.com',
    ]);

    $response = $this->actingAs($owner)->postJson(route('marketing.templates.test-send'), [
        'channel' => Campaign::CHANNEL_EMAIL,
        'recipient_email' => 'qa-recipient@example.com',
        'content' => [
            'subject' => 'Template preview',
            'editorMode' => 'html',
            'html' => '<p>Rendered body</p>',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('recipient_email', 'qa-recipient@example.com')
        ->assertJsonPath('message', 'Test email sent to qa-recipient@example.com.');

    Mail::assertSent(RenderedTemplatePreviewMail::class, function (RenderedTemplatePreviewMail $mail): bool {
        return $mail->hasTo('qa-recipient@example.com')
            && $mail->subjectLine === 'Template preview'
            && $mail->htmlBody === '<p>Rendered body</p>';
    });
});

test('marketing template test send rejects invalid tokens', function () {
    Mail::fake();

    $owner = marketingOwner([
        'email' => 'owner-invalid@example.com',
    ]);

    $response = $this->actingAs($owner)->postJson(route('marketing.templates.test-send'), [
        'channel' => Campaign::CHANNEL_EMAIL,
        'recipient_email' => 'qa-recipient@example.com',
        'content' => [
            'subject' => 'Template {missingToken}',
            'editorMode' => 'html',
            'html' => '<p>Hello world</p>',
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['content']);

    Mail::assertNothingOutgoing();
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
        app(\App\Services\Campaigns\CampaignProspectingOutreachService::class),
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

test('prospecting dispatch resolves approved prospects and syncs outreach timeline', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    allowColdOutbound($owner);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Outbound prospecting timeline',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
        'settings' => [
            'prospecting_sequence' => [
                'enabled' => true,
                'max_steps' => 3,
                'follow_up_delays_hours' => [1, 24],
            ],
        ],
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Hello {firstName}',
        'body_template' => 'Offer for {companyName}',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'company_name' => 'Acme Prospect',
        'contact_name' => 'Alice Prospect',
        'first_name' => 'Alice',
        'last_name' => 'Prospect',
        'email' => 'alice.prospect@example.com',
        'email_normalized' => 'alice.prospect@example.com',
        'status' => CampaignProspect::STATUS_APPROVED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'priority_score' => 88,
        'metadata' => [
            'available_channels' => [Campaign::CHANNEL_EMAIL],
        ],
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);
    $run = $campaignService->queueRun($campaign->fresh(['channels', 'user']), $owner);

    Queue::assertPushed(DispatchCampaignRunJob::class, fn (DispatchCampaignRunJob $job) => $job->campaignRunId === $run->id);

    (new DispatchCampaignRunJob($run->id))->handle(
        app(AudienceResolver::class),
        app(CampaignTrackingService::class),
        app(CampaignRunProgressService::class),
    );

    $recipient = CampaignRecipient::query()
        ->where('campaign_run_id', $run->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->first();

    expect($recipient)->not->toBeNull()
        ->and(data_get($recipient?->metadata, 'source'))->toBe('prospecting')
        ->and(data_get($recipient?->metadata, 'prospect_id'))->toBe($prospect->id)
        ->and(data_get($recipient?->metadata, 'outreach_phase'))->toBe('first_touch');

    /** @var CampaignTrackingService $trackingService */
    $trackingService = app(CampaignTrackingService::class);
    $trackingService->markSent($recipient);
    $trackingService->markOpened($recipient, ['source' => 'tracking_pixel']);
    $trackingService->markClicked($recipient, ['source' => 'tracking_link']);

    $prospect->refresh();
    $batch->refresh();

    expect($prospect->status)->toBe(CampaignProspect::STATUS_CONTACTED)
        ->and($prospect->first_contacted_at)->not->toBeNull()
        ->and(data_get($prospect->metadata, 'sequence.current_step'))->toBe(1)
        ->and(data_get($prospect->metadata, 'sequence.next_follow_up_at'))->not->toBeNull()
        ->and($batch->contacted_count)->toBe(1)
        ->and(CampaignProspectActivity::query()
            ->where('campaign_prospect_id', $prospect->id)
            ->whereIn('activity_type', ['outreach_sent', 'outreach_opened', 'outreach_clicked'])
            ->count())->toBe(3);
});

test('prospecting follow up due prospects become audience again on later runs', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    allowColdOutbound($owner);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospecting follow up',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
        'settings' => [
            'prospecting_sequence' => [
                'enabled' => true,
                'max_steps' => 3,
                'follow_up_delays_hours' => [1, 24],
            ],
        ],
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Follow up {firstName}',
        'body_template' => 'Still interested, {companyName}?',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'company_name' => 'Follow Up Inc',
        'contact_name' => 'Follow Prospect',
        'first_name' => 'Follow',
        'email' => 'followup@example.com',
        'email_normalized' => 'followup@example.com',
        'status' => CampaignProspect::STATUS_APPROVED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'metadata' => [
            'available_channels' => [Campaign::CHANNEL_EMAIL],
        ],
    ]);

    /** @var CampaignService $campaignService */
    $campaignService = app(CampaignService::class);
    $run = $campaignService->queueRun($campaign->fresh(['channels', 'user']), $owner);

    (new DispatchCampaignRunJob($run->id))->handle(
        app(AudienceResolver::class),
        app(CampaignTrackingService::class),
        app(CampaignRunProgressService::class),
    );

    $recipient = CampaignRecipient::query()
        ->where('campaign_run_id', $run->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->firstOrFail();

    app(CampaignTrackingService::class)->markSent($recipient);

    Carbon::setTestNow(now()->addHours(2));
    $resolved = app(AudienceResolver::class)->resolveForCampaign($campaign->fresh(['channels', 'user']));
    Carbon::setTestNow();

    expect((int) ($resolved['counts']['total_eligible'] ?? 0))->toBe(1)
        ->and(data_get($resolved, 'eligible.0.metadata.outreach_phase'))->toBe('follow_up_1')
        ->and($prospect->fresh()->status)->toBe(CampaignProspect::STATUS_FOLLOW_UP_DUE);
});

test('prospecting run cannot queue without approved or due prospects', function () {
    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Empty prospecting campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Subject',
        'body_template' => 'Body',
    ]);

    expect(fn () => app(CampaignService::class)->queueRun($campaign->fresh(['channels', 'user']), $owner))
        ->toThrow(ValidationException::class);
});

test('prospect can be marked do not contact and is suppressed from future outreach', function () {
    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    allowColdOutbound($owner);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospect suppression',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Subject',
        'body_template' => 'Body',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'company_name' => 'Suppress Inc',
        'contact_name' => 'Suppress Me',
        'email' => 'suppress@example.com',
        'email_normalized' => 'suppress@example.com',
        'status' => CampaignProspect::STATUS_APPROVED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'metadata' => [
            'available_channels' => [Campaign::CHANNEL_EMAIL],
        ],
    ]);

    $response = $this->actingAs($owner)->patchJson(route('campaigns.prospects.status', [$campaign, $prospect]), [
        'status' => CampaignProspect::STATUS_DO_NOT_CONTACT,
        'reason' => 'requested_removal',
        'note' => 'Prospect asked to stop outreach.',
    ]);

    $response->assertOk()
        ->assertJsonPath('prospect.status', CampaignProspect::STATUS_DO_NOT_CONTACT)
        ->assertJsonPath('prospect.do_not_contact', true);

    $detail = $this->actingAs($owner)->getJson(route('campaigns.prospects.show', [$campaign, $prospect]));
    $detail->assertOk()
        ->assertJsonPath('prospect.activities.0.activity_type', 'manual_status_updated');

    $prospect->refresh();
    expect($prospect->do_not_contact)->toBeTrue()
        ->and($prospect->blocked_reason)->toBe('requested_removal');

    expect(CustomerOptOut::query()
        ->where('user_id', $owner->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->where('destination_hash', CampaignRecipient::destinationHash('suppress@example.com'))
        ->exists())->toBeTrue();

    $resolved = app(AudienceResolver::class)->resolveForCampaign($campaign->fresh(['channels', 'user']));
    expect((int) ($resolved['counts']['total_eligible'] ?? 0))->toBe(0);
});

test('prospecting fallback resolves alternate destinations from prospect metadata', function () {
    Queue::fake();

    $owner = marketingOwner();
    disableMarketingQuietHours($owner);
    allowColdOutbound($owner);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'name' => 'Prospect fallback campaign',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
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
        'subject_template' => 'Fallback {firstName}',
        'body_template' => 'Fallback body',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'company_name' => 'Fallback Prospect',
        'first_name' => 'Pat',
        'email' => 'prospect-fallback@example.com',
        'email_normalized' => 'prospect-fallback@example.com',
        'phone' => '+15145550011',
        'phone_normalized' => '+15145550011',
        'status' => CampaignProspect::STATUS_APPROVED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'metadata' => [
            'available_channels' => [Campaign::CHANNEL_SMS, Campaign::CHANNEL_EMAIL],
        ],
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
        'channel' => Campaign::CHANNEL_SMS,
        'destination' => '+15145550011',
        'destination_hash' => CampaignRecipient::destinationHash('+15145550011'),
        'status' => CampaignRecipient::STATUS_QUEUED,
        'queued_at' => now(),
        'metadata' => [
            'source' => 'prospecting',
            'prospect_id' => $prospect->id,
            'prospect_batch_id' => $batch->id,
            'outreach_phase' => 'first_touch',
            'prospect_context' => [
                'firstName' => 'Pat',
                'companyName' => 'Fallback Prospect',
            ],
            'prospect_destinations' => [
                Campaign::CHANNEL_SMS => '+15145550011',
                Campaign::CHANNEL_EMAIL => 'prospect-fallback@example.com',
            ],
        ],
    ]);

    (new SendCampaignRecipientJob($recipient->id))->handle(
        app(\App\Services\Campaigns\TemplateRenderer::class),
        app(CampaignTrackingService::class),
        app(\App\Services\Campaigns\Providers\CampaignProviderManager::class),
        app(CampaignRunProgressService::class),
        app(ConsentService::class),
        app(FatigueLimiter::class),
        app(\App\Services\Campaigns\CampaignProspectingOutreachService::class),
    );

    $fallbackRecipient = CampaignRecipient::query()
        ->where('campaign_run_id', $run->id)
        ->where('channel', Campaign::CHANNEL_EMAIL)
        ->where('destination_hash', CampaignRecipient::destinationHash('prospect-fallback@example.com'))
        ->first();

    expect($fallbackRecipient)->not->toBeNull()
        ->and((string) $fallbackRecipient?->destination)->toBe('prospect-fallback@example.com')
        ->and(data_get($fallbackRecipient?->metadata, 'fallback.parent_recipient_id'))->toBe($recipient->id);

    Queue::assertPushed(SendCampaignRecipientJob::class, function (SendCampaignRecipientJob $job) use ($fallbackRecipient) {
        return $job->campaignRecipientId === $fallbackRecipient->id;
    });
});

test('prospect conversion creates attributed lead and updates funnel reporting', function () {
    $owner = marketingOwner([
        'company_features' => [
            'campaigns' => true,
            'products' => true,
            'services' => true,
            'sales' => true,
            'requests' => true,
        ],
    ]);
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospect conversion reporting',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_RUNNING,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);
    $campaign->channels()->create([
        'channel' => Campaign::CHANNEL_EMAIL,
        'is_enabled' => true,
        'subject_template' => 'Hello {firstName}',
        'body_template' => 'Let us help {companyName}',
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'approved_by_user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'batch_number' => 1,
        'input_count' => 1,
        'accepted_count' => 1,
        'scored_count' => 1,
        'status' => CampaignProspectBatch::STATUS_APPROVED,
        'approved_at' => now(),
    ]);
    $prospect = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'company_name' => 'Attribution Prospect',
        'contact_name' => 'Alice Attribution',
        'first_name' => 'Alice',
        'last_name' => 'Attribution',
        'email' => 'alice.attribution@example.com',
        'email_normalized' => 'alice.attribution@example.com',
        'status' => CampaignProspect::STATUS_QUALIFIED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'fit_score' => 82,
        'intent_score' => 71,
        'priority_score' => 90,
        'qualification_summary' => 'Prospect asked for a tailored quote.',
        'metadata' => [
            'available_channels' => [Campaign::CHANNEL_EMAIL],
        ],
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
        'channel' => Campaign::CHANNEL_EMAIL,
        'destination' => 'alice.attribution@example.com',
        'destination_hash' => CampaignRecipient::destinationHash('alice.attribution@example.com'),
        'status' => CampaignRecipient::STATUS_CLICKED,
        'queued_at' => now()->subHour(),
        'sent_at' => now()->subHour(),
        'clicked_at' => now()->subMinutes(20),
        'metadata' => [
            'source' => 'prospecting',
            'prospect_id' => $prospect->id,
            'prospect_batch_id' => $batch->id,
            'outreach_phase' => 'follow_up_1',
        ],
    ]);

    $response = $this->actingAs($owner)->postJson(route('campaigns.prospects.convert', [$campaign, $prospect]), [
        'title' => 'Qualified campaign lead',
    ]);

    $response->assertOk()
        ->assertJsonPath('created', true)
        ->assertJsonPath('prospect.status', CampaignProspect::STATUS_CONVERTED_TO_LEAD);

    $lead = LeadRequest::query()->findOrFail((int) $response->json('lead.id'));

    expect(data_get($lead->meta, 'source_kind'))->toBe('campaign_prospecting')
        ->and((int) data_get($lead->meta, 'source_campaign_id'))->toBe($campaign->id)
        ->and((int) data_get($lead->meta, 'source_campaign_recipient_id'))->toBe($recipient->id)
        ->and((int) data_get($lead->meta, 'source_prospect_id'))->toBe($prospect->id)
        ->and((string) data_get($lead->meta, 'source_direction'))->toBe('outbound');

    $prospect->refresh();
    $recipient->refresh();

    expect($prospect->status)->toBe(CampaignProspect::STATUS_CONVERTED_TO_LEAD)
        ->and((int) $prospect->converted_to_lead_id)->toBe($lead->id)
        ->and($recipient->status)->toBe(CampaignRecipient::STATUS_CONVERTED)
        ->and($recipient->converted_at)->not->toBeNull();

    $leadShow = $this->actingAs($owner)->getJson(route('request.show', $lead));
    $leadShow->assertOk()
        ->assertJsonPath('campaignOrigin.campaign.id', $campaign->id)
        ->assertJsonPath('campaignOrigin.prospect.id', $prospect->id)
        ->assertJsonPath('campaignOrigin.direction', 'outbound');

    $campaignShow = $this->actingAs($owner)->getJson(route('campaigns.show', $campaign));
    $campaignShow->assertOk()
        ->assertJsonPath('funnel.stages.prospects', 1)
        ->assertJsonPath('funnel.stages.leads', 1)
        ->assertJsonPath('funnel.stages.customers', 0);

    $lead->update([
        'status' => LeadRequest::STATUS_WON,
        'status_updated_at' => now(),
    ]);

    $campaignShow = $this->actingAs($owner)->getJson(route('campaigns.show', $campaign));
    $campaignShow->assertOk()
        ->assertJsonPath('funnel.stages.customers', 1);
});

test('prospecting workspace supports lead lookup bulk review and dashboard insights', function () {
    $owner = marketingOwner();
    $offer = marketingProduct($owner);

    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Prospecting workspace controls',
        'type' => Campaign::TYPE_PROMOTION,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'campaign_direction' => Campaign::DIRECTION_PROSPECTING_OUTBOUND,
        'prospecting_enabled' => true,
        'offer_mode' => Campaign::OFFER_MODE_PRODUCTS,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
    ]);
    $campaign->offers()->create([
        'offer_type' => 'product',
        'offer_id' => $offer->id,
    ]);

    $batch = CampaignProspectBatch::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'source_reference' => 'workspace.csv',
        'batch_number' => 1,
        'input_count' => 2,
        'accepted_count' => 2,
        'scored_count' => 2,
        'status' => CampaignProspectBatch::STATUS_ANALYZED,
        'analysis_summary' => [
            'review_required_count' => 2,
        ],
    ]);

    $prospectA = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'source_reference' => 'workspace.csv',
        'company_name' => 'Acme North',
        'contact_name' => 'Alice North',
        'email' => 'alice.north@example.com',
        'email_normalized' => 'alice.north@example.com',
        'status' => CampaignProspect::STATUS_SCORED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'priority_score' => 92,
        'fit_score' => 84,
        'intent_score' => 65,
    ]);

    $prospectB = CampaignProspect::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_prospect_batch_id' => $batch->id,
        'user_id' => $owner->id,
        'source_type' => CampaignProspect::SOURCE_IMPORT,
        'source_reference' => 'workspace.csv',
        'company_name' => 'Beta South',
        'contact_name' => 'Bob South',
        'email' => 'bob.south@example.com',
        'email_normalized' => 'bob.south@example.com',
        'status' => CampaignProspect::STATUS_SCORED,
        'match_status' => CampaignProspect::MATCH_NONE,
        'priority_score' => 78,
        'fit_score' => 71,
        'intent_score' => 40,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Acme North existing lead',
        'contact_name' => 'Alice North',
        'contact_email' => 'alice.north@example.com',
        'status_updated_at' => now(),
    ]);

    $lookup = $this->actingAs($owner)->getJson(route('campaigns.prospects.lead-options', [
        'campaign' => $campaign,
        'search' => 'Acme North',
    ]));

    $lookup->assertOk()
        ->assertJsonPath('leads.0.id', $lead->id);

    $approved = $this->actingAs($owner)->patchJson(route('campaigns.prospects.bulk-status', $campaign), [
        'prospect_ids' => [$prospectA->id, $prospectB->id],
        'status' => CampaignProspect::STATUS_APPROVED,
    ]);

    $approved->assertOk()
        ->assertJsonPath('updated_count', 2);

    $dnc = $this->actingAs($owner)->patchJson(route('campaigns.prospects.bulk-status', $campaign), [
        'prospect_ids' => [$prospectB->id],
        'status' => CampaignProspect::STATUS_DO_NOT_CONTACT,
    ]);

    $dnc->assertOk()
        ->assertJsonPath('updated_count', 1);

    $linked = $this->actingAs($owner)->postJson(route('campaigns.prospects.link', [$campaign, $prospectA]), [
        'lead_id' => $lead->id,
    ]);

    $linked->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('lead.id', $lead->id)
        ->assertJsonPath('prospect.converted_lead.id', $lead->id);

    $prospectA->refresh();
    $prospectB->refresh();
    $batch->refresh();
    $lead->refresh();

    expect($prospectA->status)->toBe(CampaignProspect::STATUS_CONVERTED_TO_LEAD)
        ->and((int) $prospectA->converted_to_lead_id)->toBe($lead->id)
        ->and($prospectB->status)->toBe(CampaignProspect::STATUS_DO_NOT_CONTACT)
        ->and($prospectB->do_not_contact)->toBeTrue()
        ->and((int) $batch->accepted_count)->toBe(1)
        ->and((int) $batch->rejected_count)->toBe(1)
        ->and((int) $batch->scored_count)->toBe(0)
        ->and((int) data_get($batch->analysis_summary, 'review_required_count'))->toBe(0)
        ->and((int) data_get($lead->meta, 'source_campaign_id'))->toBe($campaign->id);

    $campaignShow = $this->actingAs($owner)->getJson(route('campaigns.show', $campaign));
    $campaignShow->assertOk()
        ->assertJsonPath('prospectingDashboard.summary.total_batches', 1)
        ->assertJsonPath('prospectingDashboard.summary.converted_leads', 1)
        ->assertJsonPath('prospectingDashboard.summary.do_not_contact_prospects', 1)
        ->assertJsonPath('prospectingDashboard.recent_batches.0.id', $batch->id);
});
