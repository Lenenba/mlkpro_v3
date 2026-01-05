<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Laravel\Paddle\Subscription;

class CompanyFeatureService
{
    private array $featureCache = [];

    public function resolveEffectiveFeatures(User $user): array
    {
        $owner = $this->resolveOwner($user);
        if (!$owner) {
            return [];
        }

        if (array_key_exists($owner->id, $this->featureCache)) {
            return $this->featureCache[$owner->id];
        }

        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $this->resolvePlanKey($owner, $planModules);
        $defaults = $planKey ? ($planModules[$planKey] ?? []) : [];
        $overrides = $owner->company_features ?? [];

        $keys = array_unique(array_merge(array_keys($defaults), array_keys($overrides)));
        $features = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $overrides)) {
                $features[$key] = (bool) $overrides[$key];
                continue;
            }
            if (array_key_exists($key, $defaults)) {
                $features[$key] = (bool) $defaults[$key];
            }
        }

        $this->featureCache[$owner->id] = $features;

        return $features;
    }

    public function hasFeature(User $user, string $feature): bool
    {
        $features = $this->resolveEffectiveFeatures($user);
        if (!array_key_exists($feature, $features)) {
            return true;
        }

        return (bool) $features[$feature];
    }

    private function resolveOwner(User $user): ?User
    {
        $ownerId = $user->accountOwnerId();
        if (!$ownerId) {
            return null;
        }

        return $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);
    }

    private function resolvePlanKey(User $accountOwner, array $planModules): ?string
    {
        $subscription = $accountOwner->subscription(Subscription::DEFAULT_TYPE);
        $priceId = $subscription?->items()->value('price_id');

        if ($priceId) {
            foreach (config('billing.plans', []) as $key => $plan) {
                if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                    return $key;
                }
            }
        }

        if (array_key_exists('free', $planModules)) {
            return 'free';
        }

        $plans = config('billing.plans', []);
        if (array_key_exists('free', $plans)) {
            return 'free';
        }

        return null;
    }
}
