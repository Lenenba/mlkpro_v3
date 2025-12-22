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

        return Inertia::render('Settings/Billing', [
            'availableMethods' => self::AVAILABLE_METHODS,
            'paymentMethods' => array_values($user->payment_methods ?? []),
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

