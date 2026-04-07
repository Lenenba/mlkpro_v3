<?php

namespace App\Services;

use App\Data\PlanPriceData;
use App\Enums\BillingPeriod;
use App\Exceptions\Billing\StripePriceNotConfiguredException;
use App\Models\Billing\StripeSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeBillingService
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return (bool) config('services.stripe.secret');
    }

    public function createCheckoutSession(
        User $user,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        ?string $planKey = null,
        int $quantity = 1,
        ?Carbon $trialEndsAt = null
    ): array {
        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);
        $quantity = $this->normalizeQuantity($quantity);

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => $quantity,
                ],
            ],
            'metadata' => array_filter([
                'subscription_type' => 'default',
                'plan_key' => $planKey,
            ]),
        ];

        $promotionDiscounts = $this->promotionDiscountsPayload(
            $this->resolveBillingPeriodForStripePriceId($priceId)
        );
        if ($promotionDiscounts !== null) {
            $payload['discounts'] = $promotionDiscounts;
        }

        if ($trialEndsAt instanceof Carbon) {
            $payload['subscription_data'] = [
                'trial_end' => $trialEndsAt->getTimestamp(),
            ];
            $payload['payment_method_collection'] = 'always';
        }

        if ($customerId) {
            $payload['customer'] = $customerId;
        } else {
            $payload['customer_email'] = $user->email;
        }

        $session = $client->checkout->sessions->create($payload);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function createCheckoutSessionForPlanPrice(
        User $user,
        PlanPriceData $planPrice,
        string $successUrl,
        string $cancelUrl,
        int $quantity = 1,
        ?Carbon $trialEndsAt = null
    ): array {
        $this->ensureStripePriceConfigured($planPrice);

        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);
        $quantity = $this->normalizeQuantity($quantity);
        $metadata = $this->buildPlanMetadata($planPrice);

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'line_items' => [
                [
                    'price' => $planPrice->stripePriceId,
                    'quantity' => $quantity,
                ],
            ],
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => $metadata,
            ],
        ];

        $promotionDiscounts = $this->promotionDiscountsPayload($planPrice->billingPeriod);
        if ($promotionDiscounts !== null) {
            $payload['discounts'] = $promotionDiscounts;
        }

        if ($trialEndsAt instanceof Carbon) {
            $payload['subscription_data']['trial_end'] = $trialEndsAt->getTimestamp();
            $payload['payment_method_collection'] = 'always';
        }

        if ($customerId) {
            $payload['customer'] = $customerId;
        } else {
            $payload['customer_email'] = $user->email;
        }

        $session = $client->checkout->sessions->create($payload);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function syncFromCheckoutSession(string $sessionId, User $user): ?StripeSubscription
    {
        $client = $this->client();
        $session = $client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription', 'subscription.items.data.price', 'subscription.discount.coupon'],
        ]);

        if (empty($session->subscription)) {
            return null;
        }

        $subscription = is_string($session->subscription)
            ? $client->subscriptions->retrieve($session->subscription, [
                'expand' => ['items.data.price', 'discount.coupon'],
            ])
            : $session->subscription;

        return $this->upsertSubscription($user, $subscription, $session->customer ?? null);
    }

    public function swapSubscription(User $user, string $priceId, int $quantity = 1): ?StripeSubscription
    {
        $client = $this->client();
        $local = $this->getLocalSubscription($user);
        if (! $local) {
            return null;
        }

        $quantity = $this->normalizeQuantity($quantity);
        $subscription = $client->subscriptions->retrieve($local->stripe_id, [
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);
        $planItem = $this->findPlanItem($subscription);
        $itemId = $planItem?->id ?? null;
        if (! $itemId) {
            return null;
        }

        $payload = [
            'items' => [
                [
                    'id' => $itemId,
                    'price' => $priceId,
                    'quantity' => $quantity,
                ],
            ],
            'proration_behavior' => 'create_prorations',
            'expand' => ['items.data.price', 'discount.coupon'],
        ];

        $promotionDiscounts = $this->promotionDiscountsPayloadForSubscription(
            $local,
            $this->resolveBillingPeriodForStripePriceId($priceId)
        );
        if ($promotionDiscounts !== null) {
            $payload['discounts'] = $promotionDiscounts;
        }

        $updated = $client->subscriptions->update($subscription->id, $payload);

        return $this->upsertSubscription($user, $updated, $updated->customer ?? null);
    }

    public function swapSubscriptionToPlanPrice(
        User $user,
        PlanPriceData $planPrice,
        int $quantity = 1
    ): ?StripeSubscription {
        $this->ensureStripePriceConfigured($planPrice);

        $client = $this->client();
        $local = $this->getLocalSubscription($user);
        if (! $local) {
            return null;
        }

        $quantity = $this->normalizeQuantity($quantity);
        $subscription = $client->subscriptions->retrieve($local->stripe_id, [
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);
        $planItem = $this->findPlanItem($subscription);
        $itemId = $planItem?->id ?? null;
        if (! $itemId) {
            return null;
        }

        $payload = [
            'items' => [
                [
                    'id' => $itemId,
                    'price' => $planPrice->stripePriceId,
                    'quantity' => $quantity,
                ],
            ],
            'metadata' => $this->buildPlanMetadata($planPrice),
            'proration_behavior' => 'create_prorations',
            'expand' => ['items.data.price', 'discount.coupon'],
        ];

        $promotionDiscounts = $this->promotionDiscountsPayloadForSubscription($local, $planPrice->billingPeriod);
        if ($promotionDiscounts !== null) {
            $payload['discounts'] = $promotionDiscounts;
        }

        $updated = $client->subscriptions->update($subscription->id, $payload);

        return $this->upsertSubscription($user, $updated, $updated->customer ?? null);
    }

    public function assignPlan(
        User $user,
        string $priceId,
        bool $comped = false,
        ?string $planKey = null,
        int $quantity = 1
    ): ?StripeSubscription {
        $client = $this->client();
        $customerId = $this->resolveOrCreateCustomerId($user);
        if (! $customerId) {
            return null;
        }

        $quantity = $this->normalizeQuantity($quantity);
        $couponId = $comped ? $this->compedCouponId() : null;
        if ($comped && ! $couponId) {
            throw new \RuntimeException('Comped coupon is not configured.');
        }

        $local = $this->getLocalSubscription($user);
        if ($local?->stripe_id) {
            $subscription = $client->subscriptions->retrieve($local->stripe_id, [
                'expand' => ['items.data.price', 'discount.coupon'],
            ]);
            $planItem = $this->findPlanItem($subscription);
            $itemId = $planItem?->id ?? null;
            if (! $itemId) {
                return null;
            }

            $payload = [
                'items' => [
                    [
                        'id' => $itemId,
                        'price' => $priceId,
                        'quantity' => $quantity,
                    ],
                ],
                'proration_behavior' => 'none',
                'expand' => ['items.data.price', 'discount.coupon'],
            ];

            if ($comped) {
                $payload['discounts'] = [['coupon' => $couponId]];
            } elseif ($local->is_comped) {
                $payload['discounts'] = [];
            } else {
                $promotionDiscounts = $this->promotionDiscountsPayloadForSubscription(
                    $local,
                    $this->resolveBillingPeriodForStripePriceId($priceId)
                );
                if ($promotionDiscounts !== null) {
                    $payload['discounts'] = $promotionDiscounts;
                }
            }

            $updated = $client->subscriptions->update($subscription->id, $payload);

            return $this->upsertSubscription($user, $updated, $updated->customer ?? null);
        }

        $payload = [
            'customer' => $customerId,
            'items' => [
                [
                    'price' => $priceId,
                    'quantity' => $quantity,
                ],
            ],
            'expand' => ['items.data.price', 'discount.coupon'],
            'metadata' => array_filter([
                'subscription_type' => 'default',
                'plan_key' => $planKey,
            ]),
        ];

        if ($comped) {
            $payload['discounts'] = [['coupon' => $couponId]];
        } else {
            $promotionDiscounts = $this->promotionDiscountsPayload(
                $this->resolveBillingPeriodForStripePriceId($priceId)
            );
            if ($promotionDiscounts !== null) {
                $payload['discounts'] = $promotionDiscounts;
            }
        }

        $subscription = $client->subscriptions->create($payload);

        return $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);
    }

    public function assignPlanPrice(
        User $user,
        PlanPriceData $planPrice,
        bool $comped = false,
        int $quantity = 1
    ): ?StripeSubscription {
        $this->ensureStripePriceConfigured($planPrice);

        $client = $this->client();
        $customerId = $this->resolveOrCreateCustomerId($user);
        if (! $customerId) {
            return null;
        }

        $quantity = $this->normalizeQuantity($quantity);
        $couponId = $comped ? $this->compedCouponId() : null;
        if ($comped && ! $couponId) {
            throw new \RuntimeException('Comped coupon is not configured.');
        }

        $metadata = $this->buildPlanMetadata($planPrice);
        $local = $this->getLocalSubscription($user);
        if ($local?->stripe_id) {
            $subscription = $client->subscriptions->retrieve($local->stripe_id, [
                'expand' => ['items.data.price', 'discount.coupon'],
            ]);
            $planItem = $this->findPlanItem($subscription);
            $itemId = $planItem?->id ?? null;
            if (! $itemId) {
                return null;
            }

            $payload = [
                'items' => [
                    [
                        'id' => $itemId,
                        'price' => $planPrice->stripePriceId,
                        'quantity' => $quantity,
                    ],
                ],
                'metadata' => $metadata,
                'proration_behavior' => 'none',
                'expand' => ['items.data.price', 'discount.coupon'],
            ];

            if ($comped) {
                $payload['discounts'] = [['coupon' => $couponId]];
            } elseif ($local->is_comped) {
                $payload['discounts'] = [];
            } else {
                $promotionDiscounts = $this->promotionDiscountsPayloadForSubscription($local, $planPrice->billingPeriod);
                if ($promotionDiscounts !== null) {
                    $payload['discounts'] = $promotionDiscounts;
                }
            }

            $updated = $client->subscriptions->update($subscription->id, $payload);

            return $this->upsertSubscription($user, $updated, $updated->customer ?? null);
        }

        $payload = [
            'customer' => $customerId,
            'items' => [
                [
                    'price' => $planPrice->stripePriceId,
                    'quantity' => $quantity,
                ],
            ],
            'expand' => ['items.data.price', 'discount.coupon'],
            'metadata' => $metadata,
        ];

        if ($comped) {
            $payload['discounts'] = [['coupon' => $couponId]];
        } else {
            $promotionDiscounts = $this->promotionDiscountsPayload($planPrice->billingPeriod);
            if ($promotionDiscounts !== null) {
                $payload['discounts'] = $promotionDiscounts;
            }
        }

        $subscription = $client->subscriptions->create($payload);

        return $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);
    }

    public function createPortalSession(User $user, string $returnUrl): ?string
    {
        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);
        if (! $customerId) {
            return null;
        }

        $session = $client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url ?? null;
    }

    public function enableAssistantAddon(User $user): ?StripeSubscription
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        if (! $assistantPriceId) {
            return null;
        }

        $local = $this->getLocalSubscription($user);
        if (! $local) {
            return null;
        }

        $client = $this->client();
        $subscription = $client->subscriptions->retrieve($local->stripe_id, [
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);

        $assistantItem = $this->findAssistantItem($subscription);
        if (! $assistantItem) {
            $subscription = $client->subscriptions->update($subscription->id, [
                'items' => [
                    [
                        'price' => $assistantPriceId,
                        'quantity' => 1,
                    ],
                ],
                'proration_behavior' => 'create_prorations',
                'expand' => ['items.data.price', 'discount.coupon'],
            ]);
        }

        return $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);
    }

    public function disableAssistantAddon(User $user): ?StripeSubscription
    {
        $local = $this->getLocalSubscription($user);
        if (! $local) {
            return null;
        }

        $client = $this->client();
        $subscription = $client->subscriptions->retrieve($local->stripe_id, [
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);

        $assistantItem = $this->findAssistantItem($subscription);
        if (! $assistantItem) {
            return $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);
        }

        $subscription = $client->subscriptions->update($subscription->id, [
            'items' => [
                [
                    'id' => $assistantItem->id,
                    'deleted' => true,
                ],
            ],
            'proration_behavior' => 'none',
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);

        return $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);
    }

    public function recordAssistantUsage(User $user, int $quantity, ?int $timestamp = null): ?string
    {
        $quantity = max(1, $quantity);
        $itemId = $this->resolveAssistantItemId($user);
        if (! $itemId) {
            return null;
        }

        $client = $this->client();
        $record = $client->subscriptionItems->createUsageRecord($itemId, [
            'quantity' => $quantity,
            'timestamp' => $timestamp ?? time(),
            'action' => 'increment',
        ]);

        return $record->id ?? null;
    }

    public function resolveAssistantItemId(User $user): ?string
    {
        $local = $this->getLocalSubscription($user);
        if ($local?->assistant_item_id) {
            return $local->assistant_item_id;
        }

        if (! $local?->stripe_id) {
            return null;
        }

        $client = $this->client();
        $subscription = $client->subscriptions->retrieve($local->stripe_id, [
            'expand' => ['items.data.price', 'discount.coupon'],
        ]);

        $updated = $this->upsertSubscription($user, $subscription, $subscription->customer ?? null);

        return $updated?->assistant_item_id;
    }

    public function syncFromStripeSubscription(array $subscription, ?User $user = null): ?StripeSubscription
    {
        $stripeId = $subscription['id'] ?? null;
        if (! $stripeId) {
            return null;
        }

        $customerId = $subscription['customer'] ?? null;

        if (! $user && $stripeId) {
            $local = StripeSubscription::query()->where('stripe_id', $stripeId)->first();
            $user = $local?->user;
        }

        if (! $user && $customerId) {
            $user = User::query()->where('stripe_customer_id', $customerId)->first();
        }

        if (! $user) {
            return null;
        }

        return $this->upsertSubscriptionFromArray($user, $subscription);
    }

    public function getLocalSubscription(User $user): ?StripeSubscription
    {
        return StripeSubscription::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();
    }

    public function previewUpcomingInvoice(User $user, ?StripeSubscription $subscription = null): ?array
    {
        $local = $subscription ?: $this->getLocalSubscription($user);
        if (! $local?->stripe_id) {
            return null;
        }

        $customerId = $local->stripe_customer_id ?: $this->resolveCustomerId($user);
        if (! $customerId) {
            return null;
        }

        $invoice = $this->client()->invoices->createPreview([
            'customer' => $customerId,
            'subscription' => $local->stripe_id,
            'expand' => ['lines.data.price.product'],
        ]);

        return method_exists($invoice, 'toArray') ? $invoice->toArray() : null;
    }

    private function upsertSubscription(User $user, $subscription, ?string $customerId = null): ?StripeSubscription
    {
        $stripeId = $subscription->id ?? null;
        if (! $stripeId) {
            return null;
        }

        $customerId = $customerId ?: ($subscription->customer ?? null);
        $priceId = $this->extractPriceId($subscription);
        $assistantAddon = $this->extractAssistantAddon($subscription);
        $compedMeta = $this->extractCompedMeta($subscription);
        $promotionMeta = $this->extractPromotionMeta($subscription);
        $planContext = $this->resolvePlanContext($priceId, $this->extractMetadata($subscription));

        $record = StripeSubscription::updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user->id,
                'stripe_customer_id' => $customerId,
                'price_id' => $priceId,
                'currency_code' => $planContext['currency_code'],
                'plan_code' => $planContext['plan_code'],
                'plan_price_id' => $planContext['plan_price_id'],
                'billing_period' => $planContext['billing_period'],
                'is_comped' => $compedMeta['is_comped'],
                'comped_coupon_id' => $compedMeta['comped_coupon_id'],
                'promotion_coupon_id' => $promotionMeta['promotion_coupon_id'],
                'promotion_discount_percent' => $promotionMeta['promotion_discount_percent'],
                'assistant_price_id' => $assistantAddon['assistant_price_id'],
                'assistant_item_id' => $assistantAddon['assistant_item_id'],
                'assistant_enabled_at' => $assistantAddon['assistant_enabled_at'],
                'status' => $subscription->status ?? null,
                'trial_ends_at' => $this->timestampToCarbon($subscription->trial_end ?? null),
                'ends_at' => $this->timestampToCarbon($subscription->ended_at ?? $subscription->canceled_at ?? null),
                'current_period_end' => $this->timestampToCarbon($subscription->current_period_end ?? null),
            ]
        );

        if ($customerId && $user->stripe_customer_id !== $customerId) {
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        return $record;
    }

    private function upsertSubscriptionFromArray(User $user, array $subscription): ?StripeSubscription
    {
        $stripeId = $subscription['id'] ?? null;
        if (! $stripeId) {
            return null;
        }

        $customerId = $subscription['customer'] ?? null;
        $priceId = $this->extractPriceIdFromArray($subscription);
        $assistantAddon = $this->extractAssistantAddonFromArray($subscription);
        $compedMeta = $this->extractCompedMetaFromArray($subscription);
        $promotionMeta = $this->extractPromotionMetaFromArray($subscription);
        $planContext = $this->resolvePlanContext($priceId, $this->extractMetadataFromArray($subscription));

        $record = StripeSubscription::updateOrCreate(
            ['stripe_id' => $stripeId],
            [
                'user_id' => $user->id,
                'stripe_customer_id' => $customerId,
                'price_id' => $priceId,
                'currency_code' => $planContext['currency_code'],
                'plan_code' => $planContext['plan_code'],
                'plan_price_id' => $planContext['plan_price_id'],
                'billing_period' => $planContext['billing_period'],
                'is_comped' => $compedMeta['is_comped'],
                'comped_coupon_id' => $compedMeta['comped_coupon_id'],
                'promotion_coupon_id' => $promotionMeta['promotion_coupon_id'],
                'promotion_discount_percent' => $promotionMeta['promotion_discount_percent'],
                'assistant_price_id' => $assistantAddon['assistant_price_id'],
                'assistant_item_id' => $assistantAddon['assistant_item_id'],
                'assistant_enabled_at' => $assistantAddon['assistant_enabled_at'],
                'status' => $subscription['status'] ?? null,
                'trial_ends_at' => $this->timestampToCarbon($subscription['trial_end'] ?? null),
                'ends_at' => $this->timestampToCarbon($subscription['ended_at'] ?? $subscription['canceled_at'] ?? null),
                'current_period_end' => $this->timestampToCarbon($subscription['current_period_end'] ?? null),
            ]
        );

        if ($customerId && $user->stripe_customer_id !== $customerId) {
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        return $record;
    }

    private function resolveCustomerId(User $user): ?string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $local = $this->getLocalSubscription($user);
        if ($local?->stripe_customer_id) {
            $user->forceFill(['stripe_customer_id' => $local->stripe_customer_id])->save();

            return $local->stripe_customer_id;
        }

        return null;
    }

    private function resolveOrCreateCustomerId(User $user): ?string
    {
        $customerId = $this->resolveCustomerId($user);
        if ($customerId) {
            return $customerId;
        }

        $client = $this->client();
        $customer = $client->customers->create([
            'email' => $user->email,
            'name' => $user->company_name ?: $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $customerId = $customer->id ?? null;
        if ($customerId) {
            $user->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        return $customerId;
    }

    private function extractPriceId($subscription): ?string
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        $items = $subscription->items->data ?? [];
        foreach ($items as $item) {
            $priceId = $item?->price?->id ?? null;
            if (! $priceId) {
                continue;
            }
            if ($assistantPriceId && $priceId === $assistantPriceId) {
                continue;
            }

            return $priceId;
        }

        return null;
    }

    private function extractPriceIdFromArray(array $subscription): ?string
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        $items = $subscription['items']['data'] ?? [];
        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            if (! $priceId) {
                continue;
            }
            if ($assistantPriceId && $priceId === $assistantPriceId) {
                continue;
            }

            return $priceId;
        }

        return null;
    }

    private function extractAssistantAddon($subscription): array
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        if (! $assistantPriceId) {
            return [
                'assistant_price_id' => null,
                'assistant_item_id' => null,
                'assistant_enabled_at' => null,
            ];
        }

        $items = $subscription->items->data ?? [];
        foreach ($items as $item) {
            $priceId = $item?->price?->id ?? null;
            if ($priceId && $priceId === $assistantPriceId) {
                return [
                    'assistant_price_id' => $assistantPriceId,
                    'assistant_item_id' => $item->id ?? null,
                    'assistant_enabled_at' => $this->timestampToCarbon($item->created ?? null),
                ];
            }
        }

        return [
            'assistant_price_id' => null,
            'assistant_item_id' => null,
            'assistant_enabled_at' => null,
        ];
    }

    private function findPlanItem($subscription): ?object
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        $items = $subscription->items->data ?? [];
        foreach ($items as $item) {
            $priceId = $item?->price?->id ?? null;
            if (! $priceId) {
                continue;
            }
            if ($assistantPriceId && $priceId === $assistantPriceId) {
                continue;
            }

            return $item;
        }

        return null;
    }

    private function extractAssistantAddonFromArray(array $subscription): array
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        if (! $assistantPriceId) {
            return [
                'assistant_price_id' => null,
                'assistant_item_id' => null,
                'assistant_enabled_at' => null,
            ];
        }

        $items = $subscription['items']['data'] ?? [];
        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            if ($priceId && $priceId === $assistantPriceId) {
                return [
                    'assistant_price_id' => $assistantPriceId,
                    'assistant_item_id' => $item['id'] ?? null,
                    'assistant_enabled_at' => $this->timestampToCarbon($item['created'] ?? null),
                ];
            }
        }

        return [
            'assistant_price_id' => null,
            'assistant_item_id' => null,
            'assistant_enabled_at' => null,
        ];
    }

    private function extractCompedMeta($subscription): array
    {
        $discount = $subscription->discount ?? null;
        $coupon = $discount?->coupon ?? null;
        $percentOff = $coupon?->percent_off ?? null;

        return [
            'is_comped' => is_numeric($percentOff) && (float) $percentOff >= 100,
            'comped_coupon_id' => $coupon?->id ?? null,
        ];
    }

    private function extractPromotionMeta($subscription): array
    {
        $discount = $subscription->discount ?? null;
        $coupon = $discount?->coupon ?? null;
        $percentOff = $coupon?->percent_off ?? null;

        if (! app(StripePromotionCouponSyncService::class)->isPromotionCoupon($coupon)) {
            return [
                'promotion_coupon_id' => null,
                'promotion_discount_percent' => null,
            ];
        }

        return [
            'promotion_coupon_id' => $coupon?->id ?? null,
            'promotion_discount_percent' => is_numeric($percentOff) ? (int) round((float) $percentOff) : null,
        ];
    }

    private function extractCompedMetaFromArray(array $subscription): array
    {
        $discount = $subscription['discount'] ?? null;
        $coupon = $discount['coupon'] ?? null;
        $percentOff = $coupon['percent_off'] ?? null;

        return [
            'is_comped' => is_numeric($percentOff) && (float) $percentOff >= 100,
            'comped_coupon_id' => is_array($coupon) ? ($coupon['id'] ?? null) : null,
        ];
    }

    private function extractPromotionMetaFromArray(array $subscription): array
    {
        $discount = $subscription['discount'] ?? null;
        $coupon = $discount['coupon'] ?? null;
        $percentOff = is_array($coupon) ? ($coupon['percent_off'] ?? null) : null;

        if (! app(StripePromotionCouponSyncService::class)->isPromotionCoupon($coupon)) {
            return [
                'promotion_coupon_id' => null,
                'promotion_discount_percent' => null,
            ];
        }

        return [
            'promotion_coupon_id' => is_array($coupon) ? ($coupon['id'] ?? null) : null,
            'promotion_discount_percent' => is_numeric($percentOff) ? (int) round((float) $percentOff) : null,
        ];
    }

    private function findAssistantItem($subscription): ?object
    {
        $assistantPriceId = $this->assistantUsagePriceId();
        if (! $assistantPriceId) {
            return null;
        }

        $items = $subscription->items->data ?? [];
        foreach ($items as $item) {
            $priceId = $item?->price?->id ?? null;
            if ($priceId && $priceId === $assistantPriceId) {
                return $item;
            }
        }

        return null;
    }

    private function timestampToCarbon($timestamp): ?Carbon
    {
        if (! $timestamp) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp, 'UTC');
    }

    private function assistantUsagePriceId(): ?string
    {
        $price = config('services.stripe.ai_usage_price');

        return is_string($price) && trim($price) !== '' ? trim($price) : null;
    }

    private function normalizeQuantity(?int $quantity): int
    {
        $value = (int) $quantity;

        return $value > 0 ? $value : 1;
    }

    private function compedCouponId(): ?string
    {
        $coupon = config('services.stripe.comped_coupon_id');

        return is_string($coupon) && trim($coupon) !== '' ? trim($coupon) : null;
    }

    private function promotionDiscountsPayload(BillingPeriod|string|null $billingPeriod = null): ?array
    {
        $promotionService = app(SubscriptionPromotionService::class);
        $discountPercent = $promotionService->activeDiscountPercent($billingPeriod);
        if (! $discountPercent) {
            return null;
        }

        $couponId = $promotionService->activeCouponId($billingPeriod)
            ?: app(StripePromotionCouponSyncService::class)->ensureCouponIdForDiscountPercent($discountPercent);

        return $couponId ? [['coupon' => $couponId]] : null;
    }

    private function promotionDiscountsPayloadForSubscription(
        ?StripeSubscription $local,
        BillingPeriod|string|null $billingPeriod = null
    ): ?array
    {
        $targetBillingPeriod = $billingPeriod ?? $local?->billing_period;
        $activeDiscounts = $this->promotionDiscountsPayload($targetBillingPeriod);
        if ($activeDiscounts !== null) {
            return $activeDiscounts;
        }

        if (! $local || $local->is_comped || ! $local->promotion_coupon_id) {
            return null;
        }

        return [];
    }

    private function resolveBillingPeriodForStripePriceId(?string $priceId): ?string
    {
        return $this->resolvePlanContext($priceId)['billing_period'] ?? null;
    }

    public function createAssistantCreditCheckoutSession(User $user, int $packs, string $successUrl, string $cancelUrl): array
    {
        $priceId = $this->assistantCreditPriceId();
        if (! $priceId) {
            return ['id' => null, 'url' => null];
        }

        $packs = max(1, $packs);
        $packSize = $this->assistantCreditPackSize();
        $client = $this->client();
        $customerId = $this->resolveCustomerId($user);

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            'line_items' => [
                [
                    'price' => $priceId,
                    'quantity' => $packs,
                ],
            ],
            'metadata' => [
                'purpose' => 'assistant_credits',
                'user_id' => (string) $user->id,
                'pack_size' => (string) $packSize,
                'pack_count' => (string) $packs,
            ],
        ];

        if ($customerId) {
            $payload['customer'] = $customerId;
        } else {
            $payload['customer_email'] = $user->email;
        }

        $session = $client->checkout->sessions->create($payload);

        return [
            'id' => $session->id ?? null,
            'url' => $session->url ?? null,
        ];
    }

    public function retrieveCheckoutSession(string $sessionId): ?array
    {
        if (! $sessionId) {
            return null;
        }

        $client = $this->client();
        $session = $client->checkout->sessions->retrieve($sessionId);

        return $session ? $session->toArray() : null;
    }

    private function assistantCreditPriceId(): ?string
    {
        $price = config('services.stripe.ai_credit_price');

        return is_string($price) && trim($price) !== '' ? trim($price) : null;
    }

    private function assistantCreditPackSize(): int
    {
        $pack = (int) config('services.stripe.ai_credit_pack', 0);

        return $pack > 0 ? $pack : 0;
    }

    private function ensureStripePriceConfigured(PlanPriceData $planPrice): void
    {
        if ($planPrice->stripePriceId) {
            return;
        }

        throw new StripePriceNotConfiguredException(sprintf(
            'No Stripe price ID is configured for plan [%s] in currency [%s] and period [%s].',
            $planPrice->planCode,
            $planPrice->currencyCode->value,
            $planPrice->billingPeriod->value,
        ));
    }

    private function buildPlanMetadata(PlanPriceData $planPrice): array
    {
        return array_filter([
            'subscription_type' => 'default',
            'plan_key' => $planPrice->planCode,
            'plan_code' => $planPrice->planCode,
            'plan_price_id' => (string) $planPrice->planPriceId,
            'currency_code' => $planPrice->currencyCode->value,
            'billing_period' => $planPrice->billingPeriod->value,
        ]);
    }

    private function resolvePlanContext(?string $priceId, array $metadata = []): array
    {
        $planPrice = $priceId ? app(BillingPlanService::class)->resolveByStripePriceId($priceId) : null;

        return [
            'currency_code' => $metadata['currency_code']
                ?? $planPrice?->currencyCode->value,
            'plan_code' => $metadata['plan_code']
                ?? $metadata['plan_key']
                ?? $planPrice?->planCode,
            'plan_price_id' => isset($metadata['plan_price_id'])
                ? (int) $metadata['plan_price_id']
                : $planPrice?->planPriceId,
            'billing_period' => $metadata['billing_period']
                ?? $planPrice?->billingPeriod->value,
        ];
    }

    private function extractMetadata($subscription): array
    {
        $metadata = $subscription->metadata ?? [];
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_object($metadata) && method_exists($metadata, 'toArray')) {
            return $metadata->toArray();
        }

        return [];
    }

    private function extractMetadataFromArray(array $subscription): array
    {
        $metadata = $subscription['metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    private function client(): StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            Log::warning('Stripe secret key is missing.');
        }

        $this->client = new StripeClient($secret ?: '');

        return $this->client;
    }
}
