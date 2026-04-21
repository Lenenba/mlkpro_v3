<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('activity log exposes normalized crm links for message events on quote subjects', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Linking',
        'last_name' => 'Customer',
        'company_name' => 'Linking Customer Inc.',
        'email' => 'linking-customer@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Linking request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Linking quote',
        'status' => 'sent',
        'subtotal' => 900,
        'total' => 900,
        'initial_deposit' => 0,
    ]);

    $payload = ActivityLog::record($owner, $quote, 'email_sent', [
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'quote_id' => $quote->id,
        'email' => 'linking-customer@example.com',
    ], 'Quote email sent')->fresh()->toArray();

    expect(data_get($payload, 'crm_links.subject.type'))->toBe('quote')
        ->and(data_get($payload, 'crm_links.subject.id'))->toBe($quote->id)
        ->and(data_get($payload, 'crm_links.primary.type'))->toBe('quote')
        ->and(data_get($payload, 'crm_links.primary.origin'))->toBe('subject_and_property')
        ->and(data_get($payload, 'crm_links.request.id'))->toBe($lead->id)
        ->and(data_get($payload, 'crm_links.customer.id'))->toBe($customer->id)
        ->and(data_get($payload, 'crm_links.quote.role'))->toBe('subject')
        ->and(data_get($payload, 'crm_links.anchors.0.type'))->toBe('quote')
        ->and(data_get($payload, 'crm_links.anchors.1.type'))->toBe('request')
        ->and(data_get($payload, 'crm_links.anchors.2.type'))->toBe('customer');
});

test('crm linking falls back to request first for non core subjects carrying crm ids', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Fallback',
        'last_name' => 'Customer',
        'company_name' => 'Fallback Customer Inc.',
        'email' => 'fallback-customer@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Fallback request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Fallback quote',
        'status' => 'sent',
        'subtotal' => 1200,
        'total' => 1200,
        'initial_deposit' => 0,
    ]);

    $work = Work::factory()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'quote_id' => $quote->id,
    ]);

    $invoice = Invoice::create([
        'work_id' => $work->id,
        'customer_id' => $customer->id,
        'user_id' => $owner->id,
        'status' => 'sent',
        'total' => 1200,
    ]);

    $payload = ActivityLog::record($owner, $invoice, 'email_sent', [
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'quote_id' => $quote->id,
    ], 'Invoice email sent')->fresh()->toArray();

    expect(data_get($payload, 'crm_links.subject.type'))->toBe('invoice')
        ->and(data_get($payload, 'crm_links.primary.type'))->toBe('request')
        ->and(data_get($payload, 'crm_links.primary.id'))->toBe($lead->id)
        ->and(data_get($payload, 'crm_links.quote.id'))->toBe($quote->id)
        ->and(data_get($payload, 'crm_links.customer.id'))->toBe($customer->id)
        ->and(data_get($payload, 'crm_links.anchors.0.type'))->toBe('invoice')
        ->and(data_get($payload, 'crm_links.anchors.1.type'))->toBe('request')
        ->and(data_get($payload, 'crm_links.anchors.2.type'))->toBe('quote')
        ->and(data_get($payload, 'crm_links.anchors.3.type'))->toBe('customer');
});

test('request and customer detail payloads expose crm links for phase five events', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Detail',
        'last_name' => 'Linking',
        'company_name' => 'Detail Linking Inc.',
        'email' => 'detail-linking@example.com',
    ]);

    $lead = LeadRequest::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => LeadRequest::STATUS_CONTACTED,
        'title' => 'Detail linking request',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'job_title' => 'Detail linking quote',
        'status' => 'sent',
        'subtotal' => 1500,
        'total' => 1500,
        'initial_deposit' => 0,
    ]);

    ActivityLog::record($owner, $lead, 'lead_email_failed', [
        'customer_id' => $customer->id,
        'request_id' => $lead->id,
        'quote_id' => $quote->id,
        'email' => 'detail-linking@example.com',
    ], 'Lead email failed');

    $this->actingAs($owner)
        ->getJson(route('request.show', $lead))
        ->assertOk()
        ->assertJsonPath('activity.0.crm_links.primary.type', 'request')
        ->assertJsonPath('activity.0.crm_links.request.id', $lead->id)
        ->assertJsonPath('activity.0.crm_links.quote.id', $quote->id)
        ->assertJsonPath('activity.0.crm_links.customer.id', $customer->id);

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.crm_links.primary.type', 'request')
            ->where('activity.0.crm_links.request.id', $lead->id)
            ->where('activity.0.crm_links.quote.id', $quote->id)
            ->where('activity.0.crm_links.customer.id', $customer->id)
        );
});
