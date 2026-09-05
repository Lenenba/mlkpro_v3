<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class PublicLeadFormUrlService
{
    public function __construct(
        private readonly TenantBrandingResolver $tenantBrandingResolver,
    ) {}

    public function resolve(?int $preferredUserId = null, array $parameters = []): ?string
    {
        $user = $this->resolveEligibleUser($preferredUserId);
        if (! $user) {
            return null;
        }

        return URL::signedRoute('public.requests.form', array_merge(['user' => $user->id], $parameters));
    }

    private function resolveEligibleUser(?int $preferredUserId = null): ?User
    {
        if (! $preferredUserId) {
            return null;
        }

        $preferred = User::query()->find($preferredUserId);
        $preferredOwner = $this->resolveAccountOwner($preferred);

        return $this->supportsLeadForm($preferredOwner)
            ? $preferredOwner
            : null;
    }

    private function supportsLeadForm(?User $user): bool
    {
        if (! $user || $user->isSuspended() || $user->isSuperadmin() || $user->isPlatformAdmin()) {
            return false;
        }

        return app(CompanyFeatureService::class)->hasFeature($user, 'requests');
    }

    private function resolveAccountOwner(?User $user): ?User
    {
        return $user
            ? $this->tenantBrandingResolver->resolveAccountOwner($user)
            : null;
    }
}
