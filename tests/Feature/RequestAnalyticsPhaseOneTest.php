<?php

use App\Models\ActivityLog;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function seedRequestAnalyticsPhaseOneData(User $user, Carbon $referenceTime): array
{
    $createLead = function (Carbon $createdAt, array $attributes) use ($user): LeadRequest {
        Carbon::setTestNow($createdAt);

        return LeadRequest::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Analytics lead',
        ], $attributes));
    };

    $recordAction = function (LeadRequest $lead, Carbon $actionAt, string $action, string $description) use ($user): void {
        Carbon::setTestNow($actionAt);
        ActivityLog::record($user, $lead, $action, [], $description);
    };

    $wonLead = $createLead(
        Carbon::parse('2026-04-18 08:00:00'),
        ['status' => LeadRequest::STATUS_WON, 'title' => 'Won lead']
    );
    $recordAction($wonLead, Carbon::parse('2026-04-18 12:00:00'), 'contacted', 'Won lead contacted');

    $dueSoonLead = $createLead(
        Carbon::parse('2026-04-19 08:00:00'),
        [
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Due soon lead',
            'next_follow_up_at' => Carbon::parse('2026-04-20 11:00:00'),
        ]
    );
    $recordAction($dueSoonLead, Carbon::parse('2026-04-19 14:00:00'), 'contacted', 'Due soon lead contacted');

    $staleLead = $createLead(
        Carbon::parse('2026-03-10 09:00:00'),
        [
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Stale lead',
            'first_response_at' => Carbon::parse('2026-03-10 13:00:00'),
            'last_activity_at' => Carbon::parse('2026-04-10 09:00:00'),
        ]
    );

    $breachedLead = $createLead(
        Carbon::parse('2026-03-12 09:00:00'),
        [
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Breached lead',
            'first_response_at' => Carbon::parse('2026-03-12 11:00:00'),
            'last_activity_at' => Carbon::parse('2026-04-18 09:00:00'),
            'next_follow_up_at' => Carbon::parse('2026-04-20 08:00:00'),
        ]
    );

    Carbon::setTestNow($referenceTime);

    return [
        'won' => $wonLead,
        'due_soon' => $dueSoonLead,
        'stale' => $staleLead,
        'breached' => $breachedLead,
    ];
}

it('exposes phase one request analytics and triage stats in json responses', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);
        $leads = seedRequestAnalyticsPhaseOneData($user, $referenceTime);

        $response = $this->actingAs($user)->getJson(route('request.index'));

        $response->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'total',
                    'new',
                    'in_progress',
                    'due_soon',
                    'stale',
                    'breached',
                    'won',
                    'lost',
                    'unassigned',
                ],
                'analytics' => [
                    'window_days',
                    'total',
                    'won',
                    'avg_first_response_hours',
                    'avg_time_to_intake_hours',
                    'conversion_rate',
                    'conversion_by_source',
                    'stale_count',
                    'breached_count',
                    'lead_form',
                    'risk_leads',
                ],
            ])
            ->assertJsonPath('stats.total', 4)
            ->assertJsonPath('stats.in_progress', 3)
            ->assertJsonPath('stats.due_soon', 1)
            ->assertJsonPath('stats.stale', 1)
            ->assertJsonPath('stats.breached', 1)
            ->assertJsonPath('analytics.total', 2)
            ->assertJsonPath('analytics.won', 1)
            ->assertJsonPath('analytics.avg_first_response_hours', 5)
            ->assertJsonPath('analytics.avg_time_to_intake_hours', 5)
            ->assertJsonPath('analytics.conversion_rate', 50)
            ->assertJsonPath('analytics.stale_count', 1)
            ->assertJsonPath('analytics.breached_count', 1)
            ->assertJsonPath('analytics.risk_leads.0.id', $leads['breached']->id)
            ->assertJsonPath('analytics.risk_leads.0.triage_queue', 'breached')
            ->assertJsonPath('analytics.risk_leads.1.id', $leads['stale']->id)
            ->assertJsonPath('analytics.risk_leads.1.triage_queue', 'stale');
    } finally {
        Carbon::setTestNow();
    }
});

it('exposes phase one request analytics in inertia responses', function () {
    $user = User::factory()->create(['company_type' => 'services']);
    $referenceTime = Carbon::parse('2026-04-20 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);
        seedRequestAnalyticsPhaseOneData($user, $referenceTime);

        $this->actingAs($user)
            ->get(route('request.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Request/Index')
                ->where('stats.due_soon', 1)
                ->where('stats.stale', 1)
                ->where('stats.breached', 1)
                ->where('analytics.avg_first_response_hours', 5)
                ->where('analytics.avg_time_to_intake_hours', 5)
                ->where('analytics.stale_count', 1)
                ->where('analytics.breached_count', 1)
                ->where('analytics.conversion_rate', 50)
                ->has('analytics.risk_leads', 2)
            );
    } finally {
        Carbon::setTestNow();
    }
});
