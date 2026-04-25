<?php

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Requests\LeadTriageClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds phase one request inbox columns to the requests table', function () {
    expect(Schema::hasColumns('requests', [
        'first_response_at',
        'last_activity_at',
        'sla_due_at',
        'triage_priority',
        'risk_level',
        'stale_since_at',
    ]))->toBeTrue();
});

it('persists and casts phase one request inbox fields', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $firstResponseAt = Carbon::parse('2026-04-20 09:15:00');
    $lastActivityAt = Carbon::parse('2026-04-20 10:30:00');
    $slaDueAt = Carbon::parse('2026-04-20 12:00:00');
    $staleSinceAt = Carbon::parse('2026-04-27 10:30:00');

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Inbox phase one lead',
        'first_response_at' => $firstResponseAt,
        'last_activity_at' => $lastActivityAt,
        'sla_due_at' => $slaDueAt,
        'triage_priority' => 85,
        'risk_level' => 'high',
        'stale_since_at' => $staleSinceAt,
    ]);

    $freshLead = $lead->fresh();

    expect($freshLead)->not->toBeNull()
        ->and($freshLead->first_response_at)->toBeInstanceOf(Carbon::class)
        ->and($freshLead->last_activity_at)->toBeInstanceOf(Carbon::class)
        ->and($freshLead->sla_due_at)->toBeInstanceOf(Carbon::class)
        ->and($freshLead->stale_since_at)->toBeInstanceOf(Carbon::class)
        ->and($freshLead->first_response_at?->equalTo($firstResponseAt))->toBeTrue()
        ->and($freshLead->last_activity_at?->equalTo($lastActivityAt))->toBeTrue()
        ->and($freshLead->sla_due_at?->equalTo($slaDueAt))->toBeTrue()
        ->and($freshLead->stale_since_at?->equalTo($staleSinceAt))->toBeTrue()
        ->and($freshLead->triage_priority)->toBe(85)
        ->and($freshLead->risk_level)->toBe('high');
});

it('classifies a fresh open lead as new and derives an initial response sla', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime->copy()->subHours(2));

        $lead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_NEW,
            'title' => 'Fresh lead',
        ]);

        $result = $classifier->classify($lead->fresh(), $referenceTime);

        expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_NEW)
            ->and($result['is_open'])->toBeTrue()
            ->and($result['is_new'])->toBeTrue()
            ->and($result['is_due_soon'])->toBeTrue()
            ->and($result['is_breached'])->toBeFalse()
            ->and($result['risk_level'])->toBe('medium')
            ->and($result['triage_priority'])->toBe(70)
            ->and($result['sla_due_at']?->equalTo($referenceTime->copy()->subHours(2)->addHours(24)))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('classifies a lead with a near follow up as due soon', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Due soon lead',
        'first_response_at' => $referenceTime->copy()->subDay(),
        'last_activity_at' => $referenceTime->copy()->subHours(3),
        'next_follow_up_at' => $referenceTime->copy()->addHours(4),
    ]);

    $result = $classifier->classify($lead->fresh(), $referenceTime);

    expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_DUE_SOON)
        ->and($result['is_due_soon'])->toBeTrue()
        ->and($result['is_breached'])->toBeFalse()
        ->and($result['risk_level'])->toBe('high')
        ->and($result['triage_priority'])->toBe(80);
});

it('classifies an inactive lead as stale', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Stale lead',
        'first_response_at' => $referenceTime->copy()->subDays(10),
        'last_activity_at' => $referenceTime->copy()->subDays(8),
    ]);

    $result = $classifier->classify($lead->fresh(), $referenceTime);

    expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_STALE)
        ->and($result['is_stale'])->toBeTrue()
        ->and($result['is_due_soon'])->toBeFalse()
        ->and($result['is_breached'])->toBeFalse()
        ->and($result['risk_level'])->toBe('high')
        ->and($result['stale_since_at']?->equalTo($referenceTime->copy()->subDays(8)->addDays(7)))->toBeTrue();
});

it('classifies an overdue follow up as breached', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Breached lead',
        'first_response_at' => $referenceTime->copy()->subDays(2),
        'last_activity_at' => $referenceTime->copy()->subDay(),
        'next_follow_up_at' => $referenceTime->copy()->subMinutes(30),
    ]);

    $result = $classifier->classify($lead->fresh(), $referenceTime);

    expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_BREACHED)
        ->and($result['is_breached'])->toBeTrue()
        ->and($result['risk_level'])->toBe('critical')
        ->and($result['triage_priority'])->toBe(100);
});

it('keeps closed lead statuses out of open triage queues', function (string $status) {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => $status,
        'title' => 'Closed lead',
        'first_response_at' => Carbon::parse('2026-04-18 09:00:00'),
        'last_activity_at' => Carbon::parse('2026-04-19 14:00:00'),
        'next_follow_up_at' => Carbon::parse('2026-04-20 10:00:00'),
    ]);

    $result = $classifier->classify($lead->fresh(), Carbon::parse('2026-04-20 09:00:00'));

    expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_CLOSED)
        ->and($result['is_open'])->toBeFalse()
        ->and($result['is_new'])->toBeFalse()
        ->and($result['is_due_soon'])->toBeFalse()
        ->and($result['is_stale'])->toBeFalse()
        ->and($result['is_breached'])->toBeFalse()
        ->and($result['risk_level'])->toBe('closed')
        ->and($result['triage_priority'])->toBe(0);
})->with([
    LeadRequest::STATUS_WON,
    LeadRequest::STATUS_LOST,
    LeadRequest::STATUS_CONVERTED,
]);

it('falls back to activity logs for first and last activity timestamps', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $createdAt = Carbon::parse('2026-04-20 08:00:00');
    $respondedAt = Carbon::parse('2026-04-20 10:00:00');
    $lastActivityAt = Carbon::parse('2026-04-20 11:30:00');

    try {
        Carbon::setTestNow($createdAt);
        $lead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_NEW,
            'title' => 'Activity fallback lead',
        ]);
        ActivityLog::record($user, $lead, 'created', [], 'Lead created');

        Carbon::setTestNow($respondedAt);
        ActivityLog::record($user, $lead, 'contacted', [], 'Lead contacted');

        Carbon::setTestNow($lastActivityAt);
        ActivityLog::record($user, $lead, 'note_added', [], 'Lead note added');

        $result = $classifier->classify($lead->fresh(), Carbon::parse('2026-04-20 12:00:00'));

        expect($result['first_response_at']?->equalTo($respondedAt))->toBeTrue()
            ->and($result['last_activity_at']?->equalTo($lastActivityAt))->toBeTrue()
            ->and($result['queue'])->toBe(LeadTriageClassifier::QUEUE_ACTIVE)
            ->and($result['is_new'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('orders request inbox results by triage urgency and exposes computed queue signals', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime->copy()->subHours(2));
        $newLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_NEW,
            'title' => 'Fresh lead',
        ]);

        Carbon::setTestNow($referenceTime);
        $dueSoonLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Due soon lead',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHours(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(2),
        ]);

        $staleLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Stale lead',
            'first_response_at' => $referenceTime->copy()->subDays(10),
            'last_activity_at' => $referenceTime->copy()->subDays(8),
        ]);

        $breachedLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Breached lead',
            'first_response_at' => $referenceTime->copy()->subDays(3),
            'last_activity_at' => $referenceTime->copy()->subDay(),
            'next_follow_up_at' => $referenceTime->copy()->subHour(),
        ]);

        $closedLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_WON,
            'title' => 'Closed lead',
            'first_response_at' => $referenceTime->copy()->subDays(5),
            'last_activity_at' => $referenceTime->copy()->subDays(2),
        ]);

        $response = $this->actingAs($user)->getJson(route('request.index'));

        $response->assertOk()
            ->assertJsonPath('stats.total', 5)
            ->assertJsonPath('stats.new_queue', 1)
            ->assertJsonPath('stats.due_soon', 1)
            ->assertJsonPath('stats.stale', 1)
            ->assertJsonPath('stats.breached', 1)
            ->assertJsonPath('requests.data.0.id', $breachedLead->id)
            ->assertJsonPath('requests.data.0.triage_queue', LeadTriageClassifier::QUEUE_BREACHED)
            ->assertJsonPath('requests.data.1.id', $dueSoonLead->id)
            ->assertJsonPath('requests.data.1.triage_queue', LeadTriageClassifier::QUEUE_DUE_SOON)
            ->assertJsonPath('requests.data.2.id', $newLead->id)
            ->assertJsonPath('requests.data.2.triage_queue', LeadTriageClassifier::QUEUE_NEW)
            ->assertJsonPath('requests.data.2.triage_is_new', true)
            ->assertJsonPath('requests.data.3.id', $staleLead->id)
            ->assertJsonPath('requests.data.3.triage_queue', LeadTriageClassifier::QUEUE_STALE)
            ->assertJsonPath('requests.data.4.id', $closedLead->id)
            ->assertJsonPath('requests.data.4.triage_queue', LeadTriageClassifier::QUEUE_CLOSED);
    } finally {
        Carbon::setTestNow();
    }
});

it('filters request inbox results by triage queue', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $staleLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Stale lead',
            'first_response_at' => $referenceTime->copy()->subDays(10),
            'last_activity_at' => $referenceTime->copy()->subDays(8),
        ]);

        LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Due soon lead',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHours(3),
            'next_follow_up_at' => $referenceTime->copy()->addHours(2),
        ]);

        $response = $this->actingAs($user)->getJson(route('request.index', [
            'queue' => LeadTriageClassifier::QUEUE_STALE,
        ]));

        $response->assertOk()
            ->assertJsonPath('filters.queue', LeadTriageClassifier::QUEUE_STALE)
            ->assertJsonPath('requests.total', 1)
            ->assertJsonPath('requests.data.0.id', $staleLead->id)
            ->assertJsonPath('requests.data.0.triage_queue', LeadTriageClassifier::QUEUE_STALE);
    } finally {
        Carbon::setTestNow();
    }
});

it('filters request inbox results with phase two prospect filters', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $assigneeUser = User::factory()->create();
    $assignee = TeamMember::factory()->create([
        'account_id' => $user->id,
        'user_id' => $assigneeUser->id,
        'is_active' => true,
    ]);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $matchingLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'assigned_team_member_id' => $assignee->id,
            'channel' => 'phone',
            'title' => 'Qualified phone prospect',
            'contact_name' => 'Prospect Match',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHour(),
            'next_follow_up_at' => $referenceTime->copy()->addHours(3),
            'triage_priority' => 78,
            'meta' => [
                'request_type' => 'phone_inquiry',
            ],
        ]);

        LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'assigned_team_member_id' => $assignee->id,
            'channel' => 'email',
            'title' => 'Different source prospect',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHour(),
            'next_follow_up_at' => $referenceTime->copy()->addHours(3),
            'triage_priority' => 78,
            'meta' => [
                'request_type' => 'phone_inquiry',
            ],
        ]);

        LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'phone',
            'title' => 'Unassigned overdue prospect',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subDays(2),
            'next_follow_up_at' => $referenceTime->copy()->subHours(2),
            'triage_priority' => 95,
            'meta' => [
                'request_type' => 'callback_request',
            ],
        ]);

        $response = $this->actingAs($user)->getJson(route('prospects.index', [
            'assigned_team_member_id' => $assignee->id,
            'source' => 'phone',
            'request_type' => 'phone',
            'priority' => 'high',
            'follow_up' => 'today',
        ]));

        $response->assertOk()
            ->assertJsonPath('filters.assigned_team_member_id', (string) $assignee->id)
            ->assertJsonPath('filters.source', 'phone')
            ->assertJsonPath('filters.request_type', 'phone')
            ->assertJsonPath('filters.priority', 'high')
            ->assertJsonPath('filters.follow_up', 'today')
            ->assertJsonPath('requests.total', 1)
            ->assertJsonPath('requests.data.0.id', $matchingLead->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('filters request inbox results for unassigned overdue prospects', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $assignee = TeamMember::factory()->create([
        'account_id' => $user->id,
        'user_id' => User::factory()->create()->id,
        'is_active' => true,
    ]);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $matchingLead = LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'email',
            'title' => 'Overdue unassigned prospect',
            'first_response_at' => $referenceTime->copy()->subDays(2),
            'last_activity_at' => $referenceTime->copy()->subDay(),
            'next_follow_up_at' => $referenceTime->copy()->subHours(4),
        ]);

        LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'assigned_team_member_id' => $assignee->id,
            'channel' => 'email',
            'title' => 'Assigned overdue prospect',
            'first_response_at' => $referenceTime->copy()->subDays(2),
            'last_activity_at' => $referenceTime->copy()->subDay(),
            'next_follow_up_at' => $referenceTime->copy()->subHours(3),
        ]);

        LeadRequest::create([
            'user_id' => $user->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'channel' => 'email',
            'title' => 'Unassigned future prospect',
            'first_response_at' => $referenceTime->copy()->subDays(2),
            'last_activity_at' => $referenceTime->copy()->subDay(),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
        ]);

        $response = $this->actingAs($user)->getJson(route('prospects.index', [
            'unassigned' => 1,
            'follow_up' => 'overdue',
        ]));

        $response->assertOk()
            ->assertJsonPath('filters.unassigned', true)
            ->assertJsonPath('filters.follow_up', 'overdue')
            ->assertJsonPath('requests.total', 1)
            ->assertJsonPath('requests.data.0.id', $matchingLead->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('treats archived prospects as closed in triage', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $classifier = app(LeadTriageClassifier::class);

    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    $lead = LeadRequest::create([
        'user_id' => $user->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Archived lead',
        'first_response_at' => $referenceTime->copy()->subDays(2),
        'last_activity_at' => $referenceTime->copy()->subDay(),
        'next_follow_up_at' => $referenceTime->copy()->subHours(2),
        'archived_at' => $referenceTime->copy()->subHour(),
    ]);

    $result = $classifier->classify($lead->fresh(), $referenceTime);

    expect($result['queue'])->toBe(LeadTriageClassifier::QUEUE_CLOSED)
        ->and($result['is_open'])->toBeFalse()
        ->and($result['is_due_soon'])->toBeFalse()
        ->and($result['is_breached'])->toBeFalse()
        ->and($result['risk_level'])->toBe('closed')
        ->and($result['triage_priority'])->toBe(0);
});
