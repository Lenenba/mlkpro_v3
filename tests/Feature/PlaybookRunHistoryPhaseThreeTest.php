<?php

use App\Models\Playbook;
use App\Models\PlaybookRun;
use App\Models\SavedSegment;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function playbookHistoryOwner(array $features = []): User
{
    return User::factory()->create([
        'company_type' => 'services',
        'company_features' => array_merge([
            'requests' => true,
            'quotes' => true,
        ], $features),
    ]);
}

function playbookHistorySegment(User $owner, string $module, string $name): SavedSegment
{
    return SavedSegment::create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => $module,
        'name' => $name,
    ]);
}

function playbookHistoryPlaybook(User $owner, SavedSegment $segment, string $name, string $action): Playbook
{
    return Playbook::create([
        'user_id' => $owner->id,
        'saved_segment_id' => $segment->id,
        'created_by_user_id' => $owner->id,
        'updated_by_user_id' => $owner->id,
        'module' => $segment->module,
        'name' => $name,
        'action_key' => $action,
        'schedule_type' => Playbook::SCHEDULE_MANUAL,
        'is_active' => true,
    ]);
}

it('shows playbook run history with counters and summaries for the owner', function () {
    $owner = playbookHistoryOwner();

    $requestSegment = playbookHistorySegment($owner, SavedSegment::MODULE_REQUEST, 'Requests due');
    $requestPlaybook = playbookHistoryPlaybook($owner, $requestSegment, 'Assign requests', 'assign_selected');
    $requestRun = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $requestPlaybook->id,
        'saved_segment_id' => $requestSegment->id,
        'requested_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'action_key' => 'assign_selected',
        'origin' => PlaybookRun::ORIGIN_MANUAL,
        'status' => PlaybookRun::STATUS_COMPLETED,
        'selected_count' => 8,
        'processed_count' => 8,
        'success_count' => 7,
        'failed_count' => 1,
        'skipped_count' => 0,
        'summary' => [
            'message' => 'Requests updated.',
            'errors' => [],
        ],
    ]);

    $quoteSegment = playbookHistorySegment($owner, SavedSegment::MODULE_QUOTE, 'Quotes viewed');
    $quotePlaybook = playbookHistoryPlaybook($owner, $quoteSegment, 'Create recovery tasks', 'create_follow_up_task');
    $quoteRun = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $quotePlaybook->id,
        'saved_segment_id' => $quoteSegment->id,
        'requested_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'action_key' => 'create_follow_up_task',
        'origin' => PlaybookRun::ORIGIN_SCHEDULED,
        'status' => PlaybookRun::STATUS_FAILED,
        'selected_count' => 1,
        'processed_count' => 0,
        'success_count' => 0,
        'failed_count' => 0,
        'skipped_count' => 1,
        'summary' => [
            'message' => 'Tasks module is unavailable for this account.',
            'errors' => ['Tasks module is unavailable for this account.'],
        ],
    ]);

    $this->actingAs($owner)
        ->get(route('crm.playbook-runs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Campaigns/PlaybookRuns')
            ->where('stats.total', 2)
            ->where('stats.completed', 1)
            ->where('stats.failed', 1)
            ->where('stats.processed', 8)
            ->where('stats.skipped', 1)
            ->has('runs.data', 2)
        );

    $this->actingAs($owner)
        ->getJson(route('crm.playbook-runs.index'))
        ->assertOk()
        ->assertJsonPath('stats.total', 2)
        ->assertJsonPath('stats.completed', 1)
        ->assertJsonPath('stats.failed', 1)
        ->assertJsonPath('stats.processed', 8)
        ->assertJsonPath('stats.skipped', 1)
        ->assertJsonFragment([
            'id' => $requestRun->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'status' => PlaybookRun::STATUS_COMPLETED,
        ])
        ->assertJsonFragment([
            'id' => $quoteRun->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'status' => PlaybookRun::STATUS_FAILED,
        ])
        ->assertJsonFragment([
            'message' => 'Requests updated.',
        ])
        ->assertJsonFragment([
            'message' => 'Tasks module is unavailable for this account.',
        ]);
});

it('limits playbook run history to modules visible to the current team member', function () {
    $owner = playbookHistoryOwner();
    $member = User::factory()->create(['company_type' => 'services']);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'permissions' => ['quotes.view'],
        'is_active' => true,
    ]);

    $requestSegment = playbookHistorySegment($owner, SavedSegment::MODULE_REQUEST, 'Owner requests');
    $requestPlaybook = playbookHistoryPlaybook($owner, $requestSegment, 'Request owner playbook', 'assign_selected');
    PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $requestPlaybook->id,
        'saved_segment_id' => $requestSegment->id,
        'requested_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_REQUEST,
        'action_key' => 'assign_selected',
        'origin' => PlaybookRun::ORIGIN_MANUAL,
        'status' => PlaybookRun::STATUS_COMPLETED,
        'selected_count' => 3,
        'processed_count' => 3,
        'success_count' => 3,
        'failed_count' => 0,
        'skipped_count' => 0,
        'summary' => [
            'message' => 'Hidden request run.',
        ],
    ]);

    $quoteSegment = playbookHistorySegment($owner, SavedSegment::MODULE_QUOTE, 'Visible quotes');
    $quotePlaybook = playbookHistoryPlaybook($owner, $quoteSegment, 'Visible quote playbook', 'create_follow_up_task');
    $quoteRun = PlaybookRun::create([
        'user_id' => $owner->id,
        'playbook_id' => $quotePlaybook->id,
        'saved_segment_id' => $quoteSegment->id,
        'requested_by_user_id' => $owner->id,
        'module' => SavedSegment::MODULE_QUOTE,
        'action_key' => 'create_follow_up_task',
        'origin' => PlaybookRun::ORIGIN_SCHEDULED,
        'status' => PlaybookRun::STATUS_COMPLETED,
        'selected_count' => 2,
        'processed_count' => 2,
        'success_count' => 2,
        'failed_count' => 0,
        'skipped_count' => 0,
        'summary' => [
            'message' => 'Visible quote run.',
        ],
    ]);

    $this->actingAs($member)
        ->getJson(route('crm.playbook-runs.index'))
        ->assertOk()
        ->assertJsonPath('stats.total', 1)
        ->assertJsonCount(1, 'runs.data')
        ->assertJsonPath('runs.data.0.id', $quoteRun->id)
        ->assertJsonPath('runs.data.0.module', SavedSegment::MODULE_QUOTE);

    $this->actingAs($member)
        ->getJson(route('crm.playbook-runs.index', ['module' => SavedSegment::MODULE_REQUEST]))
        ->assertStatus(422);
});
