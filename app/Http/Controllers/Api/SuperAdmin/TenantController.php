<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\PlatformSetting;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Support\PlatformPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TenantController extends BaseController
{
    private array $limitKeys = [
        'quotes',
        'requests',
        'plan_scan_quotes',
        'invoices',
        'jobs',
        'products',
        'services',
        'tasks',
        'team_members',
        'assistant_requests',
    ];

    private array $moduleKeys = [
        'quotes',
        'requests',
        'plan_scans',
        'invoices',
        'jobs',
        'products',
        'services',
        'tasks',
        'team_members',
        'assistant',
    ];

    public function index(Request $request)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_VIEW);

        $filters = $request->only(['search', 'status', 'plan']);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $query = User::query()->where('role_id', $ownerRoleId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('company_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'suspended') {
                $query->where('is_suspended', true);
            } elseif ($filters['status'] === 'active') {
                $query->where('is_suspended', false);
            }
        }

        if (!empty($filters['plan'])) {
            $this->applyPlanFilter($query, (string) $filters['plan']);
        }

        $tenants = $query->orderByDesc('created_at')->paginate(15);
        $subscriptionMap = $this->subscriptionMap($tenants->pluck('id'));

        $tenants->through(function (User $tenant) use ($subscriptionMap) {
            $subscription = $subscriptionMap[$tenant->id] ?? null;
            $planKey = $this->resolvePlanKey($subscription?->price_id);
            $planName = $planKey ? (config('billing.plans.' . $planKey . '.name') ?? $planKey) : null;

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'company_name' => $tenant->company_name,
                'company_type' => $tenant->company_type,
                'created_at' => $tenant->created_at,
                'is_suspended' => (bool) $tenant->is_suspended,
                'onboarding_completed' => (bool) $tenant->onboarding_completed_at,
                'subscription' => ($subscription || $planKey) ? [
                    'status' => $subscription?->status ?? 'free',
                    'price_id' => $subscription?->price_id,
                    'plan_name' => $planName,
                ] : null,
            ];
        });

        return $this->jsonResponse([
            'filters' => $filters,
            'tenants' => $tenants,
            'plans' => $this->planOptions(),
        ]);
    }

    public function show(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_VIEW);
        $this->ensureOwner($tenant);

        $subscription = $this->subscriptionMap(collect([$tenant->id]))[$tenant->id] ?? null;
        $planKey = $this->resolvePlanKey($subscription?->price_id);
        $planName = $planKey ? (config('billing.plans.' . $planKey . '.name') ?? $planKey) : null;
        $stats = $this->buildStats($tenant);
        $featureFlags = $this->buildFeatureFlags($tenant, $subscription);
        $usageLimits = $this->buildUsageLimits($tenant, $subscription, $stats);

        return $this->jsonResponse([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'company_name' => $tenant->company_name,
                'company_type' => $tenant->company_type,
                'created_at' => $tenant->created_at,
                'is_suspended' => (bool) $tenant->is_suspended,
                'onboarding_completed' => (bool) $tenant->onboarding_completed_at,
                'subscription' => ($subscription || $planKey) ? [
                    'status' => $subscription?->status ?? 'free',
                    'price_id' => $subscription?->price_id,
                    'plan_name' => $planName,
                    'trial_ends_at' => $subscription?->trial_ends_at ?? null,
                    'ends_at' => $subscription?->ends_at ?? null,
                ] : null,
                'plan_key' => $planKey,
            ],
            'stats' => $stats,
            'feature_flags' => $featureFlags,
            'usage_limits' => $usageLimits,
        ]);
    }

    public function suspend(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $tenant->update([
            'is_suspended' => true,
            'suspended_at' => now(),
        ]);

        $this->logAudit($request, 'tenant.suspended', ['tenant_id' => $tenant->id]);

        return $this->jsonResponse(['message' => 'Tenant suspended.']);
    }

    public function restore(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $tenant->update([
            'is_suspended' => false,
            'suspended_at' => null,
        ]);

        $this->logAudit($request, 'tenant.restored', ['tenant_id' => $tenant->id]);

        return $this->jsonResponse(['message' => 'Tenant restored.']);
    }

    public function updateFeatures(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $validated = $request->validate([
            'features' => 'required|array',
            'features.*' => 'boolean',
        ]);

        $tenant->update([
            'company_features' => $validated['features'],
        ]);

        $this->logAudit($request, 'tenant.features_updated', ['tenant_id' => $tenant->id, 'features' => $validated['features']]);

        return $this->jsonResponse(['message' => 'Feature flags updated.']);
    }

    public function updateLimits(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $validated = $request->validate([
            'limits' => 'required|array',
            'limits.*' => 'nullable|numeric|min:0',
        ]);

        $limits = [];
        foreach ($this->limitKeys as $key) {
            $value = $validated['limits'][$key] ?? null;
            $limits[$key] = is_numeric($value) ? max(0, (int) $value) : null;
        }

        $tenant->update([
            'company_limits' => $limits,
        ]);

        $this->logAudit($request, 'tenant.limits_updated', ['tenant_id' => $tenant->id, 'limits' => $limits]);

        return $this->jsonResponse(['message' => 'Usage limits updated.']);
    }

    private function buildStats(User $tenant): array
    {
        return [
            'customers' => Customer::query()->where('user_id', $tenant->id)->count(),
            'quotes' => Quote::query()->where('user_id', $tenant->id)->count(),
            'requests' => LeadRequest::query()->where('user_id', $tenant->id)->count(),
            'plan_scans' => PlanScan::query()->where('user_id', $tenant->id)->count(),
            'plan_scan_quotes' => (int) PlanScan::query()->where('user_id', $tenant->id)->sum('quotes_generated'),
            'invoices' => Invoice::query()->where('user_id', $tenant->id)->count(),
            'work' => Work::query()->where('user_id', $tenant->id)->count(),
            'products' => Product::query()->where('user_id', $tenant->id)->count(),
            'services' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_SERVICE)->count(),
            'tasks' => Task::query()->where('account_id', $tenant->id)->count(),
            'team_members' => TeamMember::query()->where('account_id', $tenant->id)->count(),
            'assistant_requests' => (int) \App\Models\AssistantUsage::query()
                ->where('user_id', $tenant->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('request_count'),
        ];
    }

    private function buildFeatureFlags(User $tenant, ?object $subscription): array
    {
        $defaults = [
            'quotes' => 'Quotes',
            'requests' => 'Requests',
            'plan_scans' => 'Plan scans',
            'invoices' => 'Invoices',
            'jobs' => 'Jobs',
            'products' => 'Products',
            'services' => 'Services',
            'tasks' => 'Tasks',
            'team_members' => 'Team members',
            'assistant' => 'AI assistant',
        ];

        $current = $tenant->company_features ?? [];
        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $this->resolvePlanKey($subscription?->price_id);
        $planDefaults = $planKey ? ($planModules[$planKey] ?? []) : [];

        return collect($defaults)->map(function ($label, $key) use ($current, $planDefaults) {
            $enabled = true;
            if (array_key_exists($key, $current)) {
                $enabled = (bool) $current[$key];
            } elseif (array_key_exists($key, $planDefaults)) {
                $enabled = (bool) $planDefaults[$key];
            }

            return [
                'key' => $key,
                'label' => $label,
                'enabled' => $enabled,
            ];
        })->values()->all();
    }

    private function buildUsageLimits(User $tenant, ?object $subscription, array $stats): array
    {
        $limitLabels = [
            'quotes' => 'Quotes',
            'requests' => 'Requests',
            'plan_scan_quotes' => 'Plan scan quotes',
            'invoices' => 'Invoices',
            'jobs' => 'Jobs',
            'products' => 'Products',
            'services' => 'Services',
            'tasks' => 'Tasks',
            'team_members' => 'Team members',
            'assistant_requests' => 'AI assistant requests',
        ];

        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planModules = PlatformSetting::getValue('plan_modules', []);
        $planKey = $this->resolvePlanKey($subscription?->price_id);
        $planDefaults = $planKey ? ($planLimits[$planKey] ?? []) : [];
        $overrides = $tenant->company_limits ?? [];

        $items = [];
        foreach ($limitLabels as $key => $label) {
            $used = (int) ($stats[$key] ?? 0);
            $override = $overrides[$key] ?? null;
            $defaultLimit = $planDefaults[$key] ?? null;
            $limit = is_numeric($override) ? (int) $override : (is_numeric($defaultLimit) ? (int) $defaultLimit : null);
            $percent = null;
            $status = 'ok';
            if ($limit && $limit > 0) {
                $percent = round(($used / $limit) * 100, 1);
                if ($used > $limit) {
                    $status = 'over';
                } elseif ($percent >= 90) {
                    $status = 'warning';
                }
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'status' => $status,
                'source' => is_numeric($override) ? 'override' : 'plan',
                'override' => is_numeric($override) ? (int) $override : null,
                'plan_limit' => is_numeric($defaultLimit) ? (int) $defaultLimit : null,
            ];
        }

        return [
            'plan_key' => $planKey,
            'plan_limits' => $planDefaults,
            'plan_modules' => $planModules[$planKey] ?? [],
            'overrides' => $overrides,
            'items' => $items,
        ];
    }

    private function subscriptionMap(Collection $tenantIds): array
    {
        if ($tenantIds->isEmpty()) {
            return [];
        }

        $subscriptions = DB::table('paddle_subscriptions')
            ->where('billable_type', User::class)
            ->whereIn('billable_id', $tenantIds->all())
            ->orderByDesc('created_at')
            ->get(['id', 'billable_id', 'status', 'trial_ends_at', 'ends_at'])
            ->groupBy('billable_id')
            ->map(fn ($items) => $items->first());

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $prices = DB::table('paddle_subscription_items')
            ->whereIn('subscription_id', $subscriptions->pluck('id')->all())
            ->orderBy('id')
            ->get(['subscription_id', 'price_id'])
            ->groupBy('subscription_id')
            ->map(fn ($items) => $items->first()->price_id)
            ->all();

        $subscriptions->each(function ($subscription) use ($prices) {
            $subscription->price_id = $prices[$subscription->id] ?? null;
        });

        return $subscriptions->all();
    }

    private function resolvePlanKey(?string $priceId): ?string
    {
        foreach (config('billing.plans', []) as $key => $plan) {
            if ($priceId && !empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                return $key;
            }
        }

        $planModules = PlatformSetting::getValue('plan_modules', []);
        if (array_key_exists('free', $planModules)) {
            return 'free';
        }

        $planLimits = PlatformSetting::getValue('plan_limits', []);
        if (array_key_exists('free', $planLimits)) {
            return 'free';
        }

        $plans = config('billing.plans', []);
        if (array_key_exists('free', $plans)) {
            return 'free';
        }

        return null;
    }

    private function planOptions(): array
    {
        return collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? $key,
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function applyPlanFilter($query, string $planFilter): void
    {
        $planFilter = trim($planFilter);
        if ($planFilter === '') {
            return;
        }

        $plans = config('billing.plans', []);
        $planKey = array_key_exists($planFilter, $plans) ? $planFilter : null;
        $priceId = $planKey ? ($plans[$planKey]['price_id'] ?? null) : null;

        if (!$planKey) {
            foreach ($plans as $key => $plan) {
                if (!empty($plan['price_id']) && $plan['price_id'] === $planFilter) {
                    $planKey = $key;
                    $priceId = $planFilter;
                    break;
                }
            }
        }

        if (!$priceId && !$planKey) {
            $priceId = $planFilter;
        }

        if ($planKey === 'free') {
            $query->where(function ($builder) use ($priceId) {
                $builder->whereNotIn('id', DB::table('paddle_subscriptions')
                    ->select('billable_id')
                    ->where('billable_type', User::class));

                if ($priceId) {
                    $builder->orWhereIn('id', DB::table('paddle_subscriptions')
                        ->join(
                            'paddle_subscription_items',
                            'paddle_subscription_items.subscription_id',
                            '=',
                            'paddle_subscriptions.id'
                        )
                        ->select('paddle_subscriptions.billable_id')
                        ->where('paddle_subscriptions.billable_type', User::class)
                        ->where('paddle_subscription_items.price_id', $priceId)
                        ->distinct());
                }
            });

            return;
        }

        if (!$priceId) {
            $query->whereRaw('0 = 1');
            return;
        }

        $query->whereIn('id', DB::table('paddle_subscriptions')
            ->join('paddle_subscription_items', 'paddle_subscription_items.subscription_id', '=', 'paddle_subscriptions.id')
            ->select('paddle_subscriptions.billable_id')
            ->where('paddle_subscriptions.billable_type', User::class)
            ->where('paddle_subscription_items.price_id', $priceId)
            ->distinct());
    }

    private function ensureOwner(User $tenant): void
    {
        if (!$tenant->isOwner()) {
            abort(404);
        }
    }
}
