<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\SavedSegment;
use App\Models\User;
use App\Queries\Quotes\BuildQuoteRecoveryIndexData;
use App\Services\Requests\LeadTriageClassifier;
use App\Services\Segments\SegmentResolverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('resolves request saved segments with phase one triage filters and tenant isolation', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $matchingLead = LeadRequest::create([
            'user_id' => $owner->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Montreal office request',
            'service_type' => 'Cleaning',
            'contact_name' => 'Phase Three',
            'contact_email' => 'lead-one@example.com',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHours(2),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
            'status_updated_at' => $referenceTime->copy()->subDay(),
        ]);

        LeadRequest::create([
            'user_id' => $owner->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Toronto office request',
            'service_type' => 'Cleaning',
            'contact_name' => 'Phase Three',
            'contact_email' => 'lead-two@example.com',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHours(2),
            'next_follow_up_at' => $referenceTime->copy()->addHours(4),
            'status_updated_at' => $referenceTime->copy()->subDay(),
        ]);

        LeadRequest::create([
            'user_id' => $otherOwner->id,
            'status' => LeadRequest::STATUS_CONTACTED,
            'title' => 'Montreal office request',
            'service_type' => 'Cleaning',
            'contact_name' => 'Other Tenant',
            'contact_email' => 'lead-three@example.com',
            'first_response_at' => $referenceTime->copy()->subDay(),
            'last_activity_at' => $referenceTime->copy()->subHours(1),
            'next_follow_up_at' => $referenceTime->copy()->addHours(3),
            'status_updated_at' => $referenceTime->copy()->subDay(),
        ]);

        $segment = SavedSegment::create([
            'user_id' => $owner->id,
            'module' => SavedSegment::MODULE_REQUEST,
            'name' => 'Due soon Montreal leads',
            'filters' => [
                'queue' => LeadTriageClassifier::QUEUE_DUE_SOON,
            ],
            'search_term' => 'Montreal',
        ]);

        $resolved = app(SegmentResolverRegistry::class)->resolve($segment);

        expect($resolved['module'])->toBe(SavedSegment::MODULE_REQUEST)
            ->and($resolved['model_class'])->toBe(LeadRequest::class)
            ->and($resolved['ids'])->toBe([$matchingLead->id])
            ->and($resolved['selected_count'])->toBe(1)
            ->and($resolved['filters'])->toMatchArray([
                'queue' => LeadTriageClassifier::QUEUE_DUE_SOON,
                'search' => 'Montreal',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('resolves customer saved segments with customer filters and configured sorting', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $alpha = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Alpha',
        'last_name' => 'Owner',
        'company_name' => 'Alpha Services',
        'email' => 'alpha@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => true,
    ]);

    $zeta = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Zeta',
        'last_name' => 'Owner',
        'company_name' => 'Zeta Services',
        'email' => 'zeta@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => true,
    ]);

    $inactive = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Inactive',
        'last_name' => 'Owner',
        'company_name' => 'Inactive Services',
        'email' => 'inactive@example.com',
        'salutation' => 'Mr',
        'is_active' => false,
        'is_vip' => true,
    ]);

    $otherTenant = Customer::create([
        'user_id' => $otherOwner->id,
        'first_name' => 'Other',
        'last_name' => 'Owner',
        'company_name' => 'Other Services',
        'email' => 'other@example.com',
        'salutation' => 'Mr',
        'is_active' => true,
        'is_vip' => true,
    ]);

    foreach ([$alpha, $zeta, $inactive] as $customer) {
        Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'job_title' => 'Customer segment quote '.$customer->id,
            'status' => 'sent',
            'subtotal' => 500,
            'total' => 500,
        ]);
    }

    Quote::create([
        'user_id' => $otherOwner->id,
        'customer_id' => $otherTenant->id,
        'job_title' => 'Other tenant quote',
        'status' => 'sent',
        'subtotal' => 500,
        'total' => 500,
    ]);

    $segment = SavedSegment::create([
        'user_id' => $owner->id,
        'module' => SavedSegment::MODULE_CUSTOMER,
        'name' => 'Active VIP customers with quotes',
        'filters' => [
            'status' => 'active',
            'has_quotes' => true,
            'is_vip' => true,
        ],
        'sort' => [
            'column' => 'company_name',
            'direction' => 'asc',
        ],
    ]);

    $resolved = app(SegmentResolverRegistry::class)->resolve($segment);

    expect($resolved['module'])->toBe(SavedSegment::MODULE_CUSTOMER)
        ->and($resolved['model_class'])->toBe(Customer::class)
        ->and($resolved['ids'])->toBe([$alpha->id, $zeta->id])
        ->and($resolved['selected_count'])->toBe(2)
        ->and($resolved['sort'])->toBe([
            'column' => 'company_name',
            'direction' => 'asc',
        ])
        ->and($resolved['ids'])->not->toContain($inactive->id)
        ->and($resolved['ids'])->not->toContain($otherTenant->id);
});

it('resolves quote saved segments with phase two recovery queues and configured sorting', function () {
    $owner = User::factory()->create(['company_type' => 'services']);
    $otherOwner = User::factory()->create(['company_type' => 'services']);

    $ownerCustomer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Quote',
        'last_name' => 'Owner',
        'company_name' => 'Quote Segment Co',
        'email' => 'quote-owner@example.com',
        'salutation' => 'Mr',
    ]);

    $otherCustomer = Customer::create([
        'user_id' => $otherOwner->id,
        'first_name' => 'Other',
        'last_name' => 'Owner',
        'company_name' => 'Other Quote Segment Co',
        'email' => 'other-quote@example.com',
        'salutation' => 'Mr',
    ]);

    $referenceTime = Carbon::parse('2026-04-20 10:00:00');

    try {
        Carbon::setTestNow($referenceTime);

        $highestViewedQuote = Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $ownerCustomer->id,
            'job_title' => 'Viewed quote high total',
            'status' => 'sent',
            'subtotal' => 1800,
            'total' => 1800,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'last_viewed_at' => $referenceTime->copy()->subHours(4),
            'follow_up_count' => 1,
        ]);

        $lowerViewedQuote = Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $ownerCustomer->id,
            'job_title' => 'Viewed quote low total',
            'status' => 'sent',
            'subtotal' => 650,
            'total' => 650,
            'last_sent_at' => $referenceTime->copy()->subDays(2),
            'last_viewed_at' => $referenceTime->copy()->subHours(6),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $owner->id,
            'customer_id' => $ownerCustomer->id,
            'job_title' => 'Due quote only',
            'status' => 'sent',
            'subtotal' => 900,
            'total' => 900,
            'last_sent_at' => $referenceTime->copy()->subDays(4),
            'next_follow_up_at' => $referenceTime->copy()->addHours(3),
            'follow_up_count' => 1,
        ]);

        Quote::create([
            'user_id' => $otherOwner->id,
            'customer_id' => $otherCustomer->id,
            'job_title' => 'Other tenant viewed quote',
            'status' => 'sent',
            'subtotal' => 2200,
            'total' => 2200,
            'last_sent_at' => $referenceTime->copy()->subDays(3),
            'last_viewed_at' => $referenceTime->copy()->subHours(2),
            'follow_up_count' => 1,
        ]);

        $segment = SavedSegment::create([
            'user_id' => $owner->id,
            'module' => SavedSegment::MODULE_QUOTE,
            'name' => 'Viewed quotes by value',
            'filters' => [
                'queue' => BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED,
            ],
            'sort' => [
                'column' => 'total',
                'direction' => 'desc',
            ],
        ]);

        $resolved = app(SegmentResolverRegistry::class)->resolve($segment);

        expect($resolved['module'])->toBe(SavedSegment::MODULE_QUOTE)
            ->and($resolved['model_class'])->toBe(Quote::class)
            ->and($resolved['ids'])->toBe([$highestViewedQuote->id, $lowerViewedQuote->id])
            ->and($resolved['selected_count'])->toBe(2)
            ->and($resolved['filters'])->toMatchArray([
                'queue' => BuildQuoteRecoveryIndexData::QUEUE_VIEWED_NOT_ACCEPTED,
            ])
            ->and($resolved['sort'])->toBe([
                'column' => 'total',
                'direction' => 'desc',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});
