<?php

use App\Models\PlatformNotification;
use App\Models\PlatformNotificationSetting;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PlatformAdminDigestNotification;
use Illuminate\Support\Facades\Notification;

function makeNotificationSuperadmin(): User
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    );

    return User::query()->create([
        'name' => 'Root User',
        'email' => 'notifications-superadmin@example.com',
        'password' => 'password',
        'role_id' => $role->id,
        'onboarding_completed_at' => now(),
    ]);
}

it('persists unchecked channels and categories', function () {
    $user = makeNotificationSuperadmin();

    PlatformNotificationSetting::query()->create([
        'user_id' => $user->id,
        'channels' => ['email', 'slack'],
        'categories' => ['new_account', 'payment_succeeded', 'churn_risk'],
        'rules' => ['error_spike' => 10, 'payment_failed' => 3, 'churn_risk' => 5],
        'digest_frequency' => 'daily',
    ]);

    $this->actingAs($user)
        ->put(route('superadmin.notifications.update'), [
            // slack unchecked
            'channels' => ['email'],
            // payment_succeeded and churn_risk unchecked
            'categories' => ['new_account'],
            'digest_frequency' => 'daily',
            'rules' => ['error_spike' => 10, 'payment_failed' => 3, 'churn_risk' => 5],
        ])
        ->assertRedirect();

    $settings = PlatformNotificationSetting::query()->where('user_id', $user->id)->first();

    expect($settings->channels)->toBe(['email'])
        ->and($settings->categories)->toBe(['new_account']);
});

it('persists unchecked boxes with the exact browser-like payload', function () {
    $user = makeNotificationSuperadmin();

    PlatformNotificationSetting::query()->create([
        'user_id' => $user->id,
        'channels' => ['email', 'slack'],
        'categories' => ['new_account', 'payment_succeeded', 'churn_risk'],
        'rules' => ['error_spike' => 10, 'payment_failed' => 3, 'churn_risk' => 5],
        'digest_frequency' => 'daily',
    ]);

    // Mimic what the Vue form sends: empty quiet hours strings, rules as strings.
    $this->actingAs($user)
        ->put(route('superadmin.notifications.update'), [
            'channels' => ['email'],
            'categories' => ['new_account'],
            'digest_frequency' => 'daily',
            'quiet_hours_start' => '',
            'quiet_hours_end' => '',
            'rules' => ['error_spike' => '10', 'payment_failed' => '3', 'churn_risk' => '5'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $settings = PlatformNotificationSetting::query()->where('user_id', $user->id)->first();

    expect($settings->channels)->toBe(['email'])
        ->and($settings->categories)->toBe(['new_account']);
});

it('persists when all boxes in a group are unchecked', function () {
    $user = makeNotificationSuperadmin();

    PlatformNotificationSetting::query()->create([
        'user_id' => $user->id,
        'channels' => ['email', 'slack'],
        'categories' => ['new_account'],
        'rules' => [],
        'digest_frequency' => 'daily',
    ]);

    // Inertia omits empty arrays; emulate a payload with no channels key at all.
    $this->actingAs($user)
        ->put(route('superadmin.notifications.update'), [
            'categories' => ['new_account'],
            'digest_frequency' => 'daily',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $settings = PlatformNotificationSetting::query()->where('user_id', $user->id)->first();

    expect($settings->channels)->toBe([]);
});

it('sends an on-demand recap email without marking notifications as sent', function () {
    Notification::fake();

    $user = makeNotificationSuperadmin();

    $notification = PlatformNotification::query()->create([
        'user_id' => $user->id,
        'category' => 'new_account',
        'title' => 'New account created',
        'digest_frequency' => 'daily',
    ]);

    $this->actingAs($user)
        ->post(route('superadmin.notifications.send-recap'))
        ->assertRedirect();

    Notification::assertSentTo($user, PlatformAdminDigestNotification::class);

    // The recap must NOT consume the notification (sent_at stays null).
    expect($notification->fresh()->sent_at)->toBeNull();
});

it('does not send a recap when there is no recent activity', function () {
    Notification::fake();

    $user = makeNotificationSuperadmin();

    $this->actingAs($user)
        ->post(route('superadmin.notifications.send-recap'))
        ->assertRedirect()
        ->assertSessionHas('warning');

    Notification::assertNothingSent();
});
