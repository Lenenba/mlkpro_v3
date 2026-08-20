<?php

use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->withoutMiddleware(EnsureTwoFactorVerified::class);
});

function salesFeatureTenantOwner(string $companyType, bool $salesEnabled = true): User
{
    return User::factory()->create([
        'company_type' => $companyType,
        'company_features' => ['sales' => $salesEnabled],
    ]);
}

function salesFeatureTenantMember(User $owner, array $permissions): User
{
    $employeeRole = Role::query()->firstOrCreate(
        ['name' => 'employee'],
        ['description' => 'Employee role'],
    );
    $member = User::factory()->create([
        'role_id' => $employeeRole->id,
        'company_type' => 'services',
    ]);

    TeamMember::query()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'role' => 'seller',
        'title' => 'Point of sale',
        'permissions' => $permissions,
        'is_active' => true,
    ]);

    return $member;
}

it('opens sales and orders for every tenant type with the sales feature', function (string $companyType) {
    $owner = salesFeatureTenantOwner($companyType);

    $this->actingAs($owner)
        ->getJson(route('sales.index'))
        ->assertOk()
        ->assertJsonPath('stats.total', 0);

    $this->actingAs($owner)
        ->getJson(route('orders.index'))
        ->assertOk()
        ->assertJsonPath('stats.total', 0);

    $this->actingAs($owner)
        ->getJson(route('sales.create'))
        ->assertOk()
        ->assertJsonPath('prefillContext.requested_count', 0);
})->with(['services', 'products']);

it('keeps sales routes behind the tenant feature gate', function () {
    $owner = salesFeatureTenantOwner('services', false);

    $this->actingAs($owner)
        ->getJson(route('sales.index'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');

    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 20,
        'tax_total' => 0,
        'total' => 20,
    ]);

    $this->actingAs($owner)
        ->postJson(route('sales.payments.store', $sale), [
            'amount' => 20,
            'method' => 'cash',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Module unavailable for your plan.');
});

it('preserves member permissions for service tenant point of sale access', function () {
    $owner = salesFeatureTenantOwner('services');
    $seller = salesFeatureTenantMember($owner, ['sales.pos']);
    $memberWithoutSales = salesFeatureTenantMember($owner, []);

    $this->actingAs($seller)
        ->getJson(route('sales.index'))
        ->assertOk();

    $this->actingAs($memberWithoutSales)
        ->getJson(route('sales.index'))
        ->assertForbidden();
});

it('accepts point of sale payments for service tenants with the sales feature', function () {
    $owner = salesFeatureTenantOwner('services');
    $sale = Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'status' => Sale::STATUS_PENDING,
        'subtotal' => 35,
        'tax_total' => 0,
        'total' => 35,
        'source' => 'pos',
    ]);

    $this->actingAs($owner)
        ->postJson(route('sales.payments.store', $sale), [
            'amount' => 35,
            'method' => 'cash',
        ])
        ->assertOk();

    $payment = Payment::query()->where('sale_id', $sale->id)->first();

    expect($payment)->not->toBeNull()
        ->and($payment?->status)->toBe(Payment::STATUS_PENDING);
});

it('uses the owner customer directory for an authorized service sales member', function () {
    $owner = salesFeatureTenantOwner('services');
    $manager = salesFeatureTenantMember($owner, ['sales.manage']);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($manager)
        ->getJson(route('customer.index'))
        ->assertOk()
        ->assertJsonPath('customers.data.0.id', $customer->id);
});

it('builds sales and service customer detail data together from enabled capabilities', function () {
    $owner = User::factory()->create([
        'company_type' => 'services',
        'company_features' => [
            'sales' => true,
            'quotes' => true,
        ],
    ]);
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    Sale::query()->create([
        'user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
        'customer_id' => $customer->id,
        'status' => Sale::STATUS_PAID,
        'subtotal' => 50,
        'tax_total' => 0,
        'total' => 50,
        'paid_at' => now(),
    ]);
    Quote::query()->create([
        'user_id' => $owner->id,
        'customer_id' => $customer->id,
        'job_title' => 'Service quote alongside POS sale',
        'status' => 'accepted',
        'subtotal' => 80,
        'total' => 80,
    ]);

    $this->actingAs($owner)
        ->getJson(route('customer.show', $customer))
        ->assertOk()
        ->assertJsonPath('salesSummary.count', 1)
        ->assertJsonPath('salesSummary.paid', 50)
        ->assertJsonPath('stats.quotes', 1);
});

it('keeps customer detail access tenant scoped and rejects inactive memberships', function () {
    $owner = salesFeatureTenantOwner('services');
    $customer = Customer::factory()->create(['user_id' => $owner->id]);
    $manager = salesFeatureTenantMember($owner, ['sales.manage']);
    $otherOwner = salesFeatureTenantOwner('services');
    $otherManager = salesFeatureTenantMember($otherOwner, ['sales.manage']);

    expect($manager->can('view', $customer))->toBeTrue()
        ->and($otherManager->can('view', $customer))->toBeFalse();

    TeamMember::query()
        ->where('user_id', $manager->id)
        ->update(['is_active' => false]);
    $manager->unsetRelation('teamMembership');

    expect($manager->can('view', $customer))->toBeFalse();
});
