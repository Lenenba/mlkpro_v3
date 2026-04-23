<?php

use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\TeamMember;
use App\Models\User;
use App\Http\Middleware\EnsureTwoFactorVerified;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pulseRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function pulseOwner(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => pulseRoleId('owner'),
        'email' => 'pulse-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ], $overrides));
}

function pulseTeamMember(
    User $owner,
    array $permissions = [],
    array $userOverrides = [],
    array $membershipOverrides = []
): User {
    $member = User::factory()->create(array_merge([
        'email' => 'pulse-member-'.Str::lower(Str::random(10)).'@example.com',
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

it('renders the pulse accounts workspace page for owners', function () {
    $owner = pulseOwner();

    SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook page',
        'display_name' => 'Malikia HQ',
        'external_account_id' => 'fb-main',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('social.accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Accounts')
            ->where('access.can_view', true)
            ->where('access.can_manage_accounts', true)
            ->where('summary.configured', 1)
            ->where('summary.connected', 1)
            ->has('provider_definitions', 4)
            ->has('connections', 1)
            ->where('connections.0.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        );
});

it('renders the pulse accounts workspace page in read only mode for authorized team members', function () {
    $owner = pulseOwner();
    $member = pulseTeamMember($owner, ['social.publish']);

    SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'Corporate LinkedIn',
        'display_name' => 'Malikia Pro',
        'external_account_id' => 'li-org-9',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($member)
        ->get(route('social.accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Social/Accounts')
            ->where('access.can_view', true)
            ->where('access.can_manage_accounts', false)
            ->where('summary.configured', 1)
            ->has('connections', 1)
            ->where('connections.0.platform', SocialAccountConnection::PLATFORM_LINKEDIN)
        );
});

it('lets the owner create list update disconnect and delete pulse social account drafts', function () {
    $owner = pulseOwner();

    $create = $this->actingAs($owner)->postJson(route('social.accounts.store'), [
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Main studio IG',
        'display_name' => 'Malikia Beauty Studio',
        'account_handle' => '@malikia.beauty',
        'external_account_id' => 'ig-biz-main',
    ]);

    $create->assertCreated()
        ->assertJsonPath('connection.platform', SocialAccountConnection::PLATFORM_INSTAGRAM)
        ->assertJsonPath('connection.label', 'Main studio IG')
        ->assertJsonPath('connection.display_name', 'Malikia Beauty Studio')
        ->assertJsonPath('connection.account_handle', '@malikia.beauty')
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_DRAFT)
        ->assertJsonPath('connection.is_active', false)
        ->assertJsonPath('connection.provider_label', 'Instagram Business')
        ->assertJsonPath('connection.requested_scopes.1', 'instagram_content_publish');

    $connectionId = (int) $create->json('connection.id');

    $this->actingAs($owner)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonPath('access.can_view', true)
        ->assertJsonPath('access.can_manage_accounts', true)
        ->assertJsonPath('summary.configured', 1)
        ->assertJsonPath('summary.connected', 0)
        ->assertJsonPath('summary.attention', 1)
        ->assertJsonCount(4, 'provider_definitions')
        ->assertJsonCount(1, 'connections')
        ->assertJsonPath('connections.0.id', $connectionId)
        ->assertJsonPath('connections.0.metadata.connection_flow', 'oauth_scaffold');

    $this->actingAs($owner)
        ->putJson(route('social.accounts.update', $connectionId), [
            'label' => 'Main studio Instagram',
            'display_name' => 'Malikia Studio CA',
            'account_handle' => '@malikia.ca',
        ])
        ->assertOk()
        ->assertJsonPath('connection.label', 'Main studio Instagram')
        ->assertJsonPath('connection.display_name', 'Malikia Studio CA')
        ->assertJsonPath('connection.account_handle', '@malikia.ca');

    $this->actingAs($owner)
        ->postJson(route('social.accounts.disconnect', $connectionId))
        ->assertOk()
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_DISCONNECTED)
        ->assertJsonPath('connection.is_active', false)
        ->assertJsonPath('connection.permissions', []);

    $this->actingAs($owner)
        ->deleteJson(route('social.accounts.destroy', $connectionId))
        ->assertOk();

    expect(SocialAccountConnection::query()->count())->toBe(0);
});

it('lets the owner test a connected pulse social account without leaving the workspace', function () {
    $owner = pulseOwner();

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Launch profile',
        'display_name' => 'Malikia Launch',
        'external_account_id' => 'x-profile-1',
        'credentials' => [
            'access_token' => 'token-123',
        ],
        'permissions' => ['tweet.read', 'tweet.write'],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now()->subHour(),
        'metadata' => [
            'oauth_ready' => true,
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.accounts.test', $connection))
        ->assertOk()
        ->assertJsonPath('result.success', true)
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_CONNECTED)
        ->assertJsonPath('connection.last_test_status', 'success')
        ->assertJsonPath('connection.last_test_message', 'X Profiles connection looks valid and ready to publish.');

    $connection->refresh();

    expect(data_get($connection->metadata, 'last_test_status'))->toBe('success')
        ->and(data_get($connection->metadata, 'last_tested_at'))->not->toBeNull()
        ->and($connection->last_error)->toBeNull();
});

it('marks the pulse social account as reconnect required when the owner tests a connection without credentials', function () {
    $owner = pulseOwner();

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook',
        'external_account_id' => 'fb-main',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now()->subHour(),
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.accounts.test', $connection))
        ->assertOk()
        ->assertJsonPath('result.success', false)
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->assertJsonPath('connection.last_test_status', 'failed');

    $connection->refresh();

    expect($connection->status)->toBe(SocialAccountConnection::STATUS_RECONNECT_REQUIRED)
        ->and(data_get($connection->metadata, 'last_test_status'))->toBe('failed')
        ->and($connection->last_error)->toContain('must be reconnected');
});

it('lets the owner create multiple pulse accounts on the same platform and blocks duplicate external ids', function () {
    $owner = pulseOwner();

    $this->actingAs($owner)->postJson(route('social.accounts.store'), [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-page-001',
    ])->assertCreated();

    $this->actingAs($owner)->postJson(route('social.accounts.store'), [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'South page',
        'external_account_id' => 'fb-page-002',
    ])->assertCreated();

    $this->actingAs($owner)->postJson(route('social.accounts.store'), [
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Duplicate page',
        'external_account_id' => 'fb-page-001',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['external_account_id']);

    expect(SocialAccountConnection::query()->count())->toBe(2);
});

it('lets team members with social permissions view pulse account workspace in read only mode', function () {
    $owner = pulseOwner();
    $member = pulseTeamMember($owner, ['social.view']);

    SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'display_name' => 'Malikia Pro',
        'external_account_id' => 'org-17',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($member)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonPath('access.can_view', true)
        ->assertJsonPath('access.can_manage_accounts', false)
        ->assertJsonPath('summary.configured', 1)
        ->assertJsonPath('summary.connected', 1)
        ->assertJsonPath('connections.0.label', 'LinkedIn HQ')
        ->assertJsonPath('connections.0.platform', SocialAccountConnection::PLATFORM_LINKEDIN);
});

it('blocks team members from mutating pulse social account connections', function () {
    $owner = pulseOwner();
    $member = pulseTeamMember($owner, ['social.manage']);

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_X,
        'label' => 'Launch profile',
        'external_account_id' => 'x-1',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => now(),
    ]);

    $this->actingAs($member)
        ->postJson(route('social.accounts.store'), [
            'platform' => SocialAccountConnection::PLATFORM_X,
            'label' => 'Member draft',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->putJson(route('social.accounts.update', $connection), [
            'label' => 'Member update',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.accounts.authorize', $connection))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.accounts.refresh', $connection))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.accounts.test', $connection))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.accounts.disconnect', $connection))
        ->assertForbidden();

    $this->actingAs($member)
        ->deleteJson(route('social.accounts.destroy', $connection))
        ->assertForbidden();
});

it('blocks pulse social account routes when the social module is unavailable', function () {
    $owner = pulseOwner([
        'company_features' => [
            'social' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('social.accounts.index'))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.accounts.store'), [
            'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
            'label' => 'Blocked page',
        ])
        ->assertForbidden();

    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Blocked page',
        'status' => SocialAccountConnection::STATUS_DRAFT,
        'is_active' => false,
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.accounts.authorize', $connection))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.accounts.refresh', $connection))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('social.accounts.test', $connection))
        ->assertForbidden();
});
