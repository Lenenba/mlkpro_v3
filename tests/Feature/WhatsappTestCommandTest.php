<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'test-auth-token');
    config()->set('services.twilio.whatsapp_from', '+15145550101');
});

test('whatsapp test command normalizes a ten digit north american recipient', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $this->artisan('whatsapp:test', [
        'to' => '5145550102',
        '--message' => 'Rotation canary',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'To' => 'whatsapp:+15145550102',
        'From' => 'whatsapp:+15145550101',
        'Body' => 'Rotation canary',
    ]);
    Http::assertSentCount(1);
});

test('whatsapp test command preserves an international e164 recipient', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM_TEST_MESSAGE_SID'], 201),
    ]);

    $this->artisan('whatsapp:test', [
        'to' => '+442079460123',
        '--message' => 'International canary',
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->data()['To'] === 'whatsapp:+442079460123');
    Http::assertSentCount(1);
});

test('whatsapp test command rejects an invalid recipient without sending', function () {
    Http::fake();

    $this->artisan('whatsapp:test', [
        'to' => '51455',
        '--message' => 'Invalid canary',
    ])
        ->expectsOutputToContain('Numero invalide')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('whatsapp test command refuses incomplete configuration without sending', function () {
    config()->set('services.twilio.whatsapp_from');
    Http::fake();

    $this->artisan('whatsapp:test', [
        'to' => '+15145550102',
        '--message' => 'Missing configuration canary',
    ])
        ->expectsOutputToContain('Configuration Twilio WhatsApp incomplete')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('whatsapp test command reports when a sandbox recipient has not joined', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 63015,
            'message' => 'Channel Sandbox can only send messages to phone numbers that have joined the Sandbox',
            'more_info' => 'https://www.twilio.com/docs/errors/63015',
        ], 400),
    ]);

    $this->artisan('whatsapp:test', [
        'to' => '5145550102',
        '--message' => 'Rejected canary',
    ])
        ->expectsOutputToContain('reason=twilio_error status=400 code=63015')
        ->expectsOutputToContain('rejoindre le Sandbox WhatsApp')
        ->assertExitCode(1);

    Http::assertSentCount(1);
});
