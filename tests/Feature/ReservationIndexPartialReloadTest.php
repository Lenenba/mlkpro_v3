<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.stripe.enabled' => false]);
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::flushQueryLog();
});

function reservationIndexOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => ['reservations' => true],
    ]);
}

/** @return array<string, string> */
function reservationIndexHeaders(?string $only = null): array
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create(route('reservation.index'))),
    ];
    if ($only !== null) {
        $headers['X-Inertia-Partial-Component'] = 'Reservation/Index';
        $headers['X-Inertia-Partial-Data'] = $only;
    }

    return $headers;
}

/** @return list<string> */
function reservationIndexQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower(str_replace(['`', '"'], '', $query)))->all();
}

it('does not load reservation datasets or synchronize the queue for a filters-only reload', function (): void {
    Http::preventStrayRequests();
    $owner = reservationIndexOwner();
    ReservationSetting::factory()->create(['account_id' => $owner->id, 'business_preset' => 'salon', 'queue_mode_enabled' => true]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders('filters'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get(route('reservation.index'))->assertOk()
        ->assertJsonPath('component', 'Reservation/Index');

    foreach (['reservations', 'events', 'performance', 'clients', 'queueItems', 'waitlists'] as $prop) {
        $response->assertJsonMissingPath('props.'.$prop);
    }
    foreach (['reservations', 'customers', 'reservation_waitlists', 'reservation_queue_items', 'payments', 'weekly_availabilities'] as $table) {
        expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
});

it('loads only the requested reservation page with tenant-scoped related customers', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = reservationIndexOwner();
    $otherOwner = reservationIndexOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $otherCustomer = Customer::factory()->create(['user_id' => $otherOwner->id]);
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $customer->id,
        'status' => Reservation::STATUS_CONFIRMED, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2),
    ]);
    $invalidRelation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $otherCustomer->id,
        'status' => Reservation::STATUS_CONFIRMED, 'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4),
    ]);
    Reservation::factory()->create(['account_id' => $otherOwner->id, 'client_id' => $otherCustomer->id]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders('reservations'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('reservation.index', ['view_mode' => 'list']))->assertOk()
        ->assertJsonPath('props.reservations.total', 2)
        ->assertJsonPath('props.reservations.data.0.id', $reservation->id)
        ->assertJsonPath('props.reservations.data.0.client.id', $customer->id)
        ->assertJsonPath('props.reservations.data.1.id', $invalidRelation->id)
        ->assertJsonPath('props.reservations.data.1.client', null)
        ->assertJsonMissingPath('props.performance');

    expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from reservations ')))->toHaveCount(2);
    foreach (['reservation_waitlists', 'reservation_queue_items', 'payments', 'weekly_availabilities'] as $table) {
        expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
});

it('keeps the initial calendar props and JSON contract while partial reloads avoid their query cost', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = reservationIndexOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $reservation = Reservation::factory()->create([
        'account_id' => $owner->id, 'client_id' => $customer->id,
        'status' => Reservation::STATUS_CONFIRMED, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2),
    ]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders());
    DB::enableQueryLog();
    DB::flushQueryLog();

    $initial = $this->get(route('reservation.index', ['view_mode' => 'calendar']))->assertOk()
        ->assertJsonPath('props.reservations.data.0.id', $reservation->id)
        ->assertJsonPath('props.events.0.id', $reservation->id)
        ->assertJsonPath('props.clients.0.id', $customer->id)
        ->assertJsonStructure(['props' => ['settings', 'paymentMethodSettings', 'tips', 'performance', 'waitlists', 'waitlistStats', 'queueItems', 'queueStats', 'teamMembers', 'services']]);
    $initialQueries = count(reservationIndexQueries());

    DB::flushQueryLog();
    $this->withHeaders(reservationIndexHeaders('reservations,reservationCount,filters'))->get(route('reservation.index', ['view_mode' => 'list']))
        ->assertOk()->assertJsonPath('props.reservations.data.0.id', $reservation->id);
    expect(count(reservationIndexQueries()))->toBeLessThan($initialQueries / 2);

    $json = $this->flushHeaders()->getJson(route('reservation.index', ['view_mode' => 'calendar']))->assertOk();
    foreach (['reservations', 'reservationCount', 'events', 'clients', 'settings', 'performance', 'waitlists', 'waitlistStats', 'queueItems', 'queueStats', 'teamMembers', 'services', 'paymentMethodSettings', 'tips'] as $prop) {
        $json->assertJsonPath($prop, $initial->json('props.'.$prop));
    }
    Http::assertNothingSent();
});

it('normalizes the calendar position and active data tab in the response filters', function (array $input, array $expected): void {
    Http::preventStrayRequests();
    $owner = reservationIndexOwner();

    $response = $this->actingAs($owner)->withHeaders(reservationIndexHeaders('filters'))
        ->get(route('reservation.index', $input))->assertOk();

    foreach ($expected as $key => $value) {
        $response->assertJsonPath('props.filters.'.$key, $value);
    }
    Http::assertNothingSent();
})->with([
    'day and queue' => [['calendar_view' => 'day', 'calendar_date' => '2026-09-04', 'data_tab' => 'queue'], ['calendar_view' => 'day', 'calendar_date' => '2026-09-04', 'data_tab' => 'queue']],
    'week and waitlist' => [['calendar_view' => 'week', 'calendar_date' => '2028-02-29', 'data_tab' => 'waitlist'], ['calendar_view' => 'week', 'calendar_date' => '2028-02-29', 'data_tab' => 'waitlist']],
    'month and reservations' => [['calendar_view' => 'month', 'data_tab' => 'reservations'], ['calendar_view' => 'month', 'calendar_date' => '', 'data_tab' => 'reservations']],
    'year' => [['calendar_view' => 'year'], ['calendar_view' => 'year']],
    'invalid values' => [['calendar_view' => 'agenda', 'calendar_date' => '2026-02-30', 'data_tab' => 'customers'], ['calendar_view' => 'week', 'calendar_date' => '', 'data_tab' => 'reservations']],
    'invalid date format' => [['calendar_date' => '04/09/2026'], ['calendar_date' => '']],
    'array values' => [['calendar_view' => ['day'], 'calendar_date' => ['2026-09-04'], 'data_tab' => ['queue']], ['calendar_view' => 'week', 'calendar_date' => '', 'data_tab' => 'reservations']],
    'defaults' => [[], ['calendar_view' => 'week', 'calendar_date' => '', 'data_tab' => 'reservations']],
]);

it('counts reservations with every active date and status filter without loading the calendar or list', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = reservationIndexOwner();
    $otherOwner = reservationIndexOwner();
    Reservation::factory()->create([
        'account_id' => $owner->id, 'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => now()->startOfDay()->addHours(10), 'ends_at' => now()->startOfDay()->addHours(11),
    ]);
    Reservation::factory()->create([
        'account_id' => $owner->id, 'status' => Reservation::STATUS_PENDING,
        'starts_at' => now()->startOfDay()->addHours(12), 'ends_at' => now()->startOfDay()->addHours(13),
    ]);
    Reservation::factory()->create([
        'account_id' => $owner->id, 'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
    ]);
    Reservation::factory()->create([
        'account_id' => $otherOwner->id, 'status' => Reservation::STATUS_CONFIRMED,
        'starts_at' => now()->startOfDay()->addHours(14), 'ends_at' => now()->startOfDay()->addHours(15),
    ]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders('reservationCount'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('reservation.index', [
        'status' => Reservation::STATUS_CONFIRMED, 'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
    ]))->assertOk()->assertJsonPath('props.reservationCount', 1)
        ->assertJsonMissingPath('props.reservations')->assertJsonMissingPath('props.events');

    expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from reservations ')))->toHaveCount(1);
    Http::assertNothingSent();
});

it('evaluates only the requested reservation dataset', function (string $prop, string $loadedTable, array $excludedTables): void {
    Http::preventStrayRequests();
    $owner = reservationIndexOwner();
    ReservationSetting::factory()->create(['account_id' => $owner->id, 'business_preset' => 'salon', 'queue_mode_enabled' => true]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders($prop));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('reservation.index'))->assertOk()->assertJsonStructure(['props' => [$prop]]);

    expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$loadedTable.' ')))->not->toBeEmpty();
    foreach ($excludedTables as $table) {
        expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
})->with([
    'calendar events' => ['events', 'reservations', ['customers', 'payments', 'reservation_waitlists', 'reservation_queue_items']],
    'performance' => ['performance', 'payments', ['customers', 'reservation_waitlists', 'reservation_queue_items']],
    'customers' => ['clients', 'customers', ['reservations', 'payments', 'reservation_waitlists', 'reservation_queue_items']],
    'waitlist rows' => ['waitlists', 'reservation_waitlists', ['reservations', 'customers', 'payments', 'reservation_queue_items']],
    'waitlist counters' => ['waitlistStats', 'reservation_waitlists', ['reservations', 'customers', 'payments', 'reservation_queue_items']],
    'queue rows' => ['queueItems', 'reservation_queue_items', ['customers', 'payments', 'reservation_waitlists']],
    'queue counters' => ['queueStats', 'reservation_queue_items', ['customers', 'payments', 'reservation_waitlists']],
]);

it('shares one queue evaluation between rows and counters in the same partial reload', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = reservationIndexOwner();
    ReservationSetting::factory()->create(['account_id' => $owner->id, 'business_preset' => 'salon', 'queue_mode_enabled' => true]);
    $this->actingAs($owner)->withHeaders(reservationIndexHeaders('queueStats'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $single = $this->get(route('reservation.index'))->assertOk();
    $singleQueries = collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'reservation_queue_items'))->count();

    DB::flushQueryLog();
    $this->withHeaders(reservationIndexHeaders('queueStats,queueItems'))->get(route('reservation.index'))
        ->assertOk()->assertJsonPath('props.queueStats', $single->json('props.queueStats'))->assertJsonPath('props.queueItems', []);

    expect($singleQueries)->toBeGreaterThan(0);
    expect(collect(reservationIndexQueries())->filter(fn (string $query): bool => str_contains($query, 'reservation_queue_items')))->toHaveCount($singleQueries);
    Http::assertNothingSent();
});

it('keeps unprivileged staff limited to their reservations and hides the client directory during partial reloads', function (): void {
    Http::preventStrayRequests();
    $owner = reservationIndexOwner();
    $role = Role::query()->firstOrCreate(['name' => 'employee'], ['description' => 'Employee']);
    $staff = User::factory()->withRole($role->id)->create();
    $member = TeamMember::factory()->create([
        'account_id' => $owner->id, 'user_id' => $staff->id, 'role' => 'member',
        'permissions' => ['reservations.view'],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $ownReservation = Reservation::factory()->create(['account_id' => $owner->id, 'team_member_id' => $member->id, 'client_id' => $customer->id]);
    $otherReservation = Reservation::factory()->create(['account_id' => $owner->id]);

    $this->actingAs($staff)->withHeaders(reservationIndexHeaders('reservations,reservationCount,clients,filters'))
        ->get(route('reservation.index', ['scope' => 'all', 'team_member_id' => $otherReservation->team_member_id]))->assertOk()
        ->assertJsonPath('props.filters.scope', 'mine')
        ->assertJsonPath('props.filters.team_member_id', (string) $member->id)
        ->assertJsonPath('props.reservationCount', 1)
        ->assertJsonPath('props.reservations.total', 1)
        ->assertJsonPath('props.reservations.data.0.id', $ownReservation->id)
        ->assertJsonPath('props.clients', [])
        ->assertJsonMissingPath('props.reservations.data.0.internal_notes');
    Http::assertNothingSent();
});

it('resolves lazy reservation props for authenticated API clients without applying Inertia partial headers', function (): void {
    Http::preventStrayRequests();
    $owner = reservationIndexOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $reservation = Reservation::factory()->create(['account_id' => $owner->id, 'client_id' => $customer->id]);
    \Laravel\Sanctum\Sanctum::actingAs($owner);

    $this->withHeaders(reservationIndexHeaders('filters'))->getJson('/api/v1/reservations')->assertOk()
        ->assertJsonPath('reservationCount', 1)
        ->assertJsonPath('reservations.data.0.id', $reservation->id)
        ->assertJsonPath('clients.0.id', $customer->id)
        ->assertJsonStructure(['settings', 'performance', 'queueItems', 'queueStats', 'waitlists', 'waitlistStats'])
        ->assertJsonMissingPath('component');
    Http::assertNothingSent();
});
