<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use InvalidArgumentException;

class PlanEntitlementSyncService
{
    public function __construct(
        private readonly BillingSubscriptionService $billingSubscriptionService,
    ) {}

    public function sync(array $options = []): array
    {
        $selectedPlans = $this->normalizePlanKeys($options['plans'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $resetTenantOverrides = (bool) ($options['reset_tenant_overrides'] ?? false);

        $limitPayload = $this->buildLimitPayload($selectedPlans);
        $modulePayload = $this->buildModulePayload($selectedPlans);

        $syncAllPlans = $this->isFullPlanSync($selectedPlans);
        $currentLimits = $this->normalizeExistingPayload(PlatformSetting::getValue('plan_limits', []));
        $currentModules = $this->normalizeExistingPayload(PlatformSetting::getValue('plan_modules', []));

        $nextLimits = $syncAllPlans ? $limitPayload : array_replace($currentLimits, $limitPayload);
        $nextModules = $syncAllPlans ? $modulePayload : array_replace($currentModules, $modulePayload);

        if (! $dryRun) {
            PlatformSetting::setValue('plan_limits', $nextLimits);
            PlatformSetting::setValue('plan_modules', $nextModules);
        }

        $tenantOverrideSummary = [
            'matched' => 0,
            'updated' => 0,
            'feature_overrides_cleared' => 0,
            'limit_overrides_cleared' => 0,
        ];

        if ($resetTenantOverrides) {
            $tenantOverrideSummary = $this->resetTenantOverrides($selectedPlans, $dryRun);
        }

        return [
            'plans' => $selectedPlans,
            'dry_run' => $dryRun,
            'synced_all_plans' => $syncAllPlans,
            'plan_limits' => $nextLimits,
            'plan_modules' => $nextModules,
            'reset_tenant_overrides' => $resetTenantOverrides,
            'tenant_overrides' => $tenantOverrideSummary,
        ];
    }

    /**
     * @param  array<int, string>|null  $requestedPlans
     * @return array<int, string>
     */
    private function normalizePlanKeys(array|string|null $requestedPlans): array
    {
        $availablePlans = array_keys(config('billing.plans', []));
        if ($availablePlans === []) {
            return [];
        }

        $requested = collect(is_array($requestedPlans) ? $requestedPlans : [$requestedPlans])
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($requested === []) {
            return $availablePlans;
        }

        $unknownPlans = array_values(array_diff($requested, $availablePlans));
        if ($unknownPlans !== []) {
            throw new InvalidArgumentException(
                'Unknown plan key(s): '.implode(', ', $unknownPlans)
            );
        }

        return array_values(array_intersect($availablePlans, $requested));
    }

    /**
     * @param  array<int, string>  $selectedPlans
     * @return array<string, array<string, int|null>>
     */
    private function buildLimitPayload(array $selectedPlans): array
    {
        $payload = [];
        $limitKeys = array_keys(UsageLimitService::LIMIT_KEYS);

        foreach ($selectedPlans as $planKey) {
            $configuredLimits = config('billing.plans.'.$planKey.'.default_limits', []);

            foreach ($limitKeys as $limitKey) {
                $value = $configuredLimits[$limitKey] ?? null;
                $payload[$planKey][$limitKey] = is_numeric($value) ? max(0, (int) $value) : null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<int, string>  $selectedPlans
     * @return array<string, array<string, bool>>
     */
    private function buildModulePayload(array $selectedPlans): array
    {
        $defaults = CompanyFeatureService::defaultPlanModules();
        $payload = [];

        foreach ($selectedPlans as $planKey) {
            $payload[$planKey] = collect($defaults[$planKey] ?? [])
                ->map(fn ($enabled): bool => (bool) $enabled)
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeExistingPayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<int, string>  $selectedPlans
     */
    private function isFullPlanSync(array $selectedPlans): bool
    {
        return $selectedPlans === array_keys(config('billing.plans', []));
    }

    /**
     * @param  array<int, string>  $selectedPlans
     * @return array<string, int>
     */
    private function resetTenantOverrides(array $selectedPlans, bool $dryRun): array
    {
        $summary = [
            'matched' => 0,
            'updated' => 0,
            'feature_overrides_cleared' => 0,
            'limit_overrides_cleared' => 0,
        ];

        if ($selectedPlans === []) {
            return $summary;
        }

        $planConfig = $this->buildModulePayload($selectedPlans);

        User::query()
            ->with('role')
            ->whereDoesntHave('teamMembership')
            ->whereDoesntHave('customerProfile')
            ->whereHas('role', fn ($query) => $query->where('name', 'owner'))
            ->orderBy('id')
            ->chunkById(100, function ($owners) use (&$summary, $planConfig, $selectedPlans, $dryRun): void {
                foreach ($owners as $owner) {
                    $planKey = $this->billingSubscriptionService->resolvePlanKey($owner, $planConfig);

                    if (! is_string($planKey) || ! in_array($planKey, $selectedPlans, true)) {
                        continue;
                    }

                    $summary['matched'] += 1;

                    $hasFeatureOverrides = is_array($owner->company_features) && $owner->company_features !== [];
                    $hasLimitOverrides = is_array($owner->company_limits) && $owner->company_limits !== [];

                    if (! $hasFeatureOverrides && ! $hasLimitOverrides) {
                        continue;
                    }

                    if ($hasFeatureOverrides) {
                        $summary['feature_overrides_cleared'] += 1;
                    }

                    if ($hasLimitOverrides) {
                        $summary['limit_overrides_cleared'] += 1;
                    }

                    $summary['updated'] += 1;

                    if ($dryRun) {
                        continue;
                    }

                    $owner->forceFill([
                        'company_features' => null,
                        'company_limits' => null,
                    ])->save();
                }
            });

        return $summary;
    }
}
