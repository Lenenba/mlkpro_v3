<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Paddle\Subscription;

class SubscriptionController extends Controller
{
    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (!$subscription) {
            return redirect()->back()->with('error', 'No active subscription found.');
        }

        return $subscription->redirectToUpdatePaymentMethod();
    }

    public function swap(Request $request): RedirectResponse
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
            return redirect()->back()->withErrors([
                'price_id' => 'No subscription plans are configured.',
            ]);
        }

        $validated = $request->validate([
            'price_id' => ['required', Rule::in($priceIds)],
        ]);

        $subscription = $user->subscription(Subscription::DEFAULT_TYPE);
        if (!$subscription || !$subscription->active()) {
            return redirect()->back()->withErrors([
                'price_id' => 'You do not have an active subscription.',
            ]);
        }

        $currentPriceId = $subscription->items()->value('price_id');
        if ($currentPriceId === $validated['price_id']) {
            return redirect()->back()->with('info', 'You are already on this plan.');
        }

        $plan = $plans->firstWhere('price_id', $validated['price_id']);
        $planKey = $plan['key'] ?? null;

        try {
            $subscription->swap($validated['price_id']);
        } catch (\Throwable $exception) {
            return redirect()->back()->with('error', 'Unable to change plans right now.');
        }

        return redirect()->route('settings.billing.edit', array_filter([
            'checkout' => 'swapped',
            'plan' => $planKey,
        ], fn($value) => $value !== null && $value !== ''));
    }
}

