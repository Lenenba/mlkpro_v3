<?php

use App\Enums\BillingPeriod;
use App\Enums\CurrencyCode;
use App\Exceptions\Billing\StripePriceNotConfiguredException;
use App\Exceptions\Billing\TenantCurrencyChangeNotAllowedException;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\BillingPlanService;
use App\Services\BillingSubscriptionService;
use App\Services\PreventUnsafeTenantCurrencyChange;
use App\Services\ResolvePlanPriceForTenant;
use App\Services\ResolveTenantCurrency;
use App\Services\StripeBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createUnitCatalogCategory(User $tenant): ProductCategory
{
    return ProductCategory::factory()->create([
        'user_id' => $tenant->id,
        'created_by_user_id' => $tenant->id,
    ]);
}

function injectStripeClient(StripeBillingService $service, StripeClient $client): void
{
    $reflection = new ReflectionProperty($service, 'client');
    $reflection->setAccessible(true);
    $reflection->setValue($service, $client);
}

it('resolves the account owner currency for team members', function () {
    $owner = User::factory()->create([
        'currency_code' => 'EUR',
        'company_country' => 'FR',
        'locale' => 'fr',
    ]);
    $member = User::factory()->create([
        'currency_code' => 'USD',
    ]);

    TeamMember::factory()->create([
        'account_id' => $owner->id,
        'user_id' => $member->id,
        'is_active' => true,
    ]);

    $resolved = app(ResolveTenantCurrency::class)->forUser($member);

    expect($resolved->tenantId)->toBe($owner->id)
        ->and($resolved->currencyCode)->toBe(CurrencyCode::EUR)
        ->and($resolved->country)->toBe('FR')
        ->and($resolved->locale)->toBe('fr');
});

it('falls back to CAD when a stored tenant currency is invalid', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'ZZZ',
    ]);

    $resolved = app(ResolveTenantCurrency::class)->forUser($tenant);

    expect($resolved->currencyCode)->toBe(CurrencyCode::CAD);
});

it('prevents unsafe tenant currency changes once business activity exists', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'CAD',
    ]);
    $category = createUnitCatalogCategory($tenant);

    Product::query()->create([
        'user_id' => $tenant->id,
        'category_id' => $category->id,
        'name' => 'Catalog product',
        'description' => 'Business activity marker',
        'price' => 1000,
        'stock' => 5,
        'minimum_stock' => 1,
        'item_type' => Product::ITEM_TYPE_PRODUCT,
    ]);

    expect(app(PreventUnsafeTenantCurrencyChange::class)->hasBusinessActivity($tenant))->toBeTrue();

    app(PreventUnsafeTenantCurrencyChange::class)->ensureCanChange($tenant, 'USD');
})->throws(
    TenantCurrencyChangeNotAllowedException::class,
    'Tenant currency cannot be changed after business activity exists.'
);

it('resolves the active plan price for the tenant currency', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'USD',
    ]);
    $expectedAmount = number_format((float) env('STRIPE_PRICE_STARTER_USD_AMOUNT', 24), 2, '.', '');
    $planId = Plan::query()->where('code', 'starter')->value('id');

    DB::table('plan_prices')
        ->updateOrInsert(
            [
                'plan_id' => $planId,
                'currency_code' => 'USD',
                'billing_period' => BillingPeriod::MONTHLY->value,
            ],
            [
                'amount' => $expectedAmount,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

    $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
        $tenant,
        'starter',
        BillingPeriod::MONTHLY
    );

    expect($planPrice->planCode)->toBe('starter')
        ->and($planPrice->currencyCode)->toBe(CurrencyCode::USD)
        ->and($planPrice->billingPeriod)->toBe(BillingPeriod::MONTHLY)
        ->and($planPrice->amount)->toBe($expectedAmount);
});

it('exposes monthly and yearly price options for billing plan catalogs', function () {
    $plans = collect(app(BillingPlanService::class)->plansForCurrency('CAD'))->keyBy('key');
    $starter = $plans->get('starter');

    expect($starter)->not->toBeNull()
        ->and($starter['prices_by_period']['monthly']['billing_period'])->toBe(BillingPeriod::MONTHLY->value)
        ->and($starter['prices_by_period']['yearly']['billing_period'])->toBe(BillingPeriod::YEARLY->value)
        ->and($starter['prices_by_currency']['USD']['yearly']['billing_period'])->toBe(BillingPeriod::YEARLY->value)
        ->and($starter['annual_discount_percent'])->toBe(20);
});

it('marks solo plans as owner-only and bills a single seat for them', function () {
    $owner = User::factory()->create([
        'company_team_size' => 4,
    ]);

    TeamMember::factory()->count(2)->create([
        'account_id' => $owner->id,
        'is_active' => true,
    ]);

    $plans = collect(app(BillingPlanService::class)->plansForCurrency('CAD'))->keyBy('key');

    expect($plans->get('solo_pro'))->not->toBeNull()
        ->and($plans->get('solo_pro')['audience'])->toBe('solo')
        ->and($plans->get('solo_pro')['owner_only'])->toBeTrue()
        ->and($plans->get('solo_pro')['recommended'])->toBeTrue()
        ->and(app(BillingSubscriptionService::class)->resolveBillableQuantity($owner, 'solo_pro'))->toBe(1)
        ->and(app(BillingSubscriptionService::class)->resolveBillableQuantity($owner, 'growth'))->toBe(4);
});

it('configures unlimited fundamentals for every plan', function () {
    $fundamentalKeys = ['quotes', 'requests', 'invoices', 'products', 'services'];

    foreach (array_keys(config('billing.plans', [])) as $planCode) {
        foreach ($fundamentalKeys as $key) {
            expect(data_get(config('billing.plans'), $planCode.'.default_limits.'.$key))
                ->toBeNull();
        }
    }
});

it('builds a tenant-aware stripe checkout payload from a resolved plan price', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'EUR',
        'email' => 'eur-billing@example.test',
    ]);
    $planId = Plan::query()->where('code', 'starter')->value('id');

    DB::table('plan_prices')
        ->where('plan_id', $planId)
        ->where('plan_prices.currency_code', 'EUR')
        ->where('plan_prices.billing_period', BillingPeriod::MONTHLY->value)
        ->update([
            'stripe_price_id' => 'price_unit_starter_eur',
        ]);

    $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
        $tenant,
        'starter',
        BillingPeriod::MONTHLY
    );

    $checkoutSessions = new class
    {
        public array $payloads = [];

        public function create(array $payload): object
        {
            $this->payloads[] = $payload;

            return (object) [
                'id' => 'cs_test_unit_123',
                'url' => 'https://checkout.stripe.test/session',
            ];
        }
    };

    $fakeClient = new class($checkoutSessions) extends StripeClient
    {
        public function __construct(private object $checkoutSessions)
        {
            parent::__construct('sk_test_123');
        }

        public function getService($name)
        {
            return match ($name) {
                'checkout' => (object) ['sessions' => $this->checkoutSessions],
                default => throw new RuntimeException("Unexpected Stripe service [{$name}] in test."),
            };
        }
    };

    $service = app(StripeBillingService::class);
    injectStripeClient($service, $fakeClient);

    $trialEndsAt = Carbon::create(2026, 3, 31, 0, 0, 0, 'UTC');

    $session = $service->createCheckoutSessionForPlanPrice(
        $tenant,
        $planPrice,
        'https://example.test/success',
        'https://example.test/cancel',
        2,
        $trialEndsAt
    );

    $payload = $checkoutSessions->payloads[0];

    expect($session)->toBe([
        'id' => 'cs_test_unit_123',
        'url' => 'https://checkout.stripe.test/session',
    ])
        ->and($payload['mode'])->toBe('subscription')
        ->and($payload['success_url'])->toBe('https://example.test/success')
        ->and($payload['cancel_url'])->toBe('https://example.test/cancel')
        ->and($payload['client_reference_id'])->toBe((string) $tenant->id)
        ->and($payload['customer_email'])->toBe('eur-billing@example.test')
        ->and($payload['line_items'][0]['price'])->toBe('price_unit_starter_eur')
        ->and($payload['line_items'][0]['quantity'])->toBe(2)
        ->and($payload['metadata']['plan_code'])->toBe('starter')
        ->and($payload['metadata']['currency_code'])->toBe('EUR')
        ->and($payload['metadata']['billing_period'])->toBe(BillingPeriod::MONTHLY->value)
        ->and($payload['metadata']['plan_price_id'])->toBe((string) $planPrice->planPriceId)
        ->and($payload['subscription_data']['metadata'])->toBe($payload['metadata'])
        ->and($payload['subscription_data']['trial_end'])->toBe($trialEndsAt->getTimestamp())
        ->and($payload['payment_method_collection'])->toBe('always');
});

it('fails clearly when a resolved plan price has no stripe price id', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'USD',
    ]);
    $planId = Plan::query()->where('code', 'starter')->value('id');

    DB::table('plan_prices')
        ->where('plan_id', $planId)
        ->where('plan_prices.currency_code', 'USD')
        ->where('plan_prices.billing_period', BillingPeriod::MONTHLY->value)
        ->update([
            'stripe_price_id' => null,
        ]);

    $planPrice = app(ResolvePlanPriceForTenant::class)->execute(
        $tenant,
        'starter',
        BillingPeriod::MONTHLY
    );

    app(StripeBillingService::class)->createCheckoutSessionForPlanPrice(
        $tenant,
        $planPrice,
        'https://example.test/success',
        'https://example.test/cancel'
    );
})->throws(
    StripePriceNotConfiguredException::class,
    'No Stripe price ID is configured for plan [starter] in currency [USD] and period [monthly].'
);

it('syncs stripe subscription plan context from the resolved stripe price id', function () {
    $tenant = User::factory()->create([
        'currency_code' => 'EUR',
    ]);
    $planId = Plan::query()->where('code', 'starter')->value('id');

    DB::table('plan_prices')
        ->where('plan_id', $planId)
        ->where('plan_prices.currency_code', 'EUR')
        ->where('plan_prices.billing_period', BillingPeriod::MONTHLY->value)
        ->update([
            'stripe_price_id' => 'price_sync_starter_eur',
        ]);

    $record = app(StripeBillingService::class)->syncFromStripeSubscription([
        'id' => 'sub_test_sync_123',
        'customer' => 'cus_test_sync_123',
        'status' => 'active',
        'current_period_end' => Carbon::create(2026, 4, 30, 0, 0, 0, 'UTC')->getTimestamp(),
        'items' => [
            'data' => [
                [
                    'id' => 'si_test_sync_123',
                    'price' => [
                        'id' => 'price_sync_starter_eur',
                    ],
                ],
            ],
        ],
    ], $tenant);

    expect($record)->not->toBeNull()
        ->and($record->stripe_id)->toBe('sub_test_sync_123')
        ->and($record->stripe_customer_id)->toBe('cus_test_sync_123')
        ->and($record->price_id)->toBe('price_sync_starter_eur')
        ->and($record->currency_code)->toBe('EUR')
        ->and($record->plan_code)->toBe('starter')
        ->and($record->billing_period)->toBe(BillingPeriod::MONTHLY->value)
        ->and($tenant->fresh()->stripe_customer_id)->toBe('cus_test_sync_123');
});
