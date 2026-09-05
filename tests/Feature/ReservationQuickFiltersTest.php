<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.stripe.enabled' => false]);
});

function reservationQuickFilterOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_timezone' => 'UTC',
        'company_features' => ['reservations' => true],
    ]);
}

/** @param array<string, mixed> $attributes */
function reservationQuickFilterRow(User $owner, TeamMember $member, string $status, string $startsAt, array $attributes = []): Reservation
{
    $start = Carbon::parse($startsAt, 'UTC');

    return Reservation::factory()->create(array_merge([
        'account_id' => $owner->id, 'team_member_id' => $member->id,
        'status' => $status, 'starts_at' => $start, 'ends_at' => $start->copy()->addHour(),
        'source' => Reservation::SOURCE_STAFF,
    ], $attributes));
}

/** @return array<string, string> */
function reservationQuickFilterHeaders(string $only): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create(route('reservation.index'))),
        'X-Inertia-Partial-Component' => 'Reservation/Index',
        'X-Inertia-Partial-Data' => $only,
    ];
}

it('combines quick filters consistently in the reservation list and calendar', function (string $mode, array $expectedKeys): void {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-09-04 12:00:00', 'UTC'));
    $owner = reservationQuickFilterOwner();
    $member = TeamMember::factory()->create(['account_id' => $owner->id]);
    $reservations = [
        'pending_today' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-04 13:00:00'),
        'confirmed_today' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 15:00:00'),
        'pending_later' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-05 13:00:00'),
        'unmatched' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-05 15:00:00'),
    ];
    $filters = ['quick_filters' => ['pending', 'today'], 'quick_filter_mode' => $mode, 'scope' => 'all'];
    $expectedIds = array_map(fn (string $key): int => $reservations[$key]->id, $expectedKeys);

    $list = $this->actingAs($owner)->getJson(route('reservation.index', $filters))->assertOk()
        ->assertJsonPath('filters.quick_filters', ['pending', 'today'])
        ->assertJsonPath('filters.quick_filter_mode', $mode)
        ->assertJsonPath('filters.quick', '')
        ->assertJsonPath('reservations.total', count($expectedIds))
        ->assertJsonPath('reservationCount', count($expectedIds))
        ->assertJsonPath('quickCounts.pending', 2)->assertJsonPath('quickCounts.today', 2);

    expect(collect($list->json('reservations.data'))->pluck('id')->all())->toBe($expectedIds);
    expect(collect($list->json('events'))->pluck('id')->all())->toBe($expectedIds);

    $events = $this->getJson(route('reservation.events', array_merge($filters, [
        'start' => '2026-09-04T00:00:00Z', 'end' => '2026-09-06T00:00:00Z',
    ])))->assertOk()->json('events');

    expect(collect($events)->pluck('id')->all())->toBe($expectedIds);
    Http::assertNothingSent();
})->with([
    'all requires every quick predicate' => ['all', ['pending_today']],
    'any accepts either quick predicate' => ['any', ['pending_today', 'confirmed_today', 'pending_later']],
]);

it('normalizes canonical quick filters while keeping legacy links compatible', function (array $input, array $expectedFilters, string $expectedMode, string $expectedLegacy): void {
    Http::preventStrayRequests();
    $owner = reservationQuickFilterOwner();

    $this->actingAs($owner)->withHeaders(reservationQuickFilterHeaders('filters'))
        ->get(route('reservation.index', $input))->assertOk()
        ->assertJsonPath('props.filters.quick_filters', $expectedFilters)
        ->assertJsonPath('props.filters.quick_filter_mode', $expectedMode)
        ->assertJsonPath('props.filters.quick', $expectedLegacy);

    Http::assertNothingSent();
})->with([
    'defaults' => [[], [], 'all', ''],
    'legacy quick' => [['quick' => 'today'], ['today'], 'all', 'today'],
    'canonical overrides legacy' => [['quick_filters' => ['pending', 'today'], 'quick' => 'cancelled'], ['pending', 'today'], 'all', ''],
    'explicit canonical clear' => [['quick_filters' => '', 'quick' => 'cancelled', 'quick_filter_mode' => 'any'], [], 'any', ''],
    'canonical singleton string' => [['quick_filters' => 'completed'], ['completed'], 'all', 'completed'],
    'duplicates and malformed values' => [['quick_filters' => [' today ', 'today', ['cancelled'], 'unsupported', 'pending'], 'quick_filter_mode' => ['any']], ['today', 'pending'], 'all', ''],
    'invalid legacy array' => [['quick' => ['cancelled']], [], 'all', ''],
    'invalid mode' => [['quick_filters' => ['today'], 'quick_filter_mode' => 'or 1=1'], ['today'], 'all', 'today'],
]);

it('keeps tenant and advanced filters outside the quick OR group and respects the calendar window', function (): void {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-09-04 12:00:00', 'UTC'));
    $owner = reservationQuickFilterOwner();
    $otherOwner = reservationQuickFilterOwner();
    $member = TeamMember::factory()->create(['account_id' => $owner->id]);
    $otherMember = TeamMember::factory()->create(['account_id' => $owner->id]);
    $foreignMember = TeamMember::factory()->create(['account_id' => $otherOwner->id]);
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'first_name' => 'FilterTarget']);
    $otherCustomer = Customer::factory()->create(['user_id' => $owner->id, 'first_name' => 'Excluded']);
    $category = ProductCategory::factory()->create(['user_id' => $owner->id]);
    $service = Product::query()->create(['user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Hair styling', 'item_type' => Product::ITEM_TYPE_SERVICE, 'price' => 50]);
    $otherService = Product::query()->create(['user_id' => $owner->id, 'category_id' => $category->id, 'name' => 'Consultation', 'item_type' => Product::ITEM_TYPE_SERVICE, 'price' => 20]);
    $matching = ['client_id' => $customer->id, 'service_id' => $service->id];
    $target = reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 13:00:00', $matching);
    $outsideWindow = reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 20:00:00', $matching);
    reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-04 14:00:00', $matching);
    reservationQuickFilterRow($owner, $otherMember, Reservation::STATUS_CONFIRMED, '2026-09-04 14:00:00', $matching);
    reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 14:00:00', array_merge($matching, ['client_id' => $otherCustomer->id]));
    reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 14:00:00', array_merge($matching, ['service_id' => $otherService->id]));
    reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-03 14:00:00', $matching);
    reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-05 14:00:00', $matching);
    reservationQuickFilterRow($otherOwner, $foreignMember, Reservation::STATUS_CONFIRMED, '2026-09-04 14:00:00');
    $filters = [
        'quick_filters' => ['pending', 'today'], 'quick_filter_mode' => 'any',
        'scope' => 'all', 'team_member_id' => $member->id, 'service_id' => $service->id,
        'status' => Reservation::STATUS_CONFIRMED, 'search' => 'FilterTarget',
        'date_from' => '2026-09-04', 'date_to' => '2026-09-04',
    ];

    $list = $this->actingAs($owner)->withHeaders(reservationQuickFilterHeaders('reservations,reservationCount'))
        ->get(route('reservation.index', $filters))->assertOk()->assertJsonPath('props.reservationCount', 2);
    expect(collect($list->json('props.reservations.data'))->pluck('id')->all())->toBe([$target->id, $outsideWindow->id]);

    $events = $this->flushHeaders()->getJson(route('reservation.events', array_merge($filters, [
        'start' => '2026-09-04T12:00:00Z', 'end' => '2026-09-04T17:00:00Z',
    ])))->assertOk()->json('events');
    expect(collect($events)->pluck('id')->all())->toBe([$target->id]);
    Http::assertNothingSent();
});

it('does not let an OR filter bypass the staff reservation scope', function (): void {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-09-04 12:00:00', 'UTC'));
    $owner = reservationQuickFilterOwner();
    $role = Role::query()->firstOrCreate(['name' => 'employee'], ['description' => 'Employee']);
    $staff = User::factory()->withRole($role->id)->create();
    $member = TeamMember::factory()->create([
        'account_id' => $owner->id, 'user_id' => $staff->id, 'role' => 'member',
        'permissions' => ['reservations.view'],
    ]);
    $otherMember = TeamMember::factory()->create(['account_id' => $owner->id]);
    $target = reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-04 13:00:00');
    reservationQuickFilterRow($owner, $otherMember, Reservation::STATUS_CONFIRMED, '2026-09-04 14:00:00');
    reservationQuickFilterRow($owner, $otherMember, Reservation::STATUS_PENDING, '2026-09-05 14:00:00');
    $filters = [
        'quick_filters' => ['pending', 'today'], 'quick_filter_mode' => 'any',
        'scope' => 'all', 'team_member_id' => $otherMember->id,
    ];

    $this->actingAs($staff)->withHeaders(reservationQuickFilterHeaders('reservations,reservationCount,filters'))
        ->get(route('reservation.index', $filters))->assertOk()
        ->assertJsonPath('props.reservationCount', 1)
        ->assertJsonPath('props.reservations.data.0.id', $target->id)
        ->assertJsonPath('props.filters.scope', 'mine')
        ->assertJsonPath('props.filters.team_member_id', (string) $member->id);

    $events = $this->flushHeaders()->getJson(route('reservation.events', array_merge($filters, [
        'start' => '2026-09-04T00:00:00Z', 'end' => '2026-09-06T00:00:00Z',
    ])))->assertOk()->json('events');
    expect(collect($events)->pluck('id')->all())->toBe([$target->id]);
    Http::assertNothingSent();
});

it('normalizes tampered and legacy quick inputs identically for list and event requests', function (array $input, array $expectedKeys): void {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-09-04 12:00:00', 'UTC'));
    $owner = reservationQuickFilterOwner();
    $member = TeamMember::factory()->create(['account_id' => $owner->id]);
    $reservations = [
        'pending' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_PENDING, '2026-09-04 13:00:00'),
        'confirmed' => reservationQuickFilterRow($owner, $member, Reservation::STATUS_CONFIRMED, '2026-09-04 15:00:00'),
    ];
    $expectedIds = array_map(fn (string $key): int => $reservations[$key]->id, $expectedKeys);

    $list = $this->actingAs($owner)->withHeaders(reservationQuickFilterHeaders('reservations,reservationCount'))
        ->get(route('reservation.index', $input))->assertOk()->assertJsonPath('props.reservationCount', count($expectedIds));
    expect(collect($list->json('props.reservations.data'))->pluck('id')->all())->toBe($expectedIds);

    $events = $this->flushHeaders()->getJson(route('reservation.events', array_merge($input, [
        'start' => '2026-09-04T00:00:00Z', 'end' => '2026-09-05T00:00:00Z',
    ])))->assertOk()->json('events');
    expect(collect($events)->pluck('id')->all())->toBe($expectedIds);
    Http::assertNothingSent();
})->with([
    'legacy URL' => [['quick' => 'pending'], ['pending']],
    'canonical ignores invalid legacy' => [['quick_filters' => ['pending'], 'quick' => ['invalid']], ['pending']],
    'canonical clear ignores legacy' => [['quick_filters' => '', 'quick' => 'pending', 'quick_filter_mode' => 'any'], ['pending', 'confirmed']],
    'unknown nested and duplicate values' => [['quick_filters' => ['pending', 'pending', ['today'], 'cancelled OR 1=1']], ['pending']],
    'invalid legacy is ignored' => [['quick' => ['pending']], ['pending', 'confirmed']],
    'disjoint statuses all produce no rows' => [['quick_filters' => ['pending', 'completed']], []],
]);
