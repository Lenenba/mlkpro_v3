<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

class BillingSettingsController extends Controller
{
    private const AVAILABLE_METHODS = [
        ['id' => 'cash', 'name' => 'Cash'],
        ['id' => 'card', 'name' => 'Card'],
        ['id' => 'bank_transfer', 'name' => 'Bank transfer'],
        ['id' => 'check', 'name' => 'Check'],
    ];

    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $checkoutStatus = $request->query('checkout');

        $paddleApiEnabled = (bool) config('cashier.api_key');
        $paddleJsEnabled = (bool) (config('cashier.client_side_token') || config('cashier.seller_id'));
        $paddleError = null;
        $retainKey = config('cashier.retain_key');
        $sellerId = config('cashier.seller_id');

        if ($paddleApiEnabled) {
            try {
                $user->createAsCustomer();
            } catch (\Throwable $exception) {
                $paddleError = 'Paddle API is not configured correctly.';
                Log::warning('Unable to create Paddle customer.', [
                    'user_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        if ($checkoutStatus === 'success' && $paddleApiEnabled && !$paddleError) {
            try {
                $this->syncLatestSubscription($user);
            } catch (\Throwable $exception) {
                Log::warning('Unable to sync Paddle subscription after checkout.', [
                    'user_id' => $user->id,
                    'exception' => $exception->getMessage(),
                ]);
            }

            $user->unsetRelation('subscriptions');
        }

        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? ucfirst($key),
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $plan['price'] ?? null,
                    'display_price' => $this->resolvePlanDisplayPrice($plan),
                    'features' => $plan['features'] ?? [],
                ];
            })
            ->values()
            ->all();

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        $subscriptionPriceId = $subscription?->items()->value('price_id');

        return $this->inertiaOrJson('Settings/Billing', [
            'availableMethods' => self::AVAILABLE_METHODS,
            'paymentMethods' => array_values($user->payment_methods ?? []),
            'plans' => $plans,
            'subscription' => [
                'active' => $user->subscribed(Subscription::DEFAULT_TYPE),
                'on_trial' => $user->onTrial(Subscription::DEFAULT_TYPE),
                'status' => $subscription?->status,
                'price_id' => $subscriptionPriceId,
                'ends_at' => $subscription?->ends_at,
                'trial_ends_at' => $subscription?->trial_ends_at,
                'paddle_id' => $subscription?->paddle_id,
            ],
            'checkoutStatus' => $checkoutStatus,
            'checkoutPlanKey' => $request->query('plan'),
            'paddle' => [
                'js_enabled' => $paddleJsEnabled,
                'api_enabled' => $paddleApiEnabled,
                'sandbox' => (bool) config('cashier.sandbox'),
                'customer_id' => $user->customer?->paddle_id,
                'client_side_token' => config('cashier.client_side_token'),
                'seller_id' => is_numeric($sellerId) ? (int) $sellerId : null,
                'retain_key' => is_numeric($retainKey) ? (int) $retainKey : null,
                'error' => $paddleError,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $allowed = collect(self::AVAILABLE_METHODS)->pluck('id')->all();

        $validated = $request->validate([
            'payment_methods' => 'nullable|array',
            'payment_methods.*' => ['string', Rule::in($allowed)],
        ]);

        $user->update([
            'payment_methods' => array_values(array_unique($validated['payment_methods'] ?? [])),
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Payment settings updated.',
                'payment_methods' => $user->payment_methods,
            ]);
        }

        return redirect()->back()->with('success', 'Payment settings updated.');
    }

    private function resolvePlanDisplayPrice(array $plan): ?string
    {
        $raw = $plan['price'] ?? null;
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), config('cashier.currency', 'USD'));
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }

    private function syncLatestSubscription($user): void
    {
        $customer = $user->customer ?: $user->createAsCustomer();
        if (!$customer) {
            return;
        }

        $latest = Cashier::api('GET', 'subscriptions', [
            'customer_id' => $customer->paddle_id,
            'per_page' => 1,
            'status' => implode(',', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_TRIALING,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_PAUSED,
                Subscription::STATUS_CANCELED,
            ]),
        ])['data'][0] ?? null;

        if (!$latest || empty($latest['id'])) {
            return;
        }

        $subscription = $user->subscriptions()->firstOrNew([
            'paddle_id' => $latest['id'],
        ]);

        $subscription->type = $latest['custom_data']['subscription_type'] ?? Subscription::DEFAULT_TYPE;
        $subscription->status = $latest['status'] ?? Subscription::STATUS_ACTIVE;
        $subscription->trial_ends_at = ($subscription->status === Subscription::STATUS_TRIALING && !empty($latest['next_billed_at']))
            ? Carbon::parse($latest['next_billed_at'], 'UTC')
            : null;

        $subscription->paused_at = !empty($latest['paused_at'])
            ? Carbon::parse($latest['paused_at'], 'UTC')
            : null;

        $subscription->ends_at = !empty($latest['canceled_at'])
            ? Carbon::parse($latest['canceled_at'], 'UTC')
            : null;

        $subscription->save();

        $items = $latest['items'] ?? [];
        $knownPriceIds = [];
        foreach ($items as $item) {
            $priceId = $item['price']['id'] ?? null;
            if (!$priceId) {
                continue;
            }

            $knownPriceIds[] = $priceId;

            $subscription->items()->updateOrCreate([
                'subscription_id' => $subscription->id,
                'price_id' => $priceId,
            ], [
                'product_id' => $item['price']['product_id'] ?? '',
                'status' => $item['status'] ?? Subscription::STATUS_ACTIVE,
                'quantity' => $item['quantity'] ?? 1,
            ]);
        }

        if ($knownPriceIds) {
            $subscription->items()->whereNotIn('price_id', $knownPriceIds)->delete();
        }

        $user->customer?->update(['trial_ends_at' => null]);
    }
}
