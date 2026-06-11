<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * Thrown during social authentication when a verified provider profile has no
 * matching account and the current context (login) requires explicit user
 * confirmation before creating one. Carries the verified profile and tokens so
 * the account can be created later, after confirmation, without a second OAuth
 * round-trip.
 */
class SocialAccountConfirmationRequiredException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $tokens
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $profile,
        public readonly array $tokens,
    ) {
        parent::__construct('Social account creation requires user confirmation.');
    }
}
