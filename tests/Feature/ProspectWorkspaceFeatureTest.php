<?php

use App\Models\Customer;
use App\Models\Request as LeadRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;

function prospectWorkspaceOwner(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'company_type' => 'services',
        'company_features' => [
            'requests' => true,
        ],
        'onboarding_completed_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('creates a manual prospect without requiring a linked customer', function () {
    $owner = prospectWorkspaceOwner();

    $this->actingAs($owner)->post(route('prospects.store'), [
        'channel' => 'phone',
        'title' => 'Phone inquiry',
        'service_type' => 'Consultation',
        'contact_name' => 'Phone Prospect',
        'contact_email' => 'phone.prospect@example.com',
        'contact_phone' => '+1 555 0120',
        'meta' => [
            'request_type' => 'phone_inquiry',
            'contact_consent' => true,
            'marketing_consent' => false,
            'budget' => 1800,
        ],
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->channel)->toBe('phone')
        ->and($lead->status)->toBe(LeadRequest::STATUS_NEW)
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('phone')
        ->and(data_get($lead->meta, 'request_type'))->toBe('phone_inquiry')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and((float) data_get($lead->meta, 'budget'))->toBe(1800.0);
});

it('imports prospects from csv without auto-linking an existing customer', function () {
    $owner = prospectWorkspaceOwner();

    Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'CSV Existing Customer',
        'first_name' => 'CSV',
        'last_name' => 'Customer',
        'email' => 'csv.existing@example.com',
        'phone' => '+1 555 0130',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'prospects.csv',
        implode("\n", [
            'name,email,phone,source,request_type,contact_consent,marketing_consent,budget',
            'CSV Existing Customer,csv.existing@example.com,+1 555 0130,phone,estimate_request,yes,no,2500',
        ])
    );

    $this->actingAs($owner)->post(route('prospects.import'), [
        'file' => $file,
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->channel)->toBe('phone')
        ->and($lead->status)->toBe(LeadRequest::STATUS_NEW)
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('phone')
        ->and(data_get($lead->meta, 'request_type'))->toBe('estimate_request')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and((float) data_get($lead->meta, 'budget'))->toBe(2500.0);

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(1);
});
