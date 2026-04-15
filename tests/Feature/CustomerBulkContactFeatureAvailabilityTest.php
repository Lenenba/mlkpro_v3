<?php

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function customerBulkFeatureRoleId(string $name): int
{
    return (int) Role::query()->firstOrCreate(
        ['name' => $name],
        ['description' => $name.' role']
    )->id;
}

function customerBulkFeatureOwner(array $featureOverrides = []): User
{
    return User::factory()->create([
        'role_id' => customerBulkFeatureRoleId('owner'),
        'company_type' => 'services',
        'onboarding_completed_at' => now(),
        'company_features' => array_replace([
            'campaigns' => false,
        ], $featureOverrides),
    ]);
}

function customerBulkFeatureCustomer(User $owner, array $overrides = []): Customer
{
    return Customer::query()->create(array_merge([
        'user_id' => $owner->id,
        'first_name' => 'Maya',
        'last_name' => 'Client',
        'company_name' => 'Feature Gate Co',
        'email' => 'customer-'.fake()->unique()->safeEmail(),
        'phone' => '+15145550000',
        'is_active' => true,
    ], $overrides));
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('customer index hides bulk contact action when campaigns feature is unavailable', function () {
    $owner = customerBulkFeatureOwner([
        'customers' => true,
    ]);

    customerBulkFeatureCustomer($owner);

    $this->actingAs($owner)
        ->get(route('customer.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Customer/Index')
            ->where('bulkActions.capabilities.contact_enabled', false)
            ->where('bulkActions.capabilities.campaign_bridge_enabled', false)
            ->where('bulkActions.actions', fn ($actions) => ! collect($actions)->pluck('key')->contains('contact_selected'))
        );
});

dataset('customer bulk contact routes', [
    'preview' => 'customer.bulk-contact.preview',
    'send' => 'customer.bulk-contact.send',
    'save selection' => 'customer.bulk-contact.save-selection',
    'open campaign' => 'customer.bulk-contact.open-campaign',
]);

test('customer bulk contact endpoints are unavailable without campaigns feature', function (string $routeName) {
    $owner = customerBulkFeatureOwner();
    $customer = customerBulkFeatureCustomer($owner);

    $this->actingAs($owner)
        ->postJson(route($routeName), [
            'ids' => [$customer->id],
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
})->with('customer bulk contact routes');
