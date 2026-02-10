<?php

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationReview;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
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
