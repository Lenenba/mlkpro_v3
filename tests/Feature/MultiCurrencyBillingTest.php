<?php

use App\Enums\BillingPeriod;
use App\Exceptions\Billing\PlanPriceNotFoundException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\BillingPlanService;
use App\Services\CreateStripeSubscriptionForTenant;
use App\Services\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Notification::fake();
    config()->set('billing.provider_effective', 'paddle');
    config()->set('billing.provider_ready', false);
});

function onboardingPayload(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'Acme Services',
        'company_type' => 'services',
        'company_sector' => 'salon',
        'accept_terms' => true,
    ], $overrides);
}

function createCatalogCategory(User $tenant): ProductCategory
{
    return ProductCategory::factory()->create([
        'user_id' => $tenant->id,
        'created_by_user_id' => $tenant->id,
    ]);
}

it('stores CAD as the default tenant currency during onboarding when no currency is provided', function () {
    $tenant = User::factory()->create([
        'onboarding_completed_at' => null,
        'company_type' => 'services',
    ]);

    $response = $this->actingAs($tenant)->post(route('onboarding.store'), onboardingPayload());

    $response->assertRedirect(route('dashboard'));

    expect($tenant->fresh()->businessCurrencyCode())->toBe('CAD');
});

it('stores the selected tenant currency during onboarding', function (string $currencyCode) {
    $tenant = User::factory()->create([
        'onboarding_completed_at' => null,
        'company_type' => 'products',
    ]);

    $response = $this->actingAs($tenant)->post(route('onboarding.store'), onboardingPayload([
        'company_name' => 'Acme Retail',
        'company_type' => 'products',
        'company_sector' => 'retail',
        'currency_code' => $currencyCode,
    ]));

    $response->assertRedirect(route('dashboard'));

    expect($tenant->fresh()->businessCurrencyCode())->toBe($currencyCode);
})->with(['EUR', 'USD']);

it('assigns the tenant currency to newly created products and services', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'EUR',
    ]);
    $category = createCatalogCategory($tenant);

    $product = $tenant->products()->create([
        'name' => 'Euro Product',
        'category_id' => $category->id,
        'description' => 'Catalog product',
        'price' => 1250,
        'stock' => 10,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
    ]);

    $service = $tenant->products()->create([
        'name' => 'Euro Service',
        'category_id' => $category->id,
        'description' => 'Catalog service',
        'price' => 4500,
        'stock' => 0,
        'minimum_stock' => 0,
        'item_type' => Product::ITEM_TYPE_SERVICE,
    ]);

    expect($product->fresh()->currency_code)->toBe('EUR')
        ->and($service->fresh()->currency_code)->toBe('EUR');
});

it('blocks unsafe tenant currency changes after business activity exists', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'CAD',
        'company_type' => 'services',
    ]);
    $category = createCatalogCategory($tenant);

    $tenant->products()->create([
        'name' => 'Legacy Product',
        'category_id' => $category->id,
        'description' => 'Catalog product',
        'price' => 900,
        'stock' => 2,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
    ]);

    $response = $this->actingAs($tenant)->put(route('settings.company.update'), [
        'company_name' => $tenant->company_name,
        'company_type' => $tenant->company_type,
        'currency_code' => 'USD',
    ]);

    $response->assertSessionHasErrors(['currency_code']);
    expect($tenant->fresh()->businessCurrencyCode())->toBe('CAD');
});

it('resolves explicit plan prices for CAD, EUR, and USD', function () {
    $expectedAmounts = [
        'CAD' => number_format((float) (env('STRIPE_PRICE_STARTER_CAD_AMOUNT', env('STRIPE_PRICE_STARTER_AMOUNT', 29))), 2, '.', ''),
        'EUR' => number_format((float) env('STRIPE_PRICE_STARTER_EUR_AMOUNT', 21), 2, '.', ''),
        'USD' => number_format((float) env('STRIPE_PRICE_STARTER_USD_AMOUNT', 24), 2, '.', ''),
    ];

    foreach ($expectedAmounts as $currencyCode => $expectedAmount) {
        $planPrice = app(BillingPlanService::class)->resolveActivePlanPrice(
            'starter',
            $currencyCode,
            BillingPeriod::MONTHLY
        );

        expect($planPrice->currencyCode->value)->toBe($currencyCode)
            ->and($planPrice->amount)->toBe($expectedAmount)
            ->and($planPrice->billingPeriod)->toBe(BillingPeriod::MONTHLY);
    }
});

it('fails clearly when a plan price is missing for the tenant currency', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'USD',
    ]);

    $planId = Plan::query()->where('code', 'starter')->value('id');
    PlanPrice::query()
        ->where('plan_id', $planId)
        ->where('currency_code', 'USD')
        ->where('billing_period', BillingPeriod::MONTHLY->value)
        ->delete();

    app(BillingPlanService::class)->resolvePlanPriceForTenant($tenant, 'starter', BillingPeriod::MONTHLY);
})->throws(
    PlanPriceNotFoundException::class,
    'No active plan price exists for plan [starter] in currency [USD] and period [monthly].'
);

it('uses the tenant currency when building Stripe checkout for subscriptions', function (string $currencyCode, string $stripePriceId) {
    $tenant = User::factory()->create([
        'currency_code' => $currencyCode,
    ]);

    $planId = Plan::query()->where('code', 'starter')->value('id');
    PlanPrice::query()
        ->where('plan_id', $planId)
        ->where('currency_code', $currencyCode)
        ->where('billing_period', BillingPeriod::MONTHLY->value)
        ->update([
            'stripe_price_id' => $stripePriceId,
        ]);

    $stripeMock = \Mockery::mock(StripeBillingService::class);
    $stripeMock
        ->shouldReceive('createCheckoutSessionForPlanPrice')
        ->once()
        ->withArgs(function (User $user, $planPrice, string $successUrl, string $cancelUrl, int $quantity, $trialEndsAt) use ($tenant, $currencyCode, $stripePriceId) {
            expect($user->is($tenant))->toBeTrue();
            expect($planPrice->currencyCode->value)->toBe($currencyCode);
            expect($planPrice->stripePriceId)->toBe($stripePriceId);
            expect($successUrl)->toBe('https://example.test/success');
            expect($cancelUrl)->toBe('https://example.test/cancel');
            expect($quantity)->toBe(3);
            expect($trialEndsAt)->toBeNull();

            return true;
        })
        ->andReturn([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.test/session',
        ]);

    app()->instance(StripeBillingService::class, $stripeMock);

    $session = app(CreateStripeSubscriptionForTenant::class)->checkoutSession(
        $tenant,
        'starter',
        'https://example.test/success',
        'https://example.test/cancel',
        3
    );

    expect($session['id'])->toBe('cs_test_123')
        ->and($session['url'])->toBe('https://checkout.stripe.test/session');
})->with([
    ['EUR', 'price_test_starter_eur'],
    ['USD', 'price_test_starter_usd'],
]);

it('backfills legacy records without currency to CAD when the multi-currency migration runs', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'CAD',
    ]);
    $category = createCatalogCategory($tenant);

    $product = $tenant->products()->create([
        'name' => 'Legacy Product',
        'category_id' => $category->id,
        'description' => 'Created before rerunning the migration',
        'price' => 1000,
        'stock' => 3,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
    ]);

    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require database_path('migrations/2026_03_08_000001_add_multi_currency_billing_foundations.php');
    $migration->down();

    expect(Schema::hasColumn('users', 'currency_code'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'currency_code'))->toBeFalse();

    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require database_path('migrations/2026_03_08_000001_add_multi_currency_billing_foundations.php');
    $migration->up();

    expect(DB::table('users')->where('id', $tenant->id)->value('currency_code'))->toBe('CAD')
        ->and(DB::table('products')->where('id', $product->id)->value('currency_code'))->toBe('CAD');
});
