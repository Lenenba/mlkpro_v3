<?php

use App\Listeners\SendDatabasePushNotifications;
use App\Models\User;
use App\Notifications\DemoActionNotification;
use App\Services\NotificationPreferenceService;
use App\Services\PushNotificationService;
use Illuminate\Notifications\Events\NotificationSent;

test('demo action notification only uses the database channel', function () {
    $notification = new DemoActionNotification([
        'title' => 'Low stock',
        'message' => 'Only two units remain.',
        'action_url' => '/products/42',
        'type' => 'stock',
        'severity' => 'warning',
        'reference' => ['type' => 'product', 'id' => 42],
        'scenario_key' => 'studio_naya_coiffure',
    ]);

    expect($notification->via(new stdClass))->toBe(['database'])
        ->and($notification->toArray(new stdClass))->toBe([
            'title' => 'Low stock',
            'message' => 'Only two units remain.',
            'action_url' => '/products/42',
            'type' => 'stock',
            'category' => 'stock',
            'severity' => 'warning',
            'reference' => ['type' => 'product', 'id' => 42],
            'scenario_key' => 'studio_naya_coiffure',
        ]);
});

test('demo action notification never fans out to external push delivery', function () {
    $push = Mockery::mock(PushNotificationService::class);
    $push->shouldNotReceive('sendToUsers');
    $preferences = Mockery::mock(NotificationPreferenceService::class);
    $preferences->shouldNotReceive('shouldNotify');
    $listener = new SendDatabasePushNotifications($push, $preferences);

    $listener->handle(new NotificationSent(
        new User,
        new DemoActionNotification([
            'title' => 'Demo action',
            'scenario_key' => 'studio_naya_coiffure',
        ]),
        'database',
    ));

    expect(true)->toBeTrue();
});
