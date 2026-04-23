<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialPostTemplate;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseTemplateRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseTemplateOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseTemplateRoleId('owner'),
        'email' => 'pulse-template-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseTemplateTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-template-member-'.Str::lower(Str::random(10)).'@example.com',
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

it('renders the pulse templates page and lets owners manage reusable templates', function () {
    expect(Schema::hasTable('social_post_templates'))->toBeTrue();

    $owner = pulseTemplateOwner();

    $facebook = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main page',
        'external_account_id' => 'fb-template-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'provider_label' => 'Facebook',
        ],
    ]);

    $linkedin = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Corporate page',
        'external_account_id' => 'li-template-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'provider_label' => 'LinkedIn',
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('social.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Templates')
            ->where('access.can_manage_posts', true)
            ->has('connected_accounts', 2)
            ->has('templates', 0)
        );

    $create = $this->actingAs($owner)
        ->postJson(route('social.templates.store'), [
            'name' => 'Evergreen promo',
            'text' => 'Reusable Pulse promo copy',
            'image_url' => 'https://example.com/assets/pulse-template.jpg',
            'link_url' => 'https://example.com/offers/evergreen',
            'target_connection_ids' => [$facebook->id, $linkedin->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('template.name', 'Evergreen promo')
        ->assertJsonPath('template.selected_accounts_count', 2)
        ->assertJsonCount(1, 'templates');

    $templateId = (int) $create->json('template.id');

    $this->actingAs($owner)
        ->putJson(route('social.templates.update', $templateId), [
            'name' => 'Evergreen promo v2',
            'text' => 'Updated reusable Pulse promo copy',
            'link_url' => 'https://example.com/offers/evergreen-v2',
            'target_connection_ids' => [$linkedin->id],
        ])
        ->assertOk()
        ->assertJsonPath('template.name', 'Evergreen promo v2')
        ->assertJsonPath('template.selected_accounts_count', 1)
        ->assertJsonPath('template.selected_target_connection_ids.0', $linkedin->id);

    $this->actingAs($owner)
        ->get(route('social.composer', ['template' => $templateId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Composer')
            ->where('selected_template_id', $templateId)
            ->has('templates', 1)
        );

    $template = SocialPostTemplate::query()->findOrFail($templateId);

    expect($template->name)->toBe('Evergreen promo v2')
        ->and((string) data_get($template->content_payload, 'text'))->toBe('Updated reusable Pulse promo copy')
        ->and((string) $template->link_url)->toBe('https://example.com/offers/evergreen-v2')
        ->and((array) data_get($template->metadata, 'selected_target_connection_ids'))->toBe([$linkedin->id]);

    $this->actingAs($owner)
        ->deleteJson(route('social.templates.destroy', $templateId))
        ->assertOk()
        ->assertJsonCount(0, 'templates');

    expect(SocialPostTemplate::query()->count())->toBe(0);
});

it('lets authorized team members manage pulse templates while social view stays read only', function () {
    $owner = pulseTemplateOwner();
    $manager = pulseTemplateTeamMember($owner, ['social.manage']);
    $viewer = pulseTemplateTeamMember($owner, ['social.view']);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Main IG',
        'external_account_id' => 'ig-template-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
        'metadata' => [
            'provider_label' => 'Instagram',
        ],
    ]);

    $this->actingAs($viewer)
        ->get(route('social.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Templates')
            ->where('access.can_view', true)
            ->where('access.can_manage_posts', false)
        );

    $this->actingAs($viewer)
        ->postJson(route('social.templates.store'), [
            'name' => 'Viewer template',
            'text' => 'Viewer should not create this.',
        ])
        ->assertForbidden();

    $create = $this->actingAs($manager)
        ->postJson(route('social.templates.store'), [
            'name' => 'Manager template',
            'text' => 'Manager reusable content',
            'target_connection_ids' => [$connection->id],
        ]);

    $create->assertCreated()
        ->assertJsonPath('template.name', 'Manager template')
        ->assertJsonPath('template.selected_accounts_count', 1);

    $templateId = (int) $create->json('template.id');

    $this->actingAs($viewer)
        ->putJson(route('social.templates.update', $templateId), [
            'name' => 'Viewer update',
            'text' => 'Nope',
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('social.templates.destroy', $templateId))
        ->assertForbidden();
});

it('accepts pulse templates without remembered targets and keeps them reusable in the composer', function () {
    $owner = pulseTemplateOwner();

    $create = $this->actingAs($owner)
        ->postJson(route('social.templates.store'), [
            'name' => 'Copy-only template',
            'text' => 'No target remembered yet.',
            'link_url' => 'https://example.com/content/copy-only',
        ]);

    $create->assertCreated()
        ->assertJsonPath('template.selected_accounts_count', 0)
        ->assertJsonCount(0, 'template.selected_target_connection_ids');

    $templateId = (int) $create->json('template.id');

    $this->actingAs($owner)
        ->get(route('social.composer', ['template' => $templateId]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Composer')
            ->where('selected_template_id', $templateId)
            ->has('templates', 1)
        );
});

it('blocks pulse template routes when the social module is unavailable', function () {
    $owner = pulseTemplateOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $template = SocialPostTemplate::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'name' => 'Blocked template',
        'content_payload' => [
            'text' => 'Blocked template text',
        ],
        'metadata' => [
            'selected_target_connection_ids' => [],
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.templates.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.templates.store'), [
            'name' => 'Blocked create',
            'text' => 'No create',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->putJson(route('social.templates.update', $template), [
            'name' => 'Blocked update',
            'text' => 'No update',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->deleteJson(route('social.templates.destroy', $template))
        ->assertForbidden();
});
