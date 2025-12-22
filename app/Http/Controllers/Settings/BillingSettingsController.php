<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    private const AVAILABLE_METHODS = [
        ['id' => 'cash', 'name' => 'Cash'],
        ['id' => 'card', 'name' => 'Card'],
        ['id' => 'bank_transfer', 'name' => 'Bank transfer'],
        ['id' => 'check', 'name' => 'Check'],
    ];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        if (!$user || !$user->isAccountOwner()) {
            abort(403);
        }

        $plans = collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? ucfirst($key),
                    'price_id' => $plan['price_id'] ?? null,
                    'price' => $plan['price'] ?? null,
                    'features' => $plan['features'] ?? [],
                ];
            })
            ->values()
            ->all();

        $subscription = $user->subscription('default');

        return Inertia::render('Settings/Billing', [
            'availableMethods' => self::AVAILABLE_METHODS,
            'paymentMethods' => array_values($user->payment_methods ?? []),
            'plans' => $plans,
            'subscription' => [
                'active' => $user->subscribed('default'),
                'on_trial' => $user->onTrial('default'),
                'status' => $subscription?->stripe_status,
                'stripe_price' => $subscription?->stripe_price,
                'ends_at' => $subscription?->ends_at,
                'trial_ends_at' => $subscription?->trial_ends_at,
            ],
            'checkoutStatus' => $request->query('checkout'),
        ]);
    }

    public function update(Request $request): RedirectResponse
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

        return redirect()->back()->with('success', 'Payment settings updated.');
    }
}
