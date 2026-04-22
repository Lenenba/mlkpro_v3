<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Str;

class CompanyFeatureService
{
    private const DEFAULT_PLAN_MODULE_KEYS = [
        'quotes',
        'requests',
        'reservations',
        'plan_scans',
        'invoices',
        'jobs',
        'products',
        'performance',
        'presence',
        'planning',
        'sales',
        'promotions',
        'expenses',
        'accounting',
        'services',
        'tasks',
        'team_members',
        'assistant',
        'campaigns',
        'loyalty',
    ];

    private const MODULE_DEPENDENCIES = [
        'accounting' => ['expenses'],
        'promotions' => ['sales'],
    ];

    private const SALON_ONLY_DISABLED_MODULES = [
        'requests',
        'products',
        'quotes',
        'plan_scans',
        'jobs',
        'tasks',
    ];

    private const OWNER_ONLY_FORCED_DISABLED_MODULES = [
        'performance',
        'presence',
        'team_members',
    ];

    private const OWNER_ONLY_ALLOWED_OVERRIDE_MODULES = [
        'assistant',
        'sales',
        'promotions',
    ];

    private array $featureCache = [];

    public function resolveEffectiveFeatures(User $user): array
    {
        $owner = $this->resolveOwner($user);
        if (! $owner) {
            return [];
        }

        if (array_key_exists($owner->id, $this->featureCache)) {
            return $this->featureCache[$owner->id];
        }

        $planModules = $this->resolvePlanModules();
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

        if ($planKey && app(BillingPlanService::class)->isOwnerOnlyPlan($planKey)) {
            $allowedOverrides = array_flip(self::OWNER_ONLY_ALLOWED_OVERRIDE_MODULES);

            foreach (($planModules[$planKey] ?? []) as $moduleKey => $enabledByPlan) {
                if ($enabledByPlan || array_key_exists($moduleKey, $allowedOverrides)) {
                    continue;
                }

                $features[$moduleKey] = false;
            }

            foreach (self::OWNER_ONLY_FORCED_DISABLED_MODULES as $moduleKey) {
                $features[$moduleKey] = false;
            }
        }

        $features = $this->applyModuleDependencies($features);

        $this->featureCache[$owner->id] = $features;

        return $features;
    }

    public function hasFeature(User $user, string $feature): bool
    {
        $features = $this->resolveEffectiveFeatures($user);
        if (! array_key_exists($feature, $features)) {
            return false;
        }

        return (bool) $features[$feature];
    }

    public function resolveEnabledFeatures(User $user): array
    {
        $features = $this->resolveEffectiveFeatures($user);

        return array_filter($features, static fn ($enabled): bool => (bool) $enabled);
    }

    public function resolveFeatureValue(User $user, string $feature, callable $resolver, mixed $fallback = null): mixed
    {
        if (! $this->hasFeature($user, $feature)) {
            return $fallback;
        }

        return $resolver();
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
        if (! $ownerId) {
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

    /**
     * @return array<string, array<string, bool>>
     */
    public function resolvePlanModules(): array
    {
        $defaults = self::defaultPlanModules();
        $configuredModules = PlatformSetting::getValue('plan_modules', []);
        if (! is_array($configuredModules) || $configuredModules === []) {
            return $defaults;
        }

        $billingPlanService = app(BillingPlanService::class);
        $resolved = $defaults;

        foreach ($configuredModules as $planKey => $modules) {
            if (! is_array($modules)) {
                continue;
            }

            $isOwnerOnlyPlan = $billingPlanService->isOwnerOnlyPlan((string) $planKey);
            $defaultPlanModules = $defaults[$planKey] ?? [];

            if (! $isOwnerOnlyPlan) {
                $resolved[$planKey] = collect($modules)
                    ->map(fn ($enabled): bool => (bool) $enabled)
                    ->all();

                continue;
            }

            $resolved[$planKey] = $defaultPlanModules;

            foreach ($modules as $moduleKey => $enabled) {
                if (array_key_exists($moduleKey, $defaultPlanModules)) {
                    $resolved[$planKey][$moduleKey] = (bool) ($defaultPlanModules[$moduleKey] && $enabled);

                    continue;
                }

                $resolved[$planKey][$moduleKey] = (bool) $enabled;
            }
        }

        foreach ($resolved as $planKey => $modules) {
            if (! $billingPlanService->isOwnerOnlyPlan((string) $planKey)) {
                $resolved[$planKey] = $this->applyModuleDependencies($modules);

                continue;
            }

            foreach (self::OWNER_ONLY_FORCED_DISABLED_MODULES as $moduleKey) {
                $resolved[$planKey][$moduleKey] = false;
            }

            $resolved[$planKey] = $this->applyModuleDependencies($resolved[$planKey]);
        }

        return $resolved;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function defaultPlanModules(): array
    {
        $plans = config('billing.plans', []);
        $planKeys = array_keys($plans);
        $defaultAssistantPlan = array_key_exists('scale', $plans)
            ? 'scale'
            : array_key_last($plans);
        $planModules = [];

        foreach ($planKeys as $planKey) {
            $configuredDefaults = config('billing.plans.'.$planKey.'.default_modules', []);
            foreach (self::DEFAULT_PLAN_MODULE_KEYS as $moduleKey) {
                if (array_key_exists($moduleKey, $configuredDefaults)) {
                    $planModules[$planKey][$moduleKey] = (bool) $configuredDefaults[$moduleKey];

                    continue;
                }

                if ($moduleKey === 'assistant') {
                    $planModules[$planKey][$moduleKey] = $planKey === $defaultAssistantPlan;

                    continue;
                }

                $planModules[$planKey][$moduleKey] = true;
            }
        }

        if ($planModules === []) {
            $planModules['free'] = array_fill_keys(self::DEFAULT_PLAN_MODULE_KEYS, true);
        }

        return $planModules;
    }

    /**
     * @param  array<string, bool>  $modules
     * @return array<string, bool>
     */
    private function applyModuleDependencies(array $modules): array
    {
        foreach (self::MODULE_DEPENDENCIES as $moduleKey => $dependencies) {
            if (! ($modules[$moduleKey] ?? false)) {
                continue;
            }

            foreach ($dependencies as $dependencyKey) {
                if ($modules[$dependencyKey] ?? false) {
                    continue;
                }

                $modules[$moduleKey] = false;

                break;
            }
        }

        return $modules;
    }
}
