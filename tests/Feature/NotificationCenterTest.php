<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Work;
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
            return array_merge($this->payload, [
                'title' => (string) ($this->payload['title'] ?? 'Notification'),
                'message' => (string) ($this->payload['message'] ?? ''),
                'action_url' => $this->payload['action_url'] ?? null,
                'category' => $this->payload['category'] ?? 'system',
            ]);
        }
    });

    /** @var DatabaseNotification $notification */
    $notification = $user->notifications()
        ->whereNotIn('id', $existingIds->all())
        ->first();

    return $notification;
}

function notificationCenterRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role'],
    )->id;
}

/**
 * @param  array<string, bool>  $overrides
 * @return array<string, bool>
 */
function notificationCenterFeatureSet(array $overrides = []): array
{
    return array_replace([
        'quotes' => false,
        'requests' => false,
        'reservations' => false,
        'plan_scans' => false,
        'invoices' => false,
        'jobs' => false,
        'products' => false,
        'performance' => false,
        'presence' => false,
        'planning' => false,
        'sales' => false,
        'sales_crm' => false,
        'promotions' => false,
        'expenses' => false,
        'accounting' => false,
        'services' => false,
        'tasks' => false,
        'team_members' => false,
        'assistant' => false,
        'campaigns' => false,
        'social' => false,
        'loyalty' => false,
    ], $overrides);
}

/**
 * @param  array<string, bool>  $features
 * @return array{owner: User, client: User, customer: Customer}
 */
function notificationCenterPortalContext(array $features = []): array
{
    $owner = User::factory()->create([
        'role_id' => notificationCenterRoleId('owner'),
        'company_name' => 'Notification Center Owner',
        'company_type' => 'products',
        'company_features' => notificationCenterFeatureSet($features),
        'onboarding_completed_at' => now(),
    ]);

    $client = User::factory()->create([
        'role_id' => notificationCenterRoleId('client'),
        'company_name' => null,
        'company_type' => null,
        'company_sector' => null,
        'company_features' => [],
        'onboarding_completed_at' => now(),
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $client->id,
        'portal_access' => true,
        'first_name' => 'Notification',
        'last_name' => 'Client',
        'company_name' => 'Notification Client Company',
        'email' => $client->email,
        'phone' => '+15145550101',
        'auto_accept_quotes' => false,
        'auto_validate_jobs' => false,
        'auto_validate_tasks' => false,
        'auto_validate_invoices' => false,
    ]);

    return compact('owner', 'client', 'customer');
}

function notificationCenterWork(User $owner, Customer $customer): Work
{
    return Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Notification center work',
        'instructions' => 'Notification center test work',
        'status' => Work::STATUS_IN_PROGRESS,
    ]);
}

function notificationCenterInvoice(User $owner, Customer $customer): Invoice
{
    $work = notificationCenterWork($owner, $customer);

    return Invoice::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'work_id' => $work->id,
        'status' => 'sent',
        'total' => 150,
    ]);
}

function notificationCenterSale(User $owner, Customer $customer): Sale
{
    return Sale::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 75,
        'tax_total' => 0,
        'discount_rate' => 0,
        'discount_total' => 0,
        'total' => 75,
        'fulfillment_method' => 'delivery',
        'fulfillment_status' => Sale::FULFILLMENT_PENDING,
        'source' => 'portal',
    ]);
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

test('opening a notification without action url resolves the linked customer page', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $user->id,
    ]);

    $notification = logInAppNotification($user, [
        'title' => 'Customer notification',
        'message' => 'Review this customer.',
        'category' => 'crm',
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.open', [
            'notification' => $notification,
            'source' => 'header',
        ]))
        ->assertRedirect(route('customer.show', $customer));

    $notification->refresh();

    expect($notification->read_at)->not->toBeNull()
        ->and($notification->getAttribute('archived_at'))->not->toBeNull();
});

test('opening a notification with a missing linked entity returns a clean warning', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
    ]);

    $notification = logInAppNotification($user, [
        'title' => 'Missing customer notification',
        'message' => 'The linked customer was deleted.',
        'category' => 'crm',
        'customer_id' => 999999,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.open', [
            'notification' => $notification,
            'source' => 'history',
        ]))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('warning', 'L element lie a cette notification n est plus disponible.');

    $notification->refresh();

    expect($notification->read_at)->not->toBeNull()
        ->and($notification->getAttribute('archived_at'))->toBeNull();
});

test('client entity notifications open only their owned enabled portal destinations', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = notificationCenterPortalContext([
        'invoices' => true,
        'products' => true,
        'sales' => true,
        'reservations' => true,
    ]);

    $invoice = notificationCenterInvoice($owner, $customer);
    $sale = notificationCenterSale($owner, $customer);
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $client->id,
    ]);

    $destinations = [
        [['invoice_id' => $invoice->id], route('portal.invoices.show', $invoice)],
        [['order_id' => $sale->id], route('portal.orders.show', $sale)],
        [['reservation_id' => $reservation->id], route('client.reservations.index')],
    ];

    foreach ($destinations as [$reference, $destination]) {
        $notification = logInAppNotification($client, array_merge([
            'title' => 'Client entity notification',
            'message' => 'Open an owned portal entity.',
            'category' => 'system',
        ], $reference));

        $this->actingAs($client)
            ->get(route('notifications.open', $notification))
            ->assertRedirect($destination);
    }
});

test('client notifications never expose another client entity or an internal entity', function () {
    ['client' => $client, 'customer' => $customer] = notificationCenterPortalContext([
        'products' => true,
        'sales' => true,
    ]);
    ['owner' => $foreignOwner, 'customer' => $foreignCustomer] = notificationCenterPortalContext([
        'products' => true,
        'sales' => true,
    ]);
    $foreignSale = notificationCenterSale($foreignOwner, $foreignCustomer);

    $notifications = [
        logInAppNotification($client, [
            'title' => 'Foreign order notification',
            'sale_id' => $foreignSale->id,
            'action_url' => route('portal.orders.show', $foreignSale),
        ]),
        logInAppNotification($client, [
            'title' => 'Internal customer notification',
            'customer_id' => $customer->id,
            'action_url' => route('customer.show', $customer),
        ]),
    ];

    foreach ($notifications as $notification) {
        $this->actingAs($client)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('dashboard'));
    }
});

test('client action urls are limited to same origin safe portal surfaces', function () {
    ['client' => $client, 'customer' => $customer] = notificationCenterPortalContext();

    $safeNotification = logInAppNotification($client, [
        'title' => 'Profile notification',
        'action_url' => route('profile.edit', ['from' => 'notification']),
    ]);

    $this->actingAs($client)
        ->get(route('notifications.open', $safeNotification))
        ->assertRedirect(route('profile.edit', ['from' => 'notification']));

    $unsafeNotifications = [
        logInAppNotification($client, [
            'title' => 'Back office notification',
            'action_url' => route('customer.show', $customer),
        ]),
        logInAppNotification($client, [
            'title' => 'External notification',
            'action_url' => 'https://example.com/account',
        ]),
        logInAppNotification($client, [
            'title' => 'Unknown portal surface notification',
            'action_url' => url('/portal/internal-tools'),
        ]),
        logInAppNotification($client, [
            'title' => 'Path traversal notification',
            'action_url' => url('/').'/client/reservations/%2e%2e/%2e%2e/customers',
        ]),
    ];

    foreach ($unsafeNotifications as $notification) {
        $this->actingAs($client)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('dashboard'));
    }
});

test('client notifications fall back to dashboard when their portal capability is disabled', function () {
    ['owner' => $owner, 'client' => $client, 'customer' => $customer] = notificationCenterPortalContext();
    $sale = notificationCenterSale($owner, $customer);

    $notifications = [
        logInAppNotification($client, [
            'title' => 'Disabled order entity notification',
            'sale_id' => $sale->id,
        ]),
        logInAppNotification($client, [
            'title' => 'Disabled order action notification',
            'action_url' => route('portal.orders.show', $sale),
        ]),
    ];

    foreach ($notifications as $notification) {
        $this->actingAs($client)
            ->get(route('notifications.open', $notification))
            ->assertRedirect(route('dashboard'));
    }
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
