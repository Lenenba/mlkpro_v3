<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\MarketingSetting;
use App\Models\Role;
use App\Models\SocialAutomationRule;
use App\Models\SocialPostTemplate;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Social\SocialBrandVoiceService;
use App\Services\Social\SocialContentGeneratorService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseBrandVoiceRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseBrandVoiceOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseBrandVoiceRoleId('owner'),
        'email' => 'pulse-brand-voice-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_name' => 'Malikia Studio',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'locale' => 'fr',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseBrandVoiceTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-brand-voice-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => $owner->company_type,
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
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

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

it('renders the pulse brand voice workspace for owners', function () {
    $owner = pulseBrandVoiceOwner();

    $this->actingAs($owner)
        ->get(route('social.brand-voice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/BrandVoice')
            ->where('brand_voice.tone', 'professional')
            ->where('brand_voice.language', 'fr')
            ->where('brand_voice.is_configured', false)
            ->where('tone_options.0.value', 'professional')
            ->where('access.can_manage_posts', true)
        );

    $this->actingAs($owner)
        ->getJson(route('social.brand-voice'))
        ->assertOk()
        ->assertJsonPath('brand_voice.tone', 'professional')
        ->assertJsonPath('brand_voice.language', 'fr');
});

it('lets publishers update pulse brand voice while viewers stay read only', function () {
    $owner = pulseBrandVoiceOwner();
    $publisher = pulseBrandVoiceTeamMember($owner, ['social.publish']);
    $viewer = pulseBrandVoiceTeamMember($owner, ['social.view']);

    $payload = [
        'tone' => 'warm',
        'language' => 'fr',
        'style_notes' => 'Sobre, humain et direct.',
        'words_to_avoid' => ['urgent', 'gratuit!!!'],
        'preferred_hashtags' => ['#SignaturePulse', 'Malikia Local'],
        'preferred_ctas' => ['Reservez votre moment.'],
        'sample_phrase' => 'Une presence locale simple, propre et rassurante.',
    ];

    $this->actingAs($viewer)
        ->get(route('social.brand-voice'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('access.can_view', true)
            ->where('access.can_manage_posts', false)
        );

    $this->actingAs($viewer)
        ->putJson(route('social.brand-voice.update'), $payload)
        ->assertForbidden();

    $this->actingAs($publisher)
        ->putJson(route('social.brand-voice.update'), $payload)
        ->assertOk()
        ->assertJsonPath('brand_voice.tone', 'warm')
        ->assertJsonPath('brand_voice.style_notes', 'Sobre, humain et direct.')
        ->assertJsonPath('brand_voice.preferred_hashtags.0', '#SignaturePulse')
        ->assertJsonPath('brand_voice.preferred_hashtags.1', '#MalikiaLocal')
        ->assertJsonPath('brand_voice.preferred_ctas.0', 'Reservez votre moment.')
        ->assertJsonPath('brand_voice.is_configured', true);

    $setting = MarketingSetting::query()->firstWhere('user_id', $owner->id);

    expect($setting)->not->toBeNull()
        ->and(data_get($setting?->templates, 'brand_voice.tone'))->toBe('warm')
        ->and(data_get($setting?->templates, 'brand_voice.is_configured'))->toBeNull();
});

it('adds configured brand voice cues to generated autopilot content', function () {
    $owner = pulseBrandVoiceOwner([
        'company_features' => [
            'social' => true,
        ],
    ]);

    app(SocialBrandVoiceService::class)->update($owner, [
        'tone' => 'warm',
        'language' => 'fr',
        'preferred_hashtags' => ['#SignaturePulse'],
        'preferred_ctas' => ['Reservez votre moment.'],
        'words_to_avoid' => ['urgent'],
    ]);

    $template = SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'name' => 'Signature service',
        'content_payload' => [
            'text' => 'Service signature de la semaine',
        ],
        'link_url' => 'https://example.com/signature-service',
    ]);

    $rule = SocialAutomationRule::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Brand voice cadence',
        'is_active' => true,
        'frequency_type' => SocialAutomationRule::FREQUENCY_DAILY,
        'frequency_interval' => 1,
        'scheduled_time' => '09:00',
        'timezone' => 'America/Toronto',
        'approval_mode' => SocialAutomationRule::APPROVAL_REQUIRED,
        'language' => 'fr',
        'content_sources' => [
            ['type' => 'template', 'mode' => 'selected_ids', 'ids' => [$template->id]],
        ],
        'target_connection_ids' => [],
        'max_posts_per_day' => 1,
        'min_hours_between_similar_posts' => 24,
        'next_generation_at' => now()->addDay(),
        'metadata' => [
            'generation_settings' => SocialAutomationRule::defaultGenerationSettings(),
        ],
    ]);

    $candidate = app(SocialContentGeneratorService::class)->generate($owner, $rule, [
        'source_type' => 'template',
        'source_id' => $template->id,
        'source_label' => $template->name,
    ]);

    $text = (string) data_get($candidate, 'content_payload.text');

    expect($text)->toContain('Reservez votre moment.')
        ->and($text)->toContain('#SignaturePulse')
        ->and(data_get($candidate, 'metadata.brand_voice.tone'))->toBe('warm')
        ->and(data_get($candidate, 'metadata.brand_voice.is_configured'))->toBeTrue()
        ->and(data_get($candidate, 'metadata.brand_voice.words_to_avoid_count'))->toBe(1);
});

it('blocks pulse brand voice when the social module is unavailable', function () {
    $owner = pulseBrandVoiceOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.brand-voice'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->putJson(route('social.brand-voice.update'), [
            'tone' => 'warm',
            'language' => 'fr',
        ])
        ->assertForbidden();
});
