<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Role;
use App\Models\SocialAccountConnection;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function bufferLocalOwner(): User
{
    $roleId = (int) Role::query()->firstOrCreate(
        ['name' => 'owner'],
        ['description' => 'Owner role']
    )->id;

    $owner = User::factory()->create([
        'role_id' => $roleId,
        'email' => 'buffer-local-owner-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => 'services',
        'company_sector' => 'service_general',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'social' => true,
        ],
    ]);

    config()->set('services.buffer.local_connector.owner_id', $owner->id);

    return $owner;
}

function bufferLocalMember(User $owner): User
{
    $member = User::factory()->create([
        'email' => 'buffer-local-member-'.Str::lower(Str::random(10)).'@example.com',
        'company_type' => $owner->company_type,
        'company_features' => $owner->company_features,
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'member',
        'permissions' => ['social.manage'],
        'is_active' => true,
    ]);

    return $member;
}

/**
 * @param  array<string, mixed>  $channelOverrides
 */
function fakeHealthyBufferCatalog(array $channelOverrides = []): void
{
    Http::fake([
        'https://buffer.test/graphql' => function (Request $request) use ($channelOverrides) {
            $query = (string) ($request['query'] ?? '');

            if (str_contains($query, 'MalikiaPulseBufferAccount')) {
                return Http::response([
                    'data' => [
                        'account' => [
                            'id' => 'buffer_account_1',
                            'name' => 'Malikia Buffer',
                            'organizations' => [
                                [
                                    'id' => 'organization_1',
                                    'name' => 'Malikia Pro',
                                ],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'MalikiaPulseBufferChannels')) {
                return Http::response([
                    'data' => [
                        'channels' => [
                            array_merge([
                                'id' => 'channel_facebook_1',
                                'organizationId' => 'organization_1',
                                'name' => 'malikiapro',
                                'displayName' => 'Malikia Pro',
                                'service' => 'facebook',
                                'type' => 'page',
                                'isDisconnected' => false,
                                'isLocked' => false,
                                'isQueuePaused' => false,
                                'timezone' => 'America/Toronto',
                                'scopes' => ['channel:read'],
                                'allowedActions' => ['createPost', 'schedulePost'],
                            ], $channelOverrides),
                        ],
                    ],
                ]);
            }

            return Http::response([], 500);
        },
    ]);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);

    config()->set('services.buffer.local_connector', [
        'enabled' => true,
        'owner_id' => null,
        'access_token' => 'buffer-local-secret',
        'api_url' => 'https://buffer.test/graphql',
        'connect_timeout' => 2,
        'timeout' => 5,
    ]);
    config()->set('services.buffer.oauth.client_id', null);

    Http::preventStrayRequests();
});

it('shows the real Buffer catalog to the owner without exposing the server token', function () {
    $owner = bufferLocalOwner();
    fakeHealthyBufferCatalog();

    $response = $this->actingAs($owner)->getJson(route('social.buffer.catalog'));

    $response->assertOk()
        ->assertJsonPath('connector.available', true)
        ->assertJsonPath('connector.delivery_enabled', false)
        ->assertJsonPath('account.name', 'Malikia Buffer')
        ->assertJsonPath('organizations.0.name', 'Malikia Pro')
        ->assertJsonPath('organizations.0.channels.0.display_name', 'Malikia Pro')
        ->assertJsonPath('organizations.0.channels.0.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        ->assertJsonPath('organizations.0.channels.0.can_import', true)
        ->assertJsonPath('organizations.0.channels.0.imported', false)
        ->assertJsonPath('channel_count', 1)
        ->assertJsonPath('imported_count', 0)
        ->assertJsonMissingPath('connector.access_token')
        ->assertJsonMissingPath('connector.manage_url')
        ->assertJsonMissingPath('account.id')
        ->assertJsonMissingPath('organizations.0.channels.0.scopes')
        ->assertJsonMissingPath('organizations.0.channels.0.allowed_actions');

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('buffer-local-secret');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => (
        $request->url() === 'https://buffer.test/graphql'
        && $request->hasHeader('Authorization', 'Bearer buffer-local-secret')
        && str_contains((string) ($request['query'] ?? ''), 'MalikiaPulseBufferAccount')
        && str_contains($request->body(), '"variables":{}')
    ));
});

it('imports a healthy Buffer channel idempotently while keeping delivery disabled', function () {
    $owner = bufferLocalOwner();
    fakeHealthyBufferCatalog();

    $payload = [
        'organization_id' => 'organization_1',
        'channel_id' => 'channel_facebook_1',
    ];

    $first = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), $payload);

    $first->assertCreated()
        ->assertJsonPath('connection.platform', SocialAccountConnection::PLATFORM_FACEBOOK)
        ->assertJsonPath('connection.status', SocialAccountConnection::STATUS_CONNECTED)
        ->assertJsonPath('connection.is_active', false)
        ->assertJsonPath('connection.is_connected', false)
        ->assertJsonPath('connection.has_credentials', false);

    $second = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), $payload);

    $second->assertCreated()
        ->assertJsonPath('connection.id', $first->json('connection.id'));

    expect(SocialAccountConnection::query()->count())->toBe(1);

    $connection = SocialAccountConnection::query()->sole();

    expect($connection->delivery_provider)->toBeNull()
        ->and($connection->transport_generation)->toBeNull()
        ->and($connection->logical_destination_key)->toBeNull()
        ->and($connection->credentials)->toBeNull()
        ->and($connection->is_active)->toBeFalse()
        ->and(data_get($connection->metadata, 'buffer.catalog_only'))->toBeTrue()
        ->and(data_get($connection->metadata, 'buffer.credential_source'))->toBe('server_environment');

    $this->actingAs($owner)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonCount(0, 'connections')
        ->assertJsonPath('summary.configured', 1)
        ->assertJsonPath('buffer_connector.delivery_enabled', false);
});

it('revalidates remote channel state and refuses locked channels', function () {
    $owner = bufferLocalOwner();
    fakeHealthyBufferCatalog([
        'isLocked' => true,
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), [
            'organization_id' => 'organization_1',
            'channel_id' => 'channel_facebook_1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');

    expect(SocialAccountConnection::query()->count())->toBe(0);
});

it('never overwrites a delivery-capable Buffer connection during catalog import', function () {
    $owner = bufferLocalOwner();
    fakeHealthyBufferCatalog();
    $connection = SocialAccountConnection::query()->create([
        'user_id' => $owner->id,
        'platform' => SocialAccountConnection::PLATFORM_FACEBOOK,
        'label' => 'Existing Buffer delivery',
        'external_account_id' => 'channel_facebook_1',
        'delivery_provider' => SocialAccountConnection::DELIVERY_PROVIDER_BUFFER,
        'transport_generation' => SocialAccountConnection::TRANSPORT_GENERATION_BUFFER_V1,
        'logical_destination_key' => 'ldk:v1:'.str_repeat('a', 64),
        'auth_method' => SocialAccountConnection::AUTH_METHOD_OAUTH,
        'credentials' => ['access_token' => 'must-survive'],
        'status' => SocialAccountConnection::STATUS_CONNECTED,
        'is_active' => true,
        'metadata' => [
            'connection_flow' => 'buffer_oauth',
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), [
            'organization_id' => 'organization_1',
            'channel_id' => 'channel_facebook_1',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channel_id');

    $fresh = $connection->fresh();

    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->credentials)->toBe(['access_token' => 'must-survive'])
        ->and($fresh->metadata)->toBe(['connection_flow' => 'buffer_oauth']);
});

it('blocks every direct account mutation for an imported Buffer catalog channel', function () {
    $owner = bufferLocalOwner();
    fakeHealthyBufferCatalog();

    $import = $this->actingAs($owner)
        ->postJson(route('social.buffer.channels.store'), [
            'organization_id' => 'organization_1',
            'channel_id' => 'channel_facebook_1',
        ])
        ->assertCreated();
    $connectionId = (int) $import->json('connection.id');

    $requests = [
        ['putJson', route('social.accounts.update', $connectionId), ['is_active' => true]],
        ['postJson', route('social.accounts.authorize', $connectionId), []],
        ['postJson', route('social.accounts.refresh', $connectionId), []],
        ['postJson', route('social.accounts.test', $connectionId), []],
        ['postJson', route('social.accounts.disconnect', $connectionId), []],
        ['deleteJson', route('social.accounts.destroy', $connectionId), []],
    ];

    foreach ($requests as [$method, $url, $payload]) {
        $this->actingAs($owner)
            ->{$method}($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('connection');
    }

    $connection = SocialAccountConnection::query()->findOrFail($connectionId);

    expect($connection->status)->toBe(SocialAccountConnection::STATUS_CONNECTED)
        ->and($connection->is_active)->toBeFalse()
        ->and($connection->oauth_state)->toBeNull()
        ->and(data_get($connection->metadata, 'buffer.catalog_only'))->toBeTrue();
});

it('keeps Buffer discovery owner only and does not contact Buffer for a team member', function () {
    $owner = bufferLocalOwner();
    $member = bufferLocalMember($owner);
    fakeHealthyBufferCatalog();

    $this->actingAs($member)
        ->getJson(route('social.buffer.catalog'))
        ->assertForbidden();

    $this->actingAs($member)
        ->postJson(route('social.buffer.channels.store'), [
            'organization_id' => 'organization_1',
            'channel_id' => 'channel_facebook_1',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->getJson(route('social.accounts.index'))
        ->assertOk()
        ->assertJsonPath('buffer_connector', null);

    Http::assertNothingSent();
});

it('scopes the local Buffer credential to one configured owner', function () {
    $configuredOwner = bufferLocalOwner();
    $otherOwner = bufferLocalOwner();
    config()->set('services.buffer.local_connector.owner_id', $configuredOwner->id);
    fakeHealthyBufferCatalog();

    $this->actingAs($otherOwner)
        ->getJson(route('social.buffer.catalog'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    Http::assertNothingSent();
});

it('requires explicit local connector activation before contacting Buffer', function () {
    $owner = bufferLocalOwner();
    config()->set('services.buffer.local_connector.enabled', false);
    fakeHealthyBufferCatalog();

    $this->actingAs($owner)
        ->getJson(route('social.buffer.catalog'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    Http::assertNothingSent();
});

it('reports an invalid Buffer token without returning it to the browser', function () {
    $owner = bufferLocalOwner();
    Http::fake([
        'https://buffer.test/graphql' => Http::response([], 401),
    ]);

    $response = $this->actingAs($owner)->getJson(route('social.buffer.catalog'));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('buffer');

    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
        ->not->toContain('buffer-local-secret');
});
