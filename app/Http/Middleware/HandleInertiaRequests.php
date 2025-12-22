<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $ownerId = $user?->accountOwnerId();

        $accountOwner = null;
        if ($user && $ownerId) {
            $accountOwner = $ownerId === $user->id
                ? $user
                : User::query()
                    ->select(['id', 'company_name', 'company_type', 'onboarding_completed_at'])
                    ->find($ownerId);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'account' => $user ? [
                    'owner_id' => $ownerId,
                    'is_owner' => $user->isAccountOwner(),
                    'is_client' => $user->isClient(),
                    'company' => $accountOwner ? [
                        'name' => $accountOwner->company_name,
                        'type' => $accountOwner->company_type,
                        'onboarded' => (bool) $accountOwner->onboarding_completed_at,
                    ] : null,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
