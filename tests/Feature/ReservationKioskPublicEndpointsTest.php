<?php

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function ensureKioskRole(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function createKioskOwner(): User
{
    $ownerRoleId = ensureKioskRole('owner', 'Account owner role');

    return User::query()->create([
        'name' => 'Kiosk Owner',
        'email' => 'kiosk.owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'reservations' => true,
        ],
    ]);
}

function createKioskTeamMember(User $owner): TeamMember
{
    $employeeRoleId = ensureKioskRole('employee', 'Employee role');

    $employee = User::query()->create([
        'name' => 'Kiosk Team Member',
        'email' => 'kiosk.member@example.com',
        'password' => 'password',
        'role_id' => $employeeRoleId,
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'admin',
        'title' => 'Stylist',
        'permissions' => ['jobs.edit', 'tasks.edit'],
        'is_active' => true,
    ]);
}

function createKioskClient(User $owner, string $name, string $email, string $phone): array
{
    $clientRoleId = ensureKioskRole('client', 'Client role');

    $clientUser = User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => 'password',
        'role_id' => $clientRoleId,
    ]);

    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'portal_user_id' => $clientUser->id,
        'first_name' => $name,
        'last_name' => 'Client',
        'company_name' => $name,
        'email' => $email,
        'phone' => $phone,
    ]);

    return [$clientUser, $customer];
}

function enableKioskQueue(User $owner): void
{
    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 10,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );
}

function kioskSignedRoute(string $name, User $owner, array $params = []): string
{
    return URL::signedRoute($name, array_merge(['account' => $owner->id], $params));
}

it('allows creating a public kiosk walk-in guest ticket', function () {
    $owner = createKioskOwner();
    $teamMember = createKioskTeamMember($owner);
    enableKioskQueue($owner);

    $phone = '+1 555-000-1111';
    $response = $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.walk-in.tickets.store', $owner),
        [
            'phone' => $phone,
            'guest_name' => 'Walk-in Guest',
            'team_member_id' => $teamMember->id,
            'estimated_duration_minutes' => 30,
            'notes' => 'Kiosk guest ticket',
        ]
    )->assertCreated();

    $queueItemId = (int) ($response->json('ticket.id') ?? 0);
    expect($queueItemId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $queueItemId,
        'account_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'kiosk_guest',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);

    $item = ReservationQueueItem::query()->findOrFail($queueItemId);
    expect((string) data_get($item->metadata, 'guest_name'))->toBe('Walk-in Guest');
    expect((string) data_get($item->metadata, 'guest_phone_normalized'))->toBe('15550001111');
});

it('blocks duplicate kiosk guest tickets for the same phone', function () {
    $owner = createKioskOwner();
    enableKioskQueue($owner);

    $url = kioskSignedRoute('public.kiosk.reservations.walk-in.tickets.store', $owner);
    $payload = [
        'phone' => '+1 (555) 000-2222',
        'guest_name' => 'Repeat Guest',
    ];

    $this->postJson($url, $payload)->assertCreated();

    $this->postJson($url, $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('queue');
});

it('returns check-in intent for known client with nearby reservation', function () {
    $owner = createKioskOwner();
    $teamMember = createKioskTeamMember($owner);
    enableKioskQueue($owner);
    [$clientUser, $customer] = createKioskClient(
        $owner,
        'Known Client',
        'known.client@example.com',
        '+15550003333'
    );

    $startsAt = now('UTC')->addMinutes(40);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.clients.lookup', $owner),
        ['phone' => '+1 555 000 3333']
    )
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('verified', true)
        ->assertJsonPath('intent.next_action', 'check_in');
});

it('checks in a known client reservation from kiosk endpoint', function () {
    $owner = createKioskOwner();
    $teamMember = createKioskTeamMember($owner);
    enableKioskQueue($owner);
    [$clientUser, $customer] = createKioskClient(
        $owner,
        'Checkin Client',
        'checkin.client@example.com',
        '+15550004444'
    );

    $startsAt = now('UTC')->addMinutes(30);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.check-in', $owner),
        [
            'phone' => '+1 555 000 4444',
            'reservation_id' => $reservation->id,
        ]
    )
        ->assertOk()
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_CHECKED_IN);

    $this->assertDatabaseHas('reservation_queue_items', [
        'account_id' => $owner->id,
        'reservation_id' => $reservation->id,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);

    $queueItem = ReservationQueueItem::query()
        ->where('account_id', $owner->id)
        ->where('reservation_id', $reservation->id)
        ->firstOrFail();

    $this->assertDatabaseHas('reservation_check_ins', [
        'reservation_queue_item_id' => $queueItem->id,
        'channel' => 'kiosk_client',
    ]);
});

it('records kiosk activity logs for ticket creation and check-in', function () {
    $owner = createKioskOwner();
    $teamMember = createKioskTeamMember($owner);
    enableKioskQueue($owner);
    [$clientUser, $customer] = createKioskClient(
        $owner,
        'Audit Client',
        'audit.client@example.com',
        '+15550007777'
    );

    $startsAt = now('UTC')->addMinutes(45);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $walkIn = $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.walk-in.tickets.store', $owner),
        [
            'phone' => '+1 555 000 8888',
            'guest_name' => 'Audit Guest',
        ]
    )->assertCreated();

    $walkInQueueItemId = (int) ($walkIn->json('ticket.id') ?? 0);
    expect($walkInQueueItemId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'kiosk_ticket_created',
        'subject_type' => (new ReservationQueueItem())->getMorphClass(),
        'subject_id' => $walkInQueueItemId,
        'user_id' => $owner->id,
    ]);

    $checkIn = $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.check-in', $owner),
        [
            'phone' => '+1 555 000 7777',
            'reservation_id' => $reservation->id,
        ]
    )->assertOk();

    $checkInQueueItemId = (int) ($checkIn->json('queue_item.id') ?? 0);
    expect($checkInQueueItemId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'kiosk_queue_transition',
        'subject_type' => (new ReservationQueueItem())->getMorphClass(),
        'subject_id' => $checkInQueueItemId,
        'user_id' => $owner->id,
    ]);
});

it('tracks kiosk ticket status by phone and queue number', function () {
    $owner = createKioskOwner();
    enableKioskQueue($owner);

    $phone = '+15550005555';
    $createResponse = $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.walk-in.tickets.store', $owner),
        [
            'phone' => $phone,
            'guest_name' => 'Track Guest',
        ]
    )->assertCreated();

    $queueNumber = (string) ($createResponse->json('ticket.queue_number') ?? '');
    expect($queueNumber)->not->toBe('');

    $this->getJson(
        kioskSignedRoute('public.kiosk.reservations.tickets.track', $owner, [
            'phone' => $phone,
            'queue_number' => $queueNumber,
        ])
    )
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('ticket.queue_number', $queueNumber)
        ->assertJsonPath('ticket.client_name', 'Track Guest');
});

it('supports lookup then verify flow when kiosk sms verification is required', function () {
    $owner = createKioskOwner();
    enableKioskQueue($owner);
    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'kiosk_require_sms_verification' => true,
            ],
        ],
    ]);

    createKioskClient(
        $owner,
        'Verified Client',
        'verified.client@example.com',
        '+15550006666'
    );

    $lookup = $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.clients.lookup', $owner),
        ['phone' => '+1 555 000 6666']
    )
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('verification_required', true)
        ->assertJsonPath('verified', false);

    $code = (string) ($lookup->json('verification.debug_code') ?? '');
    expect($code)->toMatch('/^\d{6}$/');

    $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.clients.verify', $owner),
        [
            'phone' => '+1 555 000 6666',
            'code' => $code,
        ]
    )
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('verified', true);
});

it('blocks public kiosk queue endpoints for non-salon presets', function () {
    $owner = createKioskOwner();

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'restaurant',
            'buffer_minutes' => 15,
            'slot_interval_minutes' => 15,
            'min_notice_minutes' => 30,
            'max_advance_days' => 30,
            'cancellation_cutoff_hours' => 6,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 15,
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 10,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    $this->postJson(
        kioskSignedRoute('public.kiosk.reservations.walk-in.tickets.store', $owner),
        ['phone' => '+1 555 000 9000']
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('kiosk');
});
