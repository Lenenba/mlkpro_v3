<?php

use App\Services\StripePlanPriceProvisioner;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Tests\TestCase;

uses(TestCase::class);

function provisionerFakePrice(array $attributes): Price
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

function provisionerFakeProduct(array $attributes): Product
{
    return Product::constructFrom(array_merge([
        'id' => 'prod_test',
        'object' => 'product',
        'active' => true,
        'name' => 'Test plan',
        'metadata' => [],
    ], $attributes));
}

class FakeStripePlanPriceProvisionerForTest extends StripePlanPriceProvisioner
{
    public array $createdPayloads = [];

    public function __construct(
        private array $prices,
        private array $products,
    ) {}

    protected function makeClient(string $secret): StripeClient
    {
        $createdPayloads = &$this->createdPayloads;

        return new class($createdPayloads) extends StripeClient
        {
            public function __construct(private array &$createdPayloads)
            {
                parent::__construct('sk_test_123');
            }

            public function getService($name)
            {
                return match ($name) {
                    'prices' => new class($this->createdPayloads)
                    {
                        public function __construct(private array &$createdPayloads) {}

                        public function create(array $payload): Price
                        {
                            $this->createdPayloads[] = $payload;

                            return provisionerFakePrice([
                                'id' => 'price_created_unexpectedly',
                                'currency' => $payload['currency'] ?? 'cad',
                                'unit_amount' => $payload['unit_amount'] ?? 0,
                                'product' => $payload['product'] ?? 'prod_created',
                                'recurring' => [
                                    'interval' => $payload['recurring']['interval'] ?? 'month',
                                    'interval_count' => 1,
                                ],
                                'metadata' => $payload['metadata'] ?? [],
                            ]);
                        }
                    },
                    default => throw new RuntimeException("Unexpected Stripe service [{$name}] in provisioner test."),
                };
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
            throw new RuntimeException("Missing fake product [{$productId}]");
        }

        return $this->products[$productId];
    }
}

it('reuses an identifiable yearly plan price from the existing Stripe catalog before creating a duplicate', function () {
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
                    'monthly' => [
                        'amount' => 30,
                        'stripe_price_id' => 'price_solo_essential_cad_monthly',
                    ],
                    'yearly' => [
                        'amount' => 288,
                        'stripe_price_id' => null,
                    ],
                ],
            ],
        ],
    ]);

    $service = new FakeStripePlanPriceProvisionerForTest(
        [
            provisionerFakePrice([
                'id' => 'price_solo_essential_cad_monthly',
                'currency' => 'cad',
                'unit_amount' => 3000,
                'product' => 'prod_solo_monthly',
            ]),
            provisionerFakePrice([
                'id' => 'price_solo_essential_cad_yearly_existing',
                'currency' => 'cad',
                'unit_amount' => 28800,
                'product' => 'prod_solo_yearly',
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1,
                ],
            ]),
        ],
        [
            'prod_solo_monthly' => provisionerFakeProduct([
                'id' => 'prod_solo_monthly',
                'name' => 'Solo Essential',
            ]),
            'prod_solo_yearly' => provisionerFakeProduct([
                'id' => 'prod_solo_yearly',
                'name' => 'Completely different label',
                'metadata' => ['plan_code' => 'solo_essential'],
            ]),
        ],
    );

    $result = $service->execute([
        'plans' => ['solo_essential'],
    ]);

    expect(array_column($result['items'], 'action'))->toBe([
        'BASE',
        'REUSED',
    ])
        ->and($result['resolved'])->toMatchArray([
            'STRIPE_PRICE_SOLO_ESSENTIAL_CAD' => 'price_solo_essential_cad_monthly',
            'STRIPE_PRICE_SOLO_ESSENTIAL_CAD_YEARLY' => 'price_solo_essential_cad_yearly_existing',
        ])
        ->and($service->createdPayloads)->toBe([]);
});

it('fails clearly when an identifiable existing plan price has a different amount instead of creating a duplicate', function () {
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
                    'monthly' => [
                        'amount' => 30,
                        'stripe_price_id' => 'price_solo_essential_cad_monthly',
                    ],
                    'yearly' => [
                        'amount' => 288,
                        'stripe_price_id' => null,
                    ],
                ],
            ],
        ],
    ]);

    $service = new FakeStripePlanPriceProvisionerForTest(
        [
            provisionerFakePrice([
                'id' => 'price_solo_essential_cad_monthly',
                'currency' => 'cad',
                'unit_amount' => 3000,
                'product' => 'prod_solo_monthly',
            ]),
            provisionerFakePrice([
                'id' => 'price_solo_essential_cad_yearly_existing',
                'currency' => 'cad',
                'unit_amount' => 23400,
                'product' => 'prod_solo_yearly',
                'recurring' => [
                    'interval' => 'year',
                    'interval_count' => 1,
                ],
            ]),
        ],
        [
            'prod_solo_monthly' => provisionerFakeProduct([
                'id' => 'prod_solo_monthly',
                'name' => 'Solo Essential',
            ]),
            'prod_solo_yearly' => provisionerFakeProduct([
                'id' => 'prod_solo_yearly',
                'name' => 'Completely different label',
                'metadata' => ['plan_code' => 'solo_essential'],
            ]),
        ],
    );

    $run = fn () => $service->execute([
        'plans' => ['solo_essential'],
    ]);

    expect($run)->toThrow(
        RuntimeException::class,
        'Refusing to create a duplicate'
    );
    expect($service->createdPayloads)->toBe([]);
});
