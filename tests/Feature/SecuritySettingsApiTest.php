<?php

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('security settings api returns a normalized owner payload with team activity and pending app setup', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'two_factor_enabled' => true,
        'two_factor_method' => 'email',
    ]);

    $employee = User::factory()->create([
        'role_id' => securityRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
        'is_active' => true,
    ]);

    ActivityLog::record($owner, $owner, 'auth.login', [
        'channel' => 'api',
        'two_factor' => true,
        'device' => 'iPhone',
    ]);

    ActivityLog::record($employee, $employee, 'auth.logout', [
        'channel' => 'web',
        'device' => 'Safari',
    ]);

    Sanctum::actingAs($owner);

    $startResponse = $this->postJson('/api/v1/settings/security/2fa/app/start')
        ->assertCreated()
        ->assertJsonPath('two_factor.can_configure', true)
        ->assertJsonPath('two_factor.app_setup.otpauth_url', fn ($value) => is_string($value) && str_starts_with($value, 'otpauth://totp/'));

    $setupToken = $startResponse->json('two_factor.app_setup.setup_token');
    $secret = $startResponse->json('two_factor.app_setup.secret');

    $response = $this->getJson('/api/v1/settings/security')
        ->assertOk()
        ->assertJsonPath('two_factor.required', true)
        ->assertJsonPath('two_factor.enabled', true)
        ->assertJsonPath('two_factor.method', 'email')
        ->assertJsonPath('two_factor.can_configure', true)
        ->assertJsonPath('two_factor.app_setup.setup_token', $setupToken)
        ->assertJsonPath('two_factor.app_setup.secret', $secret)
        ->assertJsonPath('can_view_team', true)
        ->assertJsonCount(2, 'activity');

    $subjectIds = collect($response->json('activity'))
        ->pluck('subject.id')
        ->filter()
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($subjectIds)->toBe([$owner->id, $employee->id]);
});

test('security settings api confirms authenticator app setup without browser session state', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'two_factor_enabled' => false,
        'two_factor_method' => 'email',
        'two_factor_secret' => null,
    ]);

    Sanctum::actingAs($owner);

    $startResponse = $this->postJson('/api/v1/settings/security/2fa/app/start')->assertCreated();
    $setupToken = $startResponse->json('two_factor.app_setup.setup_token');
    $secret = $startResponse->json('two_factor.app_setup.secret');

    $this->postJson('/api/v1/settings/security/2fa/app/confirm', [
        'setup_token' => $setupToken,
        'code' => securityTotpCode($secret),
    ])
        ->assertOk()
        ->assertJsonPath('two_factor.enabled', true)
        ->assertJsonPath('two_factor.method', 'app')
        ->assertJsonPath('two_factor.has_app', true)
        ->assertJsonPath('two_factor.app_setup', null);

    $owner->refresh();

    expect($owner->two_factor_method)->toBe('app')
        ->and($owner->two_factor_enabled)->toBeTrue()
        ->and($owner->two_factor_secret)->toBe($secret);

    $this->getJson('/api/v1/settings/security')
        ->assertOk()
        ->assertJsonPath('two_factor.app_setup', null);
});

test('security settings api can cancel a pending authenticator app setup', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/security/2fa/app/start')->assertCreated();

    $this->postJson('/api/v1/settings/security/2fa/app/cancel')
        ->assertOk()
        ->assertJsonPath('two_factor.app_setup', null);

    $this->getJson('/api/v1/settings/security')
        ->assertOk()
        ->assertJsonPath('two_factor.app_setup', null);
});

test('security settings api can switch owners back to email 2fa', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'two_factor_enabled' => true,
        'two_factor_method' => 'app',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/security/2fa/email')
        ->assertOk()
        ->assertJsonPath('two_factor.method', 'email')
        ->assertJsonPath('two_factor.has_app', false);

    $owner->refresh();

    expect($owner->two_factor_method)->toBe('email')
        ->and($owner->two_factor_secret)->toBeNull();
});

test('security settings api can switch owners to sms when the company and provider allow it', function () {
    config()->set('services.twilio.sid', 'twilio_sid_test');
    config()->set('services.twilio.token', 'twilio_token_test');
    config()->set('services.twilio.from', '+15550001111');

    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'two_factor_enabled' => true,
        'two_factor_method' => 'email',
        'company_notification_settings' => [
            'security' => [
                'two_factor_sms' => true,
            ],
        ],
        'phone_number' => '+15555550123',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/security/2fa/sms')
        ->assertOk()
        ->assertJsonPath('two_factor.method', 'sms')
        ->assertJsonPath('two_factor.sms.available', true)
        ->assertJsonPath('two_factor.phone_hint', '+*******0123');

    $owner->refresh();

    expect($owner->two_factor_method)->toBe('sms')
        ->and($owner->two_factor_secret)->toBeNull();
});

test('security settings api returns a stable denial when sms cannot be enabled', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
        'company_notification_settings' => [
            'security' => [
                'two_factor_sms' => false,
            ],
        ],
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/settings/security/2fa/sms')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Activez d abord le 2FA SMS dans Parametres > Entreprise.')
        ->assertJsonPath('errors.two_factor_method.0', 'Activez d abord le 2FA SMS dans Parametres > Entreprise.');
});

test('security settings api limits employees to self activity and forbids configuration mutations', function () {
    $owner = User::factory()->create([
        'role_id' => securityRoleId('owner', 'Account owner role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    $employee = User::factory()->create([
        'role_id' => securityRoleId('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'member',
        'permissions' => ['tasks.view'],
        'is_active' => true,
    ]);

    ActivityLog::record($owner, $owner, 'auth.login', [
        'channel' => 'api',
    ]);

    ActivityLog::record($employee, $employee, 'auth.logout', [
        'channel' => 'api',
    ]);

    Sanctum::actingAs($employee);

    $response = $this->getJson('/api/v1/settings/security')
        ->assertOk()
        ->assertJsonPath('can_view_team', false)
        ->assertJsonPath('two_factor.can_configure', false)
        ->assertJsonCount(1, 'activity');

    expect(collect($response->json('activity'))->pluck('subject.id')->unique()->all())
        ->toBe([$employee->id]);

    $this->postJson('/api/v1/settings/security/2fa/app/start')->assertForbidden();
});

function securityRoleId(string $name, string $description): int
{
    return Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function securityTotpCode(string $secret): string
{
    $timestamp = (int) floor(time() / 30);
    $key = securityBase32Decode($secret);
    $time = pack('N*', 0).pack('N*', $timestamp);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;

    $binary = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );

    return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function securityBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper($secret);
    $buffer = 0;
    $bits = 0;
    $output = '';

    $length = strlen($secret);
    for ($index = 0; $index < $length; $index++) {
        $character = $secret[$index];
        if ($character === '=') {
            continue;
        }

        $value = strpos($alphabet, $character);
        if ($value === false) {
            return '';
        }

        $buffer = ($buffer << 5) | $value;
        $bits += 5;

        if ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 0xFF);
        }
    }

    return $output;
}
