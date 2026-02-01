<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\BillingSubscriptionService;
use App\Services\PlatformAdminNotifier;
use App\Services\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Paddle\Subscription;

class SubscriptionController extends Controller
{
    public function portal(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }
        $billingService = app(BillingSubscriptionService::class);
        if ($billingService->isStripe()) {
            $portalUrl = app(StripeBillingService::class)->createPortalSession($user, route('settings.billing.edit'));
            if (!$portalUrl) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Unable to open Stripe customer portal.',
                    ], 422);
                }

                return redirect()->back()->with('error', 'Unable to open Stripe customer portal.');
            }

            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'url' => $portalUrl,
                ]);
            }

            if ($request->header('X-Inertia')) {
                return Inertia::location($portalUrl);
            }

            return redirect()->away($portalUrl);
        }

        if ($response = $this->denyIfNotPaddle($request)) {
            return $response;
        }

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (!$subscription) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No active subscription found.',
                ], 422);
            }

            return redirect()->back()->with('error', 'No active subscription found.');
        }

        $updateUrl = $subscription->paymentMethodUpdateUrl();

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'url' => $updateUrl,
            ]);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::location($updateUrl);
        }

        return redirect()->away($updateUrl);
    }

    public function paymentMethodTransaction(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }
        if (!$this->isPaddleProvider()) {
            return response()->json([
                'message' => 'Billing provider is not Paddle.',
            ], 422);
        }

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (!$subscription) {
            return response()->json([
                'message' => 'No subscription found.',
            ], 422);
        }

        try {
            $transaction = $subscription->paymentMethodUpdateTransaction();
        } catch (\Throwable $exception) {
            Log::warning('Unable to create Paddle payment method update transaction.', [
                'user_id' => $user->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to start payment method update.',
            ], 500);
        }

        $transactionId = $transaction['id'] ?? $transaction['transaction_id'] ?? null;
        if (!$transactionId) {
            return response()->json([
                'message' => 'Invalid Paddle transaction response.',
            ], 500);
        }

        return response()->json([
            'transaction_id' => $transactionId,
        ]);
    }

    public function swap(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }
        $billingService = app(BillingSubscriptionService::class);

        $plans = collect(config('billing.plans', []))
            ->map(fn(array $plan, string $key) => array_merge(['key' => $key], $plan))
            ->filter(fn(array $plan) => !empty($plan['price_id']))
            ->values();

        $priceIds = $plans->pluck('price_id')->filter()->values()->all();
        if (!$priceIds) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'No subscription plans are configured.',
                    'errors' => [
                        'price_id' => ['No subscription plans are configured.'],
                    ],
                ], 422);
            }

            return redirect()->back()->withErrors([
                'price_id' => 'No subscription plans are configured.',
            ]);
        }

        $validated = $request->validate([
            'price_id' => ['required', Rule::in($priceIds)],
        ]);

        $plan = $plans->firstWhere('price_id', $validated['price_id']);
        $planKey = $plan['key'] ?? null;
        $currentPriceId = $billingService->resolvePriceId($user);

        if ($currentPriceId === $validated['price_id']) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'You are already on this plan.',
                ]);
            }

            return redirect()->back()->with('info', 'You are already on this plan.');
        }

        if ($billingService->isStripe()) {
            $summary = $billingService->subscriptionSummary($user);
            if (!$summary['active']) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'You do not have an active subscription.',
                        'errors' => [
                            'price_id' => ['You do not have an active subscription.'],
                        ],
                    ], 422);
                }

                return redirect()->back()->withErrors([
                    'price_id' => 'You do not have an active subscription.',
                ]);
            }

            try {
                $seatQuantity = $billingService->resolveSeatQuantity($user);
                $updated = app(StripeBillingService::class)->swapSubscription($user, $validated['price_id'], $seatQuantity);
                if (!$updated) {
                    throw new \RuntimeException('Stripe subscription update failed.');
                }
            } catch (\Throwable $exception) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Unable to change plans right now.',
                    ], 500);
                }

                return redirect()->back()->with('error', 'Unable to change plans right now.');
            }
        } else {
            if ($response = $this->denyIfNotPaddle($request)) {
                return $response;
            }

            $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
            if (!$subscription || !$subscription->active()) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'You do not have an active subscription.',
                        'errors' => [
                            'price_id' => ['You do not have an active subscription.'],
                        ],
                    ], 422);
                }

                return redirect()->back()->withErrors([
                    'price_id' => 'You do not have an active subscription.',
                ]);
            }

            try {
                $subscription->swap($validated['price_id']);
            } catch (\Throwable $exception) {
                if ($this->shouldReturnJson($request)) {
                    return response()->json([
                        'message' => 'Unable to change plans right now.',
                    ], 500);
                }

                return redirect()->back()->with('error', 'Unable to change plans right now.');
            }
        }

        $notifier = app(PlatformAdminNotifier::class);
        $notifier->notify('plan_changed', 'Plan changed', [
            'intro' => ($user->company_name ?: $user->email) . ' changed their plan.',
            'details' => [
                ['label' => 'Company', 'value' => $user->company_name ?: 'Not set'],
                ['label' => 'Owner', 'value' => $user->email ?: 'Unknown'],
                ['label' => 'From', 'value' => $notifier->resolvePlanName($currentPriceId)],
                ['label' => 'To', 'value' => $notifier->resolvePlanName($validated['price_id'])],
            ],
            'actionUrl' => route('superadmin.tenants.show', $user->id),
            'actionLabel' => 'View tenant',
            'reference' => 'plan:' . $user->id . ':' . $currentPriceId . ':' . $validated['price_id'],
            'severity' => 'info',
        ]);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Plan updated.',
                'plan_key' => $planKey,
            ]);
        }

        return redirect()->route('settings.billing.edit', array_filter([
            'checkout' => 'swapped',
            'plan' => $planKey,
        ], fn($value) => $value !== null && $value !== ''));
    }

    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->isStripe()) {
            return response()->json([
                'message' => 'Billing provider is not Stripe.',
            ], 422);
        }
        if (!$billingService->providerReady()) {
            return response()->json([
                'message' => 'Stripe is not configured.',
            ], 422);
        }

        $plans = collect(config('billing.plans', []))
            ->map(fn(array $plan, string $key) => array_merge(['key' => $key], $plan))
            ->filter(fn(array $plan) => !empty($plan['price_id']))
            ->values();

        $priceIds = $plans->pluck('price_id')->filter()->values()->all();
        $validated = $request->validate([
            'price_id' => ['required', Rule::in($priceIds)],
        ]);

        $plan = $plans->firstWhere('price_id', $validated['price_id']);
        $planKey = $plan['key'] ?? null;

        $successUrl = route('settings.billing.edit', array_filter([
            'checkout' => 'success',
            'plan' => $planKey,
        ], fn($value) => $value !== null && $value !== ''));
        $successUrl .= (str_contains($successUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';

        $cancelUrl = route('settings.billing.edit', ['checkout' => 'cancel']);

        try {
            $seatQuantity = $billingService->resolveSeatQuantity($user);
            $session = app(StripeBillingService::class)->createCheckoutSession(
                $user,
                $validated['price_id'],
                $successUrl,
                $cancelUrl,
                $planKey,
                $seatQuantity
            );
        } catch (\Throwable $exception) {
            Log::error('Stripe checkout session creation failed.', [
                'user_id' => $user->id,
                'price_id' => $validated['price_id'],
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage() ?: 'Unable to start Stripe checkout.',
            ], 500);
        }

        if (empty($session['url'])) {
            return response()->json([
                'message' => 'Stripe checkout did not return a URL.',
            ], 500);
        }

        return response()->json([
            'url' => $session['url'],
        ]);
    }

    private function denyIfNotPaddle(Request $request)
    {
        if ($this->isPaddleProvider()) {
            return null;
        }

        $message = 'Billing provider is not Paddle.';
        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => $message,
            ], 422);
        }

        return redirect()->back()->with('error', $message);
    }

    private function isPaddleProvider(): bool
    {
        $provider = strtolower((string) config('billing.provider_effective', config('billing.provider', 'paddle')));
        return $provider === '' || $provider === 'paddle';
    }
}
