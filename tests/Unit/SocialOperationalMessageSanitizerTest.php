<?php

use App\Services\Social\SocialOperationalMessageSanitizer;
use Illuminate\Support\Str;

it('redacts credentials from operational messages without exposing them in assertions', function (string $message, array $sensitiveFragments, string $safeContext) {
    $sanitized = app(SocialOperationalMessageSanitizer::class)->sanitize($message);

    $containsSensitiveValue = collect($sensitiveFragments)
        ->contains(fn (string $sensitiveFragment): bool => Str::contains((string) $sanitized, $sensitiveFragment));

    expect([
        'returns_message' => $sanitized !== null,
        'contains_redaction_marker' => Str::contains((string) $sanitized, '[redacted]'),
        'preserves_safe_context' => Str::contains((string) $sanitized, $safeContext),
        'contains_sensitive_value' => $containsSensitiveValue,
        'respects_maximum_length' => Str::length((string) $sanitized) <= 2000,
    ])->toBe([
        'returns_message' => true,
        'contains_redaction_marker' => true,
        'preserves_safe_context' => true,
        'contains_sensitive_value' => false,
        'respects_maximum_length' => true,
    ]);
})->with([
    'authorization header' => [
        "provider unavailable\nAuthorization: Bearer header-do-not-log",
        ['header-do-not-log'],
        'provider unavailable',
    ],
    'proxy authorization header' => [
        "provider unavailable\nProxy-Authorization: Basic cHJveHktZG8tbm90LWxvZw==",
        ['cHJveHktZG8tbm90LWxvZw=='],
        'provider unavailable',
    ],
    'standalone basic authentication' => [
        'provider rejected Basic dXNlcjpkby1ub3QtbG9n',
        ['dXNlcjpkby1ub3QtbG9n'],
        'provider rejected',
    ],
    'cookie header' => [
        "provider unavailable\nCookie: session=cookie-do-not-log; csrf=csrf-do-not-log",
        ['cookie-do-not-log', 'csrf-do-not-log'],
        'provider unavailable',
    ],
    'set cookie header' => [
        "provider unavailable\nSet-Cookie: session=set-cookie-do-not-log; Secure; HttpOnly",
        ['set-cookie-do-not-log'],
        'provider unavailable',
    ],
    'api key header' => [
        "provider unavailable\nX-Api-Key: api-key-do-not-log",
        ['api-key-do-not-log'],
        'provider unavailable',
    ],
    'auth token header' => [
        "provider unavailable\nX-Auth-Token: auth-token-do-not-log",
        ['auth-token-do-not-log'],
        'provider unavailable',
    ],
    'signature headers' => [
        "provider unavailable\nX-Signature: signature-do-not-log\nX-Hub-Signature-256: sha256=hub-signature-do-not-log",
        ['signature-do-not-log', 'hub-signature-do-not-log'],
        'provider unavailable',
    ],
    'json tokens and secrets' => [
        '{"error":"denied","id_token":"id-token-do-not-log","token":"token-do-not-log","webhook_secret":"webhook-secret-do-not-log"}',
        ['id-token-do-not-log', 'token-do-not-log', 'webhook-secret-do-not-log'],
        'denied',
    ],
    'signed url query parameters' => [
        'provider denied https://storage.test/object?signature=signature-do-not-log&sig=sig-do-not-log&X-Amz-Credential=credential-do-not-log&X-Amz-Signature=amz-signature-do-not-log&state=opaque',
        ['signature-do-not-log', 'sig-do-not-log', 'credential-do-not-log', 'amz-signature-do-not-log'],
        'provider denied',
    ],
    'form tokens and secrets' => [
        'provider denied secret=form-secret-do-not-log&id_token=form-token-do-not-log&status=denied',
        ['form-secret-do-not-log', 'form-token-do-not-log'],
        'provider denied',
    ],
    'private key block' => [
        "provider signing failed\n-----BEGIN PRIVATE KEY-----\nprivate-key-do-not-log\n-----END PRIVATE KEY-----\nretry disabled",
        ['private-key-do-not-log'],
        'provider signing failed',
    ],
]);

it('normalizes fallback and length without manufacturing a message', function () {
    $sanitizer = app(SocialOperationalMessageSanitizer::class);
    $sanitizedFallback = $sanitizer->sanitize(null, "Safe fallback\nSet-Cookie: session=fallback-do-not-log");

    $fallbackContainsSensitiveValue = Str::contains((string) $sanitizedFallback, 'fallback-do-not-log');

    expect($sanitizer->sanitize(null))->toBeNull()
        ->and($sanitizer->sanitize('', "<b>Safe fallback</b>\n"))->toBe('Safe fallback');

    expect([
        'fallback_contains_redaction_marker' => Str::contains((string) $sanitizedFallback, '[redacted]'),
        'fallback_contains_sensitive_value' => $fallbackContainsSensitiveValue,
        'long_message_length' => Str::length((string) $sanitizer->sanitize(str_repeat('x', 3000))),
    ])->toBe([
        'fallback_contains_redaction_marker' => true,
        'fallback_contains_sensitive_value' => false,
        'long_message_length' => 2000,
    ]);
});
