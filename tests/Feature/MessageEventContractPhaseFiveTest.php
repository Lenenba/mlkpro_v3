<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\User;
use App\Support\CRM\MessageEventTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('defines canonical and legacy message event actions for phase five', function () {
    $canonical = MessageEventTaxonomy::definition('message_email_received');
    $legacy = MessageEventTaxonomy::definition('lead_email_retry_scheduled');

    expect($canonical)->not->toBeNull()
        ->and($canonical['channel'])->toBe(MessageEventTaxonomy::CHANNEL_EMAIL)
        ->and($canonical['direction'])->toBe(MessageEventTaxonomy::DIRECTION_INBOUND)
        ->and($canonical['delivery_state'])->toBe('received')
        ->and($canonical['legacy'])->toBeFalse()
        ->and($legacy)->not->toBeNull()
        ->and($legacy['event_key'])->toBe('message_email_retry_scheduled')
        ->and($legacy['delivery_state'])->toBe('retry_scheduled')
        ->and($legacy['legacy'])->toBeTrue()
        ->and(MessageEventTaxonomy::isMessageEvent('email_sent'))->toBeTrue()
        ->and(MessageEventTaxonomy::isMessageEvent('updated'))->toBeFalse();
});

it('serializes message event metadata on activity logs and filters them with the message scope', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Message',
        'last_name' => 'Contract',
        'company_name' => 'Message Contract Inc.',
        'email' => 'message-contract@example.com',
    ]);

    $messageLog = ActivityLog::record($owner, $customer, 'lead_email_retry_scheduled', [
        'quote_id' => 77,
        'email' => 'prospect@example.com',
        'source' => 'lead_form_retry',
        'attempt' => '2',
        'delay_minutes' => '15',
    ], 'Quote email retry scheduled');

    $genericLog = ActivityLog::record($owner, $customer, 'updated', [], 'Customer updated');

    $messagePayload = $messageLog->fresh()->toArray();
    $genericPayload = $genericLog->fresh()->toArray();

    expect($messagePayload['is_message_event'])->toBeTrue()
        ->and($messagePayload['message_event']['event_key'])->toBe('message_email_retry_scheduled')
        ->and($messagePayload['message_event']['delivery_state'])->toBe('retry_scheduled')
        ->and($messagePayload['message_event']['email'])->toBe('prospect@example.com')
        ->and($messagePayload['message_event']['retry_attempt'])->toBe(2)
        ->and($messagePayload['message_event']['delay_minutes'])->toBe(15)
        ->and($messagePayload['message_event']['legacy'])->toBeTrue()
        ->and($genericPayload['is_message_event'])->toBeFalse()
        ->and($genericPayload['message_event'])->toBeNull()
        ->and(ActivityLog::query()->messageEvent()->pluck('id')->all())->toBe([$messageLog->id]);
});

test('customer show exposes message event metadata in the activity payload', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
    ]);

    $customer = Customer::create([
        'user_id' => $owner->id,
        'first_name' => 'Customer',
        'last_name' => 'Mailbox',
        'company_name' => 'Mailbox Customer Inc.',
        'email' => 'customer-mailbox@example.com',
    ]);

    $quote = Quote::create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Mailbox quote',
        'status' => 'sent',
        'subtotal' => 950,
        'total' => 950,
        'initial_deposit' => 0,
    ]);

    ActivityLog::record($owner, $quote, 'email_sent', [
        'email' => 'customer-mailbox@example.com',
        'source' => 'quote_manual_send',
        'assistant' => true,
    ], 'Quote email sent');

    $this->actingAs($owner)
        ->get(route('customer.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Show')
            ->where('activity.0.subject', 'Quote')
            ->where('activity.0.is_message_event', true)
            ->where('activity.0.message_event.event_key', 'message_email_sent')
            ->where('activity.0.message_event.delivery_state', 'sent')
            ->where('activity.0.message_event.email', 'customer-mailbox@example.com')
            ->where('activity.0.message_event.source', 'quote_manual_send')
            ->where('activity.0.message_event.assistant', true)
        );
});
