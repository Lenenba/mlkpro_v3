<?php

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\TeamMember;
use App\Models\User;

function prospectConversionOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
            'quotes' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

it('exposes customer conversion wizard data with potential customer matches on the prospect detail page', function () {
    $owner = prospectConversionOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'first_name' => 'Taylor',
        'last_name' => 'Client',
        'company_name' => 'Acme Labs',
        'email' => 'match@example.com',
        'phone' => '+1 514 555 0000',
    ]);

    $customer->properties()->create([
        'type' => 'physical',
        'is_default' => true,
        'street1' => '123 Main Street',
        'city' => 'Montreal',
        'zip' => 'H2H 2H2',
        'country' => 'Canada',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Customer conversion prospect',
        'contact_name' => 'Taylor Client',
        'contact_email' => 'match@example.com',
        'contact_phone' => '+1 (514) 555-0000',
        'street1' => '123 Main Street',
        'city' => 'Montreal',
        'postal_code' => 'H2H 2H2',
        'country' => 'Canada',
        'meta' => [
            'company_name' => 'Acme Labs',
        ],
    ]);

    $this->actingAs($owner)
        ->getJson(route('prospects.show', $lead))
        ->assertOk()
        ->assertJsonPath('customerConversion.can_convert', true)
        ->assertJsonPath('customerConversion.default_mode', 'link_existing')
        ->assertJsonPath('customerConversion.matches.0.id', $customer->id)
        ->assertJsonPath('customerConversion.preview.contact_email', 'match@example.com')
        ->assertJsonPath('customerConversion.preview.company_name', 'Acme Labs');
});

it('links a prospect to an existing customer and updates related quotes during conversion', function () {
    $owner = prospectConversionOwner();

    $customer = Customer::factory()->create([
        'user_id' => $owner->id,
        'email' => 'existing.customer@example.com',
    ]);

    $property = $customer->properties()->create([
        'type' => 'physical',
        'is_default' => true,
        'street1' => '400 Existing Avenue',
        'city' => 'Montreal',
        'zip' => 'H3H 3H3',
        'country' => 'Canada',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUOTE_SENT,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Existing customer conversion',
        'contact_name' => 'Existing Contact',
        'contact_email' => 'existing.customer@example.com',
    ]);

    $quote = Quote::query()->create([
        'user_id' => $owner->id,
        'prospect_id' => $lead->id,
        'job_title' => 'Existing customer quote',
        'status' => 'draft',
        'subtotal' => 950,
        'total' => 950,
        'currency_code' => 'CAD',
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'link_existing',
            'customer_id' => $customer->id,
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Prospect converted to customer.')
        ->assertJsonPath('customer.id', $customer->id)
        ->assertJsonPath('request.customer_id', $customer->id)
        ->assertJsonPath('request.status', LeadRequest::STATUS_CONVERTED);

    $lead->refresh();
    $quote->refresh();

    $activity = ActivityLog::query()
        ->where('subject_type', $lead->getMorphClass())
        ->where('subject_id', $lead->id)
        ->where('action', 'converted_to_customer')
        ->latest('id')
        ->first();

    expect($lead->customer_id)->toBe($customer->id)
        ->and($lead->status)->toBe(LeadRequest::STATUS_CONVERTED)
        ->and($lead->isConvertedToCustomer())->toBeTrue()
        ->and($lead->converted_at)->not->toBeNull()
        ->and($lead->convertedByUserId())->toBe($owner->id)
        ->and(data_get($lead->meta, 'customer_conversion.mode'))->toBe('link_existing')
        ->and($lead->customerConversionMeta()['mode'] ?? null)->toBe('link_existing')
        ->and((int) data_get($lead->meta, 'customer_conversion.customer_id'))->toBe($customer->id)
        ->and($quote->customer_id)->toBe($customer->id)
        ->and($quote->property_id)->toBe($property->id)
        ->and($customer->prospects()->whereKey($lead->id)->exists())->toBeTrue()
        ->and($lead->statusHistories()->where('to_status', LeadRequest::STATUS_CONVERTED)->exists())->toBeTrue()
        ->and($activity)->not->toBeNull()
        ->and((int) data_get($activity?->properties, 'customer_id'))->toBe($customer->id);
});

it('creates a new customer from the prospect conversion wizard payload', function () {
    $owner = prospectConversionOwner();

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'New customer conversion',
        'contact_name' => 'Morgan Prospect',
        'contact_email' => 'morgan.prospect@example.com',
        'contact_phone' => '+1 438 555 0199',
        'street1' => '200 Conversion Boulevard',
        'city' => 'Quebec',
        'postal_code' => 'G1G 1G1',
        'country' => 'Canada',
        'meta' => [
            'company_name' => 'Northwind Studio',
        ],
    ]);

    $response = $this->actingAs($owner)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'create_new',
            'contact_name' => 'Morgan Prospect',
            'contact_email' => 'morgan.prospect@example.com',
            'contact_phone' => '+1 438 555 0199',
            'company_name' => 'Northwind Studio',
            'street1' => '200 Conversion Boulevard',
            'city' => 'Quebec',
            'postal_code' => 'G1G 1G1',
            'country' => 'Canada',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Prospect converted to customer.')
        ->assertJsonPath('request.status', LeadRequest::STATUS_CONVERTED);

    $customerId = (int) $response->json('customer.id');
    $customer = Customer::query()->findOrFail($customerId);
    $lead->refresh();

    expect($customer->user_id)->toBe($owner->id)
        ->and($customer->portal_access)->toBeFalse()
        ->and($customer->first_name)->toBe('Morgan')
        ->and($customer->last_name)->toBe('Prospect')
        ->and($customer->company_name)->toBe('Northwind Studio')
        ->and($customer->email)->toBe('morgan.prospect@example.com')
        ->and($customer->defaultProperty?->city)->toBe('Quebec')
        ->and($lead->customer_id)->toBe($customerId)
        ->and($lead->status)->toBe(LeadRequest::STATUS_CONVERTED)
        ->and($lead->isConvertedToCustomer())->toBeTrue()
        ->and($lead->convertedByUserId())->toBe($owner->id)
        ->and(data_get($lead->meta, 'customer_conversion.mode'))->toBe('create_new')
        ->and($lead->companyName())->toBe('Northwind Studio')
        ->and($customer->prospects()->whereKey($lead->id)->exists())->toBeTrue()
        ->and((int) data_get($lead->meta, 'customer_conversion.customer_id'))->toBe($customerId);
});

it('blocks creating a new customer when the submitted payload matches an existing customer by email phone or company', function () {
    $owner = prospectConversionOwner();

    $existingCustomer = Customer::factory()->create([
        'user_id' => $owner->id,
        'company_name' => 'Northwind Studio',
        'email' => 'northwind@example.com',
        'phone' => '+1 438 555 0110',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Duplicate guard prospect',
        'contact_name' => 'Jordan Prospect',
        'contact_email' => 'jordan.prospect@example.com',
        'contact_phone' => '+1 438 555 0999',
        'meta' => [
            'company_name' => 'Fresh Prospect Company',
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'create_new',
            'contact_name' => 'Jordan Prospect',
            'contact_email' => 'northwind@example.com',
            'contact_phone' => '+1 (438) 555-0110',
            'company_name' => 'Northwind Studio',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['customer_id', 'contact_email', 'contact_phone', 'company_name']);

    $lead->refresh();

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(1)
        ->and($lead->customer_id)->toBeNull()
        ->and($lead->status)->toBe(LeadRequest::STATUS_QUALIFIED)
        ->and($lead->isConvertedToCustomer())->toBeFalse()
        ->and(ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->where('action', 'converted_to_customer')
            ->doesntExist())->toBeTrue()
        ->and($existingCustomer->prospects()->whereKey($lead->id)->exists())->toBeFalse();
});

it('returns a validation error when the email is already reserved by another account', function () {
    $owner = prospectConversionOwner();
    $otherOwner = prospectConversionOwner();

    Customer::factory()->create([
        'user_id' => $otherOwner->id,
        'company_name' => 'Cross Tenant Studio',
        'email' => 'cross-tenant@example.com',
        'phone' => '+1 438 555 0220',
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Cross tenant conversion',
        'contact_name' => 'Avery Prospect',
        'contact_email' => 'lead-initial@example.com',
        'meta' => [
            'company_name' => 'Initial Prospect Company',
        ],
    ]);

    $this->actingAs($owner)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'create_new',
            'contact_name' => 'Avery Prospect',
            'contact_email' => 'cross-tenant@example.com',
            'contact_phone' => '+1 438 555 0220',
            'company_name' => 'Cross Tenant Studio',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['contact_email']);

    $lead->refresh();

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(0)
        ->and($lead->customer_id)->toBeNull()
        ->and($lead->isConvertedToCustomer())->toBeFalse();
});

it('forbids customer conversion to team members without convert permission', function () {
    $owner = prospectConversionOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
            'team_members' => true,
        ],
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['prospects.view'],
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Permission guarded conversion',
        'contact_name' => 'Taylor Prospect',
        'contact_email' => 'taylor.prospect@example.com',
        'meta' => [
            'company_name' => 'Permission Guard Inc.',
        ],
    ]);

    $this->actingAs($memberUser)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'create_new',
            'contact_name' => 'Taylor Prospect',
            'contact_email' => 'taylor.prospect@example.com',
            'company_name' => 'Permission Guard Inc.',
        ])
        ->assertForbidden();

    $lead->refresh();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->isConvertedToCustomer())->toBeFalse();
});

it('allows customer conversion to team members with the legacy convert permission alias', function () {
    $owner = prospectConversionOwner([
        'company_features' => [
            'requests' => true,
            'quotes' => true,
            'team_members' => true,
        ],
    ]);

    $memberUser = User::factory()->create([
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $memberUser->id,
        'role' => 'member',
        'permissions' => ['requests.convert'],
        'is_active' => true,
    ]);

    $lead = LeadRequest::query()->create([
        'user_id' => $owner->id,
        'status' => LeadRequest::STATUS_QUALIFIED,
        'status_updated_at' => now(),
        'last_activity_at' => now(),
        'title' => 'Legacy permission conversion',
        'contact_name' => 'Casey Prospect',
        'contact_email' => 'casey.prospect@example.com',
        'meta' => [
            'company_name' => 'Legacy Permission Co.',
        ],
    ]);

    $this->actingAs($memberUser)
        ->postJson(route('prospects.convert-customer', $lead), [
            'mode' => 'create_new',
            'contact_name' => 'Casey Prospect',
            'contact_email' => 'casey.prospect@example.com',
            'company_name' => 'Legacy Permission Co.',
        ])
        ->assertOk()
        ->assertJsonPath('request.status', LeadRequest::STATUS_CONVERTED);

    $lead->refresh();

    expect($lead->customer_id)->not->toBeNull()
        ->and($lead->convertedByUserId())->toBe($memberUser->id)
        ->and($lead->isConvertedToCustomer())->toBeTrue();
});
