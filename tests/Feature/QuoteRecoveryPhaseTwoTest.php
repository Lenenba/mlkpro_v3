<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Services\Quotes\QuoteRecoveryPriorityScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds phase two quote recovery columns to the quotes table', function () {
    expect(Schema::hasColumns('quotes', [
        'last_sent_at',
        'last_viewed_at',
        'last_followed_up_at',
        'next_follow_up_at',
        'follow_up_state',
        'follow_up_count',
        'recovery_priority',
    ]))->toBeTrue();
});

it('persists and casts phase two quote recovery fields', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Phase',
        'last_name' => 'Two',
        'company_name' => 'Recovery Co',
        'email' => 'phase-two@example.com',
        'salutation' => 'Mr',
    ]);

    $lastSentAt = Carbon::parse('2026-04-20 09:15:00');
    $lastViewedAt = Carbon::parse('2026-04-20 10:30:00');
    $lastFollowedUpAt = Carbon::parse('2026-04-20 11:45:00');
    $nextFollowUpAt = Carbon::parse('2026-04-22 14:00:00');

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Quote recovery phase two',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'initial_deposit' => 100,
        'is_fixed' => true,
        'last_sent_at' => $lastSentAt,
        'last_viewed_at' => $lastViewedAt,
        'last_followed_up_at' => $lastFollowedUpAt,
        'next_follow_up_at' => $nextFollowUpAt,
        'follow_up_state' => 'due',
        'follow_up_count' => 2,
        'recovery_priority' => 85,
    ]);

    $freshQuote = $quote->fresh();

    expect($freshQuote)->not->toBeNull()
        ->and($freshQuote->last_sent_at)->toBeInstanceOf(Carbon::class)
        ->and($freshQuote->last_viewed_at)->toBeInstanceOf(Carbon::class)
        ->and($freshQuote->last_followed_up_at)->toBeInstanceOf(Carbon::class)
        ->and($freshQuote->next_follow_up_at)->toBeInstanceOf(Carbon::class)
        ->and($freshQuote->last_sent_at?->equalTo($lastSentAt))->toBeTrue()
        ->and($freshQuote->last_viewed_at?->equalTo($lastViewedAt))->toBeTrue()
        ->and($freshQuote->last_followed_up_at?->equalTo($lastFollowedUpAt))->toBeTrue()
        ->and($freshQuote->next_follow_up_at?->equalTo($nextFollowUpAt))->toBeTrue()
        ->and($freshQuote->follow_up_state)->toBe('due')
        ->and($freshQuote->follow_up_count)->toBe(2)
        ->and($freshQuote->recovery_priority)->toBe(85);
});

it('classifies quote recovery queues on the quote index response', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Queue',
        'last_name' => 'Customer',
        'company_name' => 'Queue Recovery Co',
        'email' => 'queue-recovery@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $viewedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Viewed quote',
            'status' => 'sent',
            'subtotal' => 2400,
            'total' => 2400,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'last_viewed_at' => $referenceTime->copy()->subHours(6),
            'follow_up_count' => 1,
        ]);

        $dueQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Due quote',
            'status' => 'sent',
            'subtotal' => 900,
            'total' => 900,
            'last_sent_at' => $referenceTime->copy()->subDays(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
            'follow_up_count' => 1,
        ]);

        $highValueQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'High value quote',
            'status' => 'draft',
            'subtotal' => 5200,
            'total' => 5200,
            'follow_up_count' => 0,
        ]);

        $neverFollowedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Never followed quote',
            'status' => 'sent',
            'subtotal' => 700,
            'total' => 700,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'follow_up_count' => 0,
        ]);

        $expiredQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Expired quote',
            'status' => 'sent',
            'subtotal' => 650,
            'total' => 650,
            'last_sent_at' => $referenceTime->copy()->subDays(20),
            'follow_up_count' => 2,
        ]);

        $acceptedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Accepted quote',
            'status' => 'accepted',
            'subtotal' => 1200,
            'total' => 1200,
            'accepted_at' => $referenceTime->copy()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson(route('quote.index'));

        $response->assertOk()
            ->assertJsonPath('count', 6)
            ->assertJsonPath('stats.total', 6)
            ->assertJsonPath('stats.viewed_not_accepted', 1)
            ->assertJsonPath('stats.due', 1)
            ->assertJsonPath('stats.high_value', 1)
            ->assertJsonPath('stats.never_followed', 1)
            ->assertJsonPath('stats.expired', 1);

        $quotes = collect($response->json('quotes.data'))->keyBy('id');

        expect($quotes->get($viewedQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED)
            ->and($quotes->get($dueQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_DUE)
            ->and($quotes->get($highValueQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_HIGH_VALUE)
            ->and($quotes->get($neverFollowedQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_NEVER_FOLLOWED)
            ->and($quotes->get($expiredQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_EXPIRED)
            ->and($quotes->get($acceptedQuote->id)['recovery_queue'])->toBe(BuildQuoteRecoveryIndexData::QUEUE_CLOSED)
            ->and($quotes->get($acceptedQuote->id)['recovery_is_open'])->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});

it('filters quote index results by recovery queue', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Filter',
        'last_name' => 'Customer',
        'company_name' => 'Queue Filter Co',
        'email' => 'queue-filter@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $dueQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Queue due quote',
            'status' => 'sent',
            'subtotal' => 900,
            'total' => 900,
            'last_sent_at' => $referenceTime->copy()->subDays(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Queue never followed quote',
            'status' => 'sent',
            'subtotal' => 700,
            'total' => 700,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'follow_up_count' => 0,
        ]);

        $response = $this->actingAs($user)->getJson(route('quote.index', [
            'queue' => BuildQuoteRecoveryIndexData::QUEUE_DUE,
        ]));

        $response->assertOk()
            ->assertJsonPath('filters.queue', BuildQuoteRecoveryIndexData::QUEUE_DUE)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('quotes.total', 1)
            ->assertJsonPath('quotes.data.0.id', $dueQuote->id)
            ->assertJsonPath('quotes.data.0.recovery_queue', BuildQuoteRecoveryIndexData::QUEUE_DUE)
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.due', 1);
    } finally {
        Carbon::setTestNow();
    }
});

it('scores quote recovery priorities with labels and reasons on the quote index response', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Score',
        'last_name' => 'Customer',
        'company_name' => 'Priority Score Co',
        'email' => 'priority-score@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $urgentQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Urgent viewed quote',
            'status' => 'sent',
            'subtotal' => 2500,
            'total' => 2500,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'last_viewed_at' => $referenceTime->copy()->subHours(6),
            'follow_up_count' => 1,
        ]);

        $manualPriorityQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Manual priority quote',
            'status' => 'sent',
            'subtotal' => 800,
            'total' => 800,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'follow_up_count' => 1,
            'recovery_priority' => 42,
        ]);

        $response = $this->actingAs($user)->getJson(route('quote.index'));

        $response->assertOk()
            ->assertJsonPath('filters.sort', 'recovery_priority')
            ->assertJsonPath('filters.direction', 'desc');

        $quotes = collect($response->json('quotes.data'))->keyBy('id');

        expect($quotes->get($urgentQuote->id)['recovery_priority'])->toBe(100)
            ->and($quotes->get($urgentQuote->id)['recovery_priority_label'])->toBe(QuoteRecoveryPriorityScorer::LABEL_URGENT)
            ->and($quotes->get($urgentQuote->id)['recovery_priority_reason'])->toBe('Viewed recently without decision')
            ->and($quotes->get($manualPriorityQuote->id)['recovery_priority'])->toBe(42)
            ->and($quotes->get($manualPriorityQuote->id)['recovery_priority_label'])->toBe(QuoteRecoveryPriorityScorer::LABEL_LOW)
            ->and($quotes->get($manualPriorityQuote->id)['recovery_priority_reason'])->toBe('Manual recovery priority');
    } finally {
        Carbon::setTestNow();
    }
});

it('orders quote recovery results by computed priority when no explicit sort is provided', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Order',
        'last_name' => 'Customer',
        'company_name' => 'Priority Order Co',
        'email' => 'priority-order@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $viewedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Viewed first',
            'status' => 'sent',
            'subtotal' => 2400,
            'total' => 2400,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'last_viewed_at' => $referenceTime->copy()->subHours(6),
            'follow_up_count' => 1,
        ]);

        $dueQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Due second',
            'status' => 'sent',
            'subtotal' => 900,
            'total' => 900,
            'last_sent_at' => $referenceTime->copy()->subDays(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
            'follow_up_count' => 1,
        ]);

        $highValueQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'High third',
            'status' => 'draft',
            'subtotal' => 5200,
            'total' => 5200,
            'follow_up_count' => 0,
        ]);

        $neverFollowedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Never fourth',
            'status' => 'sent',
            'subtotal' => 700,
            'total' => 700,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'follow_up_count' => 0,
        ]);

        $expiredQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Expired fifth',
            'status' => 'sent',
            'subtotal' => 650,
            'total' => 650,
            'last_sent_at' => $referenceTime->copy()->subDays(20),
            'follow_up_count' => 2,
        ]);

        $acceptedQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Accepted last',
            'status' => 'accepted',
            'subtotal' => 1200,
            'total' => 1200,
            'accepted_at' => $referenceTime->copy()->subDay(),
        ]);

        $response = $this->actingAs($user)->getJson(route('quote.index'));

        $response->assertOk()
            ->assertJsonPath('quotes.data.0.id', $viewedQuote->id)
            ->assertJsonPath('quotes.data.1.id', $dueQuote->id)
            ->assertJsonPath('quotes.data.2.id', $highValueQuote->id)
            ->assertJsonPath('quotes.data.3.id', $neverFollowedQuote->id)
            ->assertJsonPath('quotes.data.4.id', $expiredQuote->id)
            ->assertJsonPath('quotes.data.5.id', $acceptedQuote->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps explicit quote sorting available when a concrete sort is requested', function () {
    $user = User::factory()->create(['company_type' => 'services']);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Explicit',
        'last_name' => 'Sort',
        'company_name' => 'Explicit Sort Co',
        'email' => 'explicit-sort@example.com',
        'salutation' => 'Mr',
    ]);

    try {
        Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00'));

        $newerQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Newer quote',
            'status' => 'draft',
            'subtotal' => 300,
            'total' => 300,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-18 10:00:00'));

        $olderQuote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Older quote',
            'status' => 'draft',
            'subtotal' => 400,
            'total' => 400,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-20 10:00:00'));

        $response = $this->actingAs($user)->getJson(route('quote.index', [
            'sort' => 'created_at',
            'direction' => 'asc',
        ]));

        $response->assertOk()
            ->assertJsonPath('filters.sort', 'created_at')
            ->assertJsonPath('filters.direction', 'asc')
            ->assertJsonPath('quotes.data.0.id', $olderQuote->id)
            ->assertJsonPath('quotes.data.1.id', $newerQuote->id);
    } finally {
        Carbon::setTestNow();
    }
});

it('updates quote recovery follow-up fields from quick actions', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'tasks' => true,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Quick',
        'last_name' => 'Follow Up',
        'company_name' => 'Quick Follow Up Co',
        'email' => 'quick-follow-up@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');
    $scheduledAt = $referenceTime->copy()->addDays(3)->setTime(9, 0);

    try {
        Carbon::setTestNow($referenceTime);

        $quote = Quote::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'job_title' => 'Quick follow-up quote',
            'status' => 'sent',
            'subtotal' => 1200,
            'total' => 1200,
            'follow_up_count' => 1,
            'next_follow_up_at' => $referenceTime->copy()->addDay(),
            'follow_up_state' => 'due',
        ]);

        $this->actingAs($user)
            ->patchJson(route('quote.recovery.update', $quote), [
                'next_follow_up_at' => $scheduledAt->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJsonPath('quote.follow_up_state', 'scheduled');

        $quote->refresh();

        expect($quote->next_follow_up_at?->toDateTimeString())->toBe($scheduledAt->toDateTimeString())
            ->and($quote->follow_up_count)->toBe(1)
            ->and($quote->follow_up_state)->toBe('scheduled');

        $this->actingAs($user)
            ->patchJson(route('quote.recovery.update', $quote), [
                'mark_followed_up' => true,
                'next_follow_up_at' => null,
            ])
            ->assertOk()
            ->assertJsonPath('quote.follow_up_state', 'completed');

        $quote->refresh();

        expect($quote->last_followed_up_at)->toBeInstanceOf(Carbon::class)
            ->and($quote->next_follow_up_at)->toBeNull()
            ->and($quote->follow_up_count)->toBe(2)
            ->and($quote->follow_up_state)->toBe('completed');
    } finally {
        Carbon::setTestNow();
    }
});

it('creates a recovery task from a quote quick action', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'tasks' => true,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Task',
        'last_name' => 'Recovery',
        'company_name' => 'Task Recovery Co',
        'email' => 'task-recovery@example.com',
        'salutation' => 'Mr',
    ]);

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Create recovery task quote',
        'status' => 'sent',
        'subtotal' => 1800,
        'total' => 1800,
        'next_follow_up_at' => Carbon::parse('2026-04-22 09:00:00'),
        'follow_up_count' => 1,
    ]);

    $this->actingAs($user)
        ->postJson(route('quote.recovery.task.store', $quote))
        ->assertCreated()
        ->assertJsonPath('task.customer_id', $customer->id)
        ->assertJsonPath('task.status', 'todo');

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->account_id)->toBe($user->id)
        ->and($task->customer_id)->toBe($customer->id)
        ->and($task->title)->toContain('Follow up')
        ->and($task->description)->toContain('Create recovery task quote')
        ->and(optional($task->due_date)->toDateString())->toBe('2026-04-22');
});

it('records readable quote recovery activity and exposes it on quote show', function () {
    $user = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'quotes' => true,
            'tasks' => true,
        ],
    ]);

    $customer = Customer::create([
        'user_id' => $user->id,
        'first_name' => 'Activity',
        'last_name' => 'Recovery',
        'company_name' => 'Activity Recovery Co',
        'email' => 'activity-recovery@example.com',
        'salutation' => 'Mr',
    ]);

    $quote = Quote::create([
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'job_title' => 'Quote activity recovery',
        'status' => 'sent',
        'subtotal' => 2100,
        'total' => 2100,
        'follow_up_count' => 0,
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');
    $scheduledAt = Carbon::parse('2026-04-23 09:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $this->actingAs($user)
            ->patchJson(route('quote.recovery.update', $quote), [
                'next_follow_up_at' => $scheduledAt->toDateTimeString(),
            ])
            ->assertOk();

        Carbon::setTestNow($referenceTime->copy()->addMinutes(10));

        $this->actingAs($user)
            ->patchJson(route('quote.recovery.update', $quote), [
                'next_follow_up_at' => $scheduledAt->toDateTimeString(),
            ])
            ->assertOk();

        Carbon::setTestNow($referenceTime->copy()->addMinutes(20));

        $this->actingAs($user)
            ->patchJson(route('quote.recovery.update', $quote), [
                'mark_followed_up' => true,
                'next_follow_up_at' => null,
            ])
            ->assertOk();

        Carbon::setTestNow($referenceTime->copy()->addMinutes(30));

        $taskResponse = $this->actingAs($user)
            ->postJson(route('quote.recovery.task.store', $quote))
            ->assertCreated();

        $activity = ActivityLog::query()
            ->where('subject_type', $quote->getMorphClass())
            ->where('subject_id', $quote->id)
            ->latest()
            ->get();

        expect($activity)->toHaveCount(3)
            ->and($activity->pluck('description')->all())->toBe([
                'Recovery task created from quote',
                'Quote follow-up completed',
                'Quote follow-up scheduled',
            ])
            ->and($activity->pluck('action')->all())->toBe([
                'quote_follow_up_task_created',
                'quote_follow_up_completed',
                'quote_follow_up_scheduled',
            ])
            ->and($activity->first()->properties['task_id'] ?? null)->toBe($taskResponse->json('task.id'));

        $this->actingAs($user)
            ->getJson(route('customer.quote.show', $quote))
            ->assertOk()
            ->assertJsonCount(3, 'activity')
            ->assertJsonPath('activity.0.description', 'Recovery task created from quote')
            ->assertJsonPath('activity.1.description', 'Quote follow-up completed')
            ->assertJsonPath('activity.2.description', 'Quote follow-up scheduled')
            ->assertJsonPath('activity.0.user.name', $user->name);
    } finally {
        Carbon::setTestNow();
    }
});
