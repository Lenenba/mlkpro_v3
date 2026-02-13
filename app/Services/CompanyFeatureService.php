<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\BillingSubscriptionService;
use Illuminate\Support\Str;

class CompanyFeatureService
{
    private const SALON_ONLY_DISABLED_MODULES = [
        'requests',
        'products',
        'quotes',
        'plan_scans',
        'jobs',
        'tasks',
    ];

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
        $planDefaults = $planKey ? ($planModules[$planKey] ?? []) : [];
        $sectorDefaults = self::sectorFeatureDefaults((string) ($owner->company_sector ?? null));
        $defaults = array_replace($planDefaults, $sectorDefaults);
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

    /**
     * @return array<string, bool>
     */
    public static function sectorFeatureDefaults(?string $sector): array
    {
        $normalizedSector = Str::of((string) $sector)
            ->lower()
            ->trim()
            ->replace(' ', '_')
            ->toString();

        $salonLike = in_array($normalizedSector, ['salon', 'restaurant'], true);
        $defaults = [
            // Reservations are available by default only for salon/restaurant.
            'reservations' => $salonLike,
        ];

        if ($salonLike) {
            foreach (self::SALON_ONLY_DISABLED_MODULES as $module) {
                $defaults[$module] = false;
            }
        }

        return $defaults;
    }

    private function resolveOwner(User $user): ?User
    {
        if ($user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();
            if ($customer && $customer->user_id) {
                return User::query()->find($customer->user_id);
            }
        }

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
