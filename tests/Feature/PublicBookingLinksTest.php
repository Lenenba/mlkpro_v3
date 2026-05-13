<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PublicBookingLink;
use App\Models\Request as LeadRequest;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WeeklyAvailability;
use App\Modules\AiAssistant\Models\AiAssistantSetting;
use App\Notifications\ActionEmailNotification;
use App\Notifications\ReservationDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
});

function publicBookingRole(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description]
    )->id;
}

function publicBookingOwner(array $overrides = []): User
{
    return User::query()->create(array_replace_recursive([
        'name' => 'Public Booking Owner',
        'email' => 'public.booking.owner@example.com',
        'password' => 'password',
        'role_id' => publicBookingRole('owner', 'Account owner role'),
        'company_name' => 'Public Booking Co',
        'company_slug' => 'public-booking-co',
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'onboarding_completed_at' => now(),
        'company_features' => [
            'reservations' => true,
        ],
    ], $overrides));
}

function publicBookingTeamMember(User $owner): TeamMember
{
    $identifier = Str::lower(Str::random(8));
    $employee = User::query()->create([
        'name' => 'Public Booking Staff',
        'email' => "public.booking.staff.{$identifier}@example.com",
        'password' => 'password',
        'role_id' => publicBookingRole('employee', 'Employee role'),
        'onboarding_completed_at' => now(),
    ]);

    return TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $employee->id,
        'role' => 'admin',
        'title' => 'Specialist',
        'permissions' => ['reservations.view', 'reservations.manage'],
        'is_active' => true,
    ]);
}

function publicBookingService(User $owner): Product
{
    $category = ProductCategory::factory()->create([
        'user_id' => $owner->id,
    ]);

    return Product::query()->create([
        'name' => 'Consultation',
        'description' => 'Initial consultation',
        'category_id' => $category->id,
        'user_id' => $owner->id,
        'price' => 120,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
        'is_active' => true,
        'currency_code' => 'CAD',
    ]);
}

function publicBookingAvailability(User $owner, TeamMember $member, Carbon $date): void
{
    WeeklyAvailability::query()->create([
        'account_id' => $owner->id,
        'team_member_id' => $member->id,
        'day_of_week' => $date->dayOfWeek,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_active' => true,
    ]);
}

function publicBookingLinkFor(User $owner, Product $service): PublicBookingLink
{
    $link = PublicBookingLink::query()->create([
        'account_id' => $owner->id,
        'name' => 'Instagram bookings',
        'slug' => 'instagram-bookings',
        'description' => 'Book from Instagram',
        'is_active' => true,
        'requires_manual_confirmation' => true,
        'source' => 'instagram',
        'campaign' => 'spring',
    ]);
    $link->services()->sync([$service->id]);

    return $link;
}

it('exposes the ai assistant widget on public booking links when enabled', function () {
    $owner = publicBookingOwner();
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);

    AiAssistantSetting::factory()->create([
        'tenant_id' => $owner->id,
        'enabled' => true,
        'assistant_name' => 'Reception Booking',
    ]);

    $this->get(route('public.booking.show', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/PublicBooking')
            ->where('ai_assistant.enabled', true)
            ->where('ai_assistant.name', 'Reception Booking')
            ->where('ai_assistant.company_slug', $owner->company_slug)
            ->where('ai_assistant.endpoints.create', route('public.ai-assistant.conversations.store'))
        );
});

it('creates a prospect and reservation from a public booking link without creating a customer', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(3)->setTime(10, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slotResponse = $this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->assertOk();

    $slot = collect($slotResponse->json('slots'))->first();
    expect($slot)->not->toBeNull();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'phone' => '+15145550123',
        'email' => 'amina@example.com',
        'message' => 'First visit.',
    ])->assertCreated()
        ->assertJsonPath('reservation.status', Reservation::STATUS_PENDING);

    $prospect = LeadRequest::query()->first();
    $reservation = Reservation::query()->first();

    expect(Customer::query()->count())->toBe(0)
        ->and($prospect)->not->toBeNull()
        ->and($prospect->public_booking_link_id)->toBe($link->id)
        ->and($prospect->customer_id)->toBeNull()
        ->and(data_get($prospect->meta, 'public_booking.status'))->toBe(LeadRequest::PUBLIC_STATUS_BOOKING_REQUESTED)
        ->and($reservation)->not->toBeNull()
        ->and($reservation->prospect_id)->toBe($prospect->id)
        ->and($reservation->public_booking_link_id)->toBe($link->id)
        ->and($reservation->client_id)->toBeNull()
        ->and($reservation->source)->toBe(Reservation::SOURCE_PUBLIC_BOOKING);

    $this->assertDatabaseHas('notifications', [
        'notifiable_type' => User::class,
        'notifiable_id' => $owner->id,
    ]);
});

it('notifies the owner and assigned staff when a public booking is created', function () {
    Notification::fake();

    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(3)->setTime(10, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'phone' => '+15145550123',
        'email' => 'amina.notify@example.com',
    ])->assertCreated();

    $staffUser = $member->user()->firstOrFail();

    foreach ([$owner, $staffUser] as $recipient) {
        Notification::assertSentTo($recipient, ReservationDatabaseNotification::class, function (ReservationDatabaseNotification $notification) {
            return ($notification->payload['event'] ?? null) === 'public_booking_received';
        });

        Notification::assertSentTo($recipient, ActionEmailNotification::class, function (ActionEmailNotification $notification) {
            return str_contains(strtolower($notification->title), 'public booking');
        });
    }

    Notification::assertSentOnDemand(
        ActionEmailNotification::class,
        function (ActionEmailNotification $notification, array $channels, object $notifiable) {
            return in_array('mail', $channels, true)
                && data_get($notifiable, 'routes.mail') === 'amina.notify@example.com'
                && str_contains(strtolower($notification->title), 'booking request');
        }
    );
});

it('does not notify anyone for public bookings when reservation creation notifications are disabled', function () {
    Notification::fake();

    $owner = publicBookingOwner([
        'company_notification_settings' => [
            'reservations' => [
                'enabled' => true,
                'email' => true,
                'in_app' => true,
                'notify_on_created' => false,
            ],
        ],
    ]);
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(3)->setTime(10, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'phone' => '+15145550123',
        'email' => 'amina.silent@example.com',
    ])->assertCreated();

    Notification::assertNothingSent();
});

it('shows public booking prospect names in reservation calendar events', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(3)->setTime(13, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Amina',
        'last_name' => 'Diallo',
        'phone' => '+15145550123',
        'email' => 'amina.events@example.com',
    ])->assertCreated();

    $reservation = Reservation::query()->firstOrFail();
    $eventResponse = $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.events', [
            'start' => $startsAt->copy()->startOfDay()->toIso8601String(),
            'end' => $startsAt->copy()->endOfDay()->toIso8601String(),
        ]))
        ->assertOk();

    $event = collect($eventResponse->json('events'))->firstWhere('id', $reservation->id);

    expect($event)->not->toBeNull()
        ->and($event['title'])->toContain('Amina Diallo')
        ->and(data_get($event, 'extendedProps.client_name'))->toBe('Amina Diallo');
});

it('auto assigns an available team member when the public guest has no preference', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(4)->setTime(10, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'assignment_mode' => 'auto',
        'service_id' => $service->id,
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Auto',
        'last_name' => 'Guest',
        'phone' => '+15145550111',
        'email' => 'auto.guest@example.com',
    ])->assertCreated()
        ->assertJsonPath('reservation.team_member_id', $member->id);

    $reservation = Reservation::query()->firstOrFail();

    expect($reservation->team_member_id)->toBe($member->id)
        ->and(data_get($reservation->metadata, 'public_booking.assignment_mode'))->toBe('auto');
});

it('rejects a public booking when the selected slot has just been taken', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(4)->setTime(14, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $payload = [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'First',
        'last_name' => 'Guest',
        'phone' => '+15145550222',
        'email' => 'first.guest@example.com',
    ];

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), $payload)->assertCreated();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        ...$payload,
        'email' => 'second.guest@example.com',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');

    expect(Reservation::query()->count())->toBe(1)
        ->and(LeadRequest::query()->count())->toBe(1);
});

it('converts a public booking prospect by linking an existing customer', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(4)->setTime(11, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Nora',
        'last_name' => 'Client',
        'phone' => '+15145550999',
        'email' => 'nora@example.com',
    ])->assertCreated();

    $reservation = Reservation::query()->firstOrFail();
    $customer = Customer::query()->create([
        'user_id' => $owner->id,
        'first_name' => 'Nora',
        'last_name' => 'Client',
        'company_name' => 'Nora Client',
        'email' => 'nora@example.com',
        'phone' => '+15145550999',
    ]);

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->getJson(route('reservation.public-booking-conversion.show', $reservation))
        ->assertOk()
        ->assertJsonCount(1, 'matches');

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.public-booking-conversion.store', $reservation), [
            'mode' => 'link_existing',
            'customer_id' => $customer->id,
        ])
        ->assertOk()
        ->assertJsonPath('reservation.client_id', $customer->id);

    $reservation->refresh();
    $prospect = $reservation->prospect()->firstOrFail();

    expect($reservation->client_id)->toBe($customer->id)
        ->and($prospect->customer_id)->toBe($customer->id)
        ->and($prospect->converted_customer_id)->toBe($customer->id)
        ->and($prospect->status)->toBe(LeadRequest::STATUS_CONVERTED)
        ->and(data_get($prospect->meta, 'public_booking.status'))->toBe(LeadRequest::PUBLIC_STATUS_CONVERTED_TO_CUSTOMER);
});

it('converts a public booking prospect by creating a new customer', function () {
    $owner = publicBookingOwner();
    $member = publicBookingTeamMember($owner);
    $service = publicBookingService($owner);
    $link = publicBookingLinkFor($owner, $service);
    $startsAt = Carbon::now('UTC')->addDays(5)->setTime(12, 0);
    publicBookingAvailability($owner, $member, $startsAt);

    $slot = collect($this->getJson(route('public.booking.slots', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
        'service_id' => $service->id,
        'range_start' => $startsAt->copy()->startOfDay()->toIso8601String(),
        'range_end' => $startsAt->copy()->endOfDay()->toIso8601String(),
    ]))->json('slots'))->first();

    $this->postJson(route('public.booking.store', [
        'company' => $owner->company_slug,
        'slug' => $link->slug,
    ]), [
        'service_id' => $service->id,
        'team_member_id' => $slot['team_member_id'],
        'starts_at' => $slot['starts_at'],
        'ends_at' => $slot['ends_at'],
        'first_name' => 'Mila',
        'last_name' => 'Newman',
        'phone' => '+15145550777',
        'email' => 'mila.newman@example.com',
    ])->assertCreated();

    $reservation = Reservation::query()->firstOrFail();

    $this->actingAs($owner)
        ->withSession(['two_factor_passed' => true])
        ->postJson(route('reservation.public-booking-conversion.store', $reservation), [
            'mode' => 'create_new',
            'contact_name' => 'Mila Newman',
            'contact_email' => 'mila.newman@example.com',
            'contact_phone' => '+15145550777',
            'company_name' => 'Mila Newman',
        ])
        ->assertOk()
        ->assertJsonPath('customer.email', 'mila.newman@example.com');

    $customer = Customer::query()->firstOrFail();
    $reservation->refresh();
    $prospect = $reservation->prospect()->firstOrFail();

    expect($reservation->client_id)->toBe($customer->id)
        ->and($prospect->customer_id)->toBe($customer->id)
        ->and($prospect->converted_customer_id)->toBe($customer->id)
        ->and($prospect->status)->toBe(LeadRequest::STATUS_CONVERTED);
});
