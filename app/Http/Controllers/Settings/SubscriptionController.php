<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function checkout(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $plans = collect(config('billing.plans', []))
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

        if ($user->subscribed('default')) {
            return $user->redirectToBillingPortal(route('settings.billing.edit'));
        }

        return $user->newSubscription('default', $validated['price_id'])->checkout([
            'success_url' => route('settings.billing.edit', ['checkout' => 'success']),
            'cancel_url' => route('settings.billing.edit', ['checkout' => 'cancel']),
        ]);
    }

    public function portal(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        return $user->redirectToBillingPortal(route('settings.billing.edit'));
    }
}
