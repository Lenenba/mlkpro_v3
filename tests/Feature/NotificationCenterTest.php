<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function logInAppNotification(User $user, array $payload): DatabaseNotification
{
    $existingIds = $user->notifications()->pluck('id');

    $user->notify(new class($payload) extends Notification
    {
        public function __construct(
            private readonly array $payload
        ) {}

        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return [
                'title' => (string) ($this->payload['title'] ?? 'Notification'),
                'message' => (string) ($this->payload['message'] ?? ''),
                'action_url' => $this->payload['action_url'] ?? null,
                'category' => $this->payload['category'] ?? 'system',
            ];
        }
    });

    /** @var DatabaseNotification $notification */
    $notification = $user->notifications()
        ->whereNotIn('id', $existingIds->all())
        ->first();

    return $notification;
}

test('shared header notifications only include unread non archived items', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    $visible = logInAppNotification($user, [
        'title' => 'Visible notification',
        'message' => 'Unread and still in the bell.',
        'category' => 'system',
    ]);

    $readOnly = logInAppNotification($user, [
        'title' => 'Read notification',
        'message' => 'Already read.',
        'category' => 'billing',
    ]);
    $readOnly->forceFill(['read_at' => now()])->save();

    $archived = logInAppNotification($user, [
        'title' => 'Archived notification',
        'message' => 'Already archived.',
        'category' => 'orders',
    ]);
    $archived->forceFill([
        'read_at' => now(),
        'archived_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread_count', 1)
            ->has('notifications.items', 1)
            ->where('notifications.items.0.id', $visible->id)
            ->where('notifications.items.0.type', 'system')
        );
});

test('opening a notification from the header marks it read and archived before redirecting', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    $notification = logInAppNotification($user, [
        'title' => 'Header open notification',
        'message' => 'Open me from the bell.',
        'action_url' => route('profile.edit'),
        'category' => 'crm',
    ]);

    $this->actingAs($user)
        ->get(route('notifications.open', [
            'notification' => $notification,
            'source' => 'header',
        ]))
        ->assertRedirect(route('profile.edit'));

    $notification->refresh();

    expect($notification->read_at)->not->toBeNull()
        ->and($notification->getAttribute('archived_at'))->not->toBeNull();
});

test('notifications page keeps full history and filters by status and type', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    logInAppNotification($user, [
        'title' => 'Unread system item',
        'message' => 'Still unread.',
        'category' => 'system',
    ]);

    $read = logInAppNotification($user, [
        'title' => 'Read billing item',
        'message' => 'Already read but still visible in history.',
        'category' => 'billing',
    ]);
    $read->forceFill(['read_at' => now()])->save();

    $archived = logInAppNotification($user, [
        'title' => 'Archived order item',
        'message' => 'Opened from the bell.',
        'category' => 'orders',
    ]);
    $archived->forceFill([
        'read_at' => now(),
        'archived_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->get(route('notifications.index', [
            'status' => 'archived',
            'type' => 'orders',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('history_stats.all', 3)
            ->where('history_stats.unread', 1)
            ->where('history_stats.read', 1)
            ->where('history_stats.archived', 1)
            ->where('history_filters.status', 'archived')
            ->where('history_filters.type', 'orders')
            ->has('notification_history.data', 1)
            ->where('notification_history.data.0.id', $archived->id)
            ->where('notification_history.data.0.type', 'orders')
            ->where('notification_history.data.0.is_archived', true)
        );
});

test('mark all read archives the current header inbox without deleting notification history', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    $first = logInAppNotification($user, [
        'title' => 'Unread one',
        'message' => 'First unread item.',
        'category' => 'crm',
    ]);

    $second = logInAppNotification($user, [
        'title' => 'Unread two',
        'message' => 'Second unread item.',
        'category' => 'support',
    ]);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    $first->refresh();
    $second->refresh();

    expect($first->read_at)->not->toBeNull()
        ->and($second->read_at)->not->toBeNull()
        ->and($first->getAttribute('archived_at'))->not->toBeNull()
        ->and($second->getAttribute('archived_at'))->not->toBeNull();

    $this->actingAs($user)
        ->get(route('notifications.index', ['status' => 'archived']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('history_stats.archived', 2)
            ->has('notification_history.data', 2)
        );
});
