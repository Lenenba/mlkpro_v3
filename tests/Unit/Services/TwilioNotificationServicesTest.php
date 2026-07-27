<?php

use App\Services\SmsNotificationService;
use App\Services\WhatsappNotificationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.twilio.sid');
    config()->set('services.twilio.token');
    config()->set('services.twilio.from');
    config()->set('services.twilio.whatsapp_from');
});

test('sms refuses incomplete configuration', function () {
    Http::fake();

    $result = app(SmsNotificationService::class)->sendWithResult('5145550102', 'Test message');

    expect($result)->toBe([
        'ok' => false,
        'reason' => 'missing_config',
    ]);

    Http::assertNothingSent();
});

test('sms normalizes and sends an authenticated form request', function () {
    $accountSid = 'AC_TEST_ACCOUNT_SID';
    $authToken = 'rotated-test-token';
    $from = '+15145550101';

    config()->set('services.twilio.sid', $accountSid);
    config()->set('services.twilio.token', $authToken);
    config()->set('services.twilio.from', $from);

    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $result = app(SmsNotificationService::class)->sendWithResult('(514) 555-0102', 'Rotation canary');

    expect($result)->toBe([
        'ok' => true,
        'status' => 201,
        'sid' => 'SM_TEST_MESSAGE_SID',
    ]);

    Http::assertSent(function (Request $request) use ($accountSid, $authToken, $from): bool {
        $headers = $request->headers();

        return $request->method() === 'POST'
            && $request->url() === "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json"
            && ($headers['Authorization'][0] ?? null) === 'Basic '.base64_encode("{$accountSid}:{$authToken}")
            && $request->data() === [
                'To' => '+15145550102',
                'From' => $from,
                'Body' => 'Rotation canary',
            ];
    });
    Http::assertSentCount(1);
});

test('sms maps a provider authentication rejection', function () {
    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'rejected-test-token');
    config()->set('services.twilio.from', '+15145550101');

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 20003,
            'message' => 'Authentication Error - No credentials provided',
            'more_info' => 'https://www.twilio.com/docs/errors/20003',
        ], 401),
    ]);

    $result = app(SmsNotificationService::class)->sendWithResult('+15145550102', 'Rotation canary');

    expect($result)->toMatchArray([
        'ok' => false,
        'reason' => 'twilio_error',
        'status' => 401,
        'code' => 20003,
    ]);
});

test('sms maps a connection failure', function () {
    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'test-token');
    config()->set('services.twilio.from', '+15145550101');

    Http::fake(fn () => throw new ConnectionException('Simulated connection failure'));

    $result = app(SmsNotificationService::class)->sendWithResult('+15145550102', 'Rotation canary');

    expect($result)->toMatchArray([
        'ok' => false,
        'reason' => 'http_exception',
    ]);
});

test('whatsapp refuses incomplete configuration', function () {
    Http::fake();

    $sent = app(WhatsappNotificationService::class)->send('+15145550102', 'Test message');

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});

test('whatsapp normalizes prefixes and authenticates', function () {
    $accountSid = 'AC_TEST_ACCOUNT_SID';
    $authToken = 'rotated-test-token';

    config()->set('services.twilio.sid', $accountSid);
    config()->set('services.twilio.token', $authToken);
    config()->set('services.twilio.whatsapp_from', '+15145550101');

    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $sent = app(WhatsappNotificationService::class)->send('+15145550102', 'Rotation canary');

    expect($sent)->toBeTrue();

    Http::assertSent(function (Request $request) use ($accountSid, $authToken): bool {
        $headers = $request->headers();

        return $request->method() === 'POST'
            && $request->url() === "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json"
            && ($headers['Authorization'][0] ?? null) === 'Basic '.base64_encode("{$accountSid}:{$authToken}")
            && $request->data() === [
                'To' => 'whatsapp:+15145550102',
                'From' => 'whatsapp:+15145550101',
                'Body' => 'Rotation canary',
            ];
    });
    Http::assertSentCount(1);
});

test('whatsapp exposes provider rejection details', function () {
    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'rejected-test-token');
    config()->set('services.twilio.whatsapp_from', '+15145550101');

    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 20003,
            'message' => 'Authentication rejected',
        ], 401),
    ]);

    $result = app(WhatsappNotificationService::class)->sendWithResult('+15145550102', 'Rotation canary');

    expect($result)->toMatchArray([
        'ok' => false,
        'reason' => 'twilio_error',
        'status' => 401,
        'code' => 20003,
    ]);
});

test('whatsapp maps a connection failure', function () {
    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'test-token');
    config()->set('services.twilio.whatsapp_from', '+15145550101');

    Http::fake(fn () => throw new ConnectionException('Simulated connection failure'));

    $result = app(WhatsappNotificationService::class)->sendWithResult('+15145550102', 'Rotation canary');

    expect($result)->toMatchArray([
        'ok' => false,
        'reason' => 'http_exception',
    ]);
});
