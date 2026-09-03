<?php

namespace App\Services\Rbac;

use App\Models\Permission;
use App\Models\User;
use App\Services\CompanyFeatureService;

final readonly class CompanyPermissionAvailability
{
    public function __construct(
        private PermissionCatalog $permissionCatalog,
        private CompanyFeatureService $companyFeatureService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function availableSlugs(User $accountOwner): array
    {
        $effectiveFeatures = $this->companyFeatureService->resolveEffectiveFeatures($accountOwner);
        $availableCatalogSlugs = collect($this->permissionCatalog->permissions())
            ->filter(fn (array $permission): bool => $this->isDefinitionAvailable(
                $permission['group'],
                $permission['slug'],
                $accountOwner,
                $effectiveFeatures,
            ))
            ->pluck('slug')
            ->all();

        return Permission::query()
            ->whereIn('slug', $availableCatalogSlugs)
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /**
     * @param  array<string, bool>  $effectiveFeatures
     */
    private function isDefinitionAvailable(
        string $group,
        string $slug,
        User $accountOwner,
        array $effectiveFeatures,
    ): bool {
        if (in_array($group, ['clients', 'settings'], true)) {
            return true;
        }

        $feature = match ($group) {
            'reservations', 'chairs' => 'reservations',
            'services' => 'services',
            'products' => 'products',
            'sales' => 'sales',
            'team' => 'team_members',
            'presence' => 'presence',
            'reports' => 'performance',
            'campaigns' => 'campaigns',
            'jobs' => 'jobs',
            'tasks' => 'tasks',
            'quotes' => 'quotes',
            'prospects' => 'requests',
            'social' => 'social',
            'finance' => $this->financeFeature($slug),
            'storefront' => $this->storefrontFeature($slug, $accountOwner),
            default => null,
        };

        return $feature !== null && (bool) ($effectiveFeatures[$feature] ?? false);
    }

    private function financeFeature(string $permissionSlug): ?string
    {
        if (str_contains($permissionSlug, 'invoice')) {
            return 'invoices';
        }

        if (str_contains($permissionSlug, 'expense')) {
            return 'expenses';
        }

        if (str_contains($permissionSlug, 'accounting') || $permissionSlug === 'view_financial_reports') {
            return 'accounting';
        }

        return null;
    }

    private function storefrontFeature(string $permissionSlug, User $accountOwner): string
    {
        if ($permissionSlug === 'manage_public_services') {
            return 'services';
        }

        if ($permissionSlug === 'manage_public_products') {
            return 'products';
        }

        return $accountOwner->company_type === 'products' ? 'products' : 'services';
    }
}
