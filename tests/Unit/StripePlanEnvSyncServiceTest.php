<?php

use App\Enums\BillingPeriod;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\StripePlanEnvSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fakeStripePrice(array $attributes): Price
{
    return Price::constructFrom(array_merge([
        'id' => 'price_test',
        'object' => 'price',
        'active' => true,
        'currency' => 'cad',
        'unit_amount' => 0,
        'product' => 'prod_test',
        'metadata' => [],
        'recurring' => [
            'interval' => 'month',
            'interval_count' => 1,
        ],
    ], $attributes));
}

function fakeStripeProduct(array $attributes): Product
{
    return Product::constructFrom(array_merge([
        'id' => 'prod_test',
        'object' => 'product',
        'active' => true,
        'name' => 'Test plan',
        'metadata' => [],
    ], $attributes));
}

it('syncs stripe prices into the env file and plan_prices table from existing stripe catalog data', function () {
    config()->set('services.stripe.secret', 'sk_test_sync_123');

    $service = new class(
        [
            fakeStripePrice([
                'id' => 'price_starter_cad',
                'currency' => 'cad',
                'unit_amount' => 15000,
                'product' => 'prod_starter_cad',
                'metadata' => ['plan_code' => 'starter'],
            ]),
            fakeStripePrice([
                'id' => 'price_starter_eur',
                'currency' => 'eur',
                'unit_amount' => 2100,
                'product' => 'prod_starter_shared',
            ]),
            fakeStripePrice([
                'id' => 'price_starter_usd',
                'currency' => 'usd',
                'unit_amount' => 2400,
                'product' => 'prod_unlabeled_usd',
            ]),
            fakeStripePrice([
                'id' => 'price_other_usd',
                'currency' => 'usd',
                'unit_amount' => 9900,
                'product' => 'prod_other_usd',
            ]),
        ],
        [
            'prod_starter_cad' => fakeStripeProduct([
                'id' => 'prod_starter_cad',
                'metadata' => [],
            ]),
            'prod_starter_shared' => fakeStripeProduct([
                'id' => 'prod_starter_shared',
                'metadata' => ['plan_code' => 'starter'],
            ]),
            'prod_unlabeled_usd' => fakeStripeProduct([
                'id' => 'prod_unlabeled_usd',
                'metadata' => [],
            ]),
            'prod_other_usd' => fakeStripeProduct([
                'id' => 'prod_other_usd',
                'metadata' => [],
            ]),
        ]
    ) extends StripePlanEnvSyncService {
        public function __construct(
            private array $prices,
            private array $products,
        ) {}

        protected function makeClient(string $secret): StripeClient
        {
            return new class extends StripeClient
            {
                public function __construct()
                {
                    parent::__construct('sk_test_sync_123');
                }
            };
        }

        protected function fetchActiveRecurringPrices(StripeClient $client): array
        {
            return $this->prices;
        }

        protected function retrievePrice(StripeClient $client, string $priceId): ?Price
        {
            foreach ($this->prices as $price) {
                if ($price->id === $priceId) {
                    return $price;
                }
            }

            return null;
        }

        protected function loadProduct(StripeClient $client, string $productId): Product
        {
            if (! isset($this->products[$productId])) {
                throw new \RuntimeException("Missing fake product [{$productId}]");
            }

            return $this->products[$productId];
        }
    };

    $envPath = tempnam(sys_get_temp_dir(), 'stripe-sync-env-');
    expect($envPath)->toBeString();

    try {
        file_put_contents($envPath, "APP_NAME=MLK Pro\n");

        $result = $service->execute([
            'plans' => ['starter'],
            'write_env' => $envPath,
            'sync_db' => true,
        ]);

        expect(array_column($result['items'], 'action'))->toBe([
            'PRICE METADATA',
            'PRODUCT METADATA',
            'AMOUNT',
        ])
            ->and($result['resolved'])->toMatchArray([
                'STRIPE_PRICE_STARTER_CAD' => 'price_starter_cad',
                'STRIPE_PRICE_STARTER_CAD_AMOUNT' => '150.00',
                'STRIPE_PRICE_STARTER' => 'price_starter_cad',
                'STRIPE_PRICE_STARTER_AMOUNT' => '150.00',
                'STRIPE_PRICE_STARTER_EUR' => 'price_starter_eur',
                'STRIPE_PRICE_STARTER_EUR_AMOUNT' => '21.00',
                'STRIPE_PRICE_STARTER_USD' => 'price_starter_usd',
                'STRIPE_PRICE_STARTER_USD_AMOUNT' => '24.00',
            ])
            ->and($result['env_updated'])->toBeTrue()
            ->and($result['db_synced'])->toBeTrue();

        $contents = file_get_contents($envPath);
        expect($contents)->toContain('STRIPE_PRICE_STARTER_CAD=price_starter_cad')
            ->toContain('STRIPE_PRICE_STARTER=price_starter_cad')
            ->toContain('STRIPE_PRICE_STARTER_EUR=price_starter_eur')
            ->toContain('STRIPE_PRICE_STARTER_USD_AMOUNT=24.00');

        $plan = Plan::query()->where('code', 'starter')->first();
        expect($plan)->not->toBeNull();

        $cadPrice = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('currency_code', 'CAD')
            ->where('billing_period', BillingPeriod::MONTHLY->value)
            ->first();

        $usdPrice = PlanPrice::query()
            ->where('plan_id', $plan->id)
            ->where('currency_code', 'USD')
            ->where('billing_period', BillingPeriod::MONTHLY->value)
            ->first();

        expect($cadPrice)->not->toBeNull()
            ->and($cadPrice->amount)->toBe('150.00')
            ->and($cadPrice->stripe_price_id)->toBe('price_starter_cad')
            ->and($usdPrice)->not->toBeNull()
            ->and($usdPrice->amount)->toBe('24.00')
            ->and($usdPrice->stripe_price_id)->toBe('price_starter_usd');
    } finally {
        if (is_string($envPath) && file_exists($envPath)) {
            unlink($envPath);
        }
    }
});

it('matches sibling currencies from the configured stripe product even when configured solo amounts differ', function () {
    config()->set('services.stripe.secret', 'sk_test_sync_123');
    config()->set('billing.plans', [
        'solo_essential' => [
            'name' => 'Solo Essential',
            'audience' => 'solo',
        ],
    ]);
    config()->set('billing.catalog_defaults', [
        'solo_essential' => [
            'contact_only' => false,
            'prices' => [
                'CAD' => [
                    'amount' => 19,
                    'stripe_price_id' => 'price_solo_essential_cad',
                ],
                'EUR' => [
                    'amount' => 14,
                    'stripe_price_id' => null,
                ],
                'USD' => [
                    'amount' => 16,
                    'stripe_price_id' => null,
                ],
            ],
        ],
    ]);

    $service = new class(
        [
            fakeStripePrice([
                'id' => 'price_solo_essential_cad',
                'currency' => 'cad',
                'unit_amount' => 3000,
                'product' => 'prod_solo_essential',
            ]),
            fakeStripePrice([
                'id' => 'price_solo_essential_eur',
                'currency' => 'eur',
                'unit_amount' => 1900,
                'product' => 'prod_solo_essential',
            ]),
            fakeStripePrice([
                'id' => 'price_solo_essential_usd',
                'currency' => 'usd',
                'unit_amount' => 2200,
                'product' => 'prod_solo_essential',
            ]),
        ],
        [
            'prod_solo_essential' => fakeStripeProduct([
                'id' => 'prod_solo_essential',
                'name' => 'SOLO ESSENTIAL',
                'metadata' => [],
            ]),
        ]
    ) extends StripePlanEnvSyncService {
        public function __construct(
            private array $prices,
            private array $products,
        ) {}

        protected function makeClient(string $secret): StripeClient
        {
            return new class extends StripeClient
            {
                public function __construct()
                {
                    parent::__construct('sk_test_sync_123');
                }
            };
        }

        protected function fetchActiveRecurringPrices(StripeClient $client): array
        {
            return $this->prices;
        }

        protected function retrievePrice(StripeClient $client, string $priceId): ?Price
        {
            foreach ($this->prices as $price) {
                if ($price->id === $priceId) {
                    return $price;
                }
            }

            return null;
        }

        protected function loadProduct(StripeClient $client, string $productId): Product
        {
            return $this->products[$productId];
        }
    };

    $result = $service->execute([
        'plans' => ['solo_essential'],
    ]);

    expect(array_column($result['items'], 'action'))->toBe([
        'CONFIGURED',
        'PRODUCT',
        'PRODUCT',
    ])
        ->and($result['resolved'])->toMatchArray([
            'STRIPE_PRICE_SOLO_ESSENTIAL_CAD' => 'price_solo_essential_cad',
            'STRIPE_PRICE_SOLO_ESSENTIAL_CAD_AMOUNT' => '30.00',
            'STRIPE_PRICE_SOLO_ESSENTIAL' => 'price_solo_essential_cad',
            'STRIPE_PRICE_SOLO_ESSENTIAL_AMOUNT' => '30.00',
            'STRIPE_PRICE_SOLO_ESSENTIAL_EUR' => 'price_solo_essential_eur',
            'STRIPE_PRICE_SOLO_ESSENTIAL_EUR_AMOUNT' => '19.00',
            'STRIPE_PRICE_SOLO_ESSENTIAL_USD' => 'price_solo_essential_usd',
            'STRIPE_PRICE_SOLO_ESSENTIAL_USD_AMOUNT' => '22.00',
        ]);
});

it('fails clearly when amount fallback is ambiguous', function () {
    config()->set('services.stripe.secret', 'sk_test_sync_123');

    $service = new class([
        fakeStripePrice([
            'id' => 'price_starter_usd_a',
            'currency' => 'usd',
            'unit_amount' => 2400,
            'product' => 'prod_usd_a',
        ]),
        fakeStripePrice([
            'id' => 'price_starter_usd_b',
            'currency' => 'usd',
            'unit_amount' => 2400,
            'product' => 'prod_usd_b',
        ]),
    ]) extends StripePlanEnvSyncService {
        public function __construct(private array $prices) {}

        protected function makeClient(string $secret): StripeClient
        {
            return new class extends StripeClient
            {
                public function __construct()
                {
                    parent::__construct('sk_test_sync_123');
                }
            };
        }

        protected function fetchActiveRecurringPrices(StripeClient $client): array
        {
            return $this->prices;
        }

        protected function retrievePrice(StripeClient $client, string $priceId): ?Price
        {
            return null;
        }

        protected function loadProduct(StripeClient $client, string $productId): Product
        {
            return fakeStripeProduct([
                'id' => $productId,
                'metadata' => [],
            ]);
        }
    };

    $run = fn () => $service->execute([
        'plans' => ['starter'],
        'currencies' => ['USD'],
    ]);

    expect($run)->toThrow(
        \RuntimeException::class,
        'Multiple active monthly Stripe prices matched plan [starter] currency [USD] by amount 24.00.'
    );
});
