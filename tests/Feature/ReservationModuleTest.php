<?php

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PublicBookingLink;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationReview;
use App\Models\ReservationSetting;
use App\Models\ReservationWaitlist;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamMemberAttendance;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Notifications\ActionEmailNotification;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationQueueService;
use App\Services\SmsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function ensureRole(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function createOwnerWithReservationsEnabled(array $overrides = []): User
{
    $ownerRoleId = ensureRole('owner', 'Account owner role');

    return User::query()->create(array_merge([
        'name' => 'Reservation Owner',
        'email' => 'reservation.owner@example.com',
        'password' => 'password',
        'role_id' => $ownerRoleId,
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'reservations' => true,
        ],
    ], $overrides));
}

function createTeamMemberForAccount(User $owner, array $overrides = []): TeamMember
{
    $employeeRoleId = ensureRole('employee', 'Employee role');
    $identifier = Str::lower(Str::random(8));

    $employee = User::query()->create([
        'name' => $overrides['user_name'] ?? 'Staff Member',
        'email' => $overrides['user_email'] ?? "staff.member.{$identifier}@example.com",
        'password' => 'password',
        'role_id' => $employeeRoleId,
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => $overrides['role'] ?? 'admin',
        'title' => $overrides['title'] ?? 'Stylist',
        'permissions' => $overrides['permissions'] ?? ['jobs.edit', 'tasks.edit'],
        'is_active' => $overrides['is_active'] ?? true,
    ]);
}

function createClientForAccount(User $owner, string $name, string $email): array
{
    $clientRoleId = ensureRole('client', 'Client role');

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
        'phone' => '+15550001111',
    ]);

    return [$clientUser, $customer];
}

function addWeeklyAvailability(User $owner, TeamMember $member, Carbon $referenceDate): void
{
    WeeklyAvailability::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'day_of_week' => $referenceDate->dayOfWeek,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'is_active' => true,
    ]);
}

function createActiveChairForMember(User $owner, TeamMember $member, array $overrides = []): ReservationResource
{
    return ReservationResource::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $overrides['team_member_id'] ?? $member->id,
        'name' => $overrides['name'] ?? 'Chair 1',
        'type' => $overrides['type'] ?? ReservationResource::TYPE_CHAIR,
        'capacity' => $overrides['capacity'] ?? 1,
        'is_active' => $overrides['is_active'] ?? true,
        'metadata' => $overrides['metadata'] ?? null,
    ]);
}

function checkInTeamMember(User $owner, TeamMember $member, string $status = TeamMemberAttendance::STATUS_AVAILABLE): TeamMemberAttendance
{
    $memberUser = $member->user()->firstOrFail();

    return TeamMemberAttendance::query()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'team_member_id' => $member->id,
        'clock_in_at' => now('UTC')->subMinute(),
        'clock_out_at' => null,
        'method' => 'manual',
        'current_status' => $status,
    ]);
}

it('requires checkout for a paid queue service and records its payment and tip exactly once', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $owner->forceFill([
        'payment_methods' => ['cash'],
        'default_payment_method' => 'cash',
        'company_store_settings' => [
            'tips' => [
                'max_percent' => 30,
                'max_fixed_amount' => 200,
                'default_percent' => 10,
            ],
        ],
    ])->save();

    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Checkout Client', 'checkout.client@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    $category = ProductCategory::query()->create([
        'name' => 'Checkout services',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $service = Product::query()->create([
        'name' => 'Signature haircut',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 45,
        'currency_code' => 'CAD',
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => now('UTC')->subHour(),
        'ends_at' => now('UTC')->subMinutes(15),
        'duration_minutes' => 45,
        'buffer_minutes' => 0,
    ]);

    createActiveChairForMember($owner, $teamMember);
    $attendance = checkInTeamMember($owner, $teamMember);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'reservation_id' => $reservation->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'service_id' => $service->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'source' => Reservation::SOURCE_STAFF,
        'queue_number' => 'CHECKOUT-001',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 45,
        'checked_in_at' => now('UTC')->subHour(),
        'called_at' => now('UTC')->subMinutes(55),
        'started_at' => now('UTC')->subMinutes(45),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.done', $ticket))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment');

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.finish', $ticket))
        ->assertOk()
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_AWAITING_PAYMENT)
        ->assertJsonPath('queue_item.checkout.base_amount', 45);

    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)
        ->toBe(TeamMemberAttendance::STATUS_AVAILABLE);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.checkout', $ticket), [
            'method' => 'card',
            'tip_enabled' => true,
            'tip_mode' => 'percent',
            'tip_percent' => 20,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('method');

    $checkoutResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.checkout', $ticket), [
            'method' => 'cash',
            'amount' => 1,
            'tip_enabled' => true,
            'tip_mode' => 'percent',
            'tip_percent' => 20,
            'reference' => 'cash-register-001',
            'notes' => 'Paid at front desk',
        ])
        ->assertOk()
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_DONE)
        ->assertJsonPath('payment.amount', 45)
        ->assertJsonPath('payment.tip_amount', 9)
        ->assertJsonPath('payment.charged_total', 54);

    $payment = Payment::query()
        ->where('reservation_queue_item_id', $ticket->id)
        ->firstOrFail();
    $teamMemberUserId = (int) $teamMember->user_id;

    expect((float) $payment->amount)->toBe(45.0);
    expect((float) $payment->tip_amount)->toBe(9.0);
    expect((float) $payment->charged_total)->toBe(54.0);
    expect((int) $payment->tip_assignee_user_id)->toBe($teamMemberUserId);
    $this->assertDatabaseHas('payment_tip_allocations', [
        'payment_id' => $payment->id,
        'user_id' => $teamMemberUserId,
        'amount' => 9,
    ]);
    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => Reservation::STATUS_COMPLETED,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.checkout', $ticket), [
            'method' => 'cash',
        ])
        ->assertOk()
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_DONE);

    expect(Payment::query()->where('reservation_queue_item_id', $ticket->id)->count())->toBe(1);
});

it('allows a client to book a reservation from available slots', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Portal Client', 'portal.client@example.com');

    $referenceDate = Carbon::now('UTC')->addDays(3)->setTime(10, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $referenceDate);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('client.reservations.book'))
        ->assertOk();

    $slotResponse = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('client.reservations.slots', [
            'range_start' => $referenceDate->copy()->startOfWeek()->toIso8601String(),
            'range_end' => $referenceDate->copy()->endOfWeek()->toIso8601String(),
            'team_member_id' => $teamMember->id,
            'duration_minutes' => 60,
        ]))
        ->assertOk();

    $slot = collect($slotResponse->json('slots'))->first();

    expect($slot)->not->toBeNull();

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.store'), [
            'team_member_id' => $teamMember->id,
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'contact_name' => 'Portal Client',
            'contact_email' => 'portal.client@example.com',
            'contact_phone' => '+15550001111',
            'client_notes' => 'Please confirm by email.',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('reservations', [
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_CLIENT,
    ]);
});

it('prevents double booking on the same team member slot', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);

    $startsAt = Carbon::now('UTC')->addDays(2)->setTime(11, 0, 0);
    $endsAt = $startsAt->copy()->addHour();

    addWeeklyAvailability($owner, $teamMember, $startsAt);

    $payload = [
        'team_member_id' => $teamMember->id,
        'starts_at' => $startsAt->toIso8601String(),
        'ends_at' => $endsAt->toIso8601String(),
        'status' => Reservation::STATUS_CONFIRMED,
        'duration_minutes' => 60,
        'timezone' => 'UTC',
    ];

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.store'), $payload)
        ->assertCreated();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');

    expect(Reservation::query()->count())->toBe(1);
});

it('lets authorized reservation staff create a customer and book a reservation for them', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['reservations.manage', 'customers.create'],
    ]);
    $startsAt = Carbon::now('UTC')->addDays(3)->setTime(13, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $startsAt);
    $staffUser = $teamMember->user()->firstOrFail();

    $this->actingAs($staffUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('access.can_create_customer', true)
        );

    $customerResponse = $this->actingAs($staffUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('customer.quick.store'), [
            'client_type' => 'individual',
            'first_name' => 'Nadia',
            'last_name' => 'Roy',
            'email' => 'nadia.reservation@example.com',
            'phone' => '+15145550123',
            'portal_access' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('customer.email', 'nadia.reservation@example.com');

    $customerId = (int) $customerResponse->json('customer.id');

    $this->actingAs($staffUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.store'), [
            'team_member_id' => $teamMember->id,
            'client_id' => $customerId,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
            'duration_minutes' => 60,
            'status' => Reservation::STATUS_CONFIRMED,
            'timezone' => 'UTC',
        ])
        ->assertCreated()
        ->assertJsonPath('reservation.client_id', $customerId);

    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'user_id' => $owner->id,
        'email' => 'nadia.reservation@example.com',
    ]);
    $this->assertDatabaseHas('reservations', [
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customerId,
        'source' => Reservation::SOURCE_STAFF,
    ]);
});

it('lets reservation staff with sales directory access quick-create customers', function () {
    $owner = createOwnerWithReservationsEnabled([
        'company_features' => [
            'reservations' => true,
            'sales' => true,
        ],
    ]);
    $teamMember = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['reservations.manage', 'sales.pos'],
    ]);
    $staffUser = $teamMember->user()->firstOrFail();

    $this->actingAs($staffUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('access.can_create_customer', true)
        );

    $customerResponse = $this->actingAs($staffUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('customer.quick.store'), [
            'client_type' => 'individual',
            'first_name' => 'Marco',
            'last_name' => 'Diaz',
            'email' => 'marco.sales.reservation@example.com',
            'portal_access' => false,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('customers', [
        'id' => (int) $customerResponse->json('customer.id'),
        'user_id' => $owner->id,
        'email' => 'marco.sales.reservation@example.com',
    ]);
});

it('blocks a client from cancelling another clients reservation', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);

    [$clientA, $customerA] = createClientForAccount($owner, 'Client A', 'client.a@example.com');
    [$clientB] = createClientForAccount($owner, 'Client B', 'client.b@example.com');

    $startsAt = Carbon::now('UTC')->addDays(4)->setTime(14, 0, 0);
    $endsAt = $startsAt->copy()->addHour();

    addWeeklyAvailability($owner, $teamMember, $startsAt);

    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customerA->id,
        'client_user_id' => $clientA->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->actingAs($clientB)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('client.reservations.cancel', $reservation), [
            'reason' => 'Not mine',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => Reservation::STATUS_CONFIRMED,
    ]);
});

it('sends reservation notifications when a client books', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser] = createClientForAccount($owner, 'Client Notify', 'client.notify@example.com');

    $referenceDate = Carbon::now('UTC')->addDays(3)->setTime(10, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $referenceDate);

    $slotResponse = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('client.reservations.slots', [
            'range_start' => $referenceDate->copy()->startOfWeek()->toIso8601String(),
            'range_end' => $referenceDate->copy()->endOfWeek()->toIso8601String(),
            'team_member_id' => $teamMember->id,
            'duration_minutes' => 60,
        ]))
        ->assertOk();

    $slot = collect($slotResponse->json('slots'))->first();
    expect($slot)->not->toBeNull();

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.store'), [
            'team_member_id' => $teamMember->id,
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'contact_name' => 'Client Notify',
            'contact_email' => 'client.notify@example.com',
            'contact_phone' => '+15550002222',
        ])
        ->assertCreated();

    Notification::assertSentTo($owner, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'reservation');
    });

    $teamUser = $teamMember->user()->first();
    Notification::assertSentTo($teamUser, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'reservation');
    });
});

it('prevents marking future reservations as completed', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);

    $startsAt = Carbon::now('UTC')->addDays(2)->setTime(15, 0, 0);
    $endsAt = $startsAt->copy()->addHour();

    addWeeklyAvailability($owner, $teamMember, $startsAt);

    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), [
            'status' => Reservation::STATUS_COMPLETED,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('allows a client to submit a review after reservation completion', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Review Client', 'review.client@example.com');

    $startsAt = Carbon::now('UTC')->subDays(1)->setTime(10, 0, 0);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => Reservation::STATUS_COMPLETED,
        'source' => Reservation::SOURCE_CLIENT,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.review', $reservation), [
            'rating' => 5,
            'feedback' => 'Great service.',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('reservation_reviews', [
        'reservation_id' => $reservation->id,
        'client_user_id' => $clientUser->id,
        'rating' => 5,
    ]);

    expect(ReservationReview::query()->where('reservation_id', $reservation->id)->exists())->toBeTrue();

    Notification::assertSentTo($owner, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'review');
    });
});

it('sends reminder notifications from the scheduled reservation command', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => true,
                'in_app' => true,
                'notify_on_reminder' => true,
                'reminder_hours' => [24],
            ],
        ],
    ]);

    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Reminder Client', 'reminder.client@example.com');

    $startsAt = Carbon::now('UTC')->addHours(24);
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

    $this->artisan('reservations:notifications')
        ->assertExitCode(0);

    Notification::assertSentTo($clientUser, ActionEmailNotification::class);

    $metadata = (array) (Reservation::query()->find($reservation->id)?->metadata ?? []);
    expect((array) ($metadata['notifications'] ?? []))
        ->toHaveKey('reminder_24h_sent_at');
});

it('scopes calendar events to assigned reservations unless staff can view all', function () {
    $owner = createOwnerWithReservationsEnabled();
    $assignedMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Assigned Calendar Specialist',
        'user_email' => 'assigned.calendar@example.com',
        'role' => 'member',
        'permissions' => [],
    ]);
    $otherMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Other Calendar Specialist',
        'user_email' => 'other.calendar@example.com',
        'role' => 'member',
        'permissions' => [],
    ]);
    $viewerMember = createTeamMemberForAccount($owner, [
        'user_name' => 'All Calendar Viewer',
        'user_email' => 'all.calendar.viewer@example.com',
        'role' => 'member',
        'permissions' => ['view_all_reservations'],
    ]);

    $startsAt = Carbon::parse('2026-09-10 14:00:00', 'UTC');
    $assignedReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $assignedMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $otherReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $otherMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(2),
        'ends_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $range = [
        'start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'end' => $startsAt->copy()->addDay()->startOfDay()->toIso8601String(),
        'scope' => 'all',
    ];

    $assignedEvents = $this->actingAs($assignedMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', $range))
        ->assertOk()
        ->json('events');

    $this->assertSame([$assignedReservation->id], collect($assignedEvents)->pluck('id')->all());

    $viewerEvents = $this->actingAs($viewerMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', $range))
        ->assertOk()
        ->json('events');

    $this->assertEqualsCanonicalizing(
        [$assignedReservation->id, $otherReservation->id],
        collect($viewerEvents)->pluck('id')->all()
    );
});

it('returns sanitized calendar events with tenant-scoped relations', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner, [
        'user_name' => 'Safe Calendar Specialist',
        'user_email' => 'safe.calendar@example.com',
    ]);
    [, $customer] = createClientForAccount($owner, 'Safe Calendar Client', 'safe.calendar.client@example.com');
    $category = ProductCategory::query()->create([
        'name' => 'Safe calendar category',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $service = Product::query()->create([
        'name' => 'Safe calendar service',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 75,
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);

    $foreignOwner = createOwnerWithReservationsEnabled([
        'name' => 'Foreign Calendar Owner',
        'email' => 'foreign.calendar.owner@example.com',
    ]);
    $foreignMember = createTeamMemberForAccount($foreignOwner, [
        'user_name' => 'FOREIGN TEAM SECRET',
        'user_email' => 'foreign.calendar.team@example.com',
    ]);
    [, $foreignCustomer] = createClientForAccount(
        $foreignOwner,
        'FOREIGN CLIENT SECRET',
        'foreign.calendar.client@example.com'
    );
    $foreignProspect = LeadRequest::query()->create([
        'user_id' => $foreignOwner->id,
        'channel' => 'manual',
        'status' => LeadRequest::STATUS_NEW,
        'contact_name' => 'FOREIGN PROSPECT SECRET',
    ]);
    $foreignCategory = ProductCategory::query()->create([
        'name' => 'Foreign calendar category',
        'user_id' => $foreignOwner->id,
        'created_by_user_id' => $foreignOwner->id,
    ]);
    $foreignService = Product::query()->create([
        'name' => 'FOREIGN SERVICE SECRET',
        'category_id' => $foreignCategory->id,
        'user_id' => $foreignOwner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 120,
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);

    $startsAt = Carbon::parse('2026-10-05 13:00:00', 'UTC');
    $safeReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'client_id' => $customer->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'internal_notes' => 'INTERNAL CALENDAR SECRET',
        'client_notes' => 'CLIENT CALENDAR SECRET',
        'metadata' => ['provider_reference' => 'METADATA CALENDAR SECRET'],
    ]);
    $hostileRelationReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $foreignMember->id,
        'client_id' => $foreignCustomer->id,
        'prospect_id' => $foreignProspect->id,
        'service_id' => $foreignService->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_API,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(2),
        'ends_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $foreignReservation = Reservation::query()->create([
        'account_id' => $foreignOwner->id,
        'team_member_id' => $foreignMember->id,
        'client_id' => $foreignCustomer->id,
        'prospect_id' => $foreignProspect->id,
        'service_id' => $foreignService->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(4),
        'ends_at' => $startsAt->copy()->addHours(5),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $events = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', [
            'start' => $startsAt->copy()->startOfDay()->toIso8601String(),
            'end' => $startsAt->copy()->addDay()->startOfDay()->toIso8601String(),
        ]))
        ->assertOk()
        ->json('events');

    $eventIds = collect($events)->pluck('id')->all();
    $this->assertEqualsCanonicalizing(
        [$safeReservation->id, $hostileRelationReservation->id],
        $eventIds
    );
    $this->assertNotContains($foreignReservation->id, $eventIds);

    $safeEvent = collect($events)->firstWhere('id', $safeReservation->id);
    $this->assertSame('Safe calendar service · Safe Calendar Client', $safeEvent['title']);
    $this->assertSame($member->id, data_get($safeEvent, 'extendedProps.team_member_id'));
    $this->assertSame('Safe Calendar Specialist', data_get($safeEvent, 'extendedProps.team_member_name'));

    $hostileEvent = collect($events)->firstWhere('id', $hostileRelationReservation->id);
    $this->assertNull($hostileEvent['title']);
    $this->assertNull(data_get($hostileEvent, 'extendedProps.team_member_id'));
    $this->assertNull(data_get($hostileEvent, 'extendedProps.team_member_name'));
    $this->assertNull(data_get($hostileEvent, 'extendedProps.client_name'));
    $this->assertNull(data_get($hostileEvent, 'extendedProps.service_name'));

    foreach ($events as $event) {
        $this->assertArrayNotHasKey('internal_notes', $event);
        $this->assertArrayNotHasKey('client_notes', $event);
        $this->assertArrayNotHasKey('metadata', $event);
        $this->assertArrayNotHasKey('internal_notes', $event['extendedProps']);
        $this->assertArrayNotHasKey('client_notes', $event['extendedProps']);
        $this->assertArrayNotHasKey('metadata', $event['extendedProps']);
    }

    $encodedEvents = json_encode($events, JSON_THROW_ON_ERROR);
    foreach ([
        'INTERNAL CALENDAR SECRET',
        'CLIENT CALENDAR SECRET',
        'METADATA CALENDAR SECRET',
        'FOREIGN TEAM SECRET',
        'FOREIGN CLIENT SECRET',
        'FOREIGN PROSPECT SECRET',
        'FOREIGN SERVICE SECRET',
    ] as $secret) {
        $this->assertStringNotContainsString($secret, $encodedEvents);
    }
});

it('limits calendar event ranges to 370 account-local days across DST', function () {
    $owner = createOwnerWithReservationsEnabled([
        'company_timezone' => 'America/Toronto',
    ]);

    $validRange = [
        'start' => '2026-03-09T00:00:00-04:00',
        'end' => '2027-03-14T00:00:00-05:00',
    ];

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', $validRange))
        ->assertOk()
        ->assertJsonPath('events', []);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', [
            ...$validRange,
            'end' => '2027-03-14T00:00:01-05:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');
});

it('serializes tenant-safe reservation list rows and limits notes to managers', function () {
    $owner = createOwnerWithReservationsEnabled();
    $managerMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Safe List Manager',
        'user_email' => 'safe.list.manager@example.com',
        'role' => 'member',
        'permissions' => ['reservations.manage'],
    ]);
    $viewerMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Safe List Viewer',
        'user_email' => 'safe.list.viewer@example.com',
        'role' => 'member',
        'permissions' => ['view_all_reservations'],
    ]);
    [, $customer] = createClientForAccount($owner, 'Safe List Client', 'safe.list.client@example.com');
    $customer->forceFill([
        'phone' => '+15145550901',
        'description' => 'CUSTOMER LIST SECRET',
        'logo' => 'customers/safe-list-client.jpg',
    ])->save();
    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'channel' => 'public_booking',
        'status' => LeadRequest::STATUS_NEW,
        'contact_name' => 'Safe List Prospect',
        'contact_email' => 'safe.list.prospect@example.com',
        'contact_phone' => '+15145550902',
        'meta' => ['provider_reference' => 'PROSPECT LIST SECRET'],
    ]);
    $category = ProductCategory::query()->create([
        'name' => 'Safe list category',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $service = Product::query()->create([
        'name' => 'Safe list service',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 95,
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'image' => 'services/safe-list-service.jpg',
        'stripe_product_id' => 'SERVICE LIST SECRET',
    ]);
    $managerMember->user()->firstOrFail()->forceFill([
        'profile_picture' => 'avatars/safe-list-manager.jpg',
    ])->save();

    $foreignOwner = createOwnerWithReservationsEnabled([
        'name' => 'Foreign List Owner',
        'email' => 'foreign.list.owner@example.com',
    ]);
    $foreignMember = createTeamMemberForAccount($foreignOwner, [
        'user_name' => 'FOREIGN LIST TEAM SECRET',
        'user_email' => 'foreign.list.team@example.com',
    ]);
    [, $foreignCustomer] = createClientForAccount(
        $foreignOwner,
        'FOREIGN LIST CLIENT SECRET',
        'foreign.list.client@example.com'
    );
    $foreignProspect = LeadRequest::query()->create([
        'user_id' => $foreignOwner->id,
        'channel' => 'manual',
        'status' => LeadRequest::STATUS_NEW,
        'contact_name' => 'FOREIGN LIST PROSPECT SECRET',
        'meta' => ['provider_reference' => 'FOREIGN PROSPECT META SECRET'],
    ]);
    $foreignCategory = ProductCategory::query()->create([
        'name' => 'Foreign list category',
        'user_id' => $foreignOwner->id,
        'created_by_user_id' => $foreignOwner->id,
    ]);
    $foreignService = Product::query()->create([
        'name' => 'FOREIGN LIST SERVICE SECRET',
        'category_id' => $foreignCategory->id,
        'user_id' => $foreignOwner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 125,
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
    ]);

    $startsAt = Carbon::parse('2026-11-18 13:00:00', 'UTC');
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $managerMember->id,
        'client_id' => $customer->id,
        'prospect_id' => $prospect->id,
        'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_PUBLIC_BOOKING,
        'timezone' => 'America/Toronto',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(75),
        'duration_minutes' => 75,
        'buffer_minutes' => 15,
        'internal_notes' => 'MANAGER INTERNAL LIST NOTE',
        'client_notes' => 'MANAGER CLIENT LIST NOTE',
        'metadata' => ['provider_reference' => 'RESERVATION LIST SECRET'],
    ]);
    $hostileRelationReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $foreignMember->id,
        'client_id' => $foreignCustomer->id,
        'prospect_id' => $foreignProspect->id,
        'service_id' => $foreignService->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_API,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(3),
        'ends_at' => $startsAt->copy()->addHours(4),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => ['provider_reference' => 'HOSTILE RESERVATION LIST SECRET'],
    ]);
    $foreignReservation = Reservation::query()->create([
        'account_id' => $foreignOwner->id,
        'team_member_id' => $foreignMember->id,
        'client_id' => $foreignCustomer->id,
        'prospect_id' => $foreignProspect->id,
        'service_id' => $foreignService->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(5),
        'ends_at' => $startsAt->copy()->addHours(6),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $parameters = ['scope' => 'all', 'per_page' => 10];
    $ownerPayload = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index', $parameters))
        ->assertOk()
        ->assertJsonPath('reservations.total', 2)
        ->assertJsonPath('reservations.per_page', 10)
        ->json();
    $managerPayload = $this->actingAs($managerMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index', $parameters))
        ->assertOk()
        ->assertJsonPath('reservations.total', 2)
        ->json();
    $viewerPayload = $this->actingAs($viewerMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index', $parameters))
        ->assertOk()
        ->assertJsonPath('reservations.total', 2)
        ->json();

    $ownerPage = $ownerPayload['reservations'];
    $managerPage = $managerPayload['reservations'];
    $viewerPage = $viewerPayload['reservations'];
    $this->assertSame(
        ['id', 'first_name', 'last_name', 'company_name', 'email', 'phone'],
        array_keys($managerPayload['clients'][0])
    );
    $this->assertSame([], $viewerPayload['clients']);

    $ownerRow = collect($ownerPage['data'])->firstWhere('id', $reservation->id);
    $managerRow = collect($managerPage['data'])->firstWhere('id', $reservation->id);
    $viewerRow = collect($viewerPage['data'])->firstWhere('id', $reservation->id);

    foreach ([$ownerRow, $managerRow] as $editableRow) {
        $this->assertSame($managerMember->id, $editableRow['team_member_id']);
        $this->assertSame($customer->id, $editableRow['client_id']);
        $this->assertSame($service->id, $editableRow['service_id']);
        $this->assertSame(75, $editableRow['duration_minutes']);
        $this->assertSame('America/Toronto', $editableRow['timezone']);
        $this->assertSame('MANAGER INTERNAL LIST NOTE', $editableRow['internal_notes']);
        $this->assertSame('MANAGER CLIENT LIST NOTE', $editableRow['client_notes']);
    }

    $this->assertArrayNotHasKey('internal_notes', $viewerRow);
    $this->assertArrayNotHasKey('client_notes', $viewerRow);
    $this->assertSame(
        ['id', 'display_name', 'first_name', 'last_name', 'company_name', 'avatar_url'],
        array_keys($managerRow['client'])
    );
    $this->assertSame('Safe List Client', $managerRow['client']['display_name']);
    $this->assertStringEndsWith('/storage/customers/safe-list-client.jpg', $managerRow['client']['avatar_url']);
    $this->assertSame(['id', 'contact_name'], array_keys($managerRow['prospect']));
    $this->assertSame(['id', 'name', 'image_url', 'has_image'], array_keys($managerRow['service']));
    $this->assertTrue($managerRow['service']['has_image']);
    $this->assertStringEndsWith('/storage/services/safe-list-service.jpg', $managerRow['service']['image_url']);
    $this->assertSame(['id', 'name', 'title', 'avatar_url', 'user'], array_keys($managerRow['team_member']));
    $this->assertSame('Safe List Manager', $managerRow['team_member']['name']);
    $this->assertStringEndsWith('/storage/avatars/safe-list-manager.jpg', $managerRow['team_member']['avatar_url']);
    $this->assertSame(['name'], array_keys($managerRow['team_member']['user']));
    $this->assertSame([
        'can_view' => true,
        'can_edit' => true,
        'can_delete' => true,
        'can_update_status' => true,
        'can_convert' => false,
        'allowed_status_transitions' => [Reservation::STATUS_PENDING, Reservation::STATUS_CANCELLED],
    ], $managerRow['permissions']);
    $this->assertSame([
        'can_view' => true,
        'can_edit' => false,
        'can_delete' => false,
        'can_update_status' => false,
        'can_convert' => false,
        'allowed_status_transitions' => [],
    ], $viewerRow['permissions']);

    $hostileRow = collect($managerPage['data'])->firstWhere('id', $hostileRelationReservation->id);
    $this->assertNull($hostileRow['team_member_id']);
    $this->assertNull($hostileRow['client_id']);
    $this->assertNull($hostileRow['prospect_id']);
    $this->assertNull($hostileRow['service_id']);
    $this->assertNull($hostileRow['team_member']);
    $this->assertNull($hostileRow['client']);
    $this->assertNull($hostileRow['prospect']);
    $this->assertNull($hostileRow['service']);
    $this->assertNotContains($foreignReservation->id, collect($managerPage['data'])->pluck('id')->all());

    foreach ([$ownerPage['data'], $managerPage['data'], $viewerPage['data']] as $rows) {
        $encodedRows = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ([
            'CUSTOMER LIST SECRET',
            'PROSPECT LIST SECRET',
            'SERVICE LIST SECRET',
            'RESERVATION LIST SECRET',
            'HOSTILE RESERVATION LIST SECRET',
            'FOREIGN LIST TEAM SECRET',
            'FOREIGN LIST CLIENT SECRET',
            'FOREIGN LIST PROSPECT SECRET',
            'FOREIGN PROSPECT META SECRET',
            'FOREIGN LIST SERVICE SECRET',
            'safe.list.client@example.com',
            'safe.list.prospect@example.com',
            '+15145550901',
            '+15145550902',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $encodedRows);
        }

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('account_id', $row);
            $this->assertArrayNotHasKey('metadata', $row);
            $this->assertArrayNotHasKey('image', $row['service'] ?? []);
            $this->assertArrayNotHasKey('logo', $row['client'] ?? []);
            $this->assertArrayNotHasKey('profile_picture', $row['team_member'] ?? []);
        }
    }

    foreach ([
        ['search' => 'FOREIGN LIST CLIENT SECRET'],
        ['search' => 'FOREIGN LIST SERVICE SECRET'],
        ['search' => 'FOREIGN LIST PROSPECT SECRET'],
        ['team_member_id' => $foreignMember->id],
        ['service_id' => $foreignService->id],
    ] as $hostileFilters) {
        $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.index', ['scope' => 'all', ...$hostileFilters]))
            ->assertOk()
            ->assertJsonPath('reservations.total', 0);
    }
});

it('applies every supported reservation list sort deterministically and keeps pagination ties stable', function () {
    $owner = createOwnerWithReservationsEnabled();
    $members = [
        createTeamMemberForAccount($owner, [
            'user_name' => 'Charlie Stylist',
            'user_email' => 'sort.charlie@example.com',
        ]),
        createTeamMemberForAccount($owner, [
            'user_name' => 'Alice Stylist',
            'user_email' => 'sort.alice@example.com',
        ]),
        createTeamMemberForAccount($owner, [
            'user_name' => 'Bob Stylist',
            'user_email' => 'sort.bob@example.com',
        ]),
    ];
    $clients = [
        createClientForAccount($owner, 'Zulu Client', 'sort.zulu@example.com')[1],
        createClientForAccount($owner, 'Alpha Client', 'sort.alpha@example.com')[1],
        createClientForAccount($owner, 'Mike Client', 'sort.mike@example.com')[1],
    ];
    $category = ProductCategory::query()->create([
        'name' => 'Sortable services',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $services = collect(['Beta Service', 'Gamma Service', 'Alpha Service'])
        ->map(fn (string $name) => Product::query()->create([
            'name' => $name,
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'unit' => 'service',
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'is_active' => true,
        ]));
    $startsAt = Carbon::parse('2026-10-14 14:00:00', 'UTC');
    $statuses = [
        Reservation::STATUS_COMPLETED,
        Reservation::STATUS_PENDING,
        Reservation::STATUS_CONFIRMED,
    ];
    $reservations = collect(range(0, 2))->map(fn (int $index) => Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $members[$index]->id,
        'client_id' => $clients[$index]->id,
        'service_id' => $services[$index]->id,
        'status' => $statuses[$index],
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]));
    [$first, $second, $third] = $reservations->all();

    $expectedBySort = [
        'date_asc' => [$first->id, $second->id, $third->id],
        'date_desc' => [$third->id, $second->id, $first->id],
        'status' => [$second->id, $third->id, $first->id],
        'status_asc' => [$second->id, $third->id, $first->id],
        'status_desc' => [$first->id, $third->id, $second->id],
        'client_asc' => [$second->id, $third->id, $first->id],
        'client_desc' => [$first->id, $third->id, $second->id],
        'service_asc' => [$third->id, $first->id, $second->id],
        'service_desc' => [$second->id, $first->id, $third->id],
        'team_member_asc' => [$second->id, $third->id, $first->id],
        'team_member_desc' => [$first->id, $third->id, $second->id],
    ];

    foreach ($expectedBySort as $sort => $expectedIds) {
        $response = $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.index', [
                'scope' => 'all',
                'view_mode' => 'list',
                'sort' => $sort,
                'per_page' => 5,
            ]))
            ->assertOk()
            ->assertJsonPath('filters.sort', $sort);

        $this->assertSame($expectedIds, collect($response->json('reservations.data'))->pluck('id')->all(), $sort);
    }

    $extraReservations = collect(range(1, 3))->map(fn () => Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $members[0]->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]));
    $allIds = $reservations->concat($extraReservations)->pluck('id')->all();

    $pageOne = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index', [
            'scope' => 'all',
            'sort' => 'date_asc',
            'per_page' => 5,
            'page' => 1,
        ]))
        ->assertOk();
    $pageTwo = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index', [
            'scope' => 'all',
            'sort' => 'date_asc',
            'per_page' => 5,
            'page' => 2,
        ]))
        ->assertOk();

    $this->assertSame($allIds, [
        ...collect($pageOne->json('reservations.data'))->pluck('id')->all(),
        ...collect($pageTwo->json('reservations.data'))->pluck('id')->all(),
    ]);
});

it('applies reservation list date and today filters in the account timezone across DST', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-08 16:00:00', 'UTC'));

    try {
        $owner = createOwnerWithReservationsEnabled([
            'company_timezone' => 'America/Toronto',
        ]);
        $member = createTeamMemberForAccount($owner);
        $instants = [
            Carbon::parse('2026-03-07 23:30:00', 'America/Toronto')->utc(),
            Carbon::parse('2026-03-08 00:30:00', 'America/Toronto')->utc(),
            Carbon::parse('2026-03-08 23:30:00', 'America/Toronto')->utc(),
            Carbon::parse('2026-03-09 00:30:00', 'America/Toronto')->utc(),
        ];
        $reservations = collect($instants)->map(fn (Carbon $startsAt) => Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'America/Toronto',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
        ]));
        $expectedTodayIds = [$reservations[1]->id, $reservations[2]->id];

        $dateResponse = $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.index', [
                'scope' => 'all',
                'date_from' => '2026-03-08',
                'date_to' => '2026-03-08',
                'sort' => 'date_asc',
            ]))
            ->assertOk()
            ->assertJsonPath('stats.today', 2)
            ->assertJsonPath('quickCounts.today', 2);

        $this->assertSame(
            $expectedTodayIds,
            collect($dateResponse->json('reservations.data'))->pluck('id')->all()
        );

        $quickResponse = $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.index', [
                'scope' => 'all',
                'quick' => 'today',
                'sort' => 'date_asc',
            ]))
            ->assertOk();

        $this->assertSame(
            $expectedTodayIds,
            collect($quickResponse->json('reservations.data'))->pluck('id')->all()
        );
    } finally {
        Carbon::setTestNow();
    }
});

it('applies search and date filters to calendar events', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);
    [, $matchingCustomer] = createClientForAccount(
        $owner,
        'Filtered Calendar Client',
        'filtered.calendar.client@example.com'
    );
    [, $otherCustomer] = createClientForAccount(
        $owner,
        'Unrelated Calendar Client',
        'unrelated.calendar.client@example.com'
    );

    $targetDate = Carbon::parse('2026-11-10 14:00:00', 'UTC');
    $targetReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'client_id' => $matchingCustomer->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $targetDate,
        'ends_at' => $targetDate->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $sameDateUnrelated = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'client_id' => $otherCustomer->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $targetDate->copy()->addHours(2),
        'ends_at' => $targetDate->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $otherDateMatching = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'client_id' => $matchingCustomer->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $targetDate->copy()->addDays(2),
        'ends_at' => $targetDate->copy()->addDays(2)->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $events = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', [
            'start' => '2026-11-01T00:00:00Z',
            'end' => '2026-12-01T00:00:00Z',
            'search' => 'Filtered Calendar Client',
            'date_from' => '2026-11-10',
            'date_to' => '2026-11-10',
        ]))
        ->assertOk()
        ->json('events');

    $this->assertSame([$targetReservation->id], collect($events)->pluck('id')->all());
    $this->assertNotContains($sameDateUnrelated->id, collect($events)->pluck('id')->all());
    $this->assertNotContains($otherDateMatching->id, collect($events)->pluck('id')->all());
});

it('returns a sanitized account-scoped reservation detail contract', function () {
    $owner = createOwnerWithReservationsEnabled();
    $owner->forceFill([
        'profile_picture' => '/images/presets/avatar-4.svg',
    ])->save();
    $teamMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Detail Specialist',
        'user_email' => 'detail.specialist@example.com',
        'title' => 'Senior stylist',
    ]);
    $teamMember->user()->firstOrFail()->forceFill([
        'profile_picture' => '/images/presets/avatar-2.svg',
    ])->save();

    [$clientUser, $customer] = createClientForAccount($owner, 'Detail Client', 'detail.client@example.com');
    $customer->forceFill([
        'logo' => '/images/presets/avatar-3.svg',
        'is_vip' => true,
        'description' => 'customer-private-secret',
    ])->save();

    $category = ProductCategory::query()->create([
        'name' => 'Signature care',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
    $service = Product::query()->create([
        'name' => 'Premium styling',
        'description' => '<p>Premium <strong>service</strong> with care.</p>',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'stock' => 0,
        'minimum_stock' => 0,
        'price' => 89.50,
        'currency_code' => 'cad',
        'image' => 'images/mega-menu/reservations-suite.svg',
        'unit' => 'service',
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'stripe_product_id' => 'product-provider-secret',
    ]);

    $startsAt = Carbon::now('UTC')->subHours(3);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'service_id' => $service->id,
        'created_by_user_id' => $owner->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 10,
        'client_notes' => 'Prefers a quiet appointment.',
        'internal_notes' => 'Prepare station five.',
        'metadata' => [
            'party_size' => 2,
            'private_secret' => 'reservation-private-secret',
            'payment_policy' => [
                'currency_code' => 'cad',
                'deposit_required' => true,
                'deposit_amount' => 25,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 12,
                'provider_reference' => 'payment-provider-secret',
            ],
            'payment_state' => [
                'deposit_status' => 'required',
                'deposit_due_amount' => 25,
                'no_show_fee_status' => 'not_applied',
                'no_show_fee_amount' => 12,
                'processor_payload' => 'processor-private-secret',
            ],
        ],
    ]);

    $resource = createActiveChairForMember($owner, $teamMember, [
        'name' => 'Styling chair 5',
        'capacity' => 2,
        'metadata' => ['access_code' => 'resource-private-secret'],
    ]);
    ReservationResourceAllocation::query()->create([
        'account_id' => $owner->id,
        'reservation_id' => $reservation->id,
        'reservation_resource_id' => $resource->id,
        'quantity' => 1,
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertOk();

    $detail = $response->json('reservation');
    $this->assertSame($reservation->id, $detail['id']);
    $this->assertSame(2, $detail['party_size']);
    $this->assertSame('Detail Client', $detail['client']['display_name']);
    $this->assertSame('company', $detail['client']['client_type']);
    $this->assertTrue($detail['client']['is_vip']);
    $this->assertSame('/images/presets/avatar-3.svg', $detail['client']['avatar_url']);
    $this->assertSame('Premium service with care.', $detail['service']['description']);
    $this->assertSame([
        'id' => $category->id,
        'name' => 'Signature care',
    ], $detail['service']['category']);
    $this->assertSame('CAD', $detail['service']['currency_code']);
    $this->assertTrue($detail['service']['has_image']);
    $this->assertStringEndsWith('/images/mega-menu/reservations-suite.svg', $detail['service']['image_url']);
    $this->assertSame('Detail Specialist', $detail['team_member']['name']);
    $this->assertSame('/images/presets/avatar-2.svg', $detail['team_member']['avatar_url']);
    $this->assertSame([
        'name' => 'Reservation Owner',
        'avatar_url' => '/images/presets/avatar-4.svg',
    ], $detail['creator']);
    $this->assertNull($detail['canceller']);
    $this->assertSame([[
        'id' => $resource->id,
        'name' => 'Styling chair 5',
        'type' => ReservationResource::TYPE_CHAIR,
        'capacity' => 2,
        'quantity' => 1,
    ]], $detail['resources']);
    $this->assertSame('CAD', $detail['payment']['currency_code']);
    $this->assertTrue($detail['payment']['policy']['deposit_required']);
    $this->assertSame('required', $detail['payment']['state']['deposit_status']);
    $this->assertSame('not_applied', $detail['payment']['state']['no_show_fee_status']);
    $this->assertSame([
        'can_edit' => true,
        'can_delete' => true,
        'can_update_status' => true,
        'can_convert' => false,
        'allowed_status_transitions' => [
            Reservation::STATUS_PENDING,
            Reservation::STATUS_COMPLETED,
            Reservation::STATUS_NO_SHOW,
            Reservation::STATUS_CANCELLED,
        ],
    ], $detail['permissions']);

    $this->assertArrayNotHasKey('account_id', $detail);
    $this->assertArrayNotHasKey('metadata', $detail);
    $this->assertArrayNotHasKey('logo', $detail['client']);
    $this->assertArrayNotHasKey('image', $detail['service']);
    $this->assertArrayNotHasKey('profile_picture', $detail['team_member']);
    $this->assertArrayNotHasKey('metadata', $detail['resources'][0]);

    $encodedDetail = json_encode($detail, JSON_THROW_ON_ERROR);
    foreach ([
        'customer-private-secret',
        'reservation-private-secret',
        'payment-provider-secret',
        'processor-private-secret',
        'product-provider-secret',
        'resource-private-secret',
        'detail.specialist@example.com',
    ] as $secret) {
        $this->assertStringNotContainsString($secret, $encodedDetail);
    }

    $service->forceFill(['image' => null])->save();
    $withoutImage = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertOk()
        ->json('reservation.service');
    $this->assertFalse($withoutImage['has_image']);
    $this->assertStringEndsWith('/images/placeholders/service-default.jpg', $withoutImage['image_url']);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertForbidden();
});

it('exposes a safe public booking prospect and protects conversion from unassigned members', function () {
    $owner = createOwnerWithReservationsEnabled();
    $assignedMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Assigned Public Specialist',
        'user_email' => 'assigned.public@example.com',
        'role' => 'member',
        'permissions' => [],
    ]);
    $unassignedMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Unassigned Public Specialist',
        'user_email' => 'unassigned.public@example.com',
        'role' => 'member',
        'permissions' => [],
    ]);

    $link = PublicBookingLink::query()->create([
        'account_id' => $owner->id,
        'name' => 'Instagram bookings',
        'slug' => 'reservation-detail-instagram',
        'is_active' => true,
        'metadata' => ['provider_token' => 'link-provider-secret'],
    ]);
    $prospect = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'public_booking_link_id' => $link->id,
        'channel' => 'public_booking',
        'status' => LeadRequest::STATUS_NEW,
        'contact_name' => 'Public Guest',
        'contact_email' => 'public.guest@example.com',
        'contact_phone' => '+15145550123',
        'meta' => ['provider_reference' => 'prospect-provider-secret'],
    ]);
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Public',
        'last_name' => 'Guest',
        'company_name' => 'Public Guest',
        'email' => 'public.guest@example.com',
        'phone' => '+15145550123',
        'description' => 'customer-conversion-secret',
    ]);

    $startsAt = Carbon::now('UTC')->addDay()->setTime(13, 0);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $assignedMember->id,
        'prospect_id' => $prospect->id,
        'public_booking_link_id' => $link->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_PUBLIC_BOOKING,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => ['provider_reference' => 'reservation-provider-secret'],
    ]);

    $response = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertOk();

    $this->assertSame([
        'id' => $prospect->id,
        'contact_name' => 'Public Guest',
        'contact_email' => 'public.guest@example.com',
        'contact_phone' => '+15145550123',
    ], $response->json('reservation.prospect'));
    $this->assertSame([
        'id' => $link->id,
        'name' => 'Instagram bookings',
    ], $response->json('reservation.public_booking_link'));
    $this->assertTrue($response->json('reservation.permissions.can_convert'));

    $unassignedUser = $unassignedMember->user()->firstOrFail();
    $this->actingAs($unassignedUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertForbidden();
    $this->actingAs($unassignedUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.public-booking-conversion.show', $reservation))
        ->assertForbidden();

    $conversionResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.public-booking-conversion.store', $reservation), [
            'mode' => 'link_existing',
            'customer_id' => $customer->id,
        ])
        ->assertOk()
        ->assertJsonPath('reservation.id', $reservation->id)
        ->assertJsonPath('reservation.client_id', $customer->id)
        ->assertJsonPath('customer.email', 'public.guest@example.com');

    $conversionPayload = $conversionResponse->json();
    $this->assertSame(['id', 'client_id', 'prospect_id', 'status'], array_keys($conversionPayload['reservation']));
    $this->assertArrayNotHasKey('metadata', $conversionPayload['reservation']);
    $this->assertArrayNotHasKey('meta', $conversionPayload['prospect']);

    $encodedConversion = json_encode($conversionPayload, JSON_THROW_ON_ERROR);
    foreach ([
        'link-provider-secret',
        'prospect-provider-secret',
        'reservation-provider-secret',
        'customer-conversion-secret',
    ] as $secret) {
        $this->assertStringNotContainsString($secret, $encodedConversion);
    }
});

it('lets view-all members read reservation details without mutation capabilities', function () {
    $owner = createOwnerWithReservationsEnabled();
    $viewerMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Reservation Viewer',
        'user_email' => 'reservation.viewer@example.com',
        'role' => 'member',
        'permissions' => ['view_all_reservations'],
    ]);
    $assignedMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Other Reservation Specialist',
        'user_email' => 'other.reservation.specialist@example.com',
        'role' => 'member',
        'permissions' => [],
    ]);

    $startsAt = Carbon::now('UTC')->addDay()->setTime(10, 0);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $assignedMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $viewer = $viewerMember->user()->firstOrFail();
    $response = $this->actingAs($viewer)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $reservation))
        ->assertOk();

    $this->assertSame([
        'can_edit' => false,
        'can_delete' => false,
        'can_update_status' => false,
        'can_convert' => false,
        'allowed_status_transitions' => [],
    ], $response->json('reservation.permissions'));

    $this->actingAs($viewer)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), [
            'status' => Reservation::STATUS_CONFIRMED,
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->withSession(['two_factor_passed' => true])
        ->deleteJson(route('reservation.destroy', $reservation))
        ->assertForbidden();
    $this->actingAs($viewer)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.public-booking-conversion.show', $reservation))
        ->assertForbidden();
});

it('returns not found for hostile tenant reservation detail and mutation requests', function () {
    $owner = createOwnerWithReservationsEnabled();
    $foreignOwner = createOwnerWithReservationsEnabled([
        'name' => 'Foreign Reservation Owner',
        'email' => 'foreign.reservation.owner@example.com',
    ]);
    $foreignMember = createTeamMemberForAccount($foreignOwner, [
        'user_name' => 'Foreign Reservation Specialist',
        'user_email' => 'foreign.reservation.specialist@example.com',
    ]);

    $startsAt = Carbon::now('UTC')->addDay()->setTime(15, 0);
    $foreignReservation = Reservation::query()->create([
        'account_id' => $foreignOwner->id,
        'team_member_id' => $foreignMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.show', $foreignReservation))
        ->assertNotFound();
    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.public-booking-conversion.show', $foreignReservation))
        ->assertNotFound();
    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $foreignReservation), [
            'status' => Reservation::STATUS_CONFIRMED,
        ])
        ->assertNotFound();
});

it('defaults reservation scope to mine for team members and allows managers to switch to all', function () {
    $owner = createOwnerWithReservationsEnabled();
    $adminMember = createTeamMemberForAccount($owner, [
        'role' => 'admin',
        'permissions' => ['jobs.edit', 'tasks.edit'],
    ]);
    $otherMember = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    $startsAt = Carbon::now('UTC')->addDays(2)->setTime(11, 0, 0);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $adminMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $otherMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(2),
        'ends_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $adminUser = $adminMember->user()->firstOrFail();

    $this->actingAs($adminUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('filters.scope', 'mine')
            ->where('filters.team_member_id', (string) $adminMember->id)
            ->has('reservations.data', 1)
            ->where('reservations.data.0.team_member_id', $adminMember->id)
        );

    $this->actingAs($adminUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index', ['scope' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('filters.scope', 'all')
            ->has('reservations.data', 2)
        );
});

it('allows assigned team members to update only their reservation status', function () {
    $owner = createOwnerWithReservationsEnabled();
    $assignedMember = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);
    $otherMember = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    $startsAt = Carbon::now('UTC')->subHours(4);
    $assignedReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $assignedMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    $otherReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $otherMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(2),
        'ends_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $assignedUser = $assignedMember->user()->firstOrFail();

    $this->actingAs($assignedUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $assignedReservation), [
            'status' => Reservation::STATUS_CONFIRMED,
        ])
        ->assertOk();

    $this->assertDatabaseHas('reservations', [
        'id' => $assignedReservation->id,
        'status' => Reservation::STATUS_CONFIRMED,
    ]);

    $this->actingAs($assignedUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $otherReservation), [
            'status' => Reservation::STATUS_CONFIRMED,
        ])
        ->assertForbidden();

    $listPayload = $this->actingAs($assignedUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.index'))
        ->assertOk()
        ->assertJsonPath('reservations.total', 1)
        ->json('reservations.data.0');

    $this->assertTrue($listPayload['permissions']['can_view']);
    $this->assertFalse($listPayload['permissions']['can_edit']);
    $this->assertFalse($listPayload['permissions']['can_delete']);
    $this->assertTrue($listPayload['permissions']['can_update_status']);
    $this->assertArrayNotHasKey('internal_notes', $listPayload);
    $this->assertArrayNotHasKey('client_notes', $listPayload);
});

it('allows reservation-managers to access reservations settings and all reservations without jobs/tasks edit permissions', function () {
    $owner = createOwnerWithReservationsEnabled();
    $reservationManager = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['reservations.manage'],
    ]);

    $startsAt = Carbon::now('UTC')->addDay()->setTime(10, 0);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $reservationManager->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $managerUser = $reservationManager->user()->firstOrFail();

    $this->actingAs($managerUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('settings.reservations.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Reservations')
        );

    $this->actingAs($managerUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index', ['scope' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('filters.scope', 'all')
            ->where('access.can_create_customer', false)
            ->has('reservations.data', 1)
        );

    $this->actingAs($managerUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('customer.quick.store'), [
            'client_type' => 'individual',
            'first_name' => 'Blocked',
            'last_name' => 'Creator',
            'email' => 'blocked.reservation.creator@example.com',
            'portal_access' => false,
        ])
        ->assertForbidden();

    $this->assertDatabaseMissing('customers', [
        'user_id' => $owner->id,
        'email' => 'blocked.reservation.creator@example.com',
    ]);
});

it('does not grant reservation management from jobs/tasks permissions alone', function () {
    $owner = createOwnerWithReservationsEnabled();
    $jobsTasksEditor = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['jobs.edit', 'tasks.edit'],
    ]);
    $otherMember = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['jobs.edit', 'tasks.edit'],
    ]);

    $startsAt = Carbon::now('UTC')->addDay()->setTime(10, 0);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $jobsTasksEditor->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);
    Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $otherMember->id,
        'status' => Reservation::STATUS_PENDING,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt->copy()->addHours(2),
        'ends_at' => $startsAt->copy()->addHours(3),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    $memberUser = $jobsTasksEditor->user()->firstOrFail();

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index', ['scope' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('access.can_view_all', false)
            ->where('access.can_manage', false)
            ->where('filters.scope', 'mine')
            ->has('reservations.data', 1)
            ->where('reservations.data.0.team_member_id', $jobsTasksEditor->id)
        );

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('settings.reservations.edit'))
        ->assertForbidden();
});

it('keeps waitlist entries scoped to the assigned member for reservation staff', function () {
    $owner = createOwnerWithReservationsEnabled();
    $assignedMember = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['reservations.view', 'update_reservations'],
    ]);
    $otherMember = createTeamMemberForAccount($owner, [
        'role' => 'member',
        'permissions' => ['reservations.view', 'update_reservations'],
    ]);

    $requestedAt = Carbon::now('UTC')->addDay()->setTime(14, 0);
    $ownWaitlist = ReservationWaitlist::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $assignedMember->id,
        'status' => ReservationWaitlist::STATUS_PENDING,
        'requested_start_at' => $requestedAt,
        'requested_end_at' => $requestedAt->copy()->addHour(),
        'duration_minutes' => 60,
    ]);
    $otherWaitlist = ReservationWaitlist::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $otherMember->id,
        'status' => ReservationWaitlist::STATUS_PENDING,
        'requested_start_at' => $requestedAt->copy()->addHours(2),
        'requested_end_at' => $requestedAt->copy()->addHours(3),
        'duration_minutes' => 60,
    ]);

    $memberUser = $assignedMember->user()->firstOrFail();

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index', ['scope' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reservation/Index')
            ->where('access.can_view_all', false)
            ->where('access.can_manage', false)
            ->where('filters.scope', 'mine')
            ->has('waitlists', 1)
            ->where('waitlists.0.id', $ownWaitlist->id)
        );

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.waitlist.status', $otherWaitlist), [
            'status' => ReservationWaitlist::STATUS_RELEASED,
        ])
        ->assertForbidden();
});

it('stores business preset fields on reservation settings update', function () {
    $owner = createOwnerWithReservationsEnabled();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->putJson(route('settings.reservations.update'), [
            'account_settings' => [
                'business_preset' => 'salon',
                'late_release_minutes' => 10,
                'waitlist_enabled' => true,
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'global_pull',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_pre_call_threshold' => 2,
                'queue_no_show_on_grace_expiry' => true,
                'deposit_required' => true,
                'deposit_amount' => 30,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 15,
                'buffer_minutes' => 8,
                'slot_interval_minutes' => 20,
                'min_notice_minutes' => 45,
                'max_advance_days' => 45,
                'cancellation_cutoff_hours' => 18,
                'allow_client_cancel' => true,
                'allow_client_reschedule' => true,
            ],
        ])
        ->assertOk();

    $this->assertDatabaseHas('reservation_settings', [
        'account_id' => $owner->id,
        'team_member_id' => null,
        'business_preset' => 'salon',
        'late_release_minutes' => 10,
        'waitlist_enabled' => 1,
        'queue_mode_enabled' => 1,
        'queue_assignment_mode' => 'global_pull',
        'queue_dispatch_mode' => 'fifo_with_appointment_priority',
        'queue_grace_minutes' => 5,
        'queue_pre_call_threshold' => 2,
        'queue_no_show_on_grace_expiry' => 1,
        'deposit_required' => 1,
        'deposit_amount' => 30,
        'no_show_fee_enabled' => 1,
        'no_show_fee_amount' => 15,
        'buffer_minutes' => 8,
        'slot_interval_minutes' => 20,
    ]);
});

it('snapshots deposit and no-show policy into reservation metadata at booking', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser] = createClientForAccount($owner, 'Policy Client', 'policy.client@example.com');

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
            'deposit_required' => true,
            'deposit_amount' => 25,
            'no_show_fee_enabled' => true,
            'no_show_fee_amount' => 12,
        ]
    );

    $referenceDate = Carbon::now('UTC')->addDays(3)->setTime(10, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $referenceDate);

    $slotResponse = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('client.reservations.slots', [
            'range_start' => $referenceDate->copy()->startOfWeek()->toIso8601String(),
            'range_end' => $referenceDate->copy()->endOfWeek()->toIso8601String(),
            'team_member_id' => $teamMember->id,
            'duration_minutes' => 60,
        ]))
        ->assertOk();

    $slot = collect($slotResponse->json('slots'))->first();
    expect($slot)->not->toBeNull();

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.store'), [
            'team_member_id' => $teamMember->id,
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'contact_name' => 'Policy Client',
            'contact_email' => 'policy.client@example.com',
            'contact_phone' => '+15550003333',
        ])
        ->assertCreated();

    $reservation = Reservation::query()->latest('id')->firstOrFail();
    $metadata = (array) ($reservation->metadata ?? []);

    expect((bool) data_get($metadata, 'payment_policy.deposit_required'))->toBeTrue();
    expect((float) data_get($metadata, 'payment_policy.deposit_amount'))->toBe(25.0);
    expect((bool) data_get($metadata, 'payment_policy.no_show_fee_enabled'))->toBeTrue();
    expect((float) data_get($metadata, 'payment_policy.no_show_fee_amount'))->toBe(12.0);
    expect((string) data_get($metadata, 'payment_state.deposit_status'))->toBe('required');
    expect((string) data_get($metadata, 'payment_state.no_show_fee_status'))->toBe('not_applied');
});

it('flags no-show fee outcome in reservation metadata when status is marked no_show', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);

    $startsAt = Carbon::now('UTC')->subHours(3);
    $reservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'metadata' => [
            'payment_policy' => [
                'deposit_required' => true,
                'deposit_amount' => 20,
                'no_show_fee_enabled' => true,
                'no_show_fee_amount' => 10,
            ],
            'payment_state' => [
                'deposit_status' => 'required',
                'no_show_fee_status' => 'not_applied',
            ],
        ],
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.status', $reservation), [
            'status' => Reservation::STATUS_NO_SHOW,
        ])
        ->assertOk();

    $updated = Reservation::query()->findOrFail($reservation->id);
    $metadata = (array) ($updated->metadata ?? []);

    expect((string) data_get($metadata, 'payment_state.deposit_status'))->toBe('forfeited');
    expect((string) data_get($metadata, 'payment_state.no_show_fee_status'))->toBe('charge_required');
    expect((float) data_get($metadata, 'payment_state.no_show_fee_amount'))->toBe(10.0);
});

it('stores reservation resources from reservation settings update', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->putJson(route('settings.reservations.update'), [
            'resources' => [
                [
                    'team_member_id' => $member->id,
                    'name' => 'Table 1',
                    'type' => 'table',
                    'capacity' => 4,
                    'is_active' => true,
                ],
            ],
        ])
        ->assertOk();

    $this->assertDatabaseHas('reservation_resources', [
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'name' => 'Table 1',
        'type' => 'table',
        'capacity' => 4,
        'is_active' => 1,
    ]);
});

it('resolves restaurant defaults from company sector when no account settings exist', function () {
    $owner = createOwnerWithReservationsEnabled();
    $owner->update([
        'company_sector' => 'restaurant',
    ]);

    $resolved = app(ReservationAvailabilityService::class)->resolveSettings($owner->id);

    expect($resolved['business_preset'])->toBe('restaurant');
    expect($resolved['buffer_minutes'])->toBe(15);
    expect($resolved['slot_interval_minutes'])->toBe(15);
    expect($resolved['waitlist_enabled'])->toBeTrue();
    expect($resolved['queue_mode_enabled'])->toBeFalse();
    expect($resolved['queue_assignment_mode'])->toBe('global_pull');
});

it('allows a client to create and cancel a waitlist entry when enabled', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Waitlist Client', 'waitlist.client@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 0,
            'waitlist_enabled' => true,
        ]
    );

    $response = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.waitlist.store'), [
            'team_member_id' => $teamMember->id,
            'requested_start_at' => now('UTC')->addDays(3)->startOfDay()->toIso8601String(),
            'requested_end_at' => now('UTC')->addDays(5)->endOfDay()->toIso8601String(),
            'duration_minutes' => 60,
            'party_size' => 2,
            'notes' => 'Any afternoon slot.',
        ])
        ->assertCreated();

    $waitlistId = (int) $response->json('waitlist.id');
    expect($waitlistId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('reservation_waitlists', [
        'id' => $waitlistId,
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'status' => ReservationWaitlist::STATUS_PENDING,
        'party_size' => 2,
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('client.reservations.waitlist.cancel', $waitlistId))
        ->assertOk();

    $this->assertDatabaseHas('reservation_waitlists', [
        'id' => $waitlistId,
        'status' => ReservationWaitlist::STATUS_CANCELLED,
    ]);
});

it('blocks client waitlist creation when waitlist is disabled', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser] = createClientForAccount($owner, 'Disabled Waitlist Client', 'disabled.waitlist@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'service_general',
            'buffer_minutes' => 10,
            'slot_interval_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_advance_days' => 90,
            'cancellation_cutoff_hours' => 12,
            'allow_client_cancel' => true,
            'allow_client_reschedule' => true,
            'late_release_minutes' => 0,
            'waitlist_enabled' => false,
        ]
    );

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.waitlist.store'), [
            'team_member_id' => $teamMember->id,
            'requested_start_at' => now('UTC')->addDays(3)->startOfDay()->toIso8601String(),
            'requested_end_at' => now('UTC')->addDays(5)->endOfDay()->toIso8601String(),
            'duration_minutes' => 60,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('waitlist');
});

it('filters slots by resource capacity when resource constraints are requested', function () {
    $owner = createOwnerWithReservationsEnabled();
    $memberA = createTeamMemberForAccount($owner, [
        'user_name' => 'Member A',
        'user_email' => 'member.a@example.com',
    ]);
    $memberB = createTeamMemberForAccount($owner, [
        'user_name' => 'Member B',
        'user_email' => 'member.b@example.com',
    ]);
    [$clientUser] = createClientForAccount($owner, 'Capacity Client', 'capacity.client@example.com');

    $referenceDate = Carbon::now('UTC')->addDays(4)->setTime(10, 0, 0);
    addWeeklyAvailability($owner, $memberA, $referenceDate);
    addWeeklyAvailability($owner, $memberB, $referenceDate);

    $sharedResource = ReservationResource::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'name' => 'Table 1',
        'type' => 'table',
        'capacity' => 4,
        'is_active' => true,
    ]);

    $blockingReservation = Reservation::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'status' => Reservation::STATUS_CONFIRMED,
        'source' => Reservation::SOURCE_STAFF,
        'timezone' => 'UTC',
        'starts_at' => $referenceDate->copy(),
        'ends_at' => $referenceDate->copy()->addHour(),
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
    ]);

    ReservationResourceAllocation::query()->create([
        'account_id' => $owner->id,
        'reservation_id' => $blockingReservation->id,
        'reservation_resource_id' => $sharedResource->id,
        'quantity' => 4,
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('client.reservations.slots', [
            'range_start' => $referenceDate->copy()->toIso8601String(),
            'range_end' => $referenceDate->copy()->addHour()->toIso8601String(),
            'team_member_id' => $memberA->id,
            'duration_minutes' => 60,
            'party_size' => 2,
            'resource_filters' => [
                'resource_ids' => [$sharedResource->id],
            ],
        ]))
        ->assertOk()
        ->assertJsonCount(0, 'slots');

    $availableResource = ReservationResource::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'name' => 'Table 2',
        'type' => 'table',
        'capacity' => 4,
        'is_active' => true,
    ]);

    $response = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('client.reservations.slots', [
            'range_start' => $referenceDate->copy()->toIso8601String(),
            'range_end' => $referenceDate->copy()->addHour()->toIso8601String(),
            'team_member_id' => $memberA->id,
            'duration_minutes' => 60,
            'party_size' => 2,
            'resource_filters' => [
                'types' => ['table'],
            ],
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'slots');

    expect((int) $response->json('slots.0.resource_id'))->toBe($availableResource->id);
});

it('computes queue position and eta per team member lane', function () {
    $owner = createOwnerWithReservationsEnabled();
    $memberA = createTeamMemberForAccount($owner, [
        'user_name' => 'Lane A Member',
        'user_email' => 'lane.a.member@example.com',
    ]);
    $memberB = createTeamMemberForAccount($owner, [
        'user_name' => 'Lane B Member',
        'user_email' => 'lane.b.member@example.com',
    ]);
    createActiveChairForMember($owner, $memberA, ['name' => 'Chair A']);
    createActiveChairForMember($owner, $memberB, ['name' => 'Chair B']);
    checkInTeamMember($owner, $memberA);
    checkInTeamMember($owner, $memberB);

    ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberA->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'A-SERVICE',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 20,
        'started_at' => now('UTC')->subMinutes(5),
    ]);

    ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'B-SERVICE',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 10,
        'started_at' => now('UTC')->subMinutes(4),
    ]);

    $a1 = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberA->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'A-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC')->subMinutes(3),
    ]);

    $b1 = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'B-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 25,
        'checked_in_at' => now('UTC')->subMinutes(2),
    ]);

    $a2 = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberA->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'A-002',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 40,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $b2 = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'B-002',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 35,
        'checked_in_at' => now('UTC'),
    ]);

    app(ReservationQueueService::class)->refreshMetrics($owner->id, [
        'business_preset' => 'salon',
        'queue_mode_enabled' => true,
        'queue_dispatch_mode' => ReservationQueueService::DISPATCH_MODE_FIFO_WITH_APPOINTMENT_PRIORITY,
        'buffer_minutes' => 0,
        'queue_no_show_on_grace_expiry' => false,
    ]);

    $a1 = $a1->fresh();
    $b1 = $b1->fresh();
    $a2 = $a2->fresh();
    $b2 = $b2->fresh();

    expect((int) ($a1?->position ?? 0))->toBe(1);
    expect((int) ($b1?->position ?? 0))->toBe(1);
    expect((int) ($a2?->position ?? 0))->toBe(2);
    expect((int) ($b2?->position ?? 0))->toBe(2);

    expect((int) ($a1?->eta_minutes ?? -1))->toBe(20);
    expect((int) ($b1?->eta_minutes ?? -1))->toBe(10);
    expect((int) ($a2?->eta_minutes ?? -1))->toBe(50);
    expect((int) ($b2?->eta_minutes ?? -1))->toBe(35);
});

it('allows a client to create and manage a queue ticket when queue mode is enabled', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Client', 'queue.client@example.com');

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
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    createActiveChairForMember($owner, $teamMember);
    checkInTeamMember($owner, $teamMember);

    $createResponse = $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.tickets.store'), [
            'team_member_id' => $teamMember->id,
            'estimated_duration_minutes' => 45,
            'notes' => 'Walk-in ticket from tests',
        ])
        ->assertCreated();

    $ticketId = (int) ($createResponse->json('queue_item_id') ?? 0);
    expect($ticketId)->toBeGreaterThan(0);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticketId,
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('client.reservations.tickets.still-here', $ticketId))
        ->assertOk();

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('client.reservations.tickets.cancel', $ticketId))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticketId,
        'status' => ReservationQueueItem::STATUS_LEFT,
    ]);
});

it('blocks creating a second active queue ticket for the same client', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser] = createClientForAccount($owner, 'Queue Duplicate Client', 'queue.duplicate.client@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    createActiveChairForMember($owner, $teamMember);
    checkInTeamMember($owner, $teamMember);

    $payload = [
        'team_member_id' => $teamMember->id,
        'estimated_duration_minutes' => 45,
        'notes' => 'First active ticket',
    ];

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.tickets.store'), $payload)
        ->assertCreated();

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.tickets.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('queue');
});

it('blocks creating a queue ticket when client already has a nearby active reservation', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Nearby Reservation', 'queue.nearby.reservation@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
        ]
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

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.tickets.store'), [
            'team_member_id' => $teamMember->id,
            'estimated_duration_minutes' => 30,
            'notes' => 'Should be blocked by nearby reservation',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('queue');
});

it('blocks creating a reservation when client has an active queue ticket', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Reservation With Active Ticket', 'reservation.active.ticket@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    $startsAt = now('UTC')->addDays(3)->setTime(11, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $startsAt);

    ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-ACTIVE-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.store'), [
            'team_member_id' => $teamMember->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'contact_name' => 'Reservation Active Ticket',
            'contact_email' => 'reservation.active.ticket@example.com',
            'contact_phone' => '+15550004444',
            'client_notes' => 'Must be blocked by active queue ticket.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reservation');
});

it('allows reservation creation for non-salon presets even with legacy active queue tickets', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Legacy Queue Client', 'legacy.queue.client@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'restaurant',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    $startsAt = now('UTC')->addDays(2)->setTime(14, 0, 0);
    addWeeklyAvailability($owner, $teamMember, $startsAt);

    ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-LEGACY-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.store'), [
            'team_member_id' => $teamMember->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $startsAt->copy()->addHour()->toIso8601String(),
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'contact_name' => 'Legacy Queue Client',
            'contact_email' => 'legacy.queue.client@example.com',
            'contact_phone' => '+15550006666',
            'client_notes' => 'Reservation should stay available outside salon queue mode.',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('reservations', [
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'source' => Reservation::SOURCE_CLIENT,
    ]);
});

it('allows staff to progress queue items through operational states', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Ops Client', 'queue.ops.client@example.com');

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
            'queue_no_show_on_grace_expiry' => true,
        ]
    );

    createActiveChairForMember($owner, $member);
    checkInTeamMember($owner, $member);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-TEST-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $ticket))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CALLED,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.start', $ticket))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.done', $ticket))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_DONE,
    ]);
});

it('releases staff availability after a queue grace expiry so the ticket can be recalled', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Grace Client', 'queue.grace.client@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    $attendance = checkInTeamMember($owner, $member);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-GRACE-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $ticket))
        ->assertOk();

    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);

    $ticket->update(['call_expires_at' => now('UTC')->subMinute()]);

    $settings = app(ReservationAvailabilityService::class)->resolveSettings($owner->id, null);
    app(ReservationQueueService::class)->refreshMetrics($owner->id, $settings);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_SKIPPED,
    ]);
    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_AVAILABLE);

    // Simulate an item skipped by the previous grace-expiry implementation.
    $attendance->update(['current_status' => TeamMemberAttendance::STATUS_BUSY]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $ticket))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CALLED,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.skip', $ticket))
        ->assertOk();

    $attendance->update(['current_status' => TeamMemberAttendance::STATUS_BUSY]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.done', $ticket))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_DONE,
    ]);
    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_AVAILABLE);
});

it('requires confirmation before pre-calling or calling a stale busy team member, then continues the requested action', function (string $routeName, string $expectedStatus) {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    $attendance = checkInTeamMember($owner, $member, TeamMemberAttendance::STATUS_BUSY);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'STALE-BUSY-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route($routeName, $ticket))
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_team_member_availability_confirmation_required')
        ->assertJsonPath('availability_confirmation.team_member_id', $member->id);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);
    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route($routeName, $ticket), [
            'confirm_team_member_available' => true,
        ])
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => $expectedStatus,
    ]);

    // The confirmation makes the stale attendance assignable first; the successful
    // pre-call/call then correctly marks it busy again for this active ticket.
    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);
})->with([
    'pre-call' => ['reservation.queue.pre-call', ReservationQueueItem::STATUS_PRE_CALLED],
    'call' => ['reservation.queue.call', ReservationQueueItem::STATUS_CALLED],
]);

it('requires confirmation before call-next uses a stale busy team member, then calls the next ticket', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    $attendance = checkInTeamMember($owner, $member, TeamMemberAttendance::STATUS_BUSY);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'STALE-NEXT-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $payload = ['team_member_id' => $member->id];

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'), $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_team_member_availability_confirmation_required')
        ->assertJsonPath('availability_confirmation.team_member_id', $member->id);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'), [
            ...$payload,
            'confirm_team_member_available' => true,
        ])
        ->assertOk()
        ->assertJsonPath('queue_item.id', $ticket->id)
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_CALLED);

    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);
});

it('offers stale-busy confirmation for a global call-next without an employee preselected', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    checkInTeamMember($owner, $member, TeamMemberAttendance::STATUS_BUSY);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'STALE-GLOBAL-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_team_member_availability_confirmation_required')
        ->assertJsonPath('availability_confirmation.team_member_id', $member->id);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'), [
            'team_member_id' => $member->id,
            'confirm_team_member_available' => true,
        ])
        ->assertOk()
        ->assertJsonPath('queue_item.id', $ticket->id)
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_CALLED);
});

it('offers stale-busy confirmation before pre-calling an unassigned global-pull ticket', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    checkInTeamMember($owner, $member, TeamMemberAttendance::STATUS_BUSY);
    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'STALE-PRECALL-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.pre-call', $ticket))
        ->assertStatus(409)
        ->assertJsonPath('code', 'queue_team_member_availability_confirmation_required')
        ->assertJsonPath('availability_confirmation.team_member_id', $member->id);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.pre-call', $ticket), [
            'team_member_id' => $member->id,
            'confirm_team_member_available' => true,
        ])
        ->assertOk()
        ->assertJsonPath('queue_item.status', ReservationQueueItem::STATUS_PRE_CALLED);
});

it('does not offer stale-busy confirmation when the team member already has an active queue assignment', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    $attendance = checkInTeamMember($owner, $member, TeamMemberAttendance::STATUS_BUSY);
    $activeTicket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'ACTIVE-BUSY-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC')->subMinutes(15),
        'started_at' => now('UTC')->subMinutes(5),
    ]);
    $waitingTicket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'WAITING-BUSY-'.Str::upper(Str::random(6)),
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $waitingTicket), [
            'confirm_team_member_available' => true,
        ])
        ->assertStatus(422)
        ->assertJsonMissing([
            'code' => 'queue_team_member_availability_confirmation_required',
        ]);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $activeTicket->id,
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
    ]);
    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $waitingTicket->id,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);
    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);
});

it('calls next queue item in the staff lane when assignment mode is per_staff', function () {
    $owner = createOwnerWithReservationsEnabled();
    $memberA = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);
    $memberB = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $memberA, ['name' => 'Chair A']);
    createActiveChairForMember($owner, $memberB, ['name' => 'Chair B']);
    checkInTeamMember($owner, $memberA);
    checkInTeamMember($owner, $memberB);

    $ticketA = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberA->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'A-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 25,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $ticketB = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'B-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 25,
        'checked_in_at' => now('UTC'),
    ]);

    $memberAUser = $memberA->user()->firstOrFail();

    $response = $this->actingAs($memberAUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertOk();

    expect((int) ($response->json('queue_item.id') ?? 0))->toBe((int) $ticketA->id);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticketA->id,
        'status' => ReservationQueueItem::STATUS_CALLED,
    ]);
    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticketB->id,
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
    ]);
});

it('syncs queue staff availability with presence clock-in and clock-out', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'per_staff',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'P-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $memberUser = $member->user()->firstOrFail();

    TeamMemberAttendance::query()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'team_member_id' => $member->id,
        'clock_in_at' => now('UTC')->subHour(),
        'clock_out_at' => now('UTC')->subMinutes(5),
        'method' => 'manual',
        'clock_out_method' => 'manual',
        'current_status' => TeamMemberAttendance::STATUS_OFFLINE,
    ]);

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertStatus(422);

    $blockedScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect((string) ($blockedScreen->json('queue.chairs.0.state') ?? ''))->toBe('offline');

    TeamMemberAttendance::query()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'team_member_id' => $member->id,
        'clock_in_at' => now('UTC')->subMinute(),
        'clock_out_at' => null,
        'method' => 'manual',
        'current_status' => TeamMemberAttendance::STATUS_AVAILABLE,
    ]);

    $readyScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect((string) ($readyScreen->json('queue.chairs.0.state') ?? ''))->toBe('available_ready');

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertOk();

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CALLED,
        'team_member_id' => $member->id,
    ]);
});

it('exposes only operational chairs and blocks queue assignment when the assigned member is unavailable', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member, ['name' => 'Chair 1', 'is_active' => false]);
    ReservationResource::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'name' => 'Chair 2',
        'type' => ReservationResource::TYPE_CHAIR,
        'capacity' => 1,
        'is_active' => true,
    ]);
    createActiveChairForMember($owner, $member, ['name' => 'Chair 3']);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'CHAIR-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $memberUser = $member->user()->firstOrFail();

    $offlineScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect($offlineScreen->json('queue.chairs'))->toHaveCount(1);
    expect($offlineScreen->json('queue.chairs.0.chair_label'))->toBe('Chair 3');
    expect($offlineScreen->json('queue.chairs.0.state'))->toBe('offline');

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertStatus(422);

    $attendance = checkInTeamMember($owner, $member);

    $readyScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect($readyScreen->json('queue.chairs.0.state'))->toBe('available_ready');

    $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertOk();

    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_BUSY);

    $calledScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect($calledScreen->json('queue.chairs.0.state'))->toBe('called');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.start', $ticket))
        ->assertOk();

    $busyScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect($busyScreen->json('queue.chairs.0.state'))->toBe('busy');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.done', $ticket))
        ->assertOk();

    expect(TeamMemberAttendance::query()->find($attendance->id)?->current_status)->toBe(TeamMemberAttendance::STATUS_AVAILABLE);

    TeamMemberAttendance::query()->find($attendance->id)?->update([
        'clock_out_at' => now('UTC'),
        'current_status' => TeamMemberAttendance::STATUS_OFFLINE,
    ]);

    $checkedOutScreen = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    expect($checkedOutScreen->json('queue.chairs.0.state'))->toBe('offline');
});

it('lets a team member pull next from global queue mode and assigns the ticket', function () {
    $owner = createOwnerWithReservationsEnabled();
    $member = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    createActiveChairForMember($owner, $member);
    checkInTeamMember($owner, $member);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'G-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC'),
    ]);

    $memberUser = $member->user()->firstOrFail();

    $response = $this->actingAs($memberUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertOk();

    expect((int) ($response->json('queue_item.id') ?? 0))->toBe((int) $ticket->id);
    expect((int) ($response->json('queue_item.team_member_id') ?? 0))->toBe((int) $member->id);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_CALLED,
        'team_member_id' => $member->id,
    ]);
});

it('limits non-manager queue visibility to own lane and unassigned walk-in tickets', function () {
    $owner = createOwnerWithReservationsEnabled();
    $memberA = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);
    $memberB = createTeamMemberForAccount($owner, [
        'role' => 'employee',
        'permissions' => [],
    ]);

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'salon',
            'waitlist_enabled' => true,
            'queue_mode_enabled' => true,
            'queue_assignment_mode' => 'global_pull',
            'queue_dispatch_mode' => 'fifo_with_appointment_priority',
            'queue_grace_minutes' => 5,
            'queue_pre_call_threshold' => 2,
            'queue_no_show_on_grace_expiry' => false,
        ]
    );

    $ownedTicket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberA->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'QA-OWN-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC')->subMinutes(2),
    ]);

    $otherAppointment = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $memberB->id,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'source' => Reservation::SOURCE_STAFF,
        'queue_number' => 'QA-APPT-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC')->subMinutes(1),
    ]);

    $unassignedAppointment = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
        'source' => Reservation::SOURCE_STAFF,
        'queue_number' => 'QA-APPT-NULL',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC'),
    ]);

    $unassignedTicket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => null,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'QA-TICKET-NULL',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC'),
    ]);

    $memberAUser = $memberA->user()->firstOrFail();

    $response = $this->actingAs($memberAUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    $visibleIds = collect($response->json('queue.items') ?? [])
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    expect($visibleIds)->toContain((int) $ownedTicket->id);
    expect($visibleIds)->toContain((int) $unassignedTicket->id);
    expect($visibleIds)->not->toContain((int) $otherAppointment->id);
    expect($visibleIds)->not->toContain((int) $unassignedAppointment->id);
});

it('sends queue notifications for pre-call, call, and grace expiry', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Notify Client', 'queue.notify.client@example.com');

    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => true,
                'in_app' => true,
                'notify_on_queue_pre_call' => true,
                'notify_on_queue_called' => true,
                'notify_on_queue_grace_expired' => true,
            ],
        ],
    ]);

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

    createActiveChairForMember($owner, $teamMember);
    checkInTeamMember($owner, $teamMember);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-TEST-NOTIFY',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.pre-call', $ticket))
        ->assertOk();

    Notification::assertSentTo($clientUser, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'pre-call');
    });

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $ticket))
        ->assertOk();

    Notification::assertSentTo($clientUser, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'queue called');
    });

    ReservationQueueItem::query()
        ->whereKey($ticket->id)
        ->update([
            'status' => ReservationQueueItem::STATUS_CALLED,
            'call_expires_at' => now('UTC')->subMinute(),
        ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.index'))
        ->assertOk();

    Notification::assertSentTo($clientUser, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'grace expired');
    });
});

it('sends queue sms notifications when sms channel is enabled', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Sms Client', 'queue.sms.client@example.com');

    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => false,
                'in_app' => false,
                'sms' => true,
                'notify_on_queue_pre_call' => false,
                'notify_on_queue_called' => true,
                'notify_on_queue_grace_expired' => false,
            ],
        ],
    ]);

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

    createActiveChairForMember($owner, $teamMember);
    checkInTeamMember($owner, $teamMember);

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-TEST-SMS',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 30,
        'checked_in_at' => now('UTC'),
    ]);

    $smsMock = \Mockery::mock(SmsNotificationService::class);
    $smsMock
        ->shouldReceive('send')
        ->once()
        ->with(
            '+15550001111',
            \Mockery::on(fn (string $message) => str_contains(strtolower($message), 'turn'))
        )
        ->andReturn(true);
    $this->app->instance(SmsNotificationService::class, $smsMock);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->patchJson(route('reservation.queue.call', $ticket))
        ->assertOk();

    Notification::assertNothingSent();
});

it('sends one queue eta sms alert when eta enters the 10 minute window', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Eta Client', 'queue.eta.client@example.com');

    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => false,
                'in_app' => false,
                'sms' => true,
                'notify_on_queue_pre_call' => false,
                'notify_on_queue_called' => false,
                'notify_on_queue_grace_expired' => false,
                'notify_on_queue_ticket_created' => false,
                'notify_on_queue_eta_10m' => true,
                'notify_on_queue_status_changed' => false,
            ],
        ],
    ]);

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

    $inService = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'staff',
        'queue_number' => 'ETA-001',
        'status' => ReservationQueueItem::STATUS_IN_SERVICE,
        'estimated_duration_minutes' => 20,
        'started_at' => now('UTC')->subMinutes(3),
    ]);

    $waiting = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'ETA-002',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $smsMock = \Mockery::mock(SmsNotificationService::class);
    $smsMock
        ->shouldReceive('send')
        ->once()
        ->with(
            '+15550001111',
            \Mockery::on(fn (string $message) => str_contains(strtolower($message), 'about 5 min'))
        )
        ->andReturn(true);
    $this->app->instance(SmsNotificationService::class, $smsMock);

    $availabilityService = app(ReservationAvailabilityService::class);
    $queueService = app(ReservationQueueService::class);
    $settings = $availabilityService->resolveSettings($owner->id, null);

    $queueService->refreshMetrics($owner->id, $settings);
    $inService->update(['estimated_duration_minutes' => 5]);
    $queueService->refreshMetrics($owner->id, $settings);
    $queueService->refreshMetrics($owner->id, $settings);

    $waiting->refresh();
    expect((int) ($waiting->eta_minutes ?? 0))->toBe(5);
    Notification::assertNothingSent();
});

it('sends queue missed-turn sms when grace expires', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Missed Client', 'queue.missed.client@example.com');

    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => false,
                'in_app' => false,
                'sms' => true,
                'notify_on_queue_pre_call' => false,
                'notify_on_queue_called' => false,
                'notify_on_queue_grace_expired' => true,
                'notify_on_queue_ticket_created' => false,
                'notify_on_queue_eta_10m' => false,
                'notify_on_queue_status_changed' => false,
            ],
        ],
    ]);

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

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'MISS-001',
        'status' => ReservationQueueItem::STATUS_CALLED,
        'estimated_duration_minutes' => 30,
        'called_at' => now('UTC')->subMinutes(7),
        'call_expires_at' => now('UTC')->subMinute(),
    ]);

    $smsMock = \Mockery::mock(SmsNotificationService::class);
    $smsMock
        ->shouldReceive('send')
        ->once()
        ->with(
            '+15550001111',
            \Mockery::on(fn (string $message) => str_contains(strtolower($message), 'turn was missed'))
        )
        ->andReturn(true);
    $this->app->instance(SmsNotificationService::class, $smsMock);

    $availabilityService = app(ReservationAvailabilityService::class);
    $queueService = app(ReservationQueueService::class);
    $settings = $availabilityService->resolveSettings($owner->id, null);

    $queueService->refreshMetrics($owner->id, $settings);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_SKIPPED,
    ]);
    Notification::assertNothingSent();
});

it('sends queue status change sms when status-change notifications are enabled', function () {
    Notification::fake();

    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Status Client', 'queue.status.client@example.com');

    $owner->update([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => false,
                'in_app' => false,
                'sms' => true,
                'notify_on_queue_pre_call' => false,
                'notify_on_queue_called' => false,
                'notify_on_queue_grace_expired' => false,
                'notify_on_queue_ticket_created' => false,
                'notify_on_queue_eta_10m' => false,
                'notify_on_queue_status_changed' => true,
            ],
        ],
    ]);

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

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'STATUS-001',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 25,
        'checked_in_at' => now('UTC')->subMinute(),
    ]);

    $smsMock = \Mockery::mock(SmsNotificationService::class);
    $smsMock
        ->shouldReceive('send')
        ->once()
        ->with(
            '+15550001111',
            \Mockery::on(fn (string $message) => str_contains(strtolower($message), 'status changed'))
        )
        ->andReturn(true);
    $this->app->instance(SmsNotificationService::class, $smsMock);

    $availabilityService = app(ReservationAvailabilityService::class);
    $queueService = app(ReservationQueueService::class);
    $settings = $availabilityService->resolveSettings($owner->id, null);

    $queueService->transition($ticket, 'skip', $owner, $settings);

    $this->assertDatabaseHas('reservation_queue_items', [
        'id' => $ticket->id,
        'status' => ReservationQueueItem::STATUS_SKIPPED,
    ]);
    Notification::assertNothingSent();
});

it('returns queue screen payload and supports anonymize toggle', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Élodie Ångström', 'queue.screen@example.com');

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

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
        'team_member_id' => $teamMember->id,
        'item_type' => ReservationQueueItem::TYPE_TICKET,
        'source' => 'client',
        'queue_number' => 'T-TEST-SCREEN',
        'status' => ReservationQueueItem::STATUS_CHECKED_IN,
        'estimated_duration_minutes' => 20,
        'checked_in_at' => now('UTC'),
    ]);
    $inertiaVersion = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(
        \Illuminate\Http\Request::create(route('reservation.screen'))
    );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $inertiaVersion,
        ])
        ->get(route('reservation.screen'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertJsonPath('component', 'Reservation/Screen')
        ->assertJsonPath('props.queue.waiting.0.display_client_name', 'É Å.');

    $anonymized = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 1]))
        ->assertOk();

    $plain = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.screen.data', ['anonymize' => 0]))
        ->assertOk();

    $plainName = (string) ($plain->json('queue.waiting.0.display_client_name') ?? '');
    $anonymizedName = (string) ($anonymized->json('queue.waiting.0.display_client_name') ?? '');
    $realName = (string) ($customer->company_name
        ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
        ?: ($customer->email ?? ''));

    expect((int) ($plain->json('queue.waiting.0.id') ?? 0))->toBe((int) $ticket->id);
    expect($plainName)->not->toBe('');
    expect($anonymizedName)->toBe('É Å.');
    expect($plainName)->toBe($realName);
    expect($anonymizedName)->not->toBe($plainName);
});

it('hides past not-arrived appointments from the live queue without deleting history', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 15:00:00', 'UTC'));

    try {
        $owner = createOwnerWithReservationsEnabled();
        $teamMember = createTeamMemberForAccount($owner);

        ReservationSetting::query()->updateOrCreate(
            [
                'account_id' => $owner->id,
                'team_member_id' => null,
            ],
            [
                'business_preset' => 'salon',
                'queue_mode_enabled' => true,
                'queue_assignment_mode' => 'global_pull',
                'queue_dispatch_mode' => 'fifo_with_appointment_priority',
                'queue_grace_minutes' => 5,
                'queue_no_show_on_grace_expiry' => false,
            ]
        );

        $createAppointment = function (
            string $reference,
            Carbon $startsAt,
            Carbon $endsAt,
            string $queueStatus
        ) use ($owner, $teamMember): array {
            $reservation = Reservation::query()->create([
                'account_id' => $owner->id,
                'team_member_id' => $teamMember->id,
                'status' => Reservation::STATUS_CONFIRMED,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => 'UTC',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_minutes' => $startsAt->diffInMinutes($endsAt),
                'internal_notes' => $reference,
            ]);

            $queueItem = ReservationQueueItem::query()->create([
                'account_id' => $owner->id,
                'reservation_id' => $reservation->id,
                'team_member_id' => $teamMember->id,
                'item_type' => ReservationQueueItem::TYPE_APPOINTMENT,
                'source' => Reservation::SOURCE_STAFF,
                'queue_number' => $reference,
                'status' => $queueStatus,
                'estimated_duration_minutes' => $startsAt->diffInMinutes($endsAt),
                'checked_in_at' => $queueStatus === ReservationQueueItem::STATUS_NOT_ARRIVED
                    ? null
                    : now('UTC')->subHour(),
                'started_at' => $queueStatus === ReservationQueueItem::STATUS_IN_SERVICE
                    ? now('UTC')->subMinutes(45)
                    : null,
            ]);

            return [$reservation, $queueItem];
        };

        [$pastReservation, $pastNotArrived] = $createAppointment(
            'PAST-NOT-ARRIVED',
            now('UTC')->subHours(2),
            now('UTC')->subHour(),
            ReservationQueueItem::STATUS_NOT_ARRIVED
        );
        [, $upcomingNotArrived] = $createAppointment(
            'UPCOMING-NOT-ARRIVED',
            now('UTC')->addMinutes(30),
            now('UTC')->addMinutes(90),
            ReservationQueueItem::STATUS_NOT_ARRIVED
        );
        [, $pastInService] = $createAppointment(
            'PAST-IN-SERVICE',
            now('UTC')->subHours(2),
            now('UTC')->subHour(),
            ReservationQueueItem::STATUS_IN_SERVICE
        );
        $walkIn = ReservationQueueItem::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $teamMember->id,
            'item_type' => ReservationQueueItem::TYPE_TICKET,
            'source' => Reservation::SOURCE_STAFF,
            'queue_number' => 'WALK-IN-ACTIVE',
            'status' => ReservationQueueItem::STATUS_CHECKED_IN,
            'estimated_duration_minutes' => 20,
            'checked_in_at' => now('UTC')->subMinutes(10),
        ]);

        $queueService = app(ReservationQueueService::class);
        $settings = app(ReservationAvailabilityService::class)->resolveSettings($owner->id, null);
        $board = $queueService->boardForStaff($owner->id, [
            'can_view_all' => true,
            'can_manage' => true,
            'own_team_member_id' => null,
        ], $settings);
        $visibleIds = collect($board['items'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        expect($visibleIds)
            ->not->toContain((int) $pastNotArrived->id)
            ->toContain((int) $upcomingNotArrived->id)
            ->toContain((int) $pastInService->id)
            ->toContain((int) $walkIn->id);
        expect(collect($board['items'])->firstWhere('id', $upcomingNotArrived->id))
            ->toHaveKey('reservation_ends_at');

        $metrics = $queueService->refreshMetrics($owner->id, $settings);
        expect($metrics)
            ->not->toHaveKey($pastNotArrived->id)
            ->toHaveKey($upcomingNotArrived->id)
            ->toHaveKey($pastInService->id)
            ->toHaveKey($walkIn->id);

        $this->assertDatabaseHas('reservation_queue_items', [
            'id' => $pastNotArrived->id,
            'status' => ReservationQueueItem::STATUS_NOT_ARRIVED,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $pastReservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('hides salon queue operations for non-salon presets', function () {
    $owner = createOwnerWithReservationsEnabled();
    [$clientUser] = createClientForAccount($owner, 'Restaurant Client', 'restaurant.client@example.com');

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

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.screen'))
        ->assertNotFound();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.queue.call-next'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('queue');

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('client.reservations.tickets.store'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('queue');
});

it('returns three recent visits and three frequent services for fast rebooking', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-30 12:00:00', 'UTC'));

    try {
        $owner = createOwnerWithReservationsEnabled();
        $activeMember = createTeamMemberForAccount($owner, [
            'user_name' => 'Active Rebooking Stylist',
            'user_email' => 'active.rebooking.stylist@example.com',
        ]);
        $inactiveMember = createTeamMemberForAccount($owner, [
            'user_name' => 'Inactive Rebooking Stylist',
            'user_email' => 'inactive.rebooking.stylist@example.com',
            'is_active' => false,
        ]);
        [, $customer] = createClientForAccount(
            $owner,
            'Rebooking Client',
            'rebooking.client@example.com'
        );
        $category = ProductCategory::query()->create([
            'name' => 'Rebooking services',
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        $createService = fn (string $name, bool $isActive = true): Product => Product::query()->create([
            'name' => $name,
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'currency_code' => 'CAD',
            'unit' => 'service',
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'is_active' => $isActive,
        ]);
        $popularService = $createService('Popular service');
        $inactiveService = $createService('Inactive service', false);
        $thirdService = $createService('Third service');
        $fourthService = $createService('Fourth service');
        $recordVisit = function (
            Product $service,
            TeamMember $member,
            string $startsAt,
            int $durationMinutes,
            string $status = Reservation::STATUS_COMPLETED
        ) use ($owner, $customer): Reservation {
            $start = Carbon::parse($startsAt, 'UTC');

            return Reservation::query()->create([
                'account_id' => $owner->id,
                'team_member_id' => $member->id,
                'client_id' => $customer->id,
                'service_id' => $service->id,
                'status' => $status,
                'source' => Reservation::SOURCE_STAFF,
                'timezone' => 'UTC',
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes($durationMinutes),
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => 0,
                'internal_notes' => 'REBOOKING INTERNAL SECRET',
                'client_notes' => 'REBOOKING CLIENT SECRET',
            ]);
        };

        $recordVisit($popularService, $activeMember, '2026-08-01 09:00:00', 40);
        $recordVisit($popularService, $activeMember, '2026-08-10 09:00:00', 40);
        $thirdLatest = $recordVisit($popularService, $activeMember, '2026-08-18 14:00:00', 50);
        $latest = $recordVisit($popularService, $activeMember, '2026-08-20 14:00:00', 45);
        $recordVisit($inactiveService, $inactiveMember, '2026-08-02 09:00:00', 30);
        $recordVisit($inactiveService, $inactiveMember, '2026-08-11 09:00:00', 30);
        $secondLatest = $recordVisit($inactiveService, $inactiveMember, '2026-08-19 14:00:00', 30);
        $recordVisit($thirdService, $activeMember, '2026-08-03 09:00:00', 75);
        $recordVisit($thirdService, $activeMember, '2026-08-12 09:00:00', 75);
        $recordVisit($fourthService, $activeMember, '2026-08-04 09:00:00', 60);
        $recordVisit(
            $popularService,
            $activeMember,
            '2026-08-21 09:00:00',
            45,
            Reservation::STATUS_NO_SHOW
        );
        $recordVisit(
            $popularService,
            $activeMember,
            '2026-08-22 09:00:00',
            45,
            Reservation::STATUS_CANCELLED
        );
        $recordVisit($popularService, $activeMember, '2026-08-30 11:30:00', 120);

        $response = $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.customer-rebooking', $customer))
            ->assertOk();

        $payload = $response->json();
        expect(array_keys($payload))->toBe(['recent_reservations', 'frequent_services']);
        expect(collect($payload['recent_reservations'])->pluck('id')->all())
            ->toBe([$latest->id, $secondLatest->id, $thirdLatest->id]);
        expect(array_keys($payload['recent_reservations'][0]))
            ->toBe(['id', 'starts_at', 'duration_minutes', 'service', 'team_member']);
        expect($payload['recent_reservations'][0])
            ->duration_minutes->toBe(45)
            ->starts_at->toBe('2026-08-20T14:00:00+00:00');
        expect($payload['recent_reservations'][0]['service'])->toBe([
            'id' => $popularService->id,
            'name' => 'Popular service',
            'is_available' => true,
        ]);
        expect($payload['recent_reservations'][1]['service'])->toBe([
            'id' => $inactiveService->id,
            'name' => 'Inactive service',
            'is_available' => false,
        ]);
        expect($payload['recent_reservations'][1]['team_member'])->toBe([
            'id' => $inactiveMember->id,
            'name' => 'Inactive Rebooking Stylist',
            'is_available' => false,
        ]);

        expect(collect($payload['frequent_services'])->pluck('service.id')->all())
            ->toBe([$popularService->id, $inactiveService->id, $thirdService->id]);
        expect(collect($payload['frequent_services'])->pluck('reservation_count')->all())
            ->toBe([4, 3, 2]);
        expect(array_keys($payload['frequent_services'][0]))
            ->toBe([
                'service',
                'reservation_count',
                'last_booked_at',
                'duration_minutes',
                'team_member',
            ]);
        expect($payload['frequent_services'][0])
            ->duration_minutes->toBe(45)
            ->last_booked_at->toBe('2026-08-20T14:00:00+00:00');
        expect($payload['frequent_services'][1]['service']['is_available'])->toBeFalse();
        expect($payload['frequent_services'][1]['team_member']['is_available'])->toBeFalse();

        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        expect($encodedPayload)
            ->not->toContain('REBOOKING INTERNAL SECRET')
            ->not->toContain('REBOOKING CLIENT SECRET')
            ->not->toContain('status');
    } finally {
        Carbon::setTestNow();
    }
});

it('returns 404 for a rebooking customer owned by another account', function () {
    $owner = createOwnerWithReservationsEnabled();
    $foreignOwner = createOwnerWithReservationsEnabled([
        'name' => 'Foreign Rebooking Owner',
        'email' => 'foreign.rebooking.owner@example.com',
    ]);
    [, $foreignCustomer] = createClientForAccount(
        $foreignOwner,
        'Foreign Rebooking Client',
        'foreign.rebooking.client@example.com'
    );

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.customer-rebooking', $foreignCustomer))
        ->assertNotFound();
});

it('returns 403 when a read-only team member requests rebooking suggestions', function () {
    $owner = createOwnerWithReservationsEnabled();
    $viewerMember = createTeamMemberForAccount($owner, [
        'user_name' => 'Read-only Rebooking Member',
        'user_email' => 'readonly.rebooking.member@example.com',
        'role' => 'member',
        'permissions' => ['view_all_reservations'],
    ]);
    [, $customer] = createClientForAccount(
        $owner,
        'Protected Rebooking Client',
        'protected.rebooking.client@example.com'
    );

    $this->actingAs($viewerMember->user()->firstOrFail())
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.customer-rebooking', $customer))
        ->assertForbidden();
});

it('returns 403 when a client requests staff rebooking suggestions', function () {
    $owner = createOwnerWithReservationsEnabled();
    [$clientUser, $customer] = createClientForAccount(
        $owner,
        'Portal Rebooking Client',
        'portal.rebooking.client@example.com'
    );

    $this->actingAs($clientUser)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.customer-rebooking', $customer))
        ->assertForbidden();
});

it('does not expose foreign service or team member data in rebooking suggestions', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-30 12:00:00', 'UTC'));

    try {
        $owner = createOwnerWithReservationsEnabled();
        $member = createTeamMemberForAccount($owner);
        [, $customer] = createClientForAccount(
            $owner,
            'Tenant Safe Rebooking Client',
            'tenant.safe.rebooking.client@example.com'
        );
        $category = ProductCategory::query()->create([
            'name' => 'Tenant safe rebooking services',
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
        ]);
        $service = Product::query()->create([
            'name' => 'Tenant Safe Service',
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'currency_code' => 'CAD',
            'unit' => 'service',
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'is_active' => true,
        ]);
        $reclassifiedService = Product::query()->create([
            'name' => 'Reclassified Service',
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'currency_code' => 'CAD',
            'unit' => 'unit',
            'item_type' => Product::ITEM_TYPE_PRODUCT,
            'is_active' => true,
        ]);
        $deletedService = Product::query()->create([
            'name' => 'Deleted Service',
            'category_id' => $category->id,
            'user_id' => $owner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'currency_code' => 'CAD',
            'unit' => 'service',
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'is_active' => true,
        ]);

        $foreignOwner = createOwnerWithReservationsEnabled([
            'name' => 'Hostile Rebooking Owner',
            'email' => 'hostile.rebooking.owner@example.com',
        ]);
        $foreignMember = createTeamMemberForAccount($foreignOwner, [
            'user_name' => 'FOREIGN REBOOKING TEAM SECRET',
            'user_email' => 'foreign.rebooking.team@example.com',
        ]);
        $foreignCategory = ProductCategory::query()->create([
            'name' => 'Foreign rebooking services',
            'user_id' => $foreignOwner->id,
            'created_by_user_id' => $foreignOwner->id,
        ]);
        $foreignService = Product::query()->create([
            'name' => 'FOREIGN REBOOKING SERVICE SECRET',
            'category_id' => $foreignCategory->id,
            'user_id' => $foreignOwner->id,
            'stock' => 0,
            'minimum_stock' => 0,
            'price' => 80,
            'currency_code' => 'CAD',
            'unit' => 'service',
            'item_type' => Product::ITEM_TYPE_SERVICE,
            'is_active' => true,
        ]);
        $validStart = Carbon::parse('2026-08-20 09:00:00', 'UTC');
        $validReservation = Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $foreignMember->id,
            'client_id' => $customer->id,
            'service_id' => $service->id,
            'status' => Reservation::STATUS_COMPLETED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'UTC',
            'starts_at' => $validStart,
            'ends_at' => $validStart->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
        $hostileStart = Carbon::parse('2026-08-21 09:00:00', 'UTC');
        Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'client_id' => $customer->id,
            'service_id' => $foreignService->id,
            'status' => Reservation::STATUS_COMPLETED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'UTC',
            'starts_at' => $hostileStart,
            'ends_at' => $hostileStart->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
        $reclassifiedStart = Carbon::parse('2026-08-22 09:00:00', 'UTC');
        $reclassifiedReservation = Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'client_id' => $customer->id,
            'service_id' => $reclassifiedService->id,
            'status' => Reservation::STATUS_COMPLETED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'UTC',
            'starts_at' => $reclassifiedStart,
            'ends_at' => $reclassifiedStart->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
        $deletedStart = Carbon::parse('2026-08-23 09:00:00', 'UTC');
        $deletedServiceReservation = Reservation::query()->create([
            'account_id' => $owner->id,
            'team_member_id' => $member->id,
            'client_id' => $customer->id,
            'service_id' => $deletedService->id,
            'status' => Reservation::STATUS_COMPLETED,
            'source' => Reservation::SOURCE_STAFF,
            'timezone' => 'UTC',
            'starts_at' => $deletedStart,
            'ends_at' => $deletedStart->copy()->addHour(),
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);
        $deletedService->delete();

        $response = $this->actingAs($owner)
            ->withSession(['two_factor_passed' => true])
            ->getJson(route('reservation.customer-rebooking', $customer))
            ->assertOk();

        expect(collect($response->json('recent_reservations'))->pluck('id')->all())
            ->toBe([
                $deletedServiceReservation->id,
                $reclassifiedReservation->id,
                $validReservation->id,
            ]);
        expect($response->json('recent_reservations.0.service'))->toBeNull();
        expect($response->json('recent_reservations.1.service'))->toBe([
            'id' => $reclassifiedService->id,
            'name' => 'Reclassified Service',
            'is_available' => false,
        ]);
        expect($response->json('recent_reservations.2.team_member'))->toBeNull();
        $safeServiceFrequency = collect($response->json('frequent_services'))
            ->firstWhere('service.id', $service->id);
        expect($safeServiceFrequency['team_member'])->toBeNull();
        expect(json_encode($response->json(), JSON_THROW_ON_ERROR))
            ->not->toContain('FOREIGN REBOOKING TEAM SECRET')
            ->not->toContain('FOREIGN REBOOKING SERVICE SECRET');
    } finally {
        Carbon::setTestNow();
    }
});
