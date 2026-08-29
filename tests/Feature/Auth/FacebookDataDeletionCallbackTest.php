<?php

use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\SocialDataDeletionRequest;
use App\Models\SocialTransportCutover;
use App\Models\SocialTransportCutoverEvent;
use App\Models\SocialTransportCutoverMapping;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Social\SocialTransportCutoverService;

beforeEach(function () {
    config()->set('social_auth.providers.facebook.client_secret', 'facebook-client-secret');
    config()->set('social_auth.providers.facebook.data_deletion.delete_local_account', false);
});

test('facebook data deletion landing page explains that the callback expects a post request', function () {
    $this->get(route('integrations.facebook.data-deletion.landing'))
        ->assertOk()
        ->assertSee('Facebook data deletion endpoint')
        ->assertSee('POST')
        ->assertSee('signed_request');

    $this->getJson(route('integrations.facebook.data-deletion.landing'))
        ->assertOk()
        ->assertJsonPath('expected_method', 'POST')
        ->assertJsonPath('expected_parameter', 'signed_request');
});

test('facebook data deletion callback deletes only the login identity and preserves delivery connections', function () {
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

    $directFacebookConnection = SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook Page',
        'display_name' => 'Main Facebook Page',
        'external_account_id' => 'fb-page-001',
        ...pulseDirectTransportIdentity(
            $user,
            SocialAccountConnection::PLATFORM_FACEBOOK,
            'fb-page-001',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    $bufferFacebookConnection = SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer Facebook Page',
        'display_name' => 'Buffer Facebook Page',
        'external_account_id' => 'buffer-fb-page-001',
        'delivery_provider' => 'buffer',
        'transport_generation' => 'buffer_v1',
        'logical_destination_key' => $directFacebookConnection->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $cutoverMapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $user,
        $user,
        $directFacebookConnection,
        $bufferFacebookConnection,
        hash('sha256', 'targeted facebook deletion mapping'),
    );
    $cutoverId = (int) $cutoverMapping->social_transport_cutover_id;

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
        ->and($directFacebookConnection->fresh())->not->toBeNull()
        ->and($bufferFacebookConnection->fresh())->not->toBeNull()
        ->and(SocialTransportCutover::query()->whereKey($cutoverId)->exists())->toBeTrue()
        ->and(SocialTransportCutoverMapping::query()->whereKey($cutoverMapping->id)->exists())
        ->toBeTrue()
        ->and(SocialTransportCutoverEvent::query()
            ->where('social_transport_cutover_id', $cutoverId)
            ->count())->toBe(2)
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
        ->and($deletionRequest?->summary['deleted_facebook_social_connections'] ?? null)->toBe(0);

    $this->get($statusPath)
        ->assertOk()
        ->assertSee($confirmationCode)
        ->assertSee('Facebook data deletion request');

    $this->getJson(route('integrations.facebook.data-deletion.status', [
        'confirmation_code' => $confirmationCode,
    ]))
        ->assertOk()
        ->assertJsonPath('status', SocialDataDeletionRequest::STATUS_COMPLETED)
        ->assertJsonPath('summary.deleted_facebook_social_accounts', 1)
        ->assertJsonPath('summary.deleted_facebook_social_connections', 0);
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

    $directFacebookConnection = SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Main Facebook Page',
        'display_name' => 'Main Facebook Page',
        'external_account_id' => 'fb-page-002',
        ...pulseDirectTransportIdentity(
            $user,
            SocialAccountConnection::PLATFORM_FACEBOOK,
            'fb-page-002',
        ),
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);

    $bufferFacebookConnection = SocialAccountConnection::query()->create([
        'user_id' => $user->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Buffer Facebook Page',
        'display_name' => 'Buffer Facebook Page',
        'external_account_id' => 'buffer-fb-page-002',
        'delivery_provider' => 'buffer',
        'transport_generation' => 'buffer_v1',
        'logical_destination_key' => $directFacebookConnection->logical_destination_key,
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
    ]);
    $cutoverMapping = app(SocialTransportCutoverService::class)->recordOwnerValidatedMapping(
        $user,
        $user,
        $directFacebookConnection,
        $bufferFacebookConnection,
        hash('sha256', 'full facebook deletion mapping'),
    );
    $cutoverId = (int) $cutoverMapping->social_transport_cutover_id;

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
        ->and($directFacebookConnection->fresh())->toBeNull()
        ->and($bufferFacebookConnection->fresh())->toBeNull()
        ->and(SocialTransportCutover::query()->whereKey($cutoverId)->exists())->toBeFalse()
        ->and(SocialTransportCutoverMapping::query()->whereKey($cutoverMapping->id)->exists())
        ->toBeFalse()
        ->and(SocialTransportCutoverEvent::query()
            ->where('social_transport_cutover_id', $cutoverId)
            ->exists())->toBeFalse()
        ->and($deletionRequest->status)->toBe(SocialDataDeletionRequest::STATUS_COMPLETED)
        ->and($deletionRequest->delete_local_account)->toBeTrue()
        ->and($deletionRequest->user_id)->toBeNull()
        ->and($deletionRequest->summary['deleted_local_account'] ?? null)->toBeTrue()
        ->and($deletionRequest->summary['deleted_local_account_mode'] ?? null)->toBe('account')
        ->and($deletionRequest->summary['deleted_facebook_social_connections'] ?? null)->toBe(2);
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
