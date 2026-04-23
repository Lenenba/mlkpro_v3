<?php

namespace App\Services\Auth;

use App\Models\SocialAccountConnection;
use App\Models\SocialDataDeletionRequest;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\AccountDeletionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FacebookDataDeletionService
{
    public function __construct(
        private readonly AccountDeletionService $accountDeletion,
    ) {}

    public function handleCallback(string $signedRequest): SocialDataDeletionRequest
    {
        $payload = $this->parseSignedRequest($signedRequest);
        $providerUserId = trim((string) ($payload['user_id'] ?? ''));

        if ($providerUserId === '') {
            throw new InvalidArgumentException('The Facebook data deletion request is missing a user identifier.');
        }

        $deleteLocalAccount = $this->shouldDeleteLocalAccount();
        $matchedUserId = UserSocialAccount::query()
            ->where('provider', UserSocialAccount::PROVIDER_FACEBOOK)
            ->where('provider_user_id', $providerUserId)
            ->value('user_id');

        $request = SocialDataDeletionRequest::query()->create([
            'provider' => SocialDataDeletionRequest::PROVIDER_FACEBOOK,
            'confirmation_code' => (string) Str::uuid(),
            'provider_user_id' => $providerUserId,
            'user_id' => $matchedUserId,
            'status' => SocialDataDeletionRequest::STATUS_PENDING,
            'delete_local_account' => $deleteLocalAccount,
            'requested_at' => now(),
            'summary' => [
                'matched_user' => $matchedUserId !== null,
                'matched_user_id' => $matchedUserId,
                'deleted_facebook_social_accounts' => 0,
                'deleted_facebook_social_connections' => 0,
                'deleted_local_account' => false,
                'provider_user_id' => $providerUserId,
            ],
        ]);

        try {
            $summary = $this->performDeletion($providerUserId, $matchedUserId, $deleteLocalAccount);

            $request->forceFill([
                'status' => SocialDataDeletionRequest::STATUS_COMPLETED,
                'summary' => $summary,
                'failure_reason' => null,
                'completed_at' => now(),
            ])->save();

            return $request->fresh();
        } catch (\Throwable $exception) {
            $request->forceFill([
                'status' => SocialDataDeletionRequest::STATUS_FAILED,
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function findByConfirmationCode(string $confirmationCode): SocialDataDeletionRequest
    {
        $request = SocialDataDeletionRequest::query()
            ->where('provider', SocialDataDeletionRequest::PROVIDER_FACEBOOK)
            ->where('confirmation_code', $confirmationCode)
            ->first();

        if (! $request) {
            throw (new ModelNotFoundException)->setModel(SocialDataDeletionRequest::class, [$confirmationCode]);
        }

        return $request;
    }

    private function performDeletion(string $providerUserId, ?int $matchedUserId, bool $deleteLocalAccount): array
    {
        if ($matchedUserId === null) {
            return [
                'matched_user' => false,
                'matched_user_id' => null,
                'deleted_facebook_social_accounts' => 0,
                'deleted_facebook_social_connections' => 0,
                'deleted_local_account' => false,
                'provider_user_id' => $providerUserId,
            ];
        }

        /** @var User|null $user */
        $user = User::query()->find($matchedUserId);

        if (! $user) {
            return [
                'matched_user' => false,
                'matched_user_id' => $matchedUserId,
                'deleted_facebook_social_accounts' => 0,
                'deleted_facebook_social_connections' => 0,
                'deleted_local_account' => false,
                'provider_user_id' => $providerUserId,
            ];
        }

        if ($deleteLocalAccount) {
            $socialAccountCount = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', UserSocialAccount::PROVIDER_FACEBOOK)
                ->count();

            $socialConnectionCount = SocialAccountConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', SocialAccountConnection::PLATFORM_FACEBOOK)
                ->count();

            $deletionMode = $user->isAccountOwner() ? 'account' : 'user';

            if ($user->isAccountOwner()) {
                $this->accountDeletion->deleteAccount($user);
            } else {
                $this->accountDeletion->deleteUser($user);
            }

            return [
                'matched_user' => true,
                'matched_user_id' => $user->id,
                'deleted_facebook_social_accounts' => $socialAccountCount,
                'deleted_facebook_social_connections' => $socialConnectionCount,
                'deleted_local_account' => true,
                'deleted_local_account_mode' => $deletionMode,
                'provider_user_id' => $providerUserId,
            ];
        }

        return DB::transaction(function () use ($providerUserId, $user): array {
            $socialAccounts = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', UserSocialAccount::PROVIDER_FACEBOOK);

            $socialAccountCount = (clone $socialAccounts)->count();
            $socialAccounts->delete();

            $socialConnections = SocialAccountConnection::query()
                ->where('user_id', $user->id)
                ->where('platform', SocialAccountConnection::PLATFORM_FACEBOOK);

            $socialConnectionCount = (clone $socialConnections)->count();
            $socialConnections->delete();

            return [
                'matched_user' => true,
                'matched_user_id' => $user->id,
                'deleted_facebook_social_accounts' => $socialAccountCount,
                'deleted_facebook_social_connections' => $socialConnectionCount,
                'deleted_local_account' => false,
                'provider_user_id' => $providerUserId,
            ];
        });
    }

    private function parseSignedRequest(string $signedRequest): array
    {
        $signedRequest = trim($signedRequest);

        if ($signedRequest === '') {
            throw new InvalidArgumentException('The Facebook signed_request payload is required.');
        }

        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('The Facebook signed_request payload is malformed.');
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = $this->base64UrlDecode($encodedSignature);
        $payloadJson = $this->base64UrlDecode($encodedPayload);
        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The Facebook signed_request payload could not be decoded.');
        }

        $algorithm = strtolower((string) ($payload['algorithm'] ?? ''));

        if ($algorithm !== 'hmac-sha256') {
            throw new InvalidArgumentException('The Facebook signed_request algorithm is not supported.');
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->facebookAppSecret(), true);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException('The Facebook signed_request signature is invalid.');
        }

        return $payload;
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if ($decoded === false) {
            throw new InvalidArgumentException('The Facebook signed_request payload is malformed.');
        }

        return $decoded;
    }

    private function shouldDeleteLocalAccount(): bool
    {
        return (bool) config('social_auth.providers.facebook.data_deletion.delete_local_account', false);
    }

    private function facebookAppSecret(): string
    {
        $secret = (string) config('social_auth.providers.facebook.client_secret', '');

        if ($secret !== '') {
            return $secret;
        }

        $secret = (string) config('services.social.facebook.oauth.client_secret', '');

        if ($secret !== '') {
            return $secret;
        }

        throw new RuntimeException('Facebook data deletion is not configured because the app secret is missing.');
    }
}
