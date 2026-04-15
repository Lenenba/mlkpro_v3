<?php

namespace App\Http\Middleware;

use App\Models\DemoWorkspace;
use App\Models\PlatformSetting;
use App\Models\TeamMemberShift;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Support\Database\UserSelects;
use App\Support\LocalePreference;
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
        $siteUrl = rtrim((string) (config('app.url') ?: $request->getSchemeAndHttpHost()), '/');
        $featureService = app(CompanyFeatureService::class);

        $accountOwner = null;
        $accountFeatures = null;
        if ($user && $user->isClient()) {
            $customer = $user->relationLoaded('customerProfile')
                ? $user->customerProfile
                : $user->customerProfile()->first();
            if ($customer) {
                $accountOwner = User::query()
                    ->select(UserSelects::companyFeatureContext())
                    ->find($customer->user_id);
            }
        }
        if (! $accountOwner && $user && $ownerId) {
            $accountOwner = $ownerId === $user->id
                ? $user
                : User::query()
                    ->select(UserSelects::companyFeatureContext())
                    ->find($ownerId);
        }
        if ($user && $accountOwner) {
            $accountFeatures = $featureService->resolveEnabledFeatures($accountOwner);
        }

        $impersonatorId = $request->session()->get('impersonator_id');
        $impersonator = null;
        if ($impersonatorId) {
            $impersonator = User::query()->select(UserSelects::identity())->find($impersonatorId);
        }

        $teamMembership = null;
        if ($user && ! $user->isAccountOwner()) {
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
                    ->map(fn ($notification) => [
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

        $planning = null;
        if ($user && $accountOwner) {
            $planning = $featureService->resolveFeatureValue(
                $accountOwner,
                'planning',
                function () use ($accountFeatures, $accountOwner, $teamMembership, $user): array {
                    $planningPendingCount = 0;

                    if (! ($accountFeatures['team_members'] ?? false)) {
                        return [
                            'pending_count' => $planningPendingCount,
                        ];
                    }

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

                    return [
                        'pending_count' => $planningPendingCount,
                    ];
                }
            );
        }

        $assistant = null;
        if ($user && $accountOwner && (bool) config('services.openai.key')) {
            $assistant = $featureService->resolveFeatureValue(
                $accountOwner,
                'assistant',
                static fn (): array => ['enabled' => true]
            );
        }

        $demoWorkspace = null;
        if ($user && ($user->is_demo || $user->is_demo_user)) {
            $demoWorkspace = DemoWorkspace::query()
                ->select(['id', 'owner_user_id', 'company_name', 'prospect_name', 'expires_at'])
                ->where('owner_user_id', $user->accountOwnerId())
                ->first();
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
                    'currency_code' => $accountOwner?->businessCurrencyCode(),
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
            'planning' => $planning,
            'locale' => app()->getLocale(),
            'locales' => LocalePreference::supported(),
            'branding' => [
                'site_name' => config('app.name', 'Malikia Pro'),
                'site_url' => $siteUrl,
                'logo_icon_url' => url('brand/bimi-logo.svg'),
                'favicon_url' => url('favicon.ico'),
                'social_image_url' => url('brand/social-card.png'),
                'apple_touch_icon_url' => url('apple-touch-icon.png'),
            ],
            'demo' => [
                'enabled' => (bool) config('demo.enabled'),
                'allow_reset' => (bool) config('demo.allow_reset'),
                'is_demo' => (bool) ($user?->is_demo ?? false),
                'is_demo_user' => (bool) ($user?->is_demo_user ?? false),
                'demo_type' => $user?->demo_type,
                'demo_role' => $user?->demo_role,
                'is_guided' => (bool) ($user?->demo_type === 'guided' || $user?->demo_role === 'guided_demo'),
                'workspace_id' => $demoWorkspace?->id,
                'workspace_name' => $demoWorkspace?->company_name,
                'workspace_prospect_name' => $demoWorkspace?->prospect_name,
                'expires_at' => $demoWorkspace?->expires_at?->toIso8601String(),
            ],
            'assistant' => $assistant,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'last_sale_id' => fn () => $request->session()->get('last_sale_id'),
            ],
        ];
    }
}
