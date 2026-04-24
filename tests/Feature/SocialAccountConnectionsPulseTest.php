<?php

use App\Models\SocialAccountConnection;
use App\Models\User;
use App\Services\Social\SocialProviderRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds pulse social account connections table with expected columns', function () {
    expect(Schema::hasTable('social_account_connections'))->toBeTrue()
        ->and(Schema::hasColumns('social_account_connections', [
            'user_id',
            'platform',
            'label',
            'display_name',
            'account_handle',
            'external_account_id',
            'auth_method',
            'credentials',
            'permissions',
            'status',
            'is_active',
            'connected_at',
            'last_synced_at',
            'token_expires_at',
            'oauth_state',
            'oauth_state_expires_at',
            'last_error',
            'metadata',
        ]))->toBeTrue();
});

it('persists and casts social account connection fields', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $connectedAt = Carbon::parse('2026-04-22 09:45:00');
    $lastSyncedAt = Carbon::parse('2026-04-22 10:15:00');
    $tokenExpiresAt = Carbon::parse('2026-04-29 09:45:00');
    $oauthStateExpiresAt = Carbon::parse('2026-04-22 10:00:00');

    $connection = SocialAccountConnection::create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_INSTAGRAM,
        'label' => 'Main IG',
        'display_name' => 'Malikia Beauty Studio',
        'account_handle' => '@malikia.beauty',
        'external_account_id' => 'ig-biz-123',
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'credentials' => [
            'access_token' => 'token-123',
            'refresh_token' => 'refresh-456',
        ],
        'permissions' => [
            'instagram_basic',
            'instagram_content_publish',
        ],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'connected_at' => $connectedAt,
        'last_synced_at' => $lastSyncedAt,
        'token_expires_at' => $tokenExpiresAt,
        'oauth_state' => 'oauth-state-123',
        'oauth_state_expires_at' => $oauthStateExpiresAt,
        'metadata' => [
            'provider_label' => 'Instagram Business',
            'target_type' => 'business_account',
        ],
    ]);

    $freshConnection = $connection->fresh();

    expect($freshConnection)->not->toBeNull()
        ->and($freshConnection->platform)->toBe(SocialAccountConnection::PLATFORM_INSTAGRAM)
        ->and($freshConnection->label)->toBe('Main IG')
        ->and($freshConnection->display_name)->toBe('Malikia Beauty Studio')
        ->and($freshConnection->account_handle)->toBe('@malikia.beauty')
        ->and($freshConnection->external_account_id)->toBe('ig-biz-123')
        ->and($freshConnection->credentials)->toBe([
            'access_token' => 'token-123',
            'refresh_token' => 'refresh-456',
        ])
        ->and($freshConnection->permissions)->toBe([
            'instagram_basic',
            'instagram_content_publish',
        ])
        ->and($freshConnection->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($freshConnection->is_active)->toBeTrue()
        ->and($freshConnection->connected_at)->toBeInstanceOf(Carbon::class)
        ->and($freshConnection->last_synced_at)->toBeInstanceOf(Carbon::class)
        ->and($freshConnection->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($freshConnection->oauth_state_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($freshConnection->connected_at?->equalTo($connectedAt))->toBeTrue()
        ->and($freshConnection->last_synced_at?->equalTo($lastSyncedAt))->toBeTrue()
        ->and($freshConnection->token_expires_at?->equalTo($tokenExpiresAt))->toBeTrue()
        ->and($freshConnection->oauth_state)->toBe('oauth-state-123')
        ->and($freshConnection->oauth_state_expires_at?->equalTo($oauthStateExpiresAt))->toBeTrue()
        ->and($owner->socialAccountConnections->first()?->is($connection))->toBeTrue()
        ->and($connection->user->is($owner))->toBeTrue();
});

it('allows multiple social accounts on the same platform but blocks duplicate external accounts for one tenant', function () {
    $owner = User::factory()->create(['company_type' => 'services']);

    SocialAccountConnection::create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'North page',
        'external_account_id' => 'fb-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
    ]);

    SocialAccountConnection::create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'South page',
        'external_account_id' => 'fb-page-002',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
    ]);

    expect(fn () => SocialAccountConnection::create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Duplicate page',
        'external_account_id' => 'fb-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
    ]))->toThrow(QueryException::class);
});

it('isolates social account connections by tenant while allowing the same external account id for another tenant', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $ownerConnection = SocialAccountConnection::create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'external_account_id' => 'org-77',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
    ]);

    $otherConnection = SocialAccountConnection::create([
        'user_id' => $otherOwner->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn HQ',
        'external_account_id' => 'org-77',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
    ]);

    expect(SocialAccountConnection::byUser($owner->id)->pluck('id')->all())->toBe([$ownerConnection->id])
        ->and(SocialAccountConnection::byUser($otherOwner->id)->pluck('id')->all())->toBe([$otherConnection->id]);
});

it('exposes supported pulse provider definitions for future publishers', function () {
    $definitions = app(SocialProviderRegistry::class)->definitions();
    $keys = collect($definitions)->pluck('key')->all();
    $instagram = collect($definitions)->firstWhere('key', SocialAccountConnection::PLATFORM_INSTAGRAM);

    expect($keys)->toBe([
        SocialAccountConnection::PLATFORM_FACEBOOK,
        SocialAccountConnection::PLATFORM_INSTAGRAM,
        SocialAccountConnection::PLATFORM_LINKEDIN,
        SocialAccountConnection::PLATFORM_X,
    ])
        ->and(collect($definitions)->every(function (array $definition): bool {
            return ($definition['auth_method'] ?? null) === SocialAccountConnection::AUTH_METHOD_OAUTH
                && ($definition['supports_multiple_accounts'] ?? false) === true
                && ($definition['supports_redirect'] ?? false) === true
                && ($definition['supports_refresh'] ?? false) === true
                && ($definition['supports'] ?? []) === ['text', 'image', 'link', 'schedule'];
        }))->toBeTrue()
        ->and($instagram['label'] ?? null)->toBe('Instagram Business')
        ->and($instagram['target_type'] ?? null)->toBe('business_account')
        ->and($instagram['scopes'] ?? [])->toContain('instagram_content_publish');
});
