<?php

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds user social accounts table with expected columns', function () {
    expect(Schema::hasTable('user_social_accounts'))->toBeTrue()
        ->and(Schema::hasColumns('user_social_accounts', [
            'user_id',
            'provider',
            'provider_user_id',
            'provider_email',
            'provider_email_verified_at',
            'provider_name',
            'provider_avatar_url',
            'access_token',
            'refresh_token',
            'token_expires_at',
            'last_login_at',
            'metadata',
        ]))->toBeTrue();
});

it('persists linked social auth accounts with encrypted tokens and user relation', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $verifiedAt = Carbon::parse('2026-04-23 09:00:00');
    $expiresAt = Carbon::parse('2026-04-23 10:00:00');
    $lastLoginAt = Carbon::parse('2026-04-23 09:30:00');

    $account = UserSocialAccount::query()->create([
        'user_id' => $owner->id,
        'provider' => UserSocialAccount::PROVIDER_GOOGLE,
        'provider_user_id' => 'google-user-001',
        'provider_email' => 'owner@example.com',
        'provider_email_verified_at' => $verifiedAt,
        'provider_name' => 'Owner Example',
        'provider_avatar_url' => 'https://cdn.example.com/avatar.png',
        'access_token' => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'token_expires_at' => $expiresAt,
        'last_login_at' => $lastLoginAt,
        'metadata' => [
            'locale' => 'fr',
            'hosted_domain' => 'example.com',
        ],
    ]);

    $freshAccount = $account->fresh()->load('user');
    $storedAccount = DB::table('user_social_accounts')->where('id', $account->id)->first();

    expect($freshAccount)->not->toBeNull()
        ->and($freshAccount->provider)->toBe(UserSocialAccount::PROVIDER_GOOGLE)
        ->and($freshAccount->provider_user_id)->toBe('google-user-001')
        ->and($freshAccount->provider_email)->toBe('owner@example.com')
        ->and($freshAccount->provider_email_verified_at)->toBeInstanceOf(Carbon::class)
        ->and($freshAccount->provider_email_verified_at?->equalTo($verifiedAt))->toBeTrue()
        ->and($freshAccount->provider_name)->toBe('Owner Example')
        ->and($freshAccount->provider_avatar_url)->toBe('https://cdn.example.com/avatar.png')
        ->and($freshAccount->access_token)->toBe('google-access-token')
        ->and($freshAccount->refresh_token)->toBe('google-refresh-token')
        ->and($freshAccount->token_expires_at)->toBeInstanceOf(Carbon::class)
        ->and($freshAccount->token_expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and($freshAccount->last_login_at)->toBeInstanceOf(Carbon::class)
        ->and($freshAccount->last_login_at?->equalTo($lastLoginAt))->toBeTrue()
        ->and($freshAccount->metadata)->toBe([
            'locale' => 'fr',
            'hosted_domain' => 'example.com',
        ])
        ->and($freshAccount->user->is($owner))->toBeTrue()
        ->and($owner->userSocialAccounts()->first()?->is($account))->toBeTrue()
        ->and($storedAccount)->not->toBeNull()
        ->and($storedAccount->access_token)->not->toBe('google-access-token')
        ->and($storedAccount->refresh_token)->not->toBe('google-refresh-token');
});

it('prevents the same provider identity from being linked to two users', function () {
    $firstOwner = User::factory()->create(['company_type' => 'services']);
    $secondOwner = User::factory()->create(['company_type' => 'services']);

    UserSocialAccount::query()->create([
        'user_id' => $firstOwner->id,
        'provider' => UserSocialAccount::PROVIDER_MICROSOFT,
        'provider_user_id' => 'entra-user-001',
    ]);

    expect(fn () => UserSocialAccount::query()->create([
        'user_id' => $secondOwner->id,
        'provider' => UserSocialAccount::PROVIDER_MICROSOFT,
        'provider_user_id' => 'entra-user-001',
    ]))->toThrow(QueryException::class);
});

it('prevents more than one linked account per provider for the same user', function () {
    $owner = User::factory()->create(['company_type' => 'services']);

    UserSocialAccount::query()->create([
        'user_id' => $owner->id,
        'provider' => UserSocialAccount::PROVIDER_FACEBOOK,
        'provider_user_id' => 'facebook-user-001',
    ]);

    expect(fn () => UserSocialAccount::query()->create([
        'user_id' => $owner->id,
        'provider' => UserSocialAccount::PROVIDER_FACEBOOK,
        'provider_user_id' => 'facebook-user-002',
    ]))->toThrow(QueryException::class);
});
