<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'test-auth-token');
    config()->set('services.twilio.from', '+15145550101');
});

test('sms test command normalizes a ten digit north american recipient', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $this->artisan('sms:test', [
        'to' => '5145550102',
        '--message' => 'Rotation canary',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'To' => '+15145550102',
        'From' => '+15145550101',
        'Body' => 'Rotation canary',
    ]);
    Http::assertSentCount(1);
});

test('sms test command preserves an e164 recipient', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $this->artisan('sms:test', [
        'to' => '+442079460123',
        '--message' => 'International canary',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->data()['To'] === '+442079460123');
    Http::assertSentCount(1);
});

test('sms test command rejects an invalid recipient without sending', function () {
    Http::fake();

    $this->artisan('sms:test', [
        'to' => '51455',
        '--message' => 'Invalid canary',
    ])
        ->expectsOutputToContain('Numero invalide')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('sms test command refuses incomplete configuration without sending', function () {
    config()->set('services.twilio.token');
    Http::fake();

    $this->artisan('sms:test', [
        'to' => '+15145550102',
        '--message' => 'Missing configuration canary',
    ])
        ->expectsOutputToContain('Configuration Twilio incomplete')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('sms test command reports a provider rejection', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 20003,
            'message' => 'Authentication Error - No credentials provided',
            'more_info' => 'https://www.twilio.com/docs/errors/20003',
        ], 401),
    ]);

    $this->artisan('sms:test', [
        'to' => '5145550102',
        '--message' => 'Rejected canary',
    ])
        ->expectsOutputToContain('reason=twilio_error status=401 code=20003')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});
