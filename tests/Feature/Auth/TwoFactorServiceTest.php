<?php

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use App\Services\TwoFactorService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();

    config()->set('services.twilio.sid', 'AC_TEST_ACCOUNT_SID');
    config()->set('services.twilio.token', 'test-auth-token');
    config()->set('services.twilio.from', '+15145550101');
});

function twoFactorServiceSmsUser(): User
{
    return User::factory()->create([
        'locale' => 'fr',
        'company_name' => 'Maison Boréale',
        'company_logo' => 'https://assets.example.test/maison-boreale.png',
        'phone_number' => '+15145550123',
        'two_factor_exempt' => false,
        'two_factor_enabled' => false,
        'two_factor_method' => TwoFactorService::METHOD_SMS,
        'company_notification_settings' => [
            'security' => [
                'two_factor_sms' => true,
            ],
        ],
    ]);
}

test('two factor service sends and persists a matching code by sms', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'sid' => 'SM_TEST_MESSAGE_SID',
        ], 201),
    ]);

    $user = twoFactorServiceSmsUser();

    $result = app(TwoFactorService::class)->sendCode(
        $user,
        force: true,
        preferredMethod: TwoFactorService::METHOD_SMS,
    );

    $user->refresh();

    expect($result)->toMatchArray([
        'sent' => true,
        'retry_after' => 0,
        'method' => TwoFactorService::METHOD_SMS,
    ])->and($user->two_factor_enabled)->toBeTrue()
        ->and($user->two_factor_code)->not->toBeNull()
        ->and($user->two_factor_expires_at)->not->toBeNull()
        ->and($user->two_factor_last_sent_at)->not->toBeNull();

    expect(
        $user->two_factor_expires_at->equalTo(
            $user->two_factor_last_sent_at
                ->copy()
                ->addMinutes(TwoFactorService::EXPIRY_MINUTES)
        )
    )->toBeTrue();

    Http::assertSent(function (Request $request) use ($user): bool {
        $data = $request->data();

        if (preg_match('/\b(\d{6})\b/', (string) ($data['Body'] ?? ''), $matches) !== 1) {
            return false;
        }

        return ($data['To'] ?? null) === '+15145550123'
            && ($data['From'] ?? null) === '+15145550101'
            && Hash::check($matches[1], $user->two_factor_code);
    });

    Http::assertSentCount(1);
    Notification::assertNothingSent();
});

test('two factor service falls back to email when twilio rejects sms', function () {
    Http::fake([
        'https://api.twilio.com/*' => Http::response([
            'code' => 20003,
            'message' => 'Authentication rejected',
        ], 401),
    ]);

    $user = twoFactorServiceSmsUser();

    $result = app(TwoFactorService::class)->sendCode(
        $user,
        force: true,
        preferredMethod: TwoFactorService::METHOD_SMS,
    );

    $user->refresh();

    expect($result)->toMatchArray([
        'sent' => true,
        'retry_after' => 0,
        'method' => TwoFactorService::METHOD_EMAIL,
    ])->and($user->two_factor_enabled)->toBeTrue()
        ->and($user->two_factor_code)->not->toBeNull();

    Http::assertSentCount(1);

    Notification::assertSentTo(
        $user,
        TwoFactorCodeNotification::class,
        function (TwoFactorCodeNotification $notification, array $channels) use ($user): bool {
            $mail = $notification->toMail($user);
            $code = $mail->viewData['code'] ?? null;

            return $channels === ['mail']
                && is_string($code)
                && preg_match('/^\d{6}$/', $code) === 1
                && Hash::check($code, $user->two_factor_code)
                && ($mail->viewData['companyName'] ?? null) === 'Maison Boréale'
                && ($mail->viewData['companyLogo'] ?? null) === 'https://assets.example.test/maison-boreale.png';
        }
    );

    Notification::assertSentToTimes($user, TwoFactorCodeNotification::class, 1);
});
