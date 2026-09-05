<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Prospect;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config(['services.stripe.enabled' => false, 'app.faker_locale' => 'en_US']);
});

afterEach(function (): void {
    DB::disableQueryLog();
    DB::flushQueryLog();
});

function prospectPartialOwner(): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_features' => ['requests' => true, 'team_members' => true],
    ]);
}

/** @return array<string, string> */
function prospectPartialHeaders(?string $only = null): array
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create(route('prospects.index'))),
    ];
    if ($only !== null) {
        $headers['X-Inertia-Partial-Component'] = 'Request/Index';
        $headers['X-Inertia-Partial-Data'] = $only;
    }

    return $headers;
}

/** @return list<string> */
function prospectPartialQueries(): array
{
    return collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower(str_replace(['`', '"'], '', $query)))->all();
}

it('avoids the client directory and global analytics when reloading the prospect table', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = prospectPartialOwner();
    $customers = Customer::factory()->count(12)->create(['user_id' => $owner->id]);
    foreach ($customers as $customer) {
        Property::factory()->count(2)->create(['customer_id' => $customer->id]);
    }
    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id, 'customer_id' => $customers->first()->id,
        'status' => LeadRequest::STATUS_NEW, 'title' => 'Requested prospect',
    ]);
    $hydrated = ['customers' => 0, 'properties' => 0, 'analytics_prospects' => 0];
    Customer::retrieved(function () use (&$hydrated): void {
        $hydrated['customers']++;
    });
    Property::retrieved(function () use (&$hydrated): void {
        $hydrated['properties']++;
    });
    Prospect::retrieved(function () use (&$hydrated): void {
        $hydrated['analytics_prospects']++;
    });
    $this->actingAs($owner)->withHeaders(prospectPartialHeaders());
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('prospects.index'))->assertOk()
        ->assertJsonCount(12, 'props.customers')->assertJsonPath('props.analytics.summary.total', 1);
    $initialQueries = count(prospectPartialQueries());
    $hydrated = ['customers' => 0, 'properties' => 0, 'analytics_prospects' => 0];
    DB::flushQueryLog();

    $this->withHeaders(prospectPartialHeaders('requests,filters,stats'))->get(route('prospects.index'))->assertOk()
        ->assertJsonPath('props.requests.data.0.id', $lead->id)
        ->assertJsonMissingPath('props.customers')->assertJsonMissingPath('props.analytics');

    expect($hydrated)->toBe(['customers' => 1, 'properties' => 0, 'analytics_prospects' => 0]);
    expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from saved_segments ')))->toBeEmpty();
    expect(count(prospectPartialQueries()))->toBeLessThan($initialQueries);
    Http::assertNothingSent();
});

it('does not query prospect datasets when only normalized filters are requested', function (): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $this->actingAs($owner)->withHeaders(prospectPartialHeaders('filters'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('prospects.index', ['view' => 'invalid', 'search' => 'inspection']))->assertOk()
        ->assertJsonPath('props.filters.view', 'table')->assertJsonPath('props.filters.search', 'inspection');

    foreach (['requests', 'customers', 'properties', 'saved_segments', 'tracking_events'] as $table) {
        expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
});

it('loads tenant-scoped analytics without classifying the inbox or loading client options', function (): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $otherOwner = prospectPartialOwner();
    LeadRequest::query()->create(['user_id' => $owner->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Own lead']);
    LeadRequest::query()->create(['user_id' => $otherOwner->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Other lead']);
    $this->actingAs($owner)->withHeaders(prospectPartialHeaders('analytics'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('prospects.index', ['search' => 'no matching prospect']))->assertOk()
        ->assertJsonPath('props.analytics.kind', 'prospect_dashboard_v1')
        ->assertJsonPath('props.analytics.summary.total', 1)
        ->assertJsonMissingPath('props.requests');

    expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from requests ')))->toHaveCount(1);
    foreach (['customers', 'properties', 'saved_segments'] as $table) {
        expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
});

it('resolves requested inbox props once while preserving filtered values', function (string $only): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $otherOwner = prospectPartialOwner();
    $lead = LeadRequest::query()->create(['user_id' => $owner->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Matched lead']);
    LeadRequest::query()->create(['user_id' => $owner->id, 'status' => LeadRequest::STATUS_WON, 'title' => 'Excluded lead']);
    LeadRequest::query()->create(['user_id' => $otherOwner->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Matched foreign lead']);
    $this->actingAs($owner)->withHeaders(prospectPartialHeaders($only));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get(route('prospects.index', ['search' => 'Matched']))->assertOk();

    if (str_contains($only, 'requests')) {
        $response->assertJsonPath('props.requests.total', 1)
            ->assertJsonPath('props.requests.data.0.id', $lead->id);
    } else {
        $response->assertJsonMissingPath('props.requests');
    }
    if (str_contains($only, 'stats')) {
        $response->assertJsonPath('props.stats.total', 1)->assertJsonPath('props.stats.new', 1);
    } else {
        $response->assertJsonMissingPath('props.stats');
    }
    $response->assertJsonMissingPath('props.analytics')->assertJsonMissingPath('props.customers');
    expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from requests ')))->toHaveCount(1);
    Http::assertNothingSent();
})->with(['requests', 'stats', 'requests,stats']);

it('keeps initial and json prospect props complete and scoped to the account', function (): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $owner = prospectPartialOwner();
    $otherOwner = prospectPartialOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $otherCustomer = Customer::factory()->create(['user_id' => $otherOwner->id]);
    $property = Property::factory()->create(['customer_id' => $customer->id]);
    Property::factory()->create(['customer_id' => $otherCustomer->id]);
    $assignee = TeamMember::factory()->create(['account_id' => $owner->id]);
    TeamMember::factory()->create(['account_id' => $otherOwner->id]);
    $segment = SavedSegment::query()->create(['user_id' => $owner->id, 'module' => SavedSegment::MODULE_REQUEST, 'name' => 'Own segment']);
    SavedSegment::query()->create(['user_id' => $otherOwner->id, 'module' => SavedSegment::MODULE_REQUEST, 'name' => 'Foreign segment']);
    $lead = LeadRequest::query()->create(['user_id' => $owner->id, 'customer_id' => $customer->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Own lead']);
    LeadRequest::query()->create(['user_id' => $otherOwner->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'Foreign lead']);

    $initial = $this->actingAs($owner)->withHeaders(prospectPartialHeaders())->get(route('prospects.index'))
        ->assertOk()->assertJsonCount(1, 'props.customers')
        ->assertJsonPath('props.customers.0.id', $customer->id)
        ->assertJsonCount(1, 'props.customers.0.properties')
        ->assertJsonPath('props.customers.0.properties.0.id', $property->id)
        ->assertJsonCount(1, 'props.assignees')->assertJsonPath('props.assignees.0.id', $assignee->id)
        ->assertJsonCount(1, 'props.savedSegments')->assertJsonPath('props.savedSegments.0.id', $segment->id)
        ->assertJsonPath('props.requests.data.0.id', $lead->id)
        ->assertJsonPath('props.analytics.summary.total', 1)->json('props');

    $json = $this->flushHeaders()->getJson(route('prospects.index'))->assertOk()->json();

    foreach (['requests', 'filters', 'stats', 'customers', 'statuses', 'lostReasonOptions', 'assignees', 'bulkActions', 'savedSegments', 'canManageSavedSegments', 'canExport', 'lead_intake', 'analytics'] as $prop) {
        expect($json[$prop])->toEqual($initial[$prop]);
    }
    Http::assertNothingSent();
});

it('keeps the legacy api response complete despite inertia partial headers', function (): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $lead = LeadRequest::query()->create(['user_id' => $owner->id, 'customer_id' => $customer->id, 'status' => LeadRequest::STATUS_NEW, 'title' => 'API lead']);
    Sanctum::actingAs($owner);

    $this->withHeaders(prospectPartialHeaders('filters'))->getJson('/api/v1/requests')->assertOk()
        ->assertJsonPath('requests.data.0.id', $lead->id)
        ->assertJsonPath('customers.0.id', $customer->id)
        ->assertJsonPath('stats.total', 1)
        ->assertJsonStructure(['filters', 'statuses', 'lostReasonOptions', 'assignees', 'bulkActions', 'savedSegments', 'canManageSavedSegments', 'canExport', 'lead_intake', 'analytics' => ['lead_form', 'conversion_by_source']]);
    Http::assertNothingSent();
});

it('shares the account assignee query with bulk action options', function (): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $assignee = TeamMember::factory()->create(['account_id' => $owner->id]);
    $this->actingAs($owner)->withHeaders(prospectPartialHeaders('assignees,bulkActions'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get(route('prospects.index'))->assertOk()->assertJsonCount(1, 'props.assignees')
        ->assertJsonPath('props.bulkActions.controls.assign.options.0.value', (string) $assignee->id)
        ->assertJsonMissingPath('props.requests')->assertJsonMissingPath('props.analytics');

    expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from team_members ') && str_contains($query, 'order by id')))->toHaveCount(1);
    Http::assertNothingSent();
});

it('denies unauthorized team members before evaluating partial data', function (): void {
    Http::preventStrayRequests();
    $owner = prospectPartialOwner();
    $memberUser = User::factory()->create(['company_type' => 'services', 'onboarding_completed_at' => now()]);
    TeamMember::factory()->create([
        'account_id' => $owner->id, 'user_id' => $memberUser->id,
        'role' => 'member', 'permissions' => ['quotes.view'],
    ]);
    $this->actingAs($memberUser)->withHeaders(prospectPartialHeaders('analytics,customers'));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson(route('prospects.index'))->assertForbidden();

    foreach (['requests', 'customers', 'properties'] as $table) {
        expect(collect(prospectPartialQueries())->filter(fn (string $query): bool => str_contains($query, 'from '.$table.' ')))->toBeEmpty();
    }
    Http::assertNothingSent();
});
