<?php

use App\Jobs\PublishSocialPostTargetJob;
use App\Models\AssistantCreditTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialApprovalRequest;
use App\Models\SocialAutomationRule;
use App\Models\SocialAutomationRun;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\SocialPostTemplate;
use App\Models\User;
use App\Services\Assistant\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function socialAutopilotRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function socialAutopilotOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => socialAutopilotRoleId('owner'),
        'email' => 'pulse-autopilot-owner-'.Str::lower(Str::random(10)).'@example.com',
        'locale' => 'fr',
        'company_type' => 'products',
        'company_timezone' => 'America/Toronto',
        'company_features' => [
            'social' => true,
            'products' => true,
            'services' => true,
            'campaigns' => true,
            'promotions' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $overrides));
}

function socialAutopilotConnection(User $owner, string $platform, array $overrides = []): SocialAccountConnection
{
    return SocialAccountConnection::query()->create(array_merge([
        'user_id' => $owner->id,
        'platform' => $platform,
        'label' => Str::headline($platform).' Autopilot account',
        'display_name' => 'Autopilot '.Str::headline($platform),
        'external_account_id' => $platform.'-'.Str::lower(Str::random(8)),
        'credentials' => [
            'access_token' => 'token-'.$platform,
        ],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'provider_label' => Str::headline($platform),
            'target_type' => 'page',
        ],
    ], $overrides));
}

function socialAutopilotProduct(User $owner, string $name = 'Pulse featured product'): Product
{
    return Product::query()->create([
        'name' => $name,
        'description' => 'A ready-to-promote product for Pulse Autopilot.',
        'category_id' => ProductCategory::factory()->create()->id,
        'user_id' => $owner->id,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
        'image' => 'https://example.com/assets/pulse-product.jpg',
        'stock' => 10,
        'price' => 79,
        'minimum_stock' => 1,
        'currency_code' => 'CAD',
    ]);
}

function socialAutopilotTemplate(User $owner, string $name = 'Pulse evergreen template'): SocialPostTemplate
{
    return SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => $name,
        'content_payload' => [
            'text' => 'Stay visible with Malikia Pulse Autopilot.',
        ],
        'media_payload' => [
            [
                'type' => 'image',
                'url' => 'https://example.com/assets/pulse-template.jpg',
            ],
        ],
        'link_url' => 'https://example.com/pulse-template',
        'metadata' => [
            'template_saved_from' => 'social_composer',
        ],
    ]);
}

function socialAutopilotRule(User $owner, array $overrides = []): SocialAutomationRule
{
    return SocialAutomationRule::query()->create(array_merge([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Default Pulse Autopilot Rule',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [],
        'target_connection_ids' => [],
        'max_posts_per_day' => 2,
        'min_hours_between_similar_posts' => 12,
        'next_generation_at' => now()->subMinute(),
        'metadata' => [
            'day_of_week' => 5,
            'day_of_month' => 25,
        ],
    ], $overrides));
}

it('generates a pulse candidate and submits it for approval from a product automation rule', function () {
    $owner = socialAutopilotOwner();
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $product = socialAutopilotProduct($owner);

    $rule = socialAutopilotRule($owner, [
        'name' => 'Daily product autopilot',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
    ]);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $post = SocialPost::query()
        ->with(['latestApprovalRequest', 'automationRule', 'targets.socialAccountConnection'])
        ->sole();

    $rule->refresh();

    expect($post->status)->toBe(SocialPost::STATUS_PENDING_APPROVAL)
        ->and($post->source_type)->toBe('product')
        ->and($post->source_id)->toBe($product->id)
        ->and($post->social_automation_rule_id)->toBe($rule->id)
        ->and($post->automationRule?->is($rule))->toBeTrue()
        ->and((string) $post->latestApprovalRequest?->status)->toBe(SocialApprovalRequest::STATUS_PENDING)
        ->and((int) $post->latestApprovalRequest?->requested_by_user_id)->toBe((int) $owner->id)
        ->and(data_get($post->metadata, 'automation.rule_id'))->toBe($rule->id)
        ->and(data_get($post->metadata, 'automation.selected_source_type'))->toBe('product')
        ->and(data_get($post->metadata, 'automation.selected_source_id'))->toBe($product->id)
        ->and($post->targets)->toHaveCount(1)
        ->and($post->targets->first()?->socialAccountConnection?->is($connection))->toBeTrue()
        ->and($rule->last_generated_at)->not->toBeNull()
        ->and($rule->next_generation_at)->not->toBeNull()
        ->and($rule->last_error)->toBeNull();

    $run = SocialAutomationRun::query()->sole();

    expect($run->status)->toBe(SocialAutomationRun::STATUS_GENERATED)
        ->and($run->outcome_code)->toBe('queued_for_approval')
        ->and($run->social_post_id)->toBe($post->id)
        ->and($run->source_type)->toBe('product')
        ->and($run->source_id)->toBe($product->id);
});

it('generates an AI creative candidate and stores generation metadata', function () {
    config()->set('services.openai.key', 'test-key');
    config()->set('services.openai.social_creative_model', 'test-social-model');

    $owner = socialAutopilotOwner([
        'company_name' => 'Studio Pulse',
    ]);
    $facebook = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $instagram = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM);
    $product = socialAutopilotProduct($owner, 'Soin visage signature');

    $rule = socialAutopilotRule($owner, [
        'name' => 'AI product autopilot',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$facebook->id, $instagram->id],
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'metadata' => [
            'generation_settings' => [
                'text_ai_enabled' => true,
                'image_ai_enabled' => false,
                'creative_prompt' => 'Keep the copy premium and local.',
                'image_prompt' => 'Bright realistic treatment room.',
                'tone' => 'premium',
                'goal' => 'book',
                'image_mode' => 'if_missing',
                'image_format' => 'square',
                'variant_count' => 2,
            ],
        ],
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('chat')
        ->once()
        ->andReturn(['model' => 'test-social-model']);
    $client->shouldReceive('extractMessage')
        ->once()
        ->andReturn(json_encode([
            'selected' => [
                'text' => 'Decouvrez le soin visage signature de Studio Pulse, pense pour une peau lumineuse et un vrai moment de pause.',
                'hashtags' => ['#SoinVisage', '#StudioPulse'],
                'cta' => 'Reservez votre moment.',
                'image_prompt' => 'Photo realiste lumineuse d une cabine de soin premium, sans texte incruste.',
                'score' => 91,
                'score_reason' => 'Clair, premium et oriente reservation.',
            ],
            'variants' => [
                [
                    'text' => 'Soin visage signature disponible cette semaine chez Studio Pulse.',
                    'hashtags' => ['#SoinVisage'],
                    'cta' => 'Contactez-nous.',
                    'image_prompt' => 'Cabine de soin lumineuse sans texte.',
                    'score' => 74,
                    'score_reason' => 'Correct mais moins distinctif.',
                ],
            ],
        ]));
    $client->shouldReceive('extractUsage')
        ->once()
        ->andReturn([
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
            'total_tokens' => 200,
            'model' => 'test-social-model',
        ]);
    $this->app->instance(OpenAiClient::class, $client);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $post = SocialPost::query()->sole();

    expect(data_get($post->content_payload, 'text'))->toContain('Decouvrez le soin visage signature')
        ->and(data_get($post->content_payload, 'text'))->toContain('#SoinVisage')
        ->and(data_get($post->metadata, 'ai_generation.text_enabled'))->toBeTrue()
        ->and(data_get($post->metadata, 'ai_generation.text_model'))->toBe('test-social-model')
        ->and(data_get($post->metadata, 'ai_generation.selected_score'))->toBe(91)
        ->and(data_get($post->metadata, 'ai_generation.fallback_used'))->toBeFalse()
        ->and(data_get($post->metadata, 'ai_generation.image_prompt'))->toContain('cabine de soin premium')
        ->and(data_get($post->metadata, 'automation.ai_generation_mode'))->toBe('ai_creative')
        ->and(data_get($post->metadata, 'automation.ai_selected_score'))->toBe(91);

    $run = SocialAutomationRun::query()->sole();

    expect(data_get($run->metadata, 'ai_generation.text_model'))->toBe('test-social-model')
        ->and(data_get($run->metadata, 'ai_generation.selected_score'))->toBe(91)
        ->and(data_get($run->metadata, 'ai_generation.fallback_used'))->toBeFalse();
});

it('falls back to deterministic Pulse copy when AI creative generation is unavailable', function () {
    config()->set('services.openai.key', null);

    $owner = socialAutopilotOwner();
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $product = socialAutopilotProduct($owner, 'Fallback product');

    $rule = socialAutopilotRule($owner, [
        'name' => 'Fallback AI autopilot',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'metadata' => [
            'generation_settings' => [
                'text_ai_enabled' => true,
                'image_ai_enabled' => false,
                'creative_prompt' => 'Use a warm tone.',
                'image_prompt' => 'Clean product visual.',
                'tone' => 'warm',
                'goal' => 'inform',
                'image_mode' => 'if_missing',
                'image_format' => 'square',
                'variant_count' => 3,
            ],
        ],
    ]);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $post = SocialPost::query()->sole();

    expect(data_get($post->content_payload, 'text'))->not->toBe('')
        ->and(data_get($post->metadata, 'ai_generation.fallback_used'))->toBeTrue()
        ->and(data_get($post->metadata, 'ai_generation.generation_mode'))->toBe('deterministic_fallback')
        ->and(data_get($post->metadata, 'ai_generation.fallback_reason'))->toContain('OpenAI is not configured')
        ->and(data_get($post->metadata, 'automation.ai_fallback_used'))->toBeTrue();
});

it('generates an AI image for an automation candidate when the source image is missing', function () {
    Storage::fake('public');
    config()->set('services.openai.key', 'test-key');
    config()->set('services.openai.image_model', 'test-image-model');
    config()->set('services.openai.image_output_format', 'png');

    $owner = socialAutopilotOwner([
        'company_name' => 'Studio Pulse',
    ]);
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_INSTAGRAM);
    $product = socialAutopilotProduct($owner, 'Soin visage lumineux');
    $product->forceFill(['image' => null])->save();

    $rule = socialAutopilotRule($owner, [
        'name' => 'AI image autopilot',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'metadata' => [
            'generation_settings' => [
                'text_ai_enabled' => false,
                'image_ai_enabled' => true,
                'creative_prompt' => '',
                'image_prompt' => 'Ambiance lumineuse et professionnelle.',
                'tone' => 'warm',
                'goal' => 'book',
                'image_mode' => 'if_missing',
                'image_format' => 'portrait',
                'variant_count' => 3,
            ],
        ],
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('generateImage')
        ->once()
        ->with(
            \Mockery::on(fn (string $prompt): bool => str_contains($prompt, 'Soin visage lumineux')
                && str_contains($prompt, 'Ambiance lumineuse')),
            \Mockery::on(fn (array $options): bool => ($options['size'] ?? null) === '1024x1792')
        )
        ->andReturn([
            'data' => [
                ['b64_json' => base64_encode('fake-social-image')],
            ],
        ]);
    $this->app->instance(OpenAiClient::class, $client);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $post = SocialPost::query()->sole();
    $path = (string) data_get($post->media_payload, '0.path');

    expect(data_get($post->media_payload, '0.source'))->toBe('ai')
        ->and(data_get($post->media_payload, '0.url'))->toContain('/storage/company/ai/'.$owner->id.'/social-')
        ->and($path)->not->toBe('')
        ->and(data_get($post->metadata, 'ai_generation.image.generated'))->toBeTrue()
        ->and(data_get($post->metadata, 'ai_generation.image.status'))->toBe('generated')
        ->and(data_get($post->metadata, 'ai_generation.image.model'))->toBe('test-image-model')
        ->and(data_get($post->metadata, 'ai_generation.image.usage_mode'))->toBe('free')
        ->and(data_get($post->metadata, 'ai_generation.image.fallback_used'))->toBeFalse()
        ->and(data_get($post->metadata, 'automation.ai_generation_mode'))->toBe('ai_image')
        ->and(data_get($post->metadata, 'automation.ai_fallback_used'))->toBeFalse();

    Storage::disk('public')->assertExists($path);

    $transaction = AssistantCreditTransaction::query()->sole();

    expect($transaction->user_id)->toBe($owner->id)
        ->and($transaction->type)->toBe('free')
        ->and($transaction->source)->toBe('ai_image_social')
        ->and(data_get($transaction->meta, 'context'))->toBe('social')
        ->and(data_get($transaction->meta, 'mode'))->toBe('free');
});

it('keeps the Pulse candidate when AI image quota is exhausted', function () {
    Storage::fake('public');
    config()->set('services.openai.key', 'test-key');

    $owner = socialAutopilotOwner([
        'assistant_credit_balance' => 0,
    ]);
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_FACEBOOK);
    $product = socialAutopilotProduct($owner, 'Quota guarded product');
    $product->forceFill(['image' => null])->save();

    AssistantCreditTransaction::query()->create([
        'user_id' => $owner->id,
        'type' => 'free',
        'credits' => 1,
        'source' => 'ai_image_social',
        'meta' => [
            'context' => 'social',
            'mode' => 'free',
        ],
    ]);

    $rule = socialAutopilotRule($owner, [
        'name' => 'Quota guarded image autopilot',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'metadata' => [
            'generation_settings' => [
                'text_ai_enabled' => false,
                'image_ai_enabled' => true,
                'creative_prompt' => '',
                'image_prompt' => 'Clean social visual.',
                'tone' => 'professional',
                'goal' => 'inform',
                'image_mode' => 'if_missing',
                'image_format' => 'square',
                'variant_count' => 3,
            ],
        ],
    ]);

    $client = \Mockery::mock(OpenAiClient::class);
    $client->shouldReceive('generateImage')->never();
    $this->app->instance(OpenAiClient::class, $client);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $post = SocialPost::query()->sole();

    expect(data_get($post->content_payload, 'text'))->not->toBe('')
        ->and($post->media_payload)->toBeNull()
        ->and(data_get($post->metadata, 'ai_generation.image.generated'))->toBeFalse()
        ->and(data_get($post->metadata, 'ai_generation.image.status'))->toBe('failed')
        ->and(data_get($post->metadata, 'ai_generation.image.outcome_code'))->toBe('credits_exhausted')
        ->and(data_get($post->metadata, 'ai_generation.image.fallback_used'))->toBeTrue()
        ->and(data_get($post->metadata, 'automation.ai_fallback_used'))->toBeTrue();

    expect(AssistantCreditTransaction::query()->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('can auto publish a generated pulse candidate from a template automation rule', function () {
    Queue::fake();

    $owner = socialAutopilotOwner([
        'company_features' => [
            'social' => true,
        ],
    ]);
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_LINKEDIN);
    $template = socialAutopilotTemplate($owner);

    $rule = socialAutopilotRule($owner, [
        'name' => 'Template autopilot',
        'approval_mode' => SocialAutomationRule::APPROVAL_AUTO_PUBLISH,
        'content_sources' => [
            ['type' => 'template', 'mode' => 'selected_ids', 'ids' => [$template->id]],
        ],
        'target_connection_ids' => [$connection->id],
        'max_posts_per_day' => 1,
    ]);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    Queue::assertPushed(PublishSocialPostTargetJob::class, 1);

    $post = SocialPost::query()
        ->with(['latestApprovalRequest', 'targets.socialAccountConnection'])
        ->sole();

    expect($post->status)->toBe(SocialPost::STATUS_PUBLISHING)
        ->and($post->source_type)->toBe('template')
        ->and($post->source_id)->toBe($template->id)
        ->and($post->latestApprovalRequest)->toBeNull()
        ->and(data_get($post->metadata, 'automation.rule_id'))->toBe($rule->id)
        ->and(data_get($post->metadata, 'automation.selected_source_type'))->toBe('template')
        ->and($post->targets)->toHaveCount(1)
        ->and($post->targets->first()?->status)->toBe(SocialPostTarget::STATUS_PENDING);

    $run = SocialAutomationRun::query()->sole();

    expect($run->status)->toBe(SocialAutomationRun::STATUS_GENERATED)
        ->and($run->outcome_code)->toBe('auto_published')
        ->and($run->social_post_id)->toBe($post->id);
});

it('skips a pulse automation rule when its target account is no longer publishable', function () {
    $owner = socialAutopilotOwner();
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_X, [
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
        'connected_at' => null,
    ]);
    $product = socialAutopilotProduct($owner, 'Disconnected account product');

    $rule = socialAutopilotRule($owner, [
        'name' => 'Broken target rule',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
    ]);

    $this->artisan('social:run-automations', [
        '--account_id' => $owner->id,
        '--rule_id' => $rule->id,
    ])->assertExitCode(0);

    $rule->refresh();

    expect(SocialPost::query()->count())->toBe(0)
        ->and($rule->last_generated_at)->toBeNull()
        ->and($rule->next_generation_at)->not->toBeNull()
        ->and($rule->last_error)->toContain('not ready');

    $run = SocialAutomationRun::query()->sole();

    expect($run->status)->toBe(SocialAutomationRun::STATUS_SKIPPED)
        ->and($run->outcome_code)->toBe('targets_unavailable')
        ->and($run->message)->toContain('not ready');
});

it('auto pauses a pulse automation rule after repeated blocking runs', function () {
    $owner = socialAutopilotOwner();
    $connection = socialAutopilotConnection($owner, SocialAccountConnection::PLATFORM_X, [
        'status' => SocialAccountConnection::STATUS_DISCONNECTED,
        'is_active' => false,
        'connected_at' => null,
    ]);
    $product = socialAutopilotProduct($owner, 'Auto pause candidate');

    $rule = socialAutopilotRule($owner, [
        'name' => 'Auto pause rule',
        'content_sources' => [
            ['type' => 'product', 'mode' => 'selected_ids', 'ids' => [$product->id]],
        ],
        'target_connection_ids' => [$connection->id],
    ]);

    foreach (range(1, 3) as $attempt) {
        $this->artisan('social:run-automations', [
            '--account_id' => $owner->id,
            '--rule_id' => $rule->id,
        ])->assertExitCode(0);

        $rule->refresh();

        if ($attempt < 3) {
            $rule->forceFill([
                'next_generation_at' => now()->subMinute(),
            ])->save();
        }
    }

    $rule->refresh();

    expect($rule->is_active)->toBeFalse()
        ->and(data_get($rule->metadata, 'health.auto_paused'))->toBeTrue()
        ->and(data_get($rule->metadata, 'health.consecutive_failures'))->toBe(3)
        ->and(data_get($rule->metadata, 'health.auto_pause_code'))->toBe('targets_unavailable');

    $runs = SocialAutomationRun::query()
        ->where('social_automation_rule_id', $rule->id)
        ->orderBy('id')
        ->get();

    expect($runs)->toHaveCount(3)
        ->and((string) $runs->last()?->outcome_code)->toBe('auto_paused')
        ->and((string) $runs->last()?->message)->toContain('paused this rule');
});
