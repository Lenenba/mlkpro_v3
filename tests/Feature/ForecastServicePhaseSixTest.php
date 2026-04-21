<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\CRM\SalesForecastService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('sales forecast service builds a weighted forecast snapshot from projected opportunities', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = forecastPhaseSixOwner();

    $intakeCustomer = forecastPhaseSixCustomer($owner, 'Fresh Intake Co', 'fresh-intake@example.test');
    $intakeLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $intakeCustomer->id,
        'status' => LeadRequest::STATUS_NEW,
        'title' => 'Fresh intake opportunity',
        'service_type' => 'Inspection',
    ]);
    forecastPhaseSixBackdate($intakeLead, '2026-04-24 09:00:00');

    $qualifiedCustomer = forecastPhaseSixCustomer($owner, 'Qualified Co', 'qualified@example.test');
    $qualifiedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $qualifiedCustomer->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'title' => 'Qualified opportunity',
        'service_type' => 'Maintenance',
        'next_follow_up_at' => '2026-04-27 09:00:00',
    ]);
    forecastPhaseSixBackdate($qualifiedLead, '2026-04-10 09:00:00');

    $quotedCustomer = forecastPhaseSixCustomer($owner, 'Quoted Co', 'quoted@example.test');
    $quotedLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Quoted expansion opportunity',
        'service_type' => 'Install',
    ]);
    forecastPhaseSixBackdate($quotedLead, '2026-04-01 09:00:00');
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $quotedCustomer->id,
        'request_id' => $quotedLead->id,
        'job_title' => 'Quoted expansion proposal',
        'status' => 'sent',
        'subtotal' => 2000,
        'total' => 2000,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-26 09:00:00',
    ]);

    $orphanCustomer = forecastPhaseSixCustomer($owner, 'Orphan Quote Co', 'orphan@example.test');
    $orphanQuote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $orphanCustomer->id,
        'job_title' => 'Standalone quote upsell',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-24 09:00:00',
    ]);
    forecastPhaseSixBackdate($orphanQuote, '2026-03-20 09:00:00');

    $wonCustomer = forecastPhaseSixCustomer($owner, 'Won Co', 'won@example.test');
    $wonLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Closed won opportunity',
        'service_type' => 'Upgrade',
        'created_at' => '2026-04-05 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $wonCustomer->id,
        'request_id' => $wonLead->id,
        'job_title' => 'Closed won proposal',
        'status' => 'accepted',
        'subtotal' => 3000,
        'total' => 3000,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-12 09:00:00',
        'created_at' => '2026-04-06 09:00:00',
    ]);

    $lostCustomer = forecastPhaseSixCustomer($owner, 'Lost Co', 'lost@example.test');
    $lostLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'status' => LeadRequest::STATUS_LOST,
        'title' => 'Closed lost opportunity',
        'service_type' => 'Audit',
        'created_at' => '2026-04-03 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $lostCustomer->id,
        'request_id' => $lostLead->id,
        'job_title' => 'Closed lost proposal',
        'status' => 'declined',
        'subtotal' => 500,
        'total' => 500,
        'currency_code' => 'USD',
        'created_at' => '2026-04-04 09:00:00',
    ]);

    $result = app(SalesForecastService::class)->execute($owner->id, [
        'reference_time' => $referenceTime->toIso8601String(),
    ]);

    $categories = collect($result['categories'])->keyBy('key');
    $stages = collect($result['stages'])->keyBy('key');
    $aging = collect($result['aging'])->keyBy('key');

    expect($result['summary'])->toMatchArray([
        'total' => 6,
        'open_count' => 4,
        'won_count' => 1,
        'lost_count' => 1,
        'open_amount' => 2800.0,
        'weighted_open_amount' => 2100.0,
        'pipeline_open_amount' => 0.0,
        'pipeline_weighted_amount' => 0.0,
        'best_case_open_amount' => 2800.0,
        'best_case_weighted_amount' => 2100.0,
        'overdue_next_actions' => 1,
        'month_to_date_won_amount' => 3000.0,
        'quarter_to_date_won_amount' => 3000.0,
        'year_to_date_won_amount' => 3000.0,
    ])
        ->and(data_get($categories->get('pipeline'), 'count'))->toBe(2)
        ->and(data_get($categories->get('best_case'), 'count'))->toBe(2)
        ->and(data_get($categories->get('best_case'), 'amount_total'))->toBe(2800.0)
        ->and(data_get($categories->get('best_case'), 'weighted_amount'))->toBe(2100.0)
        ->and(data_get($categories->get('closed_won'), 'count'))->toBe(1)
        ->and(data_get($categories->get('closed_won'), 'amount_total'))->toBe(3000.0)
        ->and(data_get($categories->get('closed_lost'), 'count'))->toBe(1)
        ->and(data_get($categories->get('closed_lost'), 'weighted_amount'))->toBe(0.0)
        ->and(data_get($stages->get('intake'), 'count'))->toBe(1)
        ->and(data_get($stages->get('qualified'), 'count'))->toBe(1)
        ->and(data_get($stages->get('quoted'), 'count'))->toBe(2)
        ->and(data_get($stages->get('quoted'), 'weighted_amount'))->toBe(2100.0)
        ->and(data_get($stages->get('quoted'), 'average_age_days'))->toBe(30.0)
        ->and(data_get($stages->get('quoted'), 'overdue_next_actions'))->toBe(1)
        ->and(data_get($aging->get('0_7'), 'count'))->toBe(1)
        ->and(data_get($aging->get('8_14'), 'count'))->toBe(0)
        ->and(data_get($aging->get('15_30'), 'count'))->toBe(2)
        ->and(data_get($aging->get('15_30'), 'amount_total'))->toBe(2000.0)
        ->and(data_get($aging->get('15_30'), 'weighted_amount'))->toBe(1500.0)
        ->and(data_get($aging->get('31_plus'), 'count'))->toBe(1)
        ->and(data_get($aging->get('31_plus'), 'amount_total'))->toBe(800.0)
        ->and(data_get($aging->get('31_plus'), 'weighted_amount'))->toBe(600.0)
        ->and(data_get($result, 'next_actions.overdue.count'))->toBe(1)
        ->and(data_get($result, 'next_actions.scheduled.count'))->toBe(2)
        ->and(data_get($result, 'next_actions.none.count'))->toBe(1)
        ->and(data_get($result, 'wins.month_to_date.count'))->toBe(1)
        ->and(data_get($result, 'wins.month_to_date.amount_total'))->toBe(3000.0)
        ->and(data_get($result, 'wins.quarter_to_date.count'))->toBe(1)
        ->and(data_get($result, 'wins.year_to_date.count'))->toBe(1);
});

test('sales forecast service supports scoped search and customer filters for manager drill downs', function () {
    $referenceTime = Carbon::parse('2026-04-25 09:00:00');
    $owner = forecastPhaseSixOwner();

    $northwind = forecastPhaseSixCustomer($owner, 'Northwind Group', 'northwind@example.test');
    $northwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Northwind rollout',
        'service_type' => 'Install',
        'created_at' => '2026-04-12 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $northwind->id,
        'request_id' => $northwindLead->id,
        'job_title' => 'Northwind rollout quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'currency_code' => 'USD',
        'next_follow_up_at' => '2026-04-27 09:00:00',
        'created_at' => '2026-04-13 09:00:00',
    ]);

    $southwind = forecastPhaseSixCustomer($owner, 'Southwind Group', 'southwind@example.test');
    $southwindLead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'status' => LeadRequest::STATUS_WON,
        'title' => 'Southwind completed deal',
        'service_type' => 'Audit',
        'created_at' => '2026-04-01 09:00:00',
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $southwind->id,
        'request_id' => $southwindLead->id,
        'job_title' => 'Southwind won quote',
        'status' => 'accepted',
        'subtotal' => 900,
        'total' => 900,
        'currency_code' => 'USD',
        'accepted_at' => '2026-04-05 09:00:00',
        'created_at' => '2026-04-02 09:00:00',
    ]);

    $searchResult = app(SalesForecastService::class)->execute($owner->id, [
        'reference_time' => $referenceTime->toIso8601String(),
        'search' => 'northwind',
    ]);

    expect($searchResult['filters'])->toMatchArray([
        'search' => 'northwind',
        'customer_id' => null,
    ])
        ->and($searchResult['summary'])->toMatchArray([
            'total' => 1,
            'open_count' => 1,
            'won_count' => 0,
            'open_amount' => 1200.0,
            'weighted_open_amount' => 900.0,
        ])
        ->and(collect($searchResult['stages'])->firstWhere('key', 'quoted')['count'])->toBe(1)
        ->and(data_get($searchResult, 'wins.month_to_date.count'))->toBe(0);

    $customerResult = app(SalesForecastService::class)->execute($owner->id, [
        'reference_time' => $referenceTime->toIso8601String(),
        'customer_id' => $southwind->id,
    ]);

    expect($customerResult['filters'])->toMatchArray([
        'search' => '',
        'customer_id' => $southwind->id,
    ])
        ->and($customerResult['summary'])->toMatchArray([
            'total' => 1,
            'open_count' => 0,
            'won_count' => 1,
            'month_to_date_won_amount' => 900.0,
        ])
        ->and(data_get($customerResult, 'wins.month_to_date.count'))->toBe(1)
        ->and(data_get($customerResult, 'categories.2.amount_total'))->toBe(900.0);
});

function forecastPhaseSixOwner(array $overrides = []): User
{
    return User::factory()->create(array_replace_recursive([
        'role_id' => forecastPhaseSixRoleId('owner', 'Phase six forecast owner role'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'two_factor_exempt' => false,
    ], $overrides));
}

function forecastPhaseSixRoleId(string $name, string $description): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $description],
    )->id;
}

function forecastPhaseSixCustomer(User $owner, string $companyName, string $email): Customer
{
    return Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => $companyName,
        'email' => $email,
    ]);
}

function forecastPhaseSixBackdate(Model $model, string $timestamp): void
{
    $attributes = [
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ];

    $model->newQuery()->whereKey($model->getKey())->update($attributes);
    $model->forceFill($attributes);
}
