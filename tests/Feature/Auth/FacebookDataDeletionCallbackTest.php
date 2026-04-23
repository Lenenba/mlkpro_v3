<?php

use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialDataDeletionRequest;
use App\Models\User;
use App\Models\UserSocialAccount;

beforeEach(function () {
    config()->set('social_auth.providers.facebook.client_secret', 'facebook-client-secret');
    config()->set('social_auth.providers.facebook.data_deletion.delete_local_account', false);
});

test('facebook data deletion callback deletes facebook-linked app data and returns a confirmation url', function () {
    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'facebook-delete@example.com',
        'role_id' => $ownerRoleId,
    ]);

    UserSocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => UserSocialAccount::PROVIDER_FACEBOOK,
        'provider_user_id' => 'facebook-user-delete-001',
        'provider_email' => $user->email,
        'provider_name' => 'Facebook Delete User',
    ]);

    SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook Page',
        'display_name' => 'Main Facebook Page',
        'external_account_id' => 'fb-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_LINKEDIN,
        'label' => 'LinkedIn Company',
        'display_name' => 'LinkedIn Company',
        'external_account_id' => 'li-page-001',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    $response = $this->post(route('integrations.facebook.data-deletion.callback'), [
        'signed_request' => facebookSignedRequest([
            'user_id' => 'facebook-user-delete-001',
        ]),
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'url',
        'confirmation_code',
    ]);

    expect(UserSocialAccount::query()
        ->where('user_id', $user->id)
        ->where('provider', UserSocialAccount::PROVIDER_FACEBOOK)
        ->exists())->toBeFalse()
        ->and(SocialAccountConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', SocialAccountConnection::PLATFORM_FACEBOOK)
            ->exists())->toBeFalse()
        ->and(SocialAccountConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', SocialAccountConnection::PLATFORM_LINKEDIN)
            ->exists())->toBeTrue();

    $confirmationCode = $response->json('confirmation_code');
    $statusUrl = (string) $response->json('url');
    $statusPath = parse_url($statusUrl, PHP_URL_PATH) ?: $statusUrl;

    $deletionRequest = SocialDataDeletionRequest::query()
        ->where('confirmation_code', $confirmationCode)
        ->first();

    expect($deletionRequest)->not->toBeNull()
        ->and($deletionRequest?->status)->toBe(SocialDataDeletionRequest::STATUS_COMPLETED)
        ->and($deletionRequest?->delete_local_account)->toBeFalse()
        ->and($deletionRequest?->summary['deleted_facebook_social_accounts'] ?? null)->toBe(1)
        ->and($deletionRequest?->summary['deleted_facebook_social_connections'] ?? null)->toBe(1);

    $this->get($statusPath)
        ->assertOk()
        ->assertSee($confirmationCode)
        ->assertSee('Facebook data deletion request');

    $this->getJson(route('integrations.facebook.data-deletion.status', [
        'confirmationCode' => $confirmationCode,
    ]))
        ->assertOk()
        ->assertJsonPath('status', SocialDataDeletionRequest::STATUS_COMPLETED)
        ->assertJsonPath('summary.deleted_facebook_social_accounts', 1)
        ->assertJsonPath('summary.deleted_facebook_social_connections', 1);
});

test('facebook data deletion callback can delete the local account when explicitly enabled', function () {
    config()->set('social_auth.providers.facebook.data_deletion.delete_local_account', true);

    $ownerRoleId = Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Account owner role']
    )->id;

    $user = User::factory()->create([
        'email' => 'facebook-delete-account@example.com',
        'role_id' => $ownerRoleId,
    ]);

    UserSocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => UserSocialAccount::PROVIDER_FACEBOOK,
        'provider_user_id' => 'facebook-user-delete-002',
        'provider_email' => $user->email,
        'provider_name' => 'Delete Full Account',
    ]);

    SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook Page',
        'display_name' => 'Main Facebook Page',
        'external_account_id' => 'fb-page-002',
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    $response = $this->post(route('integrations.facebook.data-deletion.callback'), [
        'signed_request' => facebookSignedRequest([
            'user_id' => 'facebook-user-delete-002',
        ]),
    ]);

    $response->assertOk();

    $confirmationCode = $response->json('confirmation_code');
    $deletionRequest = SocialDataDeletionRequest::query()
        ->where('confirmation_code', $confirmationCode)
        ->firstOrFail();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and($deletionRequest->status)->toBe(SocialDataDeletionRequest::STATUS_COMPLETED)
        ->and($deletionRequest->delete_local_account)->toBeTrue()
        ->and($deletionRequest->user_id)->toBeNull()
        ->and($deletionRequest->summary['deleted_local_account'] ?? null)->toBeTrue()
        ->and($deletionRequest->summary['deleted_local_account_mode'] ?? null)->toBe('account');
});

test('facebook data deletion callback rejects an invalid signed request', function () {
    $response = $this->post(route('integrations.facebook.data-deletion.callback'), [
        'signed_request' => 'invalid.payload',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'The Facebook signed_request payload could not be decoded.');

    expect(SocialDataDeletionRequest::query()->count())->toBe(0);
});

function facebookSignedRequest(array $payload, string $secret = 'facebook-client-secret'): string
{
    $payload['algorithm'] = 'HMAC-SHA256';

    $encodedPayload = facebookBase64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $signature = hash_hmac('sha256', $encodedPayload, $secret, true);

    return facebookBase64UrlEncode($signature).'.'.$encodedPayload;
}

function facebookBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
