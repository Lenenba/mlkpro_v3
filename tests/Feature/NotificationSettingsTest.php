<?php

use App\Models\User;

test('notification settings page exposes company alert delivery settings for account owners', function () {
    config()->set('services.twilio.sid', 'AC123');
    config()->set('services.twilio.token', 'secret');
    config()->set('services.twilio.whatsapp_from', 'whatsapp:+15550000000');

    $owner = User::factory()->create([
        'company_notification_settings' => [
            'preferred_channel' => 'sms',
            'task_day' => [
                'email' => true,
                'sms' => true,
                'whatsapp' => false,
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('settings.notifications.edit'))
        ->assertOk()
        ->assertJsonPath('can_manage_company_notifications', true)
        ->assertJsonPath('whatsapp_configured', true)
        ->assertJsonPath('company_notification_settings.preferred_channel', 'sms')
        ->assertJsonPath('company_notification_settings.alerts.task_day.sms', true);
});

test('notification settings update stores personal and company alert delivery settings', function () {
    $owner = User::factory()->create([
        'notification_settings' => [
            'channels' => [
                'in_app' => true,
                'push' => true,
            ],
            'categories' => [
                'orders' => true,
                'sales' => true,
            ],
        ],
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => true,
                'in_app' => true,
                'sms' => false,
                'notify_on_reminder' => true,
                'reminder_hours' => [24],
            ],
        ],
    ]);

    $response = $this->actingAs($owner)
        ->putJson(route('settings.notifications.update'), [
            'channels' => [
                'in_app' => true,
                'push' => false,
            ],
            'categories' => [
                'orders' => true,
                'sales' => false,
                'stock' => true,
                'planning' => true,
                'billing' => true,
                'crm' => true,
                'support' => true,
                'security' => true,
                'emails_mirror' => true,
                'system' => true,
            ],
            'company_notification_settings' => [
                'preferred_channel' => 'whatsapp',
                'alerts' => [
                    'task_day' => [
                        'email' => true,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                    'task_updates' => [
                        'email' => false,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                    'reservations' => [
                        'email' => false,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                ],
                'task_day' => [
                    'email' => true,
                    'sms' => true,
                    'whatsapp' => true,
                ],
                'task_updates' => [
                    'email' => false,
                    'sms' => true,
                    'whatsapp' => true,
                ],
                'security' => [
                    'two_factor_sms' => true,
                ],
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('settings.channels.push', false)
        ->assertJsonPath('company_notification_settings.preferred_channel', 'whatsapp');

    $owner->refresh();

    expect($owner->notification_settings['channels']['push'])->toBeFalse()
        ->and($owner->notification_settings['categories']['sales'])->toBeFalse()
        ->and($owner->company_notification_settings['alerts']['task_day']['whatsapp'])->toBeTrue()
        ->and($owner->company_notification_settings['task_updates']['email'])->toBeFalse()
        ->and($owner->company_notification_settings['reservations']['email'])->toBeFalse()
        ->and($owner->company_notification_settings['reservations']['sms'])->toBeTrue()
        ->and($owner->company_notification_settings['reservations']['reminder_hours'])->toBe([24])
        ->and($owner->company_notification_settings['security']['two_factor_sms'])->toBeTrue();
});
