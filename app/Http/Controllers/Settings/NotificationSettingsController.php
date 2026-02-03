<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class NotificationSettingsController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $service = app(NotificationPreferenceService::class);
        $settings = $service->resolveFor($user);

        return $this->inertiaOrJson('Settings/Notifications', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $validated = $request->validate([
            'channels' => 'array',
            'channels.in_app' => 'boolean',
            'channels.push' => 'boolean',
            'categories' => 'array',
            'categories.orders' => 'boolean',
            'categories.sales' => 'boolean',
            'categories.stock' => 'boolean',
            'categories.planning' => 'boolean',
            'categories.billing' => 'boolean',
            'categories.crm' => 'boolean',
            'categories.support' => 'boolean',
            'categories.security' => 'boolean',
            'categories.emails_mirror' => 'boolean',
            'categories.system' => 'boolean',
        ]);

        $payload = [
            'channels' => Arr::only($validated['channels'] ?? [], ['in_app', 'push']),
            'categories' => Arr::only($validated['categories'] ?? [], [
                'orders',
                'sales',
                'stock',
                'planning',
                'billing',
                'crm',
                'support',
                'security',
                'emails_mirror',
                'system',
            ]),
        ];

        $settings = app(NotificationPreferenceService::class)->applyUpdate($user, $payload);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Preferences mises a jour.',
                'settings' => $settings,
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Preferences mises a jour.');
    }
}
