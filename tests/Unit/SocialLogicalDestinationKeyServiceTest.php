<?php

use App\Services\Social\SocialLogicalDestinationKeyService;

it('derives the frozen logical destination key vector', function () {
    $key = (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: '42',
        platform: 'facebook',
        externalAccountId: 'fb-page-001',
    );

    expect($key)->toBe(
        'ldk:v1:124ed8299d785e5082ac3d283dc5337de6140a5e584f718f2371cf22836af643'
    );
});

it('uses canonical JSON for unicode and slash identities without casting tenant IDs', function () {
    $key = (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: '18446744073709551615',
        platform: ' INSTAGRAM ',
        externalAccountId: ' Page/Été ',
    );

    expect($key)->toBe(
        'ldk:v1:6d4374c0858895233d5c71d8d119efdc597369697cf888639b32441242835c34'
    );
});

it('accepts each explicitly supported platform and returns a canonical key', function (string $platform) {
    $key = (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: '42',
        platform: $platform,
        externalAccountId: 'native-destination-001',
    );

    expect($key)
        ->toHaveLength(71)
        ->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/');
})->with([
    'facebook' => 'facebook',
    'instagram' => 'instagram',
    'linkedin' => 'linkedin',
    'x' => 'x',
]);

it('normalizes only boundary whitespace and platform casing', function () {
    $service = new SocialLogicalDestinationKeyService;
    $normalized = $service->deriveForLegacyConnection('42', 'facebook', 'Page-001');
    $padded = $service->deriveForLegacyConnection('42', ' FACEBOOK ', " \tPage-001\n");
    $lowercaseExternalId = $service->deriveForLegacyConnection('42', 'facebook', 'page-001');

    expect($padded)->toBe($normalized)
        ->and($lowercaseExternalId)->not->toBe($normalized);
});

it('binds every identity dimension and preserves internal bytes', function () {
    $service = new SocialLogicalDestinationKeyService;
    $keys = [
        $service->deriveForLegacyConnection('42', 'facebook', 'Café/Page 001'),
        $service->deriveForLegacyConnection('43', 'facebook', 'Café/Page 001'),
        $service->deriveForLegacyConnection('42', 'instagram', 'Café/Page 001'),
        $service->deriveForLegacyConnection('42', 'facebook', 'Café/Page 002'),
        $service->deriveForLegacyConnection('42', 'facebook', 'café/Page 001'),
        $service->deriveForLegacyConnection('42', 'facebook', 'Café/Page001'),
        $service->deriveForLegacyConnection('42', 'facebook', "Cafe\u{0301}/Page 001"),
    ];

    expect(array_unique($keys))->toHaveCount(count($keys));
});

it('rejects non-canonical tenant identities', function (string $tenantId) {
    expect(fn () => (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: $tenantId,
        platform: 'facebook',
        externalAccountId: 'fb-page-001',
    ))->toThrow(
        InvalidArgumentException::class,
        'The logical destination tenant ID must be a canonical positive decimal string.',
    );
})->with([
    'empty' => '',
    'zero' => '0',
    'leading zero' => '01',
    'positive sign' => '+42',
    'negative' => '-42',
    'decimal' => '42.0',
    'boundary whitespace' => ' 42 ',
    'unicode digits' => '٤٢',
]);

it('rejects unsupported platforms after normalization', function (string $platform) {
    expect(fn () => (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: '42',
        platform: $platform,
        externalAccountId: 'fb-page-001',
    ))->toThrow(
        InvalidArgumentException::class,
        'The logical destination platform is not supported.',
    );
})->with([
    'empty' => '',
    'blank' => '   ',
    'unknown' => 'tiktok',
    'control character' => "face\nbook",
]);

it('measures the external identity limit in unicode characters', function () {
    $service = new SocialLogicalDestinationKeyService;

    $maximumLengthKey = $service->deriveForLegacyConnection(
        tenantId: '42',
        platform: 'facebook',
        externalAccountId: str_repeat('é', 191),
    );
    $paddedMaximumLengthKey = $service->deriveForLegacyConnection(
        tenantId: '42',
        platform: 'facebook',
        externalAccountId: ' '.str_repeat('é', 191).' ',
    );

    expect($maximumLengthKey)->toMatch('/\Aldk:v1:[0-9a-f]{64}\z/')
        ->and($paddedMaximumLengthKey)->toBe($maximumLengthKey)
        ->and(fn () => $service->deriveForLegacyConnection(
            tenantId: '42',
            platform: 'facebook',
            externalAccountId: str_repeat('é', 192),
        ))->toThrow(
            InvalidArgumentException::class,
            'The logical destination external account ID must contain at most 191 characters.',
        );
});

it('rejects unusable native destination identities', function (string $externalAccountId, string $message) {
    expect(fn () => (new SocialLogicalDestinationKeyService)->deriveForLegacyConnection(
        tenantId: '42',
        platform: 'facebook',
        externalAccountId: $externalAccountId,
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank after trim' => [
        " \t\n ",
        'The logical destination external account ID must not be blank.',
    ],
    'invalid utf-8' => [
        "\xC3\x28",
        'The logical destination external account ID must be valid UTF-8.',
    ],
    'line feed' => [
        "page\n001",
        'The logical destination external account ID cannot contain control characters.',
    ],
    'null byte' => [
        "page\0".'001',
        'The logical destination external account ID cannot contain control characters.',
    ],
    'delete' => [
        "page\x7F001",
        'The logical destination external account ID cannot contain control characters.',
    ],
    'c1 control' => [
        "page\u{0085}001",
        'The logical destination external account ID cannot contain control characters.',
    ],
]);
