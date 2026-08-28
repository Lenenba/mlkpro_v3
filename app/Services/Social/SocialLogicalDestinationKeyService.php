<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SocialLogicalDestinationKeyService
{
    private const EXTERNAL_ACCOUNT_ID_MAX_LENGTH = 191;

    private const KEY_PREFIX = 'ldk:v1:';

    public function deriveForLegacyConnection(
        string $tenantId,
        string $platform,
        string $externalAccountId,
    ): string {
        if (preg_match('/\A[1-9][0-9]*\z/', $tenantId) !== 1) {
            throw new InvalidArgumentException(
                'The logical destination tenant ID must be a canonical positive decimal string.'
            );
        }

        $normalizedPlatform = Str::lower(trim($platform));
        if (! in_array($normalizedPlatform, SocialAccountConnection::allowedPlatforms(), true)) {
            throw new InvalidArgumentException('The logical destination platform is not supported.');
        }

        $normalizedExternalAccountId = trim($externalAccountId);
        if ($normalizedExternalAccountId === '') {
            throw new InvalidArgumentException(
                'The logical destination external account ID must not be blank.'
            );
        }

        if (! mb_check_encoding($normalizedExternalAccountId, 'UTF-8')) {
            throw new InvalidArgumentException(
                'The logical destination external account ID must be valid UTF-8.'
            );
        }

        if (Str::length($normalizedExternalAccountId) > self::EXTERNAL_ACCOUNT_ID_MAX_LENGTH) {
            throw new InvalidArgumentException(
                'The logical destination external account ID must contain at most 191 characters.'
            );
        }

        if (preg_match('/\p{Cc}/u', $normalizedExternalAccountId) !== 0) {
            throw new InvalidArgumentException(
                'The logical destination external account ID cannot contain control characters.'
            );
        }

        $preimage = json_encode([
            'malikia-pulse-logical-destination',
            'v1',
            $tenantId,
            $normalizedPlatform,
            $normalizedExternalAccountId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return self::KEY_PREFIX.hash('sha256', $preimage);
    }
}
