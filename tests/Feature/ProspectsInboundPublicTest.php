<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function inboundPublicProspectOwner(array $attributes = []): User
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

function inboundPublicProspectService(User $owner, array $overrides = []): Product
{
    $category = ProductCategory::query()->create([
        'name' => 'Inbound Services',
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);

    return Product::query()->create(array_merge([
        'user_id' => $owner->id,
        'category_id' => $category->id,
        'name' => 'Inbound discovery call',
        'description' => 'Discovery and qualification service',
        'price' => 850,
        'stock' => 0,
        'minimum_stock' => 0,
        'is_active' => true,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Notification::fake();
});

it('creates a public contact request as a prospect without linking an existing customer', function () {
    $owner = inboundPublicProspectOwner();

    Customer::query()->create([
        'user_id' => $owner->id,
        'company_name' => 'Existing Contact Account',
        'first_name' => 'Existing',
        'last_name' => 'Customer',
        'email' => 'existing.contact@example.com',
        'phone' => '+1 555 0101',
    ]);

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Existing Contact',
        'contact_email' => 'existing.contact@example.com',
        'contact_phone' => '+1 555 0101',
        'service_type' => 'Discovery call',
        'description' => 'Please call me back.',
        'final_action' => 'request_call',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    $task = Task::query()->where('request_id', $lead->id)->first();

    expect($lead->customer_id)->toBeNull()
        ->and($lead->status)->toBe(LeadRequest::STATUS_CALL_REQUESTED)
        ->and($lead->channel)->toBe('web_form')
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('web_form')
        ->and(data_get($lead->meta, 'request_type'))->toBe('contact_request')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse()
        ->and($task?->customer_id)->toBeNull();

    expect(Customer::query()->where('user_id', $owner->id)->count())->toBe(1);
});

it('keeps quote-request intake metadata while preserving the legacy quote branch', function () {
    $owner = inboundPublicProspectOwner();
    $service = inboundPublicProspectService($owner);

    $this->post(URL::signedRoute('public.requests.store', ['user' => $owner->id]), [
        'contact_name' => 'Quote Prospect',
        'contact_email' => 'quote.prospect@example.com',
        'contact_phone' => '+1 555 0102',
        'service_type' => 'Discovery and estimate',
        'description' => 'Need a quote quickly.',
        'suggested_service_ids' => [$service->id],
        'final_action' => 'receive_quote',
    ])->assertRedirect();

    $lead = LeadRequest::query()
        ->where('user_id', $owner->id)
        ->latest('id')
        ->firstOrFail();

    expect($lead->status)->toBe(LeadRequest::STATUS_QUOTE_SENT)
        ->and($lead->customer_id)->not->toBeNull()
        ->and($lead->last_activity_at)->not->toBeNull()
        ->and(data_get($lead->meta, 'intake_source'))->toBe('web_form')
        ->and(data_get($lead->meta, 'request_type'))->toBe('quote_request')
        ->and(data_get($lead->meta, 'contact_consent'))->toBeTrue()
        ->and(data_get($lead->meta, 'marketing_consent'))->toBeFalse();

    expect(Quote::query()->where('request_id', $lead->id)->exists())->toBeTrue();
});
