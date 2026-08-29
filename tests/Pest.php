<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * @return array{delivery_provider:string,transport_generation:string,logical_destination_key:string}
 */
function pulseDirectTransportIdentity(
    \App\Models\User $owner,
    string $platform,
    string $externalAccountId,
): array {
    return [
        'delivery_provider' => \App\Models\SocialAccountConnection::DELIVERY_PROVIDER_DIRECT,
        'transport_generation' => \App\Models\SocialAccountConnection::TRANSPORT_GENERATION_DIRECT_V1,
        'logical_destination_key' => app(\App\Services\Social\SocialLogicalDestinationKeyService::class)
            ->deriveForLegacyConnection((string) $owner->id, $platform, $externalAccountId),
    ];
}
