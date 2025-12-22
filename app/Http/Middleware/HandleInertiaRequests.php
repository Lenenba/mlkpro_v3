<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\PlatformSetting;
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

        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = null;
        if ($impersonatorId) {
            $impersonator = User::query()->select(['id', 'name', 'email'])->find($impersonatorId);
        }

        $platformAdmin = null;
        if ($user && $user->isPlatformAdmin()) {
            $platformAdmin = $user->relationLoaded('platformAdmin')
                ? $user->platformAdmin
                : $user->platformAdmin()->first();
        }

        $maintenance = PlatformSetting::getValue('maintenance', [
            'enabled' => false,
            'message' => '',
        ]);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'account' => $user ? [
                    'owner_id' => $ownerId,
                    'is_owner' => $user->isAccountOwner(),
                    'is_client' => $user->isClient(),
                    'is_superadmin' => $user->isSuperadmin(),
                    'is_platform_admin' => $user->isPlatformAdmin(),
                    'company' => $accountOwner ? [
                        'name' => $accountOwner->company_name,
                        'type' => $accountOwner->company_type,
                        'onboarded' => (bool) $accountOwner->onboarding_completed_at,
                    ] : null,
                    'platform' => $platformAdmin ? [
                        'role' => $platformAdmin->role,
                        'permissions' => $platformAdmin->permissions ?? [],
                        'is_active' => (bool) $platformAdmin->is_active,
                    ] : null,
                ] : null,
                'impersonator' => $impersonator,
            ],
            'platform' => [
                'maintenance' => $maintenance,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
