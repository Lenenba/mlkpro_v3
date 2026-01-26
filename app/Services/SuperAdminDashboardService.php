<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PlatformAnnouncement;
use App\Models\PlatformAuditLog;
use App\Models\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\PlatformSupportTicket;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\Work;
use App\Services\BillingSubscriptionService;
use App\Services\UsageLimitService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Paddle\Subscription;

class SuperAdminDashboardService
{
    public function getMetrics(): array
    {
        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $ownersQuery = $ownerRoleId
            ? User::query()->where('role_id', $ownerRoleId)
            : User::query()->whereRaw('0 = 1');

        $owners = (clone $ownersQuery)->get([
            'id',
            'created_at',
            'company_name',
            'email',
            'company_limits',
            'onboarding_completed_at',
        ]);
        $ownerIds = $owners->pluck('id');

        $totalCompanies = (clone $ownersQuery)->count();
        $onboardedCompanies = (clone $ownersQuery)->whereNotNull('onboarding_completed_at')->count();

        $rangeStart = now()->subDays(30)->startOfDay();
        $rangeEnd = now()->endOfDay();
        $acquisitionSeries = $this->buildAcquisitionSeries($rangeStart, $rangeEnd, $ownersQuery);
        $newCompanies = (clone $ownersQuery)->where('created_at', '>=', $rangeStart)->count();

        $onboarded30 = (clone $ownersQuery)
            ->where('created_at', '>=', $rangeStart)
            ->whereNotNull('onboarding_completed_at')
            ->count();
        $total30 = (clone $ownersQuery)
            ->where('created_at', '>=', $rangeStart)
            ->count();

        $firstActivityMap = $this->firstActivityMap($ownerIds);
        $activationRates = $this->activationRates($owners, $firstActivityMap);

        $avgQuoteDays = $this->averageDaysToFirst($owners, 'quotes');
        $avgInvoiceDays = $this->averageDaysToFirst($owners, 'invoices');
        $avgProductDays = $this->averageDaysToFirstProductType($owners, Product::ITEM_TYPE_PRODUCT);
        $avgServiceDays = $this->averageDaysToFirstProductType($owners, Product::ITEM_TYPE_SERVICE);
        $avgWorkDays = $this->averageDaysToFirst($owners, 'works');

        $wau = $this->countActiveUsersSince($ownerIds, now()->subDays(7));
        $mau = $this->countActiveUsersSince($ownerIds, now()->subDays(30));

        $activityCounts = [
            'quotes' => Quote::query()->where('created_at', '>=', $rangeStart)->count(),
            'invoices' => Invoice::query()->where('created_at', '>=', $rangeStart)->count(),
            'works' => Work::query()->where('created_at', '>=', $rangeStart)->count(),
            'products' => Product::query()->where('item_type', Product::ITEM_TYPE_PRODUCT)->where('created_at', '>=', $rangeStart)->count(),
            'services' => Product::query()->where('item_type', Product::ITEM_TYPE_SERVICE)->where('created_at', '>=', $rangeStart)->count(),
        ];

        $servicesTotal = Product::query()->where('item_type', Product::ITEM_TYPE_SERVICE)->count();
        $productsTotal = Product::query()->where('item_type', Product::ITEM_TYPE_PRODUCT)->count();

        $health = $this->healthStats();
        $subscriptionMap = $this->subscriptionMap($ownerIds);
        $usageStatsMap = $this->usageStatsForOwners($ownerIds);
        $limitAlerts = $this->limitAlerts($owners, $usageStatsMap, $subscriptionMap);
        $lastActivityMap = $this->lastActivityMap($ownerIds);
        $riskTenants = $this->riskTenants($owners, $subscriptionMap, $lastActivityMap);
        $usageTrends = $this->usageTrends();
        $siteTraffic = $this->siteVisitStats();

        return [
            'companies_total' => $totalCompanies,
            'companies_onboarded' => $onboardedCompanies,
            'companies_recent_30d' => $newCompanies,
            'acquisition_series' => $acquisitionSeries,
            'onboarding_conversion' => $totalCompanies > 0
                ? round(($onboardedCompanies / $totalCompanies) * 100, 1)
                : 0,
            'onboarding_conversion_30d' => $total30 > 0
                ? round(($onboarded30 / $total30) * 100, 1)
                : 0,
            'wau' => $wau,
            'mau' => $mau,
            'activation_rates' => $activationRates,
            'avg_days_to_first' => [
                'quote' => $avgQuoteDays,
                'invoice' => $avgInvoiceDays,
                'product' => $avgProductDays,
                'service' => $avgServiceDays,
                'work' => $avgWorkDays,
            ],
            'activity_counts' => $activityCounts,
            'services_total' => $servicesTotal,
            'products_total' => $productsTotal,
            'subscription' => $this->subscriptionStats(),
            'cohorts' => $this->cohortStats($owners, $firstActivityMap),
            'data_quality' => $this->dataQualityStats(),
            'health' => $health,
            'alerts' => $this->platformAlerts($limitAlerts, $health),
            'usage_trends' => $usageTrends,
            'site_traffic' => $siteTraffic,
            'at_risk_tenants' => $riskTenants,
            'action_center' => $this->actionCenterStats($limitAlerts, $riskTenants),
        ];
    }

    public function getRecentAudits(array $filters = [], int $limit = 10): array
    {
        $query = PlatformAuditLog::query()
            ->with('user:id,name,email')
            ->latest();

        $adminId = $filters['admin_id'] ?? null;
        if ($adminId) {
            $query->where('user_id', $adminId);
        }

        $tenantId = $filters['tenant_id'] ?? null;
        if ($tenantId) {
            $query->where(function ($sub) use ($tenantId) {
                $sub->where(function ($inner) use ($tenantId) {
                    $inner->where('subject_type', User::class)
                        ->where('subject_id', $tenantId);
                })
                    ->orWhere('metadata->tenant_id', $tenantId);
            });
        }

        $action = $filters['action'] ?? null;
        if ($action) {
            $query->where('action', 'like', '%' . $action . '%');
        }

        return $query
            ->limit($limit)
            ->get(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'created_at'])
            ->map(function (PlatformAuditLog $audit) {
                return [
                    'id' => $audit->id,
                    'action' => $audit->action,
                    'subject_type' => $audit->subject_type,
                    'subject_id' => $audit->subject_id,
                    'created_at' => $audit->created_at?->toJSON(),
                    'metadata' => $audit->metadata ?? [],
                    'ip_address' => $audit->ip_address,
                    'user' => $audit->user
                        ? [
                            'id' => $audit->user->id,
                            'name' => $audit->user->name,
                            'email' => $audit->user->email,
                        ]
                        : null,
                ];
            })
            ->toArray();
    }

    public function getAuditFilterOptions(): array
    {
        $roleIds = Role::query()
            ->whereIn('name', ['superadmin', 'admin'])
            ->pluck('id')
            ->all();

        $admins = $roleIds
            ? User::query()
                ->whereIn('role_id', $roleIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->toArray()
            : [];

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $tenants = $ownerRoleId
            ? User::query()
                ->where('role_id', $ownerRoleId)
                ->orderBy('company_name')
                ->get(['id', 'company_name', 'email'])
                ->map(fn (User $tenant) => [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                    'email' => $tenant->email,
                ])
                ->toArray()
            : [];

        $actions = PlatformAuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->limit(75)
            ->pluck('action')
            ->all();

        return [
            'admins' => $admins,
            'tenants' => $tenants,
            'actions' => $actions,
        ];
    }

    private function platformAlerts(array $limitAlerts, array $health): array
    {
        return [
            'limit_warnings' => $limitAlerts,
            'stripe_failures_24h' => $health['failed_stripe_jobs_24h'] ?? 0,
            'smtp_failures_24h' => $health['failed_mail_jobs_24h'] ?? 0,
            'jobs_backlog' => [
                'pending' => $health['pending_jobs'] ?? 0,
                'oldest_minutes' => $health['oldest_job_minutes'] ?? null,
            ],
            'storage' => [
                'used_bytes' => $health['storage_public_bytes'] ?? null,
                'total_bytes' => $health['storage_total_bytes'] ?? null,
                'used_percent' => $health['storage_used_percent'] ?? null,
                'critical' => $health['storage_critical'] ?? false,
            ],
        ];
    }

    private function usageStatsForOwners(Collection $ownerIds): array
    {
        if ($ownerIds->isEmpty()) {
            return [];
        }

        $keys = array_keys(UsageLimitService::LIMIT_KEYS);
        $stats = [];
        foreach ($ownerIds as $ownerId) {
            $stats[$ownerId] = array_fill_keys($keys, 0);
        }

        $this->applyCounts($stats, $ownerIds, 'quotes', 'quotes', 'user_id');
        $this->applyCounts($stats, $ownerIds, 'requests', 'requests', 'user_id');
        $this->applySumCounts($stats, $ownerIds, 'plan_scan_quotes', 'plan_scans', 'quotes_generated', 'user_id');
        $this->applyCounts($stats, $ownerIds, 'invoices', 'invoices', 'user_id');
        $this->applyCounts($stats, $ownerIds, 'jobs', 'works', 'user_id');
        $this->applyCounts($stats, $ownerIds, 'products', 'products', 'user_id', [
            ['item_type', '=', Product::ITEM_TYPE_PRODUCT],
        ]);
        $this->applyCounts($stats, $ownerIds, 'services', 'products', 'user_id', [
            ['item_type', '=', Product::ITEM_TYPE_SERVICE],
        ]);
        $this->applyCounts($stats, $ownerIds, 'tasks', 'tasks', 'account_id');
        $this->applyCounts($stats, $ownerIds, 'team_members', 'team_members', 'account_id', [
            ['is_active', '=', 1],
        ]);
        $this->applySumCounts($stats, $ownerIds, 'assistant_requests', 'assistant_usages', 'request_count', 'user_id', [
            ['created_at', '>=', now()->startOfMonth()],
            ['created_at', '<=', now()->endOfMonth()],
        ]);

        return $stats;
    }

    private function applyCounts(array &$stats, Collection $ownerIds, string $key, string $table, string $column, array $where = []): void
    {
        $query = DB::table($table)
            ->selectRaw($column . ' as owner_id, COUNT(*) as count')
            ->whereIn($column, $ownerIds->all());

        foreach ($where as $condition) {
            $query->where(...$condition);
        }

        $counts = $query->groupBy('owner_id')->pluck('count', 'owner_id');
        foreach ($counts as $ownerId => $count) {
            if (!array_key_exists($ownerId, $stats)) {
                continue;
            }
            $stats[$ownerId][$key] = (int) $count;
        }
    }

    private function applySumCounts(array &$stats, Collection $ownerIds, string $key, string $table, string $sumColumn, string $column, array $where = []): void
    {
        $query = DB::table($table)
            ->selectRaw($column . ' as owner_id, SUM(' . $sumColumn . ') as count')
            ->whereIn($column, $ownerIds->all());

        foreach ($where as $condition) {
            $query->where(...$condition);
        }

        $counts = $query->groupBy('owner_id')->pluck('count', 'owner_id');
        foreach ($counts as $ownerId => $count) {
            if (!array_key_exists($ownerId, $stats)) {
                continue;
            }
            $stats[$ownerId][$key] = (int) ($count ?? 0);
        }
    }

    private function limitAlerts(Collection $owners, array $usageStats, array $subscriptionMap): array
    {
        if ($owners->isEmpty()) {
            return [
                'count' => 0,
                'tenants' => [],
            ];
        }

        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planNames = collect(config('billing.plans', []))->mapWithKeys(function (array $plan, string $key) {
            return [$key => $plan['name'] ?? $key];
        })->all();

        $alerts = [];
        foreach ($owners as $owner) {
            $subscription = $subscriptionMap[$owner->id] ?? null;
            $planKey = $this->resolvePlanKeyForLimits($subscription?->price_id ?? null, $planLimits);
            $planDefaults = $planKey ? ($planLimits[$planKey] ?? []) : [];
            $overrides = $owner->company_limits ?? [];
            $stats = $usageStats[$owner->id] ?? [];

            $flags = [];
            foreach (UsageLimitService::LIMIT_KEYS as $key => $label) {
                $used = (int) ($stats[$key] ?? 0);
                $override = $overrides[$key] ?? null;
                $defaultLimit = $planDefaults[$key] ?? null;
                $limit = is_numeric($override) ? (int) $override : (is_numeric($defaultLimit) ? (int) $defaultLimit : null);
                if ($limit === null) {
                    continue;
                }

                $percent = null;
                $status = 'ok';
                if ($limit <= 0) {
                    $status = $used > 0 ? 'over' : 'ok';
                } else {
                    $percent = round(($used / $limit) * 100, 1);
                    if ($used > $limit) {
                        $status = 'over';
                    } elseif ($percent >= 90) {
                        $status = 'warning';
                    }
                }

                if ($status === 'warning' || $status === 'over') {
                    $flags[] = [
                        'key' => $key,
                        'used' => $used,
                        'limit' => $limit,
                        'percent' => $percent,
                        'status' => $status,
                    ];
                }
            }

            if (!$flags) {
                continue;
            }

            usort($flags, function ($a, $b) {
                $scoreA = ($a['status'] === 'over' ? 2 : 1) * 100 + (int) ($a['percent'] ?? 0);
                $scoreB = ($b['status'] === 'over' ? 2 : 1) * 100 + (int) ($b['percent'] ?? 0);
                return $scoreB <=> $scoreA;
            });

            $alerts[] = [
                'id' => $owner->id,
                'company_name' => $owner->company_name,
                'email' => $owner->email,
                'plan_key' => $planKey,
                'plan_name' => $planNames[$planKey] ?? $planKey,
                'flags' => array_slice($flags, 0, 3),
            ];
        }

        usort($alerts, function ($a, $b) {
            $scoreA = count($a['flags']);
            $scoreB = count($b['flags']);
            return $scoreB <=> $scoreA;
        });

        return [
            'count' => count($alerts),
            'tenants' => array_slice($alerts, 0, 8),
        ];
    }

    private function resolvePlanKeyForLimits(?string $priceId, array $planLimits): ?string
    {
        if ($priceId) {
            foreach (config('billing.plans', []) as $key => $plan) {
                if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                    return $key;
                }
            }
        }

        if (array_key_exists('free', $planLimits)) {
            return 'free';
        }

        return null;
    }

    private function lastActivityMap(Collection $ownerIds): Collection
    {
        if ($ownerIds->isEmpty()) {
            return collect();
        }

        $queries = [
            DB::table('quotes')->select('user_id', 'created_at'),
            DB::table('invoices')->select('user_id', 'created_at'),
            DB::table('works')->select('user_id', 'created_at'),
            DB::table('products')->select('user_id', 'created_at'),
        ];

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()
            ->fromSub($union, 'activity')
            ->whereIn('user_id', $ownerIds->all())
            ->selectRaw('user_id, MAX(created_at) as last_created_at')
            ->groupBy('user_id')
            ->pluck('last_created_at', 'user_id');
    }

    private function riskTenants(Collection $owners, array $subscriptionMap, Collection $lastActivityMap): array
    {
        if ($owners->isEmpty()) {
            return [
                'count' => 0,
                'tenants' => [],
            ];
        }

        $riskList = [];
        foreach ($owners as $owner) {
            $flags = [];
            $score = 0;

            if (!$owner->onboarding_completed_at) {
                $ageDays = Carbon::parse($owner->created_at)->diffInDays(now());
                if ($ageDays >= 7) {
                    $flags[] = 'onboarding_blocked';
                    $score += 3;
                }
            }

            $subscription = $subscriptionMap[$owner->id] ?? null;
            $status = $subscription?->status ?? null;
            $endsAt = $subscription?->ends_at ? Carbon::parse($subscription->ends_at) : null;
            $trialEndsAt = $subscription?->trial_ends_at ? Carbon::parse($subscription->trial_ends_at) : null;

            $churnRisk = false;
            if ($status && in_array($status, ['past_due', 'paused', 'canceled', 'unpaid'], true)) {
                $churnRisk = true;
            }
            if (!$churnRisk && $endsAt && $endsAt->lte(now()->addDays(14))) {
                $churnRisk = true;
            }
            if (!$churnRisk && $trialEndsAt && $trialEndsAt->lte(now()->addDays(3))) {
                $churnRisk = true;
            }

            if ($churnRisk) {
                $flags[] = 'churn_risk';
                $score += 3;
            }

            $lastActivity = $lastActivityMap->get($owner->id);
            $inactiveDays = $lastActivity
                ? Carbon::parse($lastActivity)->diffInDays(now())
                : Carbon::parse($owner->created_at)->diffInDays(now());

            if ($inactiveDays >= 30) {
                $flags[] = 'inactive_30';
                $score += 2;
            } elseif ($inactiveDays >= 14) {
                $flags[] = 'inactive_14';
                $score += 1;
            }

            if (!$flags) {
                continue;
            }

            $riskList[] = [
                'id' => $owner->id,
                'company_name' => $owner->company_name,
                'email' => $owner->email,
                'created_at' => $owner->created_at?->toJSON(),
                'onboarding_completed_at' => $owner->onboarding_completed_at?->toJSON(),
                'subscription_status' => $status,
                'subscription_ends_at' => $subscription?->ends_at,
                'trial_ends_at' => $subscription?->trial_ends_at,
                'last_activity_at' => $lastActivity ? Carbon::parse($lastActivity)->toJSON() : null,
                'inactive_days' => $inactiveDays,
                'flags' => $flags,
                'score' => $score,
            ];
        }

        usort($riskList, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return ($b['inactive_days'] ?? 0) <=> ($a['inactive_days'] ?? 0);
        });

        $total = count($riskList);

        return [
            'count' => $total,
            'tenants' => array_slice($riskList, 0, 10),
        ];
    }

    private function usageTrends(): array
    {
        $range7 = now()->subDays(7)->startOfDay();
        $range30 = now()->subDays(30)->startOfDay();
        $prev7Start = now()->subDays(14)->startOfDay();
        $prev7End = now()->subDays(7)->endOfDay();

        $definitions = [
            'quotes' => Quote::query(),
            'invoices' => Invoice::query(),
            'jobs' => Work::query(),
            'products' => Product::query()->where('item_type', Product::ITEM_TYPE_PRODUCT),
            'services' => Product::query()->where('item_type', Product::ITEM_TYPE_SERVICE),
        ];

        $trends = [];
        foreach ($definitions as $key => $query) {
            $count7 = (clone $query)->where('created_at', '>=', $range7)->count();
            $count30 = (clone $query)->where('created_at', '>=', $range30)->count();
            $prev7 = (clone $query)->whereBetween('created_at', [$prev7Start, $prev7End])->count();
            $delta = $count7 - $prev7;
            $direction = $this->trendDirection($count7, $prev7);
            $percent = $prev7 > 0 ? round(($delta / $prev7) * 100, 1) : null;

            $trends[] = [
                'key' => $key,
                'count_7d' => $count7,
                'count_30d' => $count30,
                'trend_delta' => $delta,
                'trend_percent' => $percent,
                'trend_direction' => $direction,
            ];
        }

        return $trends;
    }

    private function siteVisitStats(): array
    {
        $baseQuery = TrackingEvent::query()
            ->where('event_type', 'site_visit');

        $ranges = [
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7)->startOfDay(),
            '30d' => now()->subDays(30)->startOfDay(),
        ];

        $stats = [];
        foreach ($ranges as $key => $since) {
            $total = (clone $baseQuery)
                ->where('created_at', '>=', $since)
                ->count();
            $unique = (clone $baseQuery)
                ->where('created_at', '>=', $since)
                ->whereNotNull('visitor_hash')
                ->distinct('visitor_hash')
                ->count('visitor_hash');

            $stats["total_{$key}"] = $total;
            $stats["unique_{$key}"] = $unique;
        }

        return $stats;
    }

    private function trendDirection(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current > 0 ? 'new' : 'none';
        }

        if ($current > $previous) {
            return 'up';
        }

        if ($current < $previous) {
            return 'down';
        }

        return 'flat';
    }

    private function actionCenterStats(array $limitAlerts, array $riskTenants): array
    {
        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $suspendedTenants = $ownerRoleId
            ? User::query()->where('role_id', $ownerRoleId)->where('is_suspended', true)->count()
            : 0;

        $supportOpen = PlatformSupportTicket::query()
            ->whereIn('status', ['open', 'pending'])
            ->count();

        $announcementsActive = PlatformAnnouncement::query()
            ->active()
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->count();

        $notificationsPending = PlatformNotification::query()
            ->whereNull('sent_at')
            ->count();

        return [
            'support_open' => $supportOpen,
            'announcements_active' => $announcementsActive,
            'notifications_pending' => $notificationsPending,
            'tenants_suspended' => $suspendedTenants,
            'tenants_near_limits' => (int) ($limitAlerts['count'] ?? 0),
            'tenants_at_risk' => (int) ($riskTenants['count'] ?? 0),
        ];
    }

    private function subscriptionMap(Collection $ownerIds): array
    {
        if ($ownerIds->isEmpty()) {
            return [];
        }

        $billing = app(BillingSubscriptionService::class);
        if ($billing->isStripe()) {
            return DB::table('stripe_subscriptions')
                ->whereIn('user_id', $ownerIds->all())
                ->orderByDesc('updated_at')
                ->get(['user_id', 'status', 'trial_ends_at', 'ends_at', 'price_id'])
                ->groupBy('user_id')
                ->map(fn ($items) => $items->first())
                ->all();
        }

        $subscriptions = DB::table('paddle_subscriptions')
            ->where('billable_type', User::class)
            ->whereIn('billable_id', $ownerIds->all())
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

    private function buildAcquisitionSeries(Carbon $start, Carbon $end, $ownersQuery): array
    {
        $rows = (clone $ownersQuery)
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $dateKey,
                'count' => $rows[$dateKey] ?? 0,
            ];
            $cursor->addDay();
        }

        return $series;
    }

    private function averageDaysToFirst(Collection $owners, string $table): ?float
    {
        if ($owners->isEmpty()) {
            return null;
        }

        $ownerIds = $owners->pluck('id')->all();
        $firstMap = DB::table($table)
            ->selectRaw('user_id, MIN(created_at) as first_created_at')
            ->whereIn('user_id', $ownerIds)
            ->groupBy('user_id')
            ->pluck('first_created_at', 'user_id');

        if ($firstMap->isEmpty()) {
            return null;
        }

        $ownersById = $owners->keyBy('id');
        $days = [];
        foreach ($firstMap as $userId => $firstCreatedAt) {
            $owner = $ownersById->get($userId);
            if (!$owner || !$firstCreatedAt) {
                continue;
            }

            $diffDays = Carbon::parse($firstCreatedAt)->diffInHours(Carbon::parse($owner->created_at)) / 24;
            $days[] = $diffDays;
        }

        if (!$days) {
            return null;
        }

        return round(array_sum($days) / count($days), 1);
    }

    private function averageDaysToFirstProductType(Collection $owners, string $itemType): ?float
    {
        if ($owners->isEmpty()) {
            return null;
        }

        $ownerIds = $owners->pluck('id')->all();
        $firstMap = DB::table('products')
            ->selectRaw('user_id, MIN(created_at) as first_created_at')
            ->where('item_type', $itemType)
            ->whereIn('user_id', $ownerIds)
            ->groupBy('user_id')
            ->pluck('first_created_at', 'user_id');

        if ($firstMap->isEmpty()) {
            return null;
        }

        $ownersById = $owners->keyBy('id');
        $days = [];

        foreach ($firstMap as $userId => $firstCreatedAt) {
            $owner = $ownersById->get($userId);
            if (!$owner || !$firstCreatedAt) {
                continue;
            }

            $diffDays = Carbon::parse($firstCreatedAt)->diffInHours(Carbon::parse($owner->created_at)) / 24;
            $days[] = $diffDays;
        }

        if (!$days) {
            return null;
        }

        return round(array_sum($days) / count($days), 1);
    }

    private function firstActivityMap(Collection $ownerIds): Collection
    {
        if ($ownerIds->isEmpty()) {
            return collect();
        }

        $queries = [
            DB::table('quotes')->select('user_id', 'created_at'),
            DB::table('invoices')->select('user_id', 'created_at'),
            DB::table('works')->select('user_id', 'created_at'),
            DB::table('products')->select('user_id', 'created_at'),
        ];

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()
            ->fromSub($union, 'activity')
            ->whereIn('user_id', $ownerIds->all())
            ->selectRaw('user_id, MIN(created_at) as first_created_at')
            ->groupBy('user_id')
            ->pluck('first_created_at', 'user_id');
    }

    private function activationRates(Collection $owners, Collection $firstActivityMap): array
    {
        $eligible7 = 0;
        $active7 = 0;
        $eligible30 = 0;
        $active30 = 0;

        foreach ($owners as $owner) {
            $createdAt = Carbon::parse($owner->created_at);
            $firstActivity = $firstActivityMap->get($owner->id);

            if ($createdAt->lte(now()->subDays(7))) {
                $eligible7++;
                if ($firstActivity && Carbon::parse($firstActivity)->lte($createdAt->copy()->addDays(7))) {
                    $active7++;
                }
            }

            if ($createdAt->lte(now()->subDays(30))) {
                $eligible30++;
                if ($firstActivity && Carbon::parse($firstActivity)->lte($createdAt->copy()->addDays(30))) {
                    $active30++;
                }
            }
        }

        return [
            'j7' => $eligible7 > 0 ? round(($active7 / $eligible7) * 100, 1) : 0,
            'j30' => $eligible30 > 0 ? round(($active30 / $eligible30) * 100, 1) : 0,
        ];
    }

    private function countActiveUsersSince(Collection $ownerIds, Carbon $since): int
    {
        if ($ownerIds->isEmpty()) {
            return 0;
        }

        $queries = [
            DB::table('quotes')->select('user_id')->where('created_at', '>=', $since),
            DB::table('invoices')->select('user_id')->where('created_at', '>=', $since),
            DB::table('works')->select('user_id')->where('created_at', '>=', $since),
            DB::table('products')->select('user_id')->where('created_at', '>=', $since),
        ];

        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return (int) DB::query()
            ->fromSub($union, 'activity')
            ->whereIn('user_id', $ownerIds->all())
            ->distinct()
            ->count('user_id');
    }

    private function subscriptionStats(): array
    {
        $plans = collect(config('billing.plans', []));
        $planMap = $plans->mapWithKeys(function (array $plan) {
            $priceId = $plan['price_id'] ?? null;
            if (!$priceId) {
                return [];
            }

            $priceValue = $this->parsePrice($plan['price'] ?? null);

            return [$priceId => $priceValue];
        });

        $subscriptions = DB::table('paddle_subscriptions')
            ->where('billable_type', User::class)
            ->get([
                'id',
                'billable_id',
                'status',
                'trial_ends_at',
                'ends_at',
            ]);

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

        $activeSubs = $subscriptions->where('status', Subscription::STATUS_ACTIVE);
        $trialSubs = $subscriptions->where('status', Subscription::STATUS_TRIALING);
        $pastDue = $subscriptions->where('status', Subscription::STATUS_PAST_DUE);

        $mrr = 0.0;
        foreach ($activeSubs as $sub) {
            $mrr += (float) ($planMap[$sub->price_id] ?? 0);
        }

        $activeCustomers = $activeSubs->pluck('billable_id')->unique()->count();
        $trialTotal = $subscriptions->whereNotNull('trial_ends_at')->count();
        $trialConverted = $subscriptions
            ->whereNotNull('trial_ends_at')
            ->where('status', Subscription::STATUS_ACTIVE)
            ->count();

        $churnedLast30 = $subscriptions
            ->whereNotNull('ends_at')
            ->filter(function ($sub) {
                return Carbon::parse($sub->ends_at)->gte(now()->subDays(30));
            })
            ->count();

        $churnRate = $activeCustomers > 0
            ? round(($churnedLast30 / $activeCustomers) * 100, 1)
            : 0;

        return [
            'mrr' => round($mrr, 2),
            'arpu' => $activeCustomers > 0 ? round($mrr / $activeCustomers, 2) : 0,
            'active_subscriptions' => $activeSubs->count(),
            'trialing_subscriptions' => $trialSubs->count(),
            'payment_failed' => $pastDue->count(),
            'trial_conversion' => $trialTotal > 0 ? round(($trialConverted / $trialTotal) * 100, 1) : 0,
            'churned_30d' => $churnedLast30,
            'churn_rate' => $churnRate,
        ];
    }

    private function parsePrice(?string $raw): float
    {
        if (!$raw) {
            return 0.0;
        }

        $clean = preg_replace('/[^0-9.]/', '', (string) $raw);
        if ($clean === '' || $clean === '.') {
            return 0.0;
        }

        return (float) $clean;
    }

    private function cohortStats(Collection $owners, Collection $firstActivityMap): array
    {
        $cohorts = [];
        $ownersByMonth = $owners->groupBy(function ($owner) {
            return Carbon::parse($owner->created_at)->format('Y-m');
        });

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthKey = $monthStart->format('Y-m');
            $cohortOwners = $ownersByMonth->get($monthKey, collect());
            $newCount = $cohortOwners->count();

            $retained = 0;
            foreach ($cohortOwners as $owner) {
                $firstActivity = $firstActivityMap->get($owner->id);
                if (!$firstActivity) {
                    continue;
                }

                $createdAt = Carbon::parse($owner->created_at);
                if (Carbon::parse($firstActivity)->lte($createdAt->copy()->addDays(30))) {
                    $retained++;
                }
            }

            $cohorts[] = [
                'month' => $monthKey,
                'new' => $newCount,
                'retained_30d' => $newCount > 0 ? round(($retained / $newCount) * 100, 1) : 0,
            ];
        }

        return $cohorts;
    }

    private function healthStats(): array
    {
        $failedLast24 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        $failedLast7 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDays(7))
            ->count();

        $failedMailLast24 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->where(function ($query) {
                $query->where('payload', 'like', '%Mail%')
                    ->orWhere('payload', 'like', '%mail%')
                    ->orWhere('exception', 'like', '%SMTP%')
                    ->orWhere('exception', 'like', '%Mail%');
            })
            ->count();

        $failedStripeLast24 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->where(function ($query) {
                $query->where('payload', 'like', '%Stripe%')
                    ->orWhere('payload', 'like', '%stripe%')
                    ->orWhere('exception', 'like', '%Stripe%')
                    ->orWhere('exception', 'like', '%stripe%');
            })
            ->count();

        $pendingJobs = DB::table('jobs')->count();
        $oldestJobCreatedAt = DB::table('jobs')->min('created_at');
        $oldestJobMinutes = null;
        if ($oldestJobCreatedAt) {
            $oldestJobMinutes = round((now()->timestamp - (int) $oldestJobCreatedAt) / 60, 1);
        }

        $storageBytes = null;
        $storageTotal = null;
        $storagePercent = null;
        $storageCritical = false;
        try {
            $disk = Storage::disk('public');
            $files = $disk->allFiles();
            $total = 0;
            foreach ($files as $file) {
                $total += $disk->size($file);
            }
            $storageBytes = $total;

            $rootPath = $disk->path('');
            $diskTotal = @disk_total_space($rootPath);
            $diskFree = @disk_free_space($rootPath);
            if ($diskTotal !== false && $diskTotal > 0) {
                $storageTotal = (int) $diskTotal;
                if ($diskFree !== false) {
                    $storagePercent = round((1 - ($diskFree / $diskTotal)) * 100, 1);
                    $storageCritical = $storagePercent >= 90;
                }
            }
        } catch (\Throwable $exception) {
            $storageBytes = null;
            $storageTotal = null;
            $storagePercent = null;
            $storageCritical = false;
        }

        return [
            'failed_jobs_24h' => $failedLast24,
            'failed_jobs_7d' => $failedLast7,
            'failed_mail_jobs_24h' => $failedMailLast24,
            'failed_stripe_jobs_24h' => $failedStripeLast24,
            'pending_jobs' => $pendingJobs,
            'oldest_job_minutes' => $oldestJobMinutes,
            'storage_public_bytes' => $storageBytes,
            'storage_total_bytes' => $storageTotal,
            'storage_used_percent' => $storagePercent,
            'storage_critical' => $storageCritical,
        ];
    }

    private function dataQualityStats(): array
    {
        $duplicateCustomerEmails = DB::table('customers')
            ->whereNotNull('email')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateCustomerNames = DB::table('customers')
            ->whereNotNull('company_name')
            ->select('company_name')
            ->groupBy('company_name')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateProductNames = DB::table('products')
            ->select('user_id', 'name')
            ->groupBy('user_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        return [
            'customer_email_duplicates' => $duplicateCustomerEmails,
            'customer_name_duplicates' => $duplicateCustomerNames,
            'product_name_duplicates' => $duplicateProductNames,
        ];
    }
}
