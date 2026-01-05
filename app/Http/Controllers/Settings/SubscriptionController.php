<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\PlatformAdminNotifier;
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

        $currentPriceId = $subscription->items()->value('price_id');
        if ($currentPriceId === $validated['price_id']) {
            if ($this->shouldReturnJson($request)) {
                return response()->json([
                    'message' => 'You are already on this plan.',
                ]);
            }

            return redirect()->back()->with('info', 'You are already on this plan.');
        }

        $plan = $plans->firstWhere('price_id', $validated['price_id']);
        $planKey = $plan['key'] ?? null;

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
}
