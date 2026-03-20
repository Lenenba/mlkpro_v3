<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

class PublicLeadFormUrlService
{
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
        if ($preferredUserId) {
            $preferred = User::query()->find($preferredUserId);
            if ($this->supportsLeadForm($preferred)) {
                return $preferred;
            }
        }

        foreach (User::query()->orderBy('id')->cursor() as $candidate) {
            if ($this->supportsLeadForm($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function supportsLeadForm(?User $user): bool
    {
        if (! $user || $user->isSuspended()) {
            return false;
        }

        return app(CompanyFeatureService::class)->hasFeature($user, 'requests');
    }
}
