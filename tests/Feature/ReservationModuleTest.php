<?php

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationQueueItem;
use App\Models\ReservationResource;
use App\Models\ReservationResourceAllocation;
use App\Models\ReservationReview;
use App\Models\ReservationSetting;
use App\Models\ReservationWaitlist;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationQueueService;
use App\Models\WeeklyAvailability;
use App\Notifications\ActionEmailNotification;
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

function createOwnerWithReservationsEnabled(): User
{
    $ownerRoleId = ensureRole('owner', 'Account owner role');

    return User::query()->create([
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
    ]);
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

    Notification::assertSentTo($clientUser, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
        return str_contains(strtolower($notification->title), 'reminder');
    });

    $metadata = (array) (Reservation::query()->find($reservation->id)?->metadata ?? []);
    expect((array) ($metadata['notifications'] ?? []))
        ->toHaveKey('reminder_24h_sent_at');
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

it('allows staff to progress queue items through operational states', function () {
    $owner = createOwnerWithReservationsEnabled();
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

    $ticket = ReservationQueueItem::query()->create([
        'account_id' => $owner->id,
        'client_id' => $customer->id,
        'client_user_id' => $clientUser->id,
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

it('returns queue screen payload and supports anonymize toggle', function () {
    $owner = createOwnerWithReservationsEnabled();
    $teamMember = createTeamMemberForAccount($owner);
    [$clientUser, $customer] = createClientForAccount($owner, 'Queue Screen', 'queue.screen@example.com');

    ReservationSetting::query()->updateOrCreate(
        [
            'account_id' => $owner->id,
            'team_member_id' => null,
        ],
        [
            'business_preset' => 'restaurant',
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

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->get(route('reservation.screen'))
        ->assertOk();

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
        ?: trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
        ?: ($customer->email ?? ''));

    expect((int) ($plain->json('queue.waiting.0.id') ?? 0))->toBe((int) $ticket->id);
    expect($plainName)->not->toBe('');
    expect($anonymizedName)->not->toBe('');
    expect($plainName)->toBe($realName);
    expect($anonymizedName)->not->toBe($plainName);
});
