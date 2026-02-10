<?php

namespace App\Http\Controllers\SuperAdmin;

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
use App\Services\BillingSubscriptionService;
use App\Services\StripeBillingService;
use App\Support\PlanDisplay;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        if (!empty($filters['plan'])) {
            $this->applyPlanFilter($query, (string) $filters['plan']);
        }

        $recentThreshold = now()->subDays(30);
        $totalCount = (clone $query)->count();
        $activeCount = (clone $query)->where('is_suspended', false)->count();
        $suspendedCount = (clone $query)->where('is_suspended', true)->count();
        $newCount = (clone $query)->whereDate('created_at', '>=', $recentThreshold)->count();
        $onboardedCount = (clone $query)->whereNotNull('onboarding_completed_at')->count();

        $tenants = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        $subscriptionMap = $this->subscriptionMap($tenants->pluck('id'));
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);

        $tenants->through(function (User $tenant) use ($subscriptionMap, $planDisplayOverrides) {
            $subscription = $subscriptionMap[$tenant->id] ?? null;
            $planKey = $this->resolvePlanKey($subscription?->price_id);
            $planName = null;
            if ($planKey) {
                $planConfig = config('billing.plans.' . $planKey, []);
                $display = PlanDisplay::merge($planConfig, $planKey, $planDisplayOverrides);
                $planName = $display['name'];
            }

            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'company_name' => $tenant->company_name,
                'company_type' => $tenant->company_type,
                'created_at' => $tenant->created_at,
                'is_suspended' => (bool) $tenant->is_suspended,
                'onboarding_completed_at' => $tenant->onboarding_completed_at,
                'subscription' => ($subscription || $planKey) ? [
                    'status' => $subscription?->status ?? 'free',
                    'price_id' => $subscription?->price_id,
                    'plan_name' => $planName,
                    'is_comped' => (bool) ($subscription?->is_comped ?? false),
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

        $billingService = app(BillingSubscriptionService::class);
        $subscription = $this->subscriptionMap(collect([$tenant->id]))[$tenant->id] ?? null;
        $planKey = $this->resolvePlanKey($subscription?->price_id);
        $planName = null;
        if ($planKey) {
            $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
            $planConfig = config('billing.plans.' . $planKey, []);
            $display = PlanDisplay::merge($planConfig, $planKey, $planDisplayOverrides);
            $planName = $display['name'];
        }

        $stats = [
            'customers' => Customer::query()->where('user_id', $tenant->id)->count(),
            'quotes' => Quote::query()->where('user_id', $tenant->id)->count(),
            'requests' => LeadRequest::query()->where('user_id', $tenant->id)->count(),
            'plan_scan_quotes' => (int) PlanScan::query()->where('user_id', $tenant->id)->sum('quotes_generated'),
            'invoices' => Invoice::query()->where('user_id', $tenant->id)->count(),
            'works' => Work::query()->where('user_id', $tenant->id)->count(),
            'products' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_PRODUCT)->count(),
            'services' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_SERVICE)->count(),
            'tasks' => Task::query()->where('account_id', $tenant->id)->count(),
            'team_members' => TeamMember::query()->where('account_id', $tenant->id)->count(),
            'assistant_requests' => (int) \App\Models\AssistantUsage::query()
                ->where('user_id', $tenant->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('request_count'),
        ];

        $featureFlags = $this->buildFeatureFlags($tenant, $subscription);
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
                'subscription' => ($subscription || $planKey) ? [
                    'status' => $subscription?->status ?? 'free',
                    'price_id' => $subscription?->price_id,
                    'plan_name' => $planName,
                    'trial_ends_at' => $subscription?->trial_ends_at ?? null,
                    'ends_at' => $subscription?->ends_at ?? null,
                    'is_comped' => (bool) ($subscription?->is_comped ?? false),
                ] : null,
            ],
            'security' => [
                'two_factor_exempt' => (bool) $tenant->two_factor_exempt,
                'two_factor_method' => $tenant->twoFactorMethod(),
                'two_factor_has_app' => !empty($tenant->two_factor_secret),
            ],
            'stats' => $stats,
            'feature_flags' => $featureFlags,
            'usage_limits' => $usageLimits,
            'plans' => array_values($this->planMap()),
            'billing' => [
                'provider' => $billingService->providerEffective(),
                'ready' => $billingService->providerReady(),
            ],
        ]);
    }

    public function updateSecurity(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $validated = $request->validate([
            'two_factor_exempt' => ['required', 'boolean'],
        ]);

        $tenant->forceFill([
            'two_factor_exempt' => (bool) $validated['two_factor_exempt'],
        ])->save();

        $this->logAudit($request, 'tenant.security_updated', $tenant, [
            'two_factor_exempt' => (bool) $validated['two_factor_exempt'],
        ]);

        return redirect()->back()->with('success', 'Security overrides updated.');
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
        $this->authorizeSuperadmin($request);
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
            'requests',
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

    public function updatePlan(Request $request, User $tenant): RedirectResponse
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_MANAGE);
        $this->ensureOwner($tenant);

        $billingService = app(BillingSubscriptionService::class);
        if (!$billingService->isStripe()) {
            return redirect()->back()->with('error', 'Billing provider is not Stripe.');
        }
        if (!$billingService->providerReady()) {
            return redirect()->back()->with('error', 'Stripe is not configured.');
        }

        $plans = collect(config('billing.plans', []))
            ->map(fn(array $plan, string $key) => array_merge(['key' => $key], $plan))
            ->filter(fn(array $plan) => !empty($plan['price_id']))
            ->values();

        $priceIds = $plans->pluck('price_id')->filter()->values()->all();
        if (!$priceIds) {
            return redirect()->back()->withErrors([
                'price_id' => 'No subscription plans are configured.',
            ]);
        }

        $validated = $request->validate([
            'price_id' => ['required', Rule::in($priceIds)],
            'comped' => ['sometimes', 'boolean'],
        ]);

        $plan = $plans->firstWhere('price_id', $validated['price_id']);
        $planKey = $plan['key'] ?? null;
        $comped = (bool) ($validated['comped'] ?? false);

        if ($comped && !config('services.stripe.comped_coupon_id')) {
            return redirect()->back()->withErrors([
                'comped' => 'Comped coupon is not configured.',
            ]);
        }

        try {
            $seatQuantity = app(BillingSubscriptionService::class)->resolveSeatQuantity($tenant);
            $updated = app(StripeBillingService::class)
                ->assignPlan($tenant, $validated['price_id'], $comped, $planKey, $seatQuantity);
            if (!$updated) {
                throw new \RuntimeException('Stripe subscription update failed.');
            }
        } catch (\Throwable $exception) {
            return redirect()->back()->with('error', 'Unable to update plan right now.');
        }

        $this->logAudit($request, 'tenant.plan_updated', $tenant, [
            'price_id' => $validated['price_id'],
            'plan_key' => $planKey,
            'comped' => $comped,
        ]);

        return redirect()->back()->with('success', 'Plan updated.');
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

        $billingService = app(BillingSubscriptionService::class);
        if ($billingService->isStripe()) {
            $subscriptions = DB::table('stripe_subscriptions')
                ->whereIn('user_id', $tenantIds->all())
                ->orderByDesc('updated_at')
                ->get([
                    'id',
                    'user_id',
                    'status',
                    'trial_ends_at',
                    'ends_at',
                    'price_id',
                    'is_comped',
                ])
                ->groupBy('user_id')
                ->map(fn ($items) => $items->first());

            return $subscriptions->all();
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

        $billingService = app(BillingSubscriptionService::class);
        if ($billingService->isStripe()) {
            if ($planKey === 'free') {
                $query->where(function ($builder) use ($priceId) {
                    $builder->whereNotIn('id', DB::table('stripe_subscriptions')
                        ->select('user_id'));

                    if ($priceId) {
                        $builder->orWhereIn('id', DB::table('stripe_subscriptions')
                            ->select('user_id')
                            ->where('price_id', $priceId)
                            ->distinct());
                    }
                });

                return;
            }

            if (!$priceId) {
                $query->whereRaw('0 = 1');
                return;
            }

            $query->whereIn('id', DB::table('stripe_subscriptions')
                ->select('user_id')
                ->where('price_id', $priceId)
                ->distinct());

            return;
        }

        if ($planKey === 'free') {
            $query->where(function ($builder) use ($priceId) {
                $builder->whereNotIn('id', DB::table('paddle_subscriptions')
                    ->select('billable_id')
                    ->where('billable_type', User::class));

                if ($priceId) {
                    $builder->orWhereIn('id', DB::table('paddle_subscriptions')
                        ->join('paddle_subscription_items', 'paddle_subscription_items.subscription_id', '=', 'paddle_subscriptions.id')
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

    private function planMap(): array
    {
        $planDisplayOverrides = PlatformSetting::getValue('plan_display', []);
        return collect(config('billing.plans', []))
            ->map(function (array $plan, string $key) use ($planDisplayOverrides) {
                $display = PlanDisplay::merge($plan, $key, $planDisplayOverrides);
                return [
                    'key' => $key,
                    'name' => $display['name'],
                    'price_id' => $plan['price_id'] ?? null,
                ];
            })
            ->filter(fn ($plan) => !empty($plan['price_id']))
            ->keyBy('price_id')
            ->all();
    }

    private function buildFeatureFlags(User $tenant, ?object $subscription): array
    {
        $defaults = [
            'quotes' => 'Quotes',
            'requests' => 'Requests',
            'reservations' => 'Reservations',
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
        $limitKeys = [
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
}
