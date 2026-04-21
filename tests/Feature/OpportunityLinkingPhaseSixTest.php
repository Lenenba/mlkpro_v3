<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Models\Work;
use App\Support\CRM\CrmOpportunityLinking;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('crm opportunity linking keeps quote as subject and request as primary for request backed opportunities', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Linking Opportunity Co',
        'email' => 'linking-opportunity@example.test',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'title' => 'Request backed opportunity',
        'service_type' => 'Maintenance',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Request backed quote',
        'status' => 'sent',
        'subtotal' => 1000,
        'total' => 1200,
        'currency_code' => 'USD',
    ]);

    $work = Work::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
        'job_title' => 'Request backed work',
        'instructions' => 'Deliver the sold scope.',
        'status' => 'in_progress',
        'start_date' => '2026-04-22',
        'end_date' => '2026-04-22',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'is_all_day' => false,
        'later' => false,
        'ends' => 'Never',
        'frequencyNumber' => 1,
        'frequency' => 'Weekly',
        'totalVisits' => 1,
        'repeatsOn' => [],
        'subtotal' => 1200,
        'total' => 1200,
    ]);

    $invoice = Invoice::query()->create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'status' => 'partial',
        'total' => 1200,
    ]);

    $links = CrmOpportunityLinking::present($lead, $quote, $work, $invoice);

    expect(data_get($links, 'subject.type'))->toBe('quote')
        ->and(data_get($links, 'subject.id'))->toBe($quote->id)
        ->and(data_get($links, 'primary.type'))->toBe('request')
        ->and(data_get($links, 'primary.id'))->toBe($lead->id)
        ->and(data_get($links, 'request.id'))->toBe($lead->id)
        ->and(data_get($links, 'quote.id'))->toBe($quote->id)
        ->and(data_get($links, 'customer.id'))->toBe($customer->id)
        ->and(data_get($links, 'job.id'))->toBe($work->id)
        ->and(data_get($links, 'invoice.id'))->toBe($invoice->id)
        ->and(data_get($links, 'anchors.0.type'))->toBe('quote')
        ->and(data_get($links, 'anchors.1.type'))->toBe('request')
        ->and(data_get($links, 'anchors.2.type'))->toBe('customer')
        ->and(data_get($links, 'anchors.3.type'))->toBe('job')
        ->and(data_get($links, 'anchors.4.type'))->toBe('invoice');
});

test('crm opportunity linking keeps quote only opportunities anchored on quote and customer', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Standalone Quote Co',
        'email' => 'standalone-quote@example.test',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Standalone expansion quote',
        'status' => 'sent',
        'subtotal' => 800,
        'total' => 800,
        'currency_code' => 'USD',
    ]);

    $links = CrmOpportunityLinking::present(null, $quote);

    expect(data_get($links, 'subject.type'))->toBe('quote')
        ->and(data_get($links, 'primary.type'))->toBe('quote')
        ->and(data_get($links, 'request'))->toBeNull()
        ->and(data_get($links, 'quote.id'))->toBe($quote->id)
        ->and(data_get($links, 'customer.id'))->toBe($customer->id)
        ->and(data_get($links, 'anchors.0.type'))->toBe('quote')
        ->and(data_get($links, 'anchors.1.type'))->toBe('customer');
});
