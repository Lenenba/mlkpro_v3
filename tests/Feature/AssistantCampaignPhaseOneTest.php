<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\CampaignChannel;
use App\Models\CampaignOffer;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\MailingList;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\User;
use App\Models\VipTier;
use App\Services\Assistant\AssistantInterpreter;
use App\Services\Assistant\AssistantWorkflowService;
use App\Services\Assistant\CampaignAssistantContextService;
use App\Services\Assistant\OpenAiClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function assistantCampaignOwner(array $overrides = []): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    return User::factory()->create(array_merge([
        'role_id' => $roleId,
        'email' => 'assistant-owner-'.Str::lower(Str::random(12)).'@example.com',
        'company_features' => [
            'assistant' => true,
            'campaigns' => true,
            'products' => true,
            'services' => true,
            'sales' => true,
        ],
        'onboarding_completed_at' => now(),
        'company_type' => 'products',
        'company_name' => 'Assistant Campaign Tenant',
        'company_timezone' => 'America/Toronto',
        'locale' => 'fr',
        'currency_code' => 'CAD',
    ], $overrides));
}

function assistantCampaignProduct(User $owner, array $overrides = []): Product
{
    $categoryId = $overrides['category_id'] ?? ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Assistant Campaign Category '.Str::upper(Str::random(4)),
    ])->id;

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $categoryId,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'name' => 'Assistant Offer '.Str::upper(Str::random(6)),
        'description' => 'Assistant campaign offer',
        'price' => 29.99,
        'stock' => 25,
        'minimum_stock' => 1,
        'is_active' => true,
        'tags' => ['promo', 'spring'],
    ], $overrides));
}

function assistantCampaignCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::factory()->create(array_merge([
        'user_id' => $owner->id,
        'email' => 'campaign-customer-'.Str::lower(Str::random(8)).'@example.com',
        'phone' => '+1514555'.random_int(1000, 9999),
    ], $overrides));
}

function assistantGrantCampaignConsents(User $owner, Customer $customer, array $channels = [Campaign::CHANNEL_EMAIL, Campaign::CHANNEL_SMS]): void
{
    foreach ($channels as $channel) {
        CustomerConsent::query()->create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'status' => CustomerConsent::STATUS_GRANTED,
            'source' => 'assistant-test',
            'granted_at' => now(),
        ]);
    }
}

function assistantSeedCampaignPerformance(
    User $owner,
    Product $offer,
    MessageTemplate $template,
    string $campaignType,
    string $channel,
    int $sent = 12,
    int $clicked = 6,
    int $converted = 3
): Campaign {
    $campaign = Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Historical '.Str::upper(Str::random(6)),
        'type' => $campaignType,
        'campaign_type' => $campaignType,
        'offer_mode' => $offer->item_type === Product::ITEM_TYPE_SERVICE
            ? Campaign::OFFER_MODE_SERVICES
            : Campaign::OFFER_MODE_PRODUCTS,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'status' => Campaign::STATUS_COMPLETED,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'locale' => 'FR',
        'is_marketing' => true,
        'settings' => [],
        'completed_at' => now()->subDay(),
    ]);

    CampaignOffer::query()->create([
        'campaign_id' => $campaign->id,
        'offer_type' => $offer->item_type,
        'offer_id' => $offer->id,
    ]);

    CampaignChannel::query()->create([
        'campaign_id' => $campaign->id,
        'message_template_id' => $template->id,
        'channel' => $channel,
        'is_enabled' => true,
        'subject_template' => $channel === Campaign::CHANNEL_EMAIL ? 'Historique {offerName}' : null,
        'title_template' => $channel === Campaign::CHANNEL_IN_APP ? 'Historique {offerName}' : null,
        'body_template' => $channel === Campaign::CHANNEL_SMS
            ? '{offerName} maintenant {ctaUrl}'
            : '<p>{offerName}</p>',
        'metadata' => [],
    ]);

    $run = CampaignRun::query()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'triggered_by_user_id' => $owner->id,
        'trigger_type' => CampaignRun::TRIGGER_MANUAL,
        'status' => CampaignRun::STATUS_COMPLETED,
        'idempotency_key' => 'history-'.$campaign->id.'-'.Str::lower(Str::random(8)),
        'started_at' => now()->subDays(7),
        'completed_at' => now()->subDays(7),
        'audience_snapshot' => [],
        'summary' => [],
    ]);

    for ($index = 1; $index <= $sent; $index++) {
        $destination = $channel === Campaign::CHANNEL_SMS
            ? '+1514777'.str_pad((string) ($index + $campaign->id), 4, '0', STR_PAD_LEFT)
            : sprintf('history-%d-%d@example.com', $campaign->id, $index);

        CampaignRecipient::query()->create([
            'campaign_run_id' => $run->id,
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'customer_id' => null,
            'channel' => $channel,
            'destination' => $destination,
            'destination_hash' => CampaignRecipient::destinationHash($destination),
            'status' => $index <= $converted
                ? CampaignRecipient::STATUS_CONVERTED
                : ($index <= $clicked ? CampaignRecipient::STATUS_CLICKED : CampaignRecipient::STATUS_DELIVERED),
            'queued_at' => now()->subDays(7),
            'sent_at' => now()->subDays(7),
            'delivered_at' => now()->subDays(7),
            'clicked_at' => $index <= $clicked ? now()->subDays(7) : null,
            'converted_at' => $index <= $converted ? now()->subDays(7) : null,
            'metadata' => [],
        ]);
    }

    return $campaign;
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
    Cache::flush();
    config()->set('services.openai.key', 'test-key');
    config()->set('billing.provider_effective', 'paddle');
});

test('assistant interpreter normalizes draft campaign payload', function () {
    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('chat')->once()->andReturn(['model' => 'test-model']);
    $client->shouldReceive('extractMessage')->once()->andReturn(json_encode([
        'intent' => 'draft_campaign',
        'confidence' => 0.92,
        'campaign' => [
            'objective' => 'Relancer les anciens clients',
            'campaign_type' => 'winback',
            'offer_mode_hint' => 'services',
            'audience_hint' => 'clients inactifs',
            'timing_hint' => 'ce week-end',
            'channel_hints' => ['email', 'sms', 'push'],
            'language_hint' => 'fr',
            'name_hint' => 'Relance weekend',
        ],
    ]));
    $client->shouldReceive('extractUsage')->once()->andReturn([]);

    $interpreter = new AssistantInterpreter($client);
    $result = $interpreter->interpret('Je veux relancer mes anciens clients.');

    expect($result['intent'])->toBe('draft_campaign')
        ->and($result['campaign']['campaign_type'])->toBe(Campaign::TYPE_WINBACK)
        ->and($result['campaign']['offer_mode_hint'])->toBe(Campaign::OFFER_MODE_SERVICES)
        ->and($result['campaign']['channel_hints'])->toBe([Campaign::CHANNEL_EMAIL, Campaign::CHANNEL_SMS])
        ->and($result['campaign']['language_hint'])->toBe(Campaign::LANGUAGE_MODE_FR);
});

test('campaign assistant context service returns compact tenant marketing context', function () {
    $owner = assistantCampaignOwner();

    AudienceSegment::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Inactive 90d',
        'filters' => ['operator' => 'AND', 'rules' => []],
        'exclusions' => ['operator' => 'AND', 'rules' => []],
        'tags' => ['winback'],
        'cached_count' => 42,
        'last_computed_at' => now(),
        'is_shared' => true,
    ]);

    MailingList::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'VIP Spring',
        'tags' => ['vip'],
    ]);

    VipTier::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'code' => 'GOLD',
        'name' => 'Gold',
        'perks' => ['priority'],
        'is_active' => true,
    ]);

    $offer = assistantCampaignProduct($owner, [
        'name' => 'Massage Premium',
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    MessageTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Winback FR Email',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => Campaign::TYPE_WINBACK,
        'language' => 'FR',
        'content' => [
            'subject' => 'Revenez',
            'html' => '<p>Bonjour</p>',
        ],
        'is_default' => true,
    ]);

    Campaign::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Winback Mars',
        'type' => Campaign::TYPE_WINBACK,
        'campaign_type' => Campaign::TYPE_WINBACK,
        'offer_mode' => Campaign::OFFER_MODE_SERVICES,
        'language_mode' => Campaign::LANGUAGE_MODE_FR,
        'status' => Campaign::STATUS_DRAFT,
        'schedule_type' => Campaign::SCHEDULE_MANUAL,
        'is_marketing' => true,
        'settings' => [],
    ]);

    /** @var CampaignAssistantContextService $service */
    $service = app(CampaignAssistantContextService::class);
    $context = $service->build($owner);

    expect($context['available'])->toBeTrue()
        ->and($context['tenant']['company_name'])->toBe('Assistant Campaign Tenant')
        ->and($context['counts']['segments'])->toBe(1)
        ->and($context['counts']['mailing_lists'])->toBe(1)
        ->and($context['counts']['vip_tiers'])->toBe(1)
        ->and($context['counts']['active_offers'])->toBe(1)
        ->and($context['offers'][0]['name'])->toBe($offer->name)
        ->and($context['recent_campaigns'][0]['name'])->toBe('Winback Mars')
        ->and($context['default_templates'][0]['name'])->toBe('Winback FR Email');
});

test('assistant message routes draft campaign intent to campaign assistant flow', function () {
    $owner = assistantCampaignOwner();
    assistantCampaignProduct($owner, [
        'name' => 'Weekend Offer',
    ]);
    $customer = assistantCampaignCustomer($owner);
    assistantGrantCampaignConsents($owner, $customer);

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.95,
        'campaign' => [
            'objective' => 'Relancer les anciens clients',
            'campaign_type' => '',
            'offer_hint' => '',
            'offer_mode_hint' => '',
            'audience_hint' => '',
            'timing_hint' => 'ce week-end',
            'channel_hints' => [],
            'kpi_hint' => '',
            'language_hint' => '',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux relancer mes anciens clients ce week-end.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('action.type', 'campaign_draft_ready')
        ->assertJsonPath('campaign_review.type', 'campaign_review')
        ->assertJsonPath('context.intent', 'draft_campaign')
        ->assertJsonPath('context.campaign.campaign_type', Campaign::TYPE_WINBACK)
        ->assertJsonMissingPath('context.campaign_context');

    expect($response->json('context.campaign.channel_hints'))->toContain(Campaign::CHANNEL_EMAIL)
        ->and($response->json('context.campaign.name_hint'))->not->toBe('')
        ->and($response->json('context.campaign.offer_hint'))->toBe('Weekend Offer');

    $campaign = Campaign::query()
        ->with(['offers', 'channels', 'audience'])
        ->sole();

    expect($campaign->campaign_type)->toBe(Campaign::TYPE_WINBACK)
        ->and($campaign->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($campaign->scheduled_at)->not->toBeNull()
        ->and($campaign->cta_url)->not->toBe('')
        ->and($campaign->offers)->toHaveCount(1)
        ->and($campaign->channels->where('is_enabled', true)->count())->toBeGreaterThan(0)
        ->and($campaign->audience)->not->toBeNull()
        ->and(data_get($campaign->audience?->estimated_counts, 'total_eligible'))->toBeGreaterThan(0)
        ->and($campaign->channels->firstWhere('channel', Campaign::CHANNEL_EMAIL)?->subject_template)->not->toBe('')
        ->and($campaign->channels->firstWhere('channel', Campaign::CHANNEL_EMAIL)?->body_template)->not->toBe('')
        ->and(data_get($campaign->settings, 'assistant.source'))->toBe('assistant');

    $review = $response->json('campaign_review');

    expect($review['campaign_id'])->toBe($campaign->id)
        ->and($review['summary'])->toBeArray()
        ->and($review['proposed'])->toBeArray()
        ->and(collect($review['next_steps'])->pluck('type')->all())->toContain(
            'open_campaign_draft',
            'preview_campaign',
            'test_send_campaign',
            'view_campaign',
        );
});

test('assistant campaign flow regenerates invalid default template content before returning ready', function () {
    $owner = assistantCampaignOwner();
    assistantCampaignProduct($owner, [
        'name' => 'Premium Care',
    ]);
    $customer = assistantCampaignCustomer($owner);
    assistantGrantCampaignConsents($owner, $customer, [Campaign::CHANNEL_EMAIL]);

    $invalidTemplate = MessageTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Invalid Email',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => Campaign::TYPE_WINBACK,
        'language' => 'FR',
        'content' => [
            'subject' => 'Hi {badToken}',
            'html' => '<p>{badToken}</p>',
        ],
        'is_default' => true,
    ]);

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.95,
        'campaign' => [
            'objective' => 'Relancer les anciens clients',
            'campaign_type' => Campaign::TYPE_WINBACK,
            'offer_hint' => '',
            'offer_mode_hint' => '',
            'audience_hint' => '',
            'timing_hint' => '',
            'channel_hints' => [Campaign::CHANNEL_EMAIL],
            'kpi_hint' => '',
            'language_hint' => 'FR',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux relancer mes anciens clients par email.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('action.type', 'campaign_draft_ready')
        ->assertJsonPath('campaign_review.type', 'campaign_review');

    $campaign = Campaign::query()
        ->where('name', 'like', '%Premium Care%')
        ->with('channels')
        ->latest('id')
        ->firstOrFail();

    $emailChannel = $campaign->channels->firstWhere('channel', Campaign::CHANNEL_EMAIL);

    expect($invalidTemplate->id)->toBeGreaterThan(0)
        ->and($emailChannel)->not->toBeNull()
        ->and($emailChannel->message_template_id)->toBe($invalidTemplate->id)
        ->and($emailChannel->subject_template)->not->toContain('{badToken}')
        ->and($emailChannel->body_template)->not->toContain('{badToken}')
        ->and(data_get($campaign->settings, 'assistant.preview_validation.validated'))->toBeTrue();

    $review = $response->json('campaign_review');

    expect(collect($review['needs_confirmation'])->pluck('label')->all())->toContain('Messages generes');
});

test('assistant campaign flow asks for an offer when no active offer exists', function () {
    $owner = assistantCampaignOwner();

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.95,
        'campaign' => [
            'objective' => 'Promouvoir mon nouveau service',
            'campaign_type' => '',
            'offer_hint' => '',
            'offer_mode_hint' => '',
            'audience_hint' => '',
            'timing_hint' => '',
            'channel_hints' => [],
            'kpi_hint' => '',
            'language_hint' => '',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux promouvoir mon nouveau service.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'needs_input')
        ->assertJsonPath('context.intent', 'draft_campaign');

    expect($response->json('questions'))->toContain(
        'Quel produit ou service faut-il mettre en avant dans cette campagne ?'
    );
});

test('assistant campaign flow resolves an explicitly named service even when it is outside compact offer context', function () {
    $owner = assistantCampaignOwner([
        'company_type' => 'services',
    ]);

    $serviceCategoryId = ProductCategory::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'name' => 'Services',
    ])->id;

    $targetService = assistantCampaignProduct($owner, [
        'category_id' => $serviceCategoryId,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'name' => 'Pressure wash',
        'updated_at' => now()->subDays(20),
    ]);
    $targetService->forceFill(['updated_at' => now()->subDays(20)])->save();

    for ($index = 1; $index <= 11; $index++) {
        $product = assistantCampaignProduct($owner, [
            'name' => 'Recent Product '.$index,
            'item_type' => Product::ITEM_TYPE_PRODUCT,
            'updated_at' => now()->subHours($index),
        ]);
        $product->forceFill(['updated_at' => now()->subHours($index)])->save();
    }

    $customer = assistantCampaignCustomer($owner);
    assistantGrantCampaignConsents($owner, $customer, [Campaign::CHANNEL_EMAIL, Campaign::CHANNEL_SMS]);

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.97,
        'campaign' => [
            'objective' => 'Promouvoir mon service pressure wash',
            'campaign_type' => Campaign::TYPE_PROMOTION,
            'offer_hint' => 'Pressure wash',
            'offer_mode_hint' => Campaign::OFFER_MODE_SERVICES,
            'audience_hint' => '',
            'timing_hint' => '',
            'channel_hints' => [],
            'kpi_hint' => '',
            'language_hint' => 'FR',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux faire une nouvelle campagne pour le service pressure wash.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('action.type', 'campaign_draft_ready')
        ->assertJsonPath('context.campaign.offer_hint', 'Pressure wash')
        ->assertJsonMissingPath('questions');

    $campaign = Campaign::query()
        ->latest('id')
        ->with(['offers.offer'])
        ->firstOrFail();

    expect($campaign->offers)->toHaveCount(1)
        ->and((int) $campaign->offers->first()->offer_id)->toBe($targetService->id)
        ->and(data_get($campaign->settings, 'assistant.scoring.offers.0.reasons'))->toContain('offer_hint_database_match');
});

test('assistant campaign flow explains when all current customers are blocked by missing consent', function () {
    $owner = assistantCampaignOwner([
        'company_type' => 'services',
    ]);

    assistantCampaignProduct($owner, [
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'name' => 'Pressure wash',
    ]);

    assistantCampaignCustomer($owner, [
        'email' => 'client-a@example.com',
        'phone' => '+15145550111',
    ]);
    assistantCampaignCustomer($owner, [
        'email' => 'client-b@example.com',
        'phone' => '+15145550112',
    ]);

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.97,
        'campaign' => [
            'objective' => 'Promouvoir mon service pressure wash',
            'campaign_type' => Campaign::TYPE_PROMOTION,
            'offer_hint' => 'Pressure wash',
            'offer_mode_hint' => Campaign::OFFER_MODE_SERVICES,
            'audience_hint' => 'clients actuels',
            'timing_hint' => '',
            'channel_hints' => [Campaign::CHANNEL_EMAIL],
            'kpi_hint' => '',
            'language_hint' => 'FR',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux faire une campagne pour le service pressure wash pour tous les clients actuels.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'needs_input')
        ->assertJsonPath('message', 'Le brouillon a ete cree, mais aucun client actuel n est eligible pour Email.');

    expect($response->json('questions'))->toContain(
        'Vous ciblez deja tous les clients actuels, mais 2 contacts sont bloques car aucun consentement marketing explicite n est enregistre pour Email.',
        'Accordez ou importez les consentements marketing pour Email, ou choisissez un autre canal deja autorise.'
    );
});

test('assistant campaign flow uses historical outcomes to improve offer channel and template recommendations', function () {
    $owner = assistantCampaignOwner();

    $historicalOffer = assistantCampaignProduct($owner, [
        'name' => 'Historical Winner',
    ]);
    $historicalOffer->forceFill(['updated_at' => now()->subDays(10)])->save();
    $latestOffer = assistantCampaignProduct($owner, [
        'name' => 'Latest Offer',
    ]);
    $latestOffer->forceFill(['updated_at' => now()->subDay()])->save();

    $historicalTemplate = MessageTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Winning SMS Template',
        'channel' => Campaign::CHANNEL_SMS,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'language' => 'FR',
        'content' => [
            'text' => 'Historique gagnant {offerName} {ctaUrl}',
        ],
        'is_default' => true,
    ]);
    $historicalTemplate->forceFill(['updated_at' => now()->subDays(9)])->save();

    $recentTemplate = MessageTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Weak Email Template',
        'channel' => Campaign::CHANNEL_EMAIL,
        'campaign_type' => Campaign::TYPE_PROMOTION,
        'language' => 'FR',
        'content' => [
            'subject' => 'Email faible {offerName}',
            'html' => '<p>Email faible {offerName}</p>',
        ],
        'is_default' => true,
    ]);
    $recentTemplate->forceFill(['updated_at' => now()])->save();

    assistantSeedCampaignPerformance(
        $owner,
        $historicalOffer,
        $historicalTemplate,
        Campaign::TYPE_PROMOTION,
        Campaign::CHANNEL_SMS,
        14,
        8,
        5,
    );

    assistantSeedCampaignPerformance(
        $owner,
        $latestOffer,
        $recentTemplate,
        Campaign::TYPE_PROMOTION,
        Campaign::CHANNEL_EMAIL,
        12,
        2,
        0,
    );

    $customer = assistantCampaignCustomer($owner);
    assistantGrantCampaignConsents($owner, $customer, [Campaign::CHANNEL_EMAIL, Campaign::CHANNEL_SMS]);

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'draft_campaign',
        'confidence' => 0.95,
        'campaign' => [
            'objective' => 'Promouvoir mon offre ce mois ci',
            'campaign_type' => Campaign::TYPE_PROMOTION,
            'offer_hint' => '',
            'offer_mode_hint' => '',
            'audience_hint' => '',
            'timing_hint' => '',
            'channel_hints' => [],
            'kpi_hint' => '',
            'language_hint' => 'FR',
            'name_hint' => '',
            'notes' => '',
        ],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Je veux promouvoir mon offre ce mois ci.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'created')
        ->assertJsonPath('context.campaign.offer_hint', 'Historical Winner')
        ->assertJsonPath('context.campaign.channel_hints.0', Campaign::CHANNEL_SMS);

    $campaign = Campaign::query()
        ->where('name', 'not like', 'Historical %')
        ->latest('id')
        ->with(['offers.offer', 'channels'])
        ->firstOrFail();

    $smsChannel = $campaign->channels->firstWhere('channel', Campaign::CHANNEL_SMS);

    expect($campaign->offers)->toHaveCount(1)
        ->and((int) $campaign->offers->first()->offer_id)->toBe($historicalOffer->id)
        ->and($smsChannel)->not->toBeNull()
        ->and((int) $smsChannel->message_template_id)->toBe($historicalTemplate->id)
        ->and(data_get($campaign->settings, 'assistant.history_used'))->toBeTrue()
        ->and(data_get($campaign->settings, 'assistant.confidence'))->toBe('phase_5')
        ->and(collect(data_get($campaign->settings, 'assistant.scoring.offers', []))->pluck('reasons')->flatten()->all())
        ->toContain('historical_offer_performance')
        ->and(collect(data_get($campaign->settings, 'assistant.scoring.channels', []))->pluck('reasons')->flatten()->all())
        ->toContain('historical_type_performance')
        ->and(collect(data_get($campaign->settings, 'assistant.scoring.templates.SMS', []))->pluck('reasons')->flatten()->all())
        ->toContain('historical_template_performance');

    expect(collect($response->json('campaign_review.deduced'))->pluck('reason')->implode(' '))
        ->toContain('meilleures performances');
});

test('assistant message still routes non campaign intents to workflow service', function () {
    $owner = assistantCampaignOwner();

    $interpreter = \Mockery::mock(AssistantInterpreter::class);
    $interpreter->shouldReceive('interpret')->once()->andReturn([
        'intent' => 'list_customers',
        'confidence' => 0.97,
        'usage' => [],
    ]);
    $this->app->instance(AssistantInterpreter::class, $interpreter);

    $workflow = \Mockery::mock(AssistantWorkflowService::class);
    $workflow->shouldReceive('handle')->once()->andReturn([
        'status' => 'ok',
        'message' => 'Clients:',
    ]);
    $this->app->instance(AssistantWorkflowService::class, $workflow);

    $response = $this->actingAs($owner)->postJson(route('assistant.message'), [
        'message' => 'Liste mes clients.',
        'context' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('message', 'Clients:');
});
