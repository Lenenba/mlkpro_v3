<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\TeamMemberShift;
use App\Models\PlatformSetting;
use App\Services\CompanyFeatureService;
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
        $accountFeatures = null;
        if ($user && $user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();
            if ($customer) {
                $accountOwner = User::query()
                    ->select(['id', 'company_name', 'company_type', 'company_logo', 'onboarding_completed_at'])
                    ->find($customer->user_id);
            }
        }
        if (!$accountOwner && $user && $ownerId) {
            $accountOwner = $ownerId === $user->id
                ? $user
                : User::query()
                    ->select(['id', 'company_name', 'company_type', 'company_logo', 'onboarding_completed_at'])
                    ->find($ownerId);
        }
        if ($user && $accountOwner) {
            $accountFeatures = app(CompanyFeatureService::class)->resolveEffectiveFeatures($accountOwner);
        }

        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = null;
        if ($impersonatorId) {
            $impersonator = User::query()->select(['id', 'name', 'email'])->find($impersonatorId);
        }

        $teamMembership = null;
        if ($user && !$user->isAccountOwner()) {
            $teamMembership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
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
        $notifications = null;
        if ($user) {
            $notifications = [
                'unread_count' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()
                    ->latest()
                    ->limit(6)
                    ->get()
                    ->map(fn($notification) => [
                        'id' => $notification->id,
                        'title' => $notification->data['title'] ?? 'Notification',
                        'message' => $notification->data['message'] ?? '',
                        'action_url' => $notification->data['action_url'] ?? null,
                        'created_at' => $notification->created_at?->toIso8601String(),
                        'read_at' => $notification->read_at?->toIso8601String(),
                    ])
                    ->values(),
            ];
        }

        $planningPendingCount = 0;
        if ($user && $accountOwner && ($accountFeatures['planning'] ?? false)) {
            $isServiceCompany = $accountOwner->company_type !== 'products';
            $canApproveTimeOff = $user->id === $accountOwner->id;
            if ($teamMembership) {
                $isRoleApprover = in_array($teamMembership->role, ['admin', 'sales_manager'], true);
                $hasManagePermission = $isServiceCompany
                    ? ($teamMembership->hasPermission('jobs.edit') || $teamMembership->hasPermission('tasks.edit'))
                    : $teamMembership->hasPermission('sales.manage');
                $canApproveTimeOff = $canApproveTimeOff || $isRoleApprover || $hasManagePermission;
            }
            if ($canApproveTimeOff) {
                $planningPendingCount = TeamMemberShift::query()
                    ->where('account_id', $accountOwner->id)
                    ->whereIn('kind', ['absence', 'leave'])
                    ->where('status', 'pending')
                    ->count();
            }
        }

        $assistantEnabled = (bool) config('services.openai.key');
        if (!$user) {
            $assistantEnabled = false;
        } elseif ($accountFeatures !== null) {
            $assistantEnabled = $assistantEnabled && (bool) ($accountFeatures['assistant'] ?? true);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'account' => $user ? [
                    'owner_id' => $accountOwner?->id ?? $ownerId,
                    'is_owner' => $user->isAccountOwner(),
                    'is_client' => $user->isClient(),
                    'is_superadmin' => $user->isSuperadmin(),
                    'is_platform_admin' => $user->isPlatformAdmin(),
                    'company' => $accountOwner ? [
                        'name' => $accountOwner->company_name,
                        'type' => $accountOwner->company_type,
                        'onboarded' => (bool) $accountOwner->onboarding_completed_at,
                        'logo_url' => $accountOwner->company_logo_url,
                    ] : null,
                    'features' => $accountFeatures,
                    'platform' => $platformAdmin ? [
                        'role' => $platformAdmin->role,
                        'permissions' => $platformAdmin->permissions ?? [],
                        'is_active' => (bool) $platformAdmin->is_active,
                    ] : null,
                    'team' => $teamMembership ? [
                        'role' => $teamMembership->role,
                        'permissions' => $teamMembership->permissions ?? [],
                    ] : null,
                ] : null,
                'impersonator' => $impersonator,
            ],
            'platform' => [
                'maintenance' => $maintenance,
            ],
            'notifications' => $notifications,
            'planning' => [
                'pending_count' => $planningPendingCount,
            ],
            'locale' => app()->getLocale(),
            'locales' => ['fr', 'en'],
            'demo' => [
                'enabled' => (bool) config('demo.enabled'),
                'allow_reset' => (bool) config('demo.allow_reset'),
                'is_demo' => (bool) ($user?->is_demo ?? false),
                'is_demo_user' => (bool) ($user?->is_demo_user ?? false),
                'demo_type' => $user?->demo_type,
                'demo_role' => $user?->demo_role,
                'is_guided' => (bool) ($user?->demo_type === 'guided' || $user?->demo_role === 'guided_demo'),
            ],
            'assistant' => [
                'enabled' => $assistantEnabled,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'last_sale_id' => fn () => $request->session()->get('last_sale_id'),
            ],
        ];
    }
}
