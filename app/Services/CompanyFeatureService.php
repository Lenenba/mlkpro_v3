<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\BillingSubscriptionService;

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
            $owner = $this->resolveOwner($user);
            if (!$owner) {
                return false;
            }

            $planModules = PlatformSetting::getValue('plan_modules', []);
            $planKey = $this->resolvePlanKey($owner, $planModules);
            if (!$planKey) {
                return false;
            }

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
        return app(BillingSubscriptionService::class)->resolvePlanKey($accountOwner, $planModules);
    }
}
