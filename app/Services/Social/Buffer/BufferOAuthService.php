<?php

namespace App\Services\Social\Buffer;

use App\Models\SocialAccountConnection;
use App\Models\SocialBufferConnection;
use App\Models\User;
use App\Services\Social\SocialConnectionDeliveryMutex;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BufferOAuthService
{
    private const CLAIM_PREFIX = 'claim:';

    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const STATE_PREFIX = 'state:';

    public function __construct(
        private readonly BufferGraphqlClient $client,
        private readonly SocialConnectionDeliveryMutex $deliveryMutex,
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientId() !== ''
            && $this->authorizeUrl() !== ''
            && $this->tokenUrl() !== ''
            && $this->redirectUri() !== '';
    }

    /**
     * @return array{
     *     oauth_configured: bool,
     *     connected: bool,
     *     authorizing: bool,
     *     can_connect: bool,
     *     can_disconnect: bool,
     *     account_name: ?string,
     *     token_expires_at: ?string
     * }
     */
    public function status(User $owner): array
    {
        $connection = SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->first();
        $connected = $connection?->isConnected() ?? false;

        return [
            'oauth_configured' => $this->isConfigured(),
            'connected' => $connected,
            'authorizing' => $connection !== null && $this->hasActiveClaim($connection),
            'can_connect' => $this->isConfigured(),
            'can_disconnect' => $connected,
            'account_name' => $connected ? $connection->buffer_account_name : null,
            'token_expires_at' => $connected
                ? $connection->token_expires_at?->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array{redirect_url: string, connector: array<string, mixed>}
     */
    public function beginAuthorization(User $owner): array
    {
        $this->assertConfigured();

        return $this->withOwnerLock((int) $owner->id, function () use ($owner): array {
            $state = Str::random(64);
            $verifier = $this->base64UrlEncode(random_bytes(32));
            $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));

            DB::transaction(function () use ($owner, $state, $verifier): void {
                User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

                $connection = SocialBufferConnection::query()
                    ->whereBelongsTo($owner)
                    ->lockForUpdate()
                    ->first() ?? new SocialBufferConnection(['user_id' => $owner->id]);

                if ($this->hasActiveClaim($connection)) {
                    throw ValidationException::withMessages([
                        'buffer' => 'La connexion Buffer est déjà en cours de validation.',
                    ]);
                }

                $connection->forceFill([
                    'oauth_state' => $this->stateMarker($state),
                    'oauth_code_verifier' => $verifier,
                    'oauth_state_expires_at' => now()->addMinutes(15),
                    'last_error' => null,
                ])->save();
            }, 3);

            $query = [
                'client_id' => $this->clientId(),
                'redirect_uri' => $this->redirectUri(),
                'response_type' => 'code',
                'scope' => implode(' ', $this->scopes()),
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
                'prompt' => 'consent',
            ];

            return [
                'redirect_url' => $this->authorizeUrl().'?'.http_build_query(
                    $query,
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
                'connector' => $this->status($owner),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: true, message: string, owner_id: int}
     */
    public function completeAuthorization(array $payload): array
    {
        $state = $this->callbackState($payload);
        $claim = $this->claimAuthorization($state, $payload);

        try {
            return $this->withOwnerLock($claim['user_id'], function () use ($claim): array {
                try {
                    $tokens = $this->requestTokens([
                        'client_id' => $this->clientId(),
                        ...$this->clientSecretPayload(),
                        'grant_type' => 'authorization_code',
                        'code' => $claim['code'],
                        'redirect_uri' => $this->redirectUri(),
                        'code_verifier' => $claim['verifier'],
                    ]);
                    $account = $this->client->account($tokens['access_token']);
                } catch (ValidationException $exception) {
                    $this->failClaim(
                        $claim['connection_id'],
                        $claim['marker'],
                        $this->validationMessage($exception),
                    );

                    throw $exception;
                }

                DB::transaction(function () use ($account, $claim, $tokens): void {
                    $connection = SocialBufferConnection::query()
                        ->whereKey($claim['connection_id'])
                        ->where('oauth_state', $claim['marker'])
                        ->lockForUpdate()
                        ->first();

                    if (! $connection) {
                        throw ValidationException::withMessages([
                            'buffer' => 'Cette connexion Buffer a été remplacée. Recommencez.',
                        ]);
                    }

                    $connection->forceFill([
                        'buffer_account_id' => $account['id'],
                        'buffer_account_name' => $account['name'],
                        'access_token' => $tokens['access_token'],
                        'refresh_token' => $tokens['refresh_token'],
                        'token_type' => $tokens['token_type'],
                        'scopes' => $tokens['scopes'],
                        'token_expires_at' => $tokens['token_expires_at'],
                        'connected_at' => now(),
                        'last_refreshed_at' => null,
                        'oauth_state' => null,
                        'oauth_code_verifier' => null,
                        'oauth_state_expires_at' => null,
                        'last_error' => null,
                    ])->save();
                }, 3);

                return [
                    'success' => true,
                    'message' => 'Le compte Buffer est connecté.',
                    'owner_id' => $claim['user_id'],
                ];
            });
        } catch (ValidationException $exception) {
            $this->failClaim(
                $claim['connection_id'],
                $claim['marker'],
                $this->validationMessage($exception),
            );

            throw $exception;
        }
    }

    public function accessToken(User $owner): string
    {
        $connection = SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->first();

        if (! $connection?->isConnected()) {
            throw ValidationException::withMessages([
                'buffer' => 'Connectez votre compte Buffer pour continuer.',
            ]);
        }

        if ($connection->token_expires_at?->isAfter(now()->addMinute())) {
            return (string) $connection->access_token;
        }

        return $this->refreshAccessToken($owner);
    }

    /**
     * @param  list<string>  $requiredScopes
     */
    public function hasGrantedScopes(User $owner, array $requiredScopes): bool
    {
        $connection = SocialBufferConnection::query()
            ->whereBelongsTo($owner)
            ->first();

        if (! $connection?->isConnected()) {
            return false;
        }

        $grantedScopes = collect((array) $connection->scopes)
            ->filter(fn (mixed $scope): bool => is_string($scope))
            ->map(fn (string $scope): string => trim($scope))
            ->filter()
            ->unique();

        return collect($requiredScopes)
            ->filter(fn (mixed $scope): bool => is_string($scope))
            ->map(fn (string $scope): string => trim($scope))
            ->filter()
            ->every(fn (string $scope): bool => $grantedScopes->containsStrict($scope));
    }

    public function disconnect(User $owner): void
    {
        $tenantLock = $this->deliveryMutex->acquireTenant((int) $owner->id);

        if ($tenantLock === null) {
            throw ValidationException::withMessages([
                'buffer' => 'Une publication Pulse est en cours. Réessayez la déconnexion dans un instant.',
            ]);
        }

        $connectionLocks = [];

        try {
            $connectionIds = SocialAccountConnection::query()
                ->byUser($owner->id)
                ->where(function ($query): void {
                    $query
                        ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
                        ->orWhere('metadata->buffer->catalog_only', true);
                })
                ->orderBy('id')
                ->pluck('id');

            foreach ($connectionIds as $connectionId) {
                $connectionLock = $this->deliveryMutex->acquire((int) $connectionId);

                if ($connectionLock === null) {
                    throw ValidationException::withMessages([
                        'buffer' => 'Une publication Buffer est en cours. Réessayez la déconnexion dans un instant.',
                    ]);
                }

                $connectionLocks[] = $connectionLock;
            }

            $this->withOwnerLock((int) $owner->id, function () use ($owner): void {
                DB::transaction(function () use ($owner): void {
                    User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();

                    $connections = SocialAccountConnection::query()
                        ->byUser($owner->id)
                        ->where(function ($query): void {
                            $query
                                ->where('delivery_provider', SocialAccountConnection::DELIVERY_PROVIDER_BUFFER)
                                ->orWhere('metadata->buffer->catalog_only', true);
                        })
                        ->lockForUpdate()
                        ->get();

                    foreach ($connections as $connection) {
                        $metadata = (array) ($connection->metadata ?? []);
                        $bufferMetadata = (array) data_get($metadata, 'buffer', []);

                        $connection->forceFill([
                            'status' => SocialAccountConnection::STATUS_RECONNECT_REQUIRED,
                            'is_active' => false,
                            'last_error' => 'Reconnectez Buffer avant la prochaine publication.',
                            'metadata' => [
                                ...$metadata,
                                'buffer' => [
                                    ...$bufferMetadata,
                                    'publication_enabled' => false,
                                ],
                            ],
                        ])->save();
                    }

                    SocialBufferConnection::query()
                        ->whereBelongsTo($owner)
                        ->lockForUpdate()
                        ->first()?->delete();
                }, 3);
            });
        } finally {
            foreach (array_reverse($connectionLocks) as $connectionLock) {
                $connectionLock->release();
            }

            $tenantLock->release();
        }
    }

    private function refreshAccessToken(User $owner): string
    {
        return $this->withOwnerLock((int) $owner->id, function () use ($owner): string {
            $connection = SocialBufferConnection::query()
                ->whereBelongsTo($owner)
                ->first();

            if (! $connection?->isConnected()) {
                throw ValidationException::withMessages([
                    'buffer' => 'Reconnectez votre compte Buffer pour continuer.',
                ]);
            }

            if ($connection->token_expires_at?->isAfter(now()->addMinute())) {
                return (string) $connection->access_token;
            }

            $refreshToken = trim((string) $connection->refresh_token);

            if ($refreshToken === '') {
                throw ValidationException::withMessages([
                    'buffer' => 'Reconnectez votre compte Buffer pour renouveler l’accès.',
                ]);
            }

            try {
                $tokens = $this->requestTokens([
                    'client_id' => $this->clientId(),
                    ...$this->clientSecretPayload(),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
            } catch (ValidationException $exception) {
                $connection->forceFill([
                    'last_error' => $this->validationMessage($exception),
                ])->save();

                throw $exception;
            }

            DB::transaction(function () use ($connection, $refreshToken, $tokens): void {
                $lockedConnection = SocialBufferConnection::query()
                    ->whereKey($connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals($refreshToken, (string) $lockedConnection->refresh_token)) {
                    throw ValidationException::withMessages([
                        'buffer' => 'Le compte Buffer a déjà été renouvelé. Réessayez.',
                    ]);
                }

                $lockedConnection->forceFill([
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => $tokens['token_type'],
                    'scopes' => $tokens['scopes'],
                    'token_expires_at' => $tokens['token_expires_at'],
                    'last_refreshed_at' => now(),
                    'last_error' => null,
                ])->save();
            }, 3);

            return $tokens['access_token'];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{connection_id: int, user_id: int, marker: string, code: string, verifier: string}
     */
    private function claimAuthorization(string $state, array $payload): array
    {
        $stateMarker = $this->stateMarker($state);

        $claim = DB::transaction(function () use ($payload, $stateMarker): array {
            $connection = SocialBufferConnection::query()
                ->where('oauth_state', $stateMarker)
                ->lockForUpdate()
                ->first();

            if (! $connection) {
                return ['error' => 'Cette autorisation Buffer est inconnue ou déjà utilisée.'];
            }

            if (! $connection->oauth_state_expires_at
                || $connection->oauth_state_expires_at->isPast()) {
                $connection->forceFill([
                    'oauth_state' => null,
                    'oauth_code_verifier' => null,
                    'oauth_state_expires_at' => null,
                    'last_error' => 'L’autorisation Buffer a expiré.',
                ])->save();

                return ['error' => 'L’autorisation Buffer a expiré. Recommencez.'];
            }

            $providerError = $this->callbackValue($payload, 'error', 128);

            if ($providerError !== '') {
                $connection->forceFill([
                    'oauth_state' => null,
                    'oauth_code_verifier' => null,
                    'oauth_state_expires_at' => null,
                    'last_error' => 'Buffer a refusé l’autorisation.',
                ])->save();

                return ['error' => 'La connexion Buffer a été annulée.'];
            }

            $code = $this->callbackValue($payload, 'code', 2048);
            $verifier = trim((string) $connection->oauth_code_verifier);

            if ($code === '' || $verifier === '') {
                $connection->forceFill([
                    'oauth_state' => null,
                    'oauth_code_verifier' => null,
                    'oauth_state_expires_at' => null,
                    'last_error' => 'Buffer n’a pas retourné une autorisation complète.',
                ])->save();

                return ['error' => 'Buffer n’a pas retourné une autorisation complète.'];
            }

            $claimMarker = self::CLAIM_PREFIX.hash('sha256', Str::random(64));

            $connection->forceFill([
                'oauth_state' => $claimMarker,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => now()->addMinutes(2),
            ])->save();

            return [
                'connection_id' => (int) $connection->id,
                'user_id' => (int) $connection->user_id,
                'marker' => $claimMarker,
                'code' => $code,
                'verifier' => $verifier,
            ];
        }, 3);

        if (isset($claim['error'])) {
            throw ValidationException::withMessages([
                'buffer' => (string) $claim['error'],
            ]);
        }

        /** @var array{connection_id: int, user_id: int, marker: string, code: string, verifier: string} $claim */
        return $claim;
    }

    private function failClaim(int $connectionId, string $claimMarker, string $message): void
    {
        SocialBufferConnection::query()
            ->whereKey($connectionId)
            ->where('oauth_state', $claimMarker)
            ->update([
                'oauth_state' => null,
                'oauth_code_verifier' => null,
                'oauth_state_expires_at' => null,
                'last_error' => Str::limit($message, 500, ''),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     scopes: list<string>,
     *     token_expires_at: Carbon
     * }
     */
    private function requestTokens(array $payload): array
    {
        $this->assertConfigured();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->post($this->tokenUrl(), $payload);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer est momentanément inaccessible. Réessayez.',
            ]);
        }

        $this->assertTokenResponse($response);
        $data = $response->json();

        if (! is_array($data)) {
            $this->invalidTokenResponse();
        }

        $accessToken = $this->tokenString($data, 'access_token', 8192);
        $refreshToken = $this->tokenString($data, 'refresh_token', 8192);
        $tokenType = $this->tokenString($data, 'token_type', 32);
        $expiresIn = filter_var($data['expires_in'] ?? null, FILTER_VALIDATE_INT);

        if ($accessToken === ''
            || $refreshToken === ''
            || Str::lower($tokenType) !== 'bearer'
            || $expiresIn === false
            || $expiresIn < 1
            || $expiresIn > 2_592_000) {
            $this->invalidTokenResponse();
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'scopes' => $this->tokenScopes($data['scope'] ?? null),
            'token_expires_at' => now()->addSeconds($expiresIn),
        ];
    }

    private function assertTokenResponse(Response $response): void
    {
        if (Str::length($response->body()) > self::MAX_RESPONSE_BYTES) {
            $this->invalidTokenResponse();
        }

        if ($response->successful()) {
            return;
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw ValidationException::withMessages([
                'buffer' => 'Buffer a refusé l’autorisation. Reconnectez le compte.',
            ]);
        }

        if ($response->status() === 429) {
            throw ValidationException::withMessages([
                'buffer' => 'Le quota Buffer est temporairement atteint. Réessayez plus tard.',
            ]);
        }

        throw ValidationException::withMessages([
            'buffer' => 'Buffer n’a pas pu terminer l’autorisation.',
        ]);
    }

    /**
     * @return list<string>
     */
    private function tokenScopes(mixed $value): array
    {
        if (! is_string($value)) {
            $this->invalidTokenResponse();
        }

        $scopes = preg_split('/\s+/', trim($value)) ?: [];

        foreach ($scopes as $scope) {
            if (preg_match('/\A[a-z][a-z0-9:_-]{0,63}\z/i', $scope) !== 1) {
                $this->invalidTokenResponse();
            }
        }

        return array_values(array_unique(array_filter($scopes)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackState(array $payload): string
    {
        $state = $this->callbackValue($payload, 'state', 64);

        if (preg_match('/\A[A-Za-z0-9]{64}\z/', $state) !== 1) {
            throw ValidationException::withMessages([
                'buffer' => 'L’état OAuth Buffer est invalide.',
            ]);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callbackValue(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                'buffer' => 'La réponse OAuth Buffer est invalide.',
            ]);
        }

        $value = trim($value);

        if (Str::length($value) > $maxLength || preg_match('/\p{Cc}/u', $value) !== 0) {
            throw ValidationException::withMessages([
                'buffer' => 'La réponse OAuth Buffer est invalide.',
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tokenString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return Str::length($value) <= $maxLength && preg_match('/\p{Cc}/u', $value) === 0
            ? $value
            : '';
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $configured = config('services.buffer.oauth.scopes', [
            'account:read',
            'posts:read',
            'posts:write',
            'offline_access',
        ]);

        return collect(is_array($configured) ? $configured : [])
            ->filter(fn (mixed $scope): bool => is_string($scope))
            ->map(fn (string $scope): string => trim($scope))
            ->filter(fn (string $scope): bool => preg_match('/\A[a-z][a-z0-9:_-]{0,63}\z/i', $scope) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{client_secret?: string}
     */
    private function clientSecretPayload(): array
    {
        $clientSecret = trim((string) config('services.buffer.oauth.client_secret'));

        return $clientSecret !== '' ? ['client_secret' => $clientSecret] : [];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw ValidationException::withMessages([
                'buffer' => 'OAuth Buffer n’est pas configuré sur ce serveur.',
            ]);
        }
    }

    private function clientId(): string
    {
        return trim((string) config('services.buffer.oauth.client_id'));
    }

    private function authorizeUrl(): string
    {
        return trim((string) config('services.buffer.oauth.authorize_url', 'https://auth.buffer.com/auth'));
    }

    private function tokenUrl(): string
    {
        return trim((string) config('services.buffer.oauth.token_url', 'https://auth.buffer.com/token'));
    }

    private function redirectUri(): string
    {
        $configured = trim((string) config('services.buffer.oauth.redirect_uri'));

        return $configured !== '' ? $configured : route('social.buffer.oauth.callback');
    }

    private function connectTimeout(): int
    {
        return max(1, min(10, (int) config('services.buffer.oauth.connect_timeout', 5)));
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('services.buffer.oauth.timeout', 15)));
    }

    private function stateMarker(string $state): string
    {
        return self::STATE_PREFIX.hash('sha256', $state);
    }

    private function hasActiveClaim(SocialBufferConnection $connection): bool
    {
        return Str::startsWith((string) $connection->oauth_state, self::CLAIM_PREFIX)
            && $connection->oauth_state_expires_at?->isFuture();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function validationMessage(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->first(fn (mixed $message): bool => is_string($message) && trim($message) !== '')
            ?: 'La connexion Buffer a échoué.';
    }

    private function invalidTokenResponse(): never
    {
        throw ValidationException::withMessages([
            'buffer' => 'Buffer a retourné une réponse OAuth invalide.',
        ]);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withOwnerLock(int $ownerId, Closure $callback): mixed
    {
        try {
            return Cache::lock('social-buffer-oauth:'.$ownerId, 30)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'buffer' => 'Une autre opération Buffer est en cours. Réessayez.',
            ]);
        }
    }
}
