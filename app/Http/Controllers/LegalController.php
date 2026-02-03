<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Support\PlanDisplay;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Paddle\Cashier;

class LegalController extends Controller
{
    public function terms(): Response
    {
        return Inertia::render('Terms', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('Privacy', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
        ]);
    }

    public function refund(): Response
    {
        return Inertia::render('Refund', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
        ]);
    }

    public function pricing(): Response
    {
        $rawPlans = config('billing.plans', []);
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        $preferredOrder = array_values(array_filter(['starter', 'growth', 'enterprise'], fn ($key) => isset($rawPlans[$key])));
        $order = $preferredOrder ?: array_slice(array_keys($rawPlans), 0, 3);

        $plans = collect($order)
            ->map(function (string $key) use ($rawPlans, $planDisplayOverrides) {
                $plan = $rawPlans[$key] ?? [];
                $display = PlanDisplay::merge($plan, $key, $planDisplayOverrides);

                return [
                    'key' => $key,
                    'name' => $display['name'],
                    'price' => $display['price'],
                    'display_price' => $this->resolvePlanDisplayPrice($display['price']),
                    'features' => $display['features'],
                    'badge' => $display['badge'],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Pricing', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('onboarding.index'),
            'pricingPlans' => $plans,
            'highlightedPlanKey' => $order[1] ?? ($order[0] ?? null),
        ]);
    }

    private function resolvePlanDisplayPrice($raw): ?string
    {
        $rawValue = is_string($raw) ? trim($raw) : $raw;

        if (is_numeric($rawValue)) {
            return Cashier::formatAmount((int) round((float) $rawValue * 100), config('cashier.currency', 'USD'));
        }

        if (is_string($rawValue) && $rawValue !== '') {
            return $rawValue;
        }

        return null;
    }
}
