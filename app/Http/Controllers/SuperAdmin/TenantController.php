<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PlanScan;
use App\Models\Product;
use App\Models\PlatformSetting;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_VIEW);

        $filters = $request->only([
            'search',
            'company_type',
            'status',
            'plan',
            'created_from',
            'created_to',
        ]);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $query = User::query()->where('role_id', $ownerRoleId);

        $query->when($filters['search'] ?? null, function ($builder, $search) {
            $builder->where(function ($sub) use ($search) {
                $sub->where('company_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('name', 'like', '%' . $search . '%');
            });
        });

        $query->when($filters['company_type'] ?? null, function ($builder, $companyType) {
            $builder->where('company_type', $companyType);
        });

        $query->when($filters['status'] ?? null, function ($builder, $status) {
            if ($status === 'suspended') {
                $builder->where('is_suspended', true);
            } elseif ($status === 'active') {
                $builder->where('is_suspended', false);
            }
        });

        $query->when($filters['created_from'] ?? null, function ($builder, $from) {
            $builder->whereDate('created_at', '>=', $from);
        });

        $query->when($filters['created_to'] ?? null, function ($builder, $to) {
            $builder->whereDate('created_at', '<=', $to);
        });

        $query->when($filters['plan'] ?? null, function ($builder, $plan) {
            $userIds = DB::table('paddle_subscriptions')
                ->join('paddle_subscription_items', 'paddle_subscription_items.subscription_id', '=', 'paddle_subscriptions.id')
                ->where('paddle_subscriptions.billable_type', User::class)
                ->where('paddle_subscription_items.price_id', $plan)
                ->distinct()
                ->pluck('paddle_subscriptions.billable_id');
            $builder->whereIn('id', $userIds);
        });

        $recentThreshold = now()->subDays(30);
        $totalCount = (clone $query)->count();
        $activeCount = (clone $query)->where('is_suspended', false)->count();
        $suspendedCount = (clone $query)->where('is_suspended', true)->count();
        $newCount = (clone $query)->whereDate('created_at', '>=', $recentThreshold)->count();
        $onboardedCount = (clone $query)->whereNotNull('onboarding_completed_at')->count();

        $tenants = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        $subscriptionMap = $this->subscriptionMap($tenants->pluck('id'));
        $planMap = $this->planMap();

        $tenants->through(function (User $tenant) use ($subscriptionMap, $planMap) {
            $subscription = $subscriptionMap[$tenant->id] ?? null;
            $planName = $subscription?->price_id ? ($planMap[$subscription->price_id]['name'] ?? null) : null;

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'company_name' => $tenant->company_name,
                'company_type' => $tenant->company_type,
                'created_at' => $tenant->created_at,
                'is_suspended' => (bool) $tenant->is_suspended,
                'onboarding_completed_at' => $tenant->onboarding_completed_at,
                'subscription' => $subscription ? [
                    'status' => $subscription->status,
                    'price_id' => $subscription->price_id,
                    'plan_name' => $planName,
                ] : null,
            ];
        });

        return Inertia::render('SuperAdmin/Tenants/Index', [
            'filters' => $filters,
            'tenants' => $tenants,
            'plans' => array_values($this->planMap()),
            'stats' => [
                'total' => $totalCount,
                'active' => $activeCount,
                'suspended' => $suspendedCount,
                'new_30d' => $newCount,
                'onboarded' => $onboardedCount,
            ],
        ]);
    }

    public function show(Request $request, User $tenant): Response
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_VIEW);

        $this->ensureOwner($tenant);

        $subscription = $this->subscriptionMap(collect([$tenant->id]))[$tenant->id] ?? null;
        $planMap = $this->planMap();
        $planName = $subscription?->price_id ? ($planMap[$subscription->price_id]['name'] ?? null) : null;

        $stats = [
            'customers' => Customer::query()->where('user_id', $tenant->id)->count(),
            'quotes' => Quote::query()->where('user_id', $tenant->id)->count(),
            'plan_scan_quotes' => (int) PlanScan::query()->where('user_id', $tenant->id)->sum('quotes_generated'),
            'invoices' => Invoice::query()->where('user_id', $tenant->id)->count(),
            'works' => Work::query()->where('user_id', $tenant->id)->count(),
            'products' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_PRODUCT)->count(),
            'services' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_SERVICE)->count(),
            'tasks' => Task::query()->where('account_id', $tenant->id)->count(),
            'team_members' => TeamMember::query()->where('account_id', $tenant->id)->count(),
        ];

        $featureFlags = $this->buildFeatureFlags($tenant);
        $usageLimits = $this->buildUsageLimits($tenant, $subscription, $stats);

        return Inertia::render('SuperAdmin/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'company_name' => $tenant->company_name,
                'company_type' => $tenant->company_type,
                'company_country' => $tenant->company_country,
                'company_city' => $tenant->company_city,
                'onboarding_completed_at' => $tenant->onboarding_completed_at,
                'created_at' => $tenant->created_at,
                'is_suspended' => (bool) $tenant->is_suspended,
                'suspended_at' => $tenant->suspended_at,
                'suspension_reason' => $tenant->suspension_reason,
                'subscription' => $subscription ? [
                    'status' => $subscription->status,
                    'price_id' => $subscription->price_id,
                    'plan_name' => $planName,
                    'trial_ends_at' => $subscription->trial_ends_at ?? null,
                    'ends_at' => $subscription->ends_at ?? null,
                ] : null,
            ],
            'stats' => $stats,
            'feature_flags' => $featureFlags,
            'usage_limits' => $usageLimits,
        ]);
    }

    public function suspend(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant->update([
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspension_reason' => $validated['reason'] ?? null,
        ]);

        $this->logAudit($request, 'tenant.suspended', $tenant, [
            'reason' => $validated['reason'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Tenant suspended.');
    }

    public function restore(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $tenant->update([
            'is_suspended' => false,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $this->logAudit($request, 'tenant.restored', $tenant);

        return redirect()->back()->with('success', 'Tenant restored.');
    }

    public function resetOnboarding(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $tenant->update([
            'onboarding_completed_at' => null,
        ]);

        $this->logAudit($request, 'tenant.onboarding_reset', $tenant);

        return redirect()->back()->with('success', 'Onboarding reset.');
    }

    public function updateFeatures(Request $request, User $tenant): RedirectResponse
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

        $this->logAudit($request, 'tenant.features_updated', $tenant, [
            'features' => $validated['features'],
        ]);

        return redirect()->back()->with('success', 'Feature flags updated.');
    }

    public function updateLimits(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $validated = $request->validate([
            'limits' => 'required|array',
            'limits.*' => 'nullable|numeric|min:0',
        ]);

        $allowedKeys = [
            'quotes',
            'plan_scan_quotes',
            'invoices',
            'jobs',
            'products',
            'services',
            'tasks',
            'team_members',
        ];

        $limits = [];
        foreach ($allowedKeys as $key) {
            $value = $validated['limits'][$key] ?? null;
            $limits[$key] = is_numeric($value) ? max(0, (int) $value) : null;
        }

        $tenant->update([
            'company_limits' => $limits,
        ]);

        $this->logAudit($request, 'tenant.limits_updated', $tenant, [
            'limits' => $limits,
        ]);

        return redirect()->back()->with('success', 'Usage limits updated.');
    }

    public function impersonate(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::SUPPORT_IMPERSONATE);
        $this->ensureOwner($tenant);

        if ($request->session()->has('impersonator_id')) {
            return redirect()->back()->with('warning', 'Already impersonating another account.');
        }

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::login($tenant);

        $this->logAudit($request, 'tenant.impersonate', $tenant);

        return redirect()->route('dashboard');
    }

    public function stopImpersonate(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');
        if ($impersonatorId) {
            Auth::loginUsingId($impersonatorId);
        }

        return redirect()->route('superadmin.dashboard')->with('success', 'Impersonation stopped.');
    }

    public function export(Request $request, User $tenant)
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $this->logAudit($request, 'tenant.export', $tenant);

        $fileName = 'tenant-' . $tenant->id . '-export.json';

        return response()->streamDownload(function () use ($tenant) {
            $company = $tenant->only([
                'id',
                'name',
                'email',
                'company_name',
                'company_type',
                'company_country',
                'company_city',
                'created_at',
                'onboarding_completed_at',
            ]);

            $streamCollection = function (string $key, $query) {
                echo '"' . $key . '":[';
                $first = true;
                $query->orderBy('id')->chunk(200, function ($items) use (&$first) {
                    foreach ($items as $item) {
                        if (!$first) {
                            echo ',';
                        }
                        $first = false;
                        echo json_encode($item->toArray());
                    }
                });
                echo ']';
            };

            echo '{';
            echo '"company":' . json_encode($company);
            echo ',';
            $streamCollection('customers', Customer::query()->where('user_id', $tenant->id));
            echo ',';
            $streamCollection('products', Product::query()->where('user_id', $tenant->id));
            echo ',';
            $streamCollection('quotes', Quote::query()->where('user_id', $tenant->id));
            echo ',';
            $streamCollection('invoices', Invoice::query()->where('user_id', $tenant->id));
            echo ',';
            $streamCollection('works', Work::query()->where('user_id', $tenant->id));
            echo '}';
        }, $fileName, [
            'Content-Type' => 'application/json',
        ]);
    }

    private function ensureOwner(User $tenant): void
    {
        if (!$tenant->isOwner()) {
            abort(404);
        }
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
            ->get([
                'id',
                'billable_id',
                'status',
                'trial_ends_at',
                'ends_at',
            ])
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

    private function planMap(): array
    {
        return collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) {
                return [
                    'key' => $key,
                    'name' => $plan['name'] ?? $key,
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->filter(fn ($plan) => !empty($plan['price_id']))
            ->keyBy('price_id')
            ->all();
    }

    private function buildFeatureFlags(User $tenant): array
    {
        $defaults = [
            'quotes' => 'Quotes',
            'invoices' => 'Invoices',
            'jobs' => 'Jobs',
            'products' => 'Products',
            'services' => 'Services',
            'tasks' => 'Tasks',
        ];

        $current = $tenant->company_features ?? [];

        return collect($defaults)->map(function ($label, $key) use ($current) {
            $enabled = true;
            if (array_key_exists($key, $current)) {
                $enabled = (bool) $current[$key];
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
        $limitKeys = [
            'quotes' => 'Quotes',
            'plan_scan_quotes' => 'Plan scan quotes',
            'invoices' => 'Invoices',
            'jobs' => 'Jobs',
            'products' => 'Products',
            'services' => 'Services',
            'tasks' => 'Tasks',
            'team_members' => 'Team members',
        ];

        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planKey = $this->resolvePlanKey($subscription?->price_id);
        $planDefaults = $planKey ? ($planLimits[$planKey] ?? []) : [];
        $overrides = $tenant->company_limits ?? [];

        $results = [];
        foreach ($limitKeys as $key => $label) {
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

            $results[] = [
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
            'overrides' => $overrides,
            'items' => $results,
        ];
    }

    private function resolvePlanKey(?string $priceId): ?string
    {
        if (!$priceId) {
            return null;
        }

        foreach (config('billing.plans', []) as $key => $plan) {
            if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                return $key;
            }
        }

        return null;
    }
}
