<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Support\PlatformPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            $userIds = DB::table('subscriptions')
                ->where('stripe_price', $plan)
                ->pluck('user_id');
            $builder->whereIn('id', $userIds);
        });

        $tenants = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        $subscriptionMap = $this->subscriptionMap($tenants->pluck('id'));
        $planMap = $this->planMap();

        $tenants->through(function (User $tenant) use ($subscriptionMap, $planMap) {
            $subscription = $subscriptionMap[$tenant->id] ?? null;
            $planName = $subscription?->stripe_price ? ($planMap[$subscription->stripe_price]['name'] ?? null) : null;

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
                    'stripe_status' => $subscription->stripe_status,
                    'stripe_price' => $subscription->stripe_price,
                    'plan_name' => $planName,
                ] : null,
            ];
        });

        return Inertia::render('SuperAdmin/Tenants/Index', [
            'filters' => $filters,
            'tenants' => $tenants,
            'plans' => array_values($this->planMap()),
        ]);
    }

    public function show(Request $request, User $tenant): Response
    {
        $this->authorizePermission($request, PlatformPermissions::TENANTS_VIEW);

        $this->ensureOwner($tenant);

        $subscription = $this->subscriptionMap(collect([$tenant->id]))[$tenant->id] ?? null;
        $planMap = $this->planMap();
        $planName = $subscription?->stripe_price ? ($planMap[$subscription->stripe_price]['name'] ?? null) : null;

        $stats = [
            'customers' => Customer::query()->where('user_id', $tenant->id)->count(),
            'quotes' => Quote::query()->where('user_id', $tenant->id)->count(),
            'invoices' => Invoice::query()->where('user_id', $tenant->id)->count(),
            'works' => Work::query()->where('user_id', $tenant->id)->count(),
            'products' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_PRODUCT)->count(),
            'services' => Product::query()->where('user_id', $tenant->id)->where('item_type', Product::ITEM_TYPE_SERVICE)->count(),
        ];

        $featureFlags = $this->buildFeatureFlags($tenant);

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
                    'stripe_status' => $subscription->stripe_status,
                    'stripe_price' => $subscription->stripe_price,
                    'plan_name' => $planName,
                    'trial_ends_at' => $subscription->trial_ends_at ?? null,
                    'ends_at' => $subscription->ends_at ?? null,
                ] : null,
            ],
            'stats' => $stats,
            'feature_flags' => $featureFlags,
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

        $data = [
            'company' => $tenant->only([
                'id',
                'name',
                'email',
                'company_name',
                'company_type',
                'company_country',
                'company_city',
                'created_at',
                'onboarding_completed_at',
            ]),
            'customers' => Customer::query()->where('user_id', $tenant->id)->get(),
            'products' => Product::query()->where('user_id', $tenant->id)->get(),
            'quotes' => Quote::query()->where('user_id', $tenant->id)->get(),
            'invoices' => Invoice::query()->where('user_id', $tenant->id)->get(),
            'works' => Work::query()->where('user_id', $tenant->id)->get(),
        ];

        $this->logAudit($request, 'tenant.export', $tenant);

        $fileName = 'tenant-' . $tenant->id . '-export.json';

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT);
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

        $subscriptions = DB::table('subscriptions')
            ->whereIn('user_id', $tenantIds->all())
            ->orderByDesc('created_at')
            ->get([
                'user_id',
                'stripe_status',
                'stripe_price',
                'trial_ends_at',
                'ends_at',
            ])
            ->groupBy('user_id')
            ->map(fn ($items) => $items->first());

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
}
