<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('defines canonical and legacy sales activity actions for phase four', function () {
    $canonical = SalesActivityTaxonomy::definition('sales_call_no_answer');
    $legacy = SalesActivityTaxonomy::definition('quote_follow_up_scheduled');
    $quickActions = SalesActivityTaxonomy::quickActions();
    $manualActions = SalesActivityTaxonomy::manualActionDefinitions();

    expect($canonical)->not->toBeNull()
        ->and($canonical['type'])->toBe(SalesActivityTaxonomy::TYPE_CALL_OUTCOME)
        ->and($canonical['activity_key'])->toBe('sales_call_no_answer')
        ->and($legacy)->not->toBeNull()
        ->and($legacy['type'])->toBe(SalesActivityTaxonomy::TYPE_NEXT_ACTION)
        ->and($legacy['activity_key'])->toBe('sales_next_action_scheduled')
        ->and($legacy['legacy'])->toBeTrue()
        ->and(SalesActivityTaxonomy::isSalesActivity('quote_follow_up_completed'))->toBeTrue()
        ->and(SalesActivityTaxonomy::isSalesActivity('updated'))->toBeFalse()
        ->and($quickActions['callback_tomorrow']['action'])->toBe('sales_next_action_scheduled')
        ->and($quickActions['quote_discussed']['label'])->toBe('Devis discute')
        ->and($manualActions[0]['action'])->toBe('sales_note_added')
        ->and($manualActions[0]['legacy'])->toBeFalse();
});

it('serializes sales activity metadata on activity logs and filters them with the sales scope', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Sales',
        'last_name' => 'Activity',
        'company_name' => 'Sales Activity Inc.',
        'email' => 'sales-activity@example.com',
    ]);

    $dueAt = '2026-04-21T09:30:00+00:00';

    $salesLog = ActivityLog::record($owner, $customer, 'quote_follow_up_scheduled', [
        'next_follow_up_at' => $dueAt,
    ], 'Quote follow-up scheduled');

    $genericLog = ActivityLog::record($owner, $customer, 'updated', [], 'Customer updated');

    $salesPayload = $salesLog->fresh()->toArray();
    $genericPayload = $genericLog->fresh()->toArray();

    expect($salesPayload['is_sales_activity'])->toBeTrue()
        ->and($salesPayload['sales_activity']['type'])->toBe(SalesActivityTaxonomy::TYPE_NEXT_ACTION)
        ->and($salesPayload['sales_activity']['activity_key'])->toBe('sales_next_action_scheduled')
        ->and($salesPayload['sales_activity']['due_at'])->toBe($dueAt)
        ->and($genericPayload['is_sales_activity'])->toBeFalse()
        ->and($genericPayload['sales_activity'])->toBeNull()
        ->and(ActivityLog::query()->salesActivity()->pluck('id')->all())->toBe([$salesLog->id]);
});

test('customer show exposes sales activity metadata in the activity payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Timeline',
        'company_name' => 'Timeline Customer Inc.',
        'email' => 'customer-timeline@example.com',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Timeline quote',
        'status' => 'sent',
        'subtotal' => 950,
        'total' => 950,
        'initial_deposit' => 0,
    ]);

    ActivityLog::record($owner, $quote, 'quote_follow_up_task_created', [
        'task_id' => 77,
        'task_title' => 'Call back prospect',
        'task_due_date' => '2026-04-22',
    ], 'Recovery task created from quote');

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.subject', 'Quote')
            ->where('activity.0.is_sales_activity', true)
            ->where('activity.0.sales_activity.type', SalesActivityTaxonomy::TYPE_NEXT_ACTION)
            ->where('activity.0.sales_activity.activity_key', 'sales_next_action_scheduled')
            ->where('activity.0.sales_activity.task_id', 77)
            ->where('activity.0.sales_activity.task_title', 'Call back prospect')
            ->where('activity.0.sales_activity.due_at', '2026-04-22')
        );
});
