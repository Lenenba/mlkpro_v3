<?php

use App\Models\SavedSegment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds phase three saved segments table with expected columns', function () {
    expect(Schema::hasTable('saved_segments'))->toBeTrue()
        ->and(Schema::hasColumns('saved_segments', [
            'user_id',
            'created_by_user_id',
            'updated_by_user_id',
            'module',
            'name',
            'description',
            'filters',
            'sort',
            'search_term',
            'is_shared',
            'cached_count',
            'last_resolved_at',
        ]))->toBeTrue();
});

it('persists and casts saved segment fields', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $actor = User::factory()->create(['company_type' => 'services']);
    $lastResolvedAt = Carbon::parse('2026-04-20 16:45:00');

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Due leads Montreal',
        'description' => 'Saved request inbox filter for due leads.',
        'filters' => [
            'queue' => 'due_soon',
            'assignee_id' => 12,
        ],
        'sort' => [
            'column' => 'sla_due_at',
            'direction' => 'asc',
        ],
        'search_term' => 'Montreal cleaning',
        'is_shared' => true,
        'cached_count' => 18,
        'last_resolved_at' => $lastResolvedAt,
    ]);

    $freshSegment = $segment->fresh();

    expect($freshSegment)->not->toBeNull()
        ->and($freshSegment->module)->toBe(SavedSegment::MODULE_REQUEST)
        ->and($freshSegment->filters)->toBe([
            'queue' => 'due_soon',
            'assignee_id' => 12,
        ])
        ->and($freshSegment->sort)->toBe([
            'column' => 'sla_due_at',
            'direction' => 'asc',
        ])
        ->and($freshSegment->search_term)->toBe('Montreal cleaning')
        ->and($freshSegment->is_shared)->toBeTrue()
        ->and($freshSegment->cached_count)->toBe(18)
        ->and($freshSegment->last_resolved_at)->toBeInstanceOf(Carbon::class)
        ->and($freshSegment->last_resolved_at?->equalTo($lastResolvedAt))->toBeTrue();
});

it('enforces uniqueness per tenant module and name while allowing the same name across modules', function () {
    $owner = User::factory()->create(['company_type' => 'services']);

    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Needs attention',
    ]);

    SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'name' => 'Needs attention',
    ]);

    expect(fn () => SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'name' => 'Needs attention',
    ]))->toThrow(QueryException::class);
});

it('isolates saved segments by tenant while allowing the same module and name for another tenant', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $ownerSegment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Inactive customers',
    ]);

    $otherTenantSegment = SavedSegment::create([
        'user_id' => $otherOwner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Inactive customers',
    ]);

    expect(SavedSegment::byUser($owner->id)->pluck('id')->all())->toBe([$ownerSegment->id])
        ->and(SavedSegment::byUser($otherOwner->id)->pluck('id')->all())->toBe([$otherTenantSegment->id]);
});

it('blocks crm request saved segment routes when the requests feature is unavailable', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'requests' => false,
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('crm.saved-segments.index', ['module' => SavedSegment::MODULE_REQUEST]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->postJson(route('crm.saved-segments.store'), [
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Blocked request segment',
            'filters' => [
                'queue' => 'due_soon',
            ],
        ])
        ->assertForbidden();
});
