<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Models\Invoice;
use App\Models\PlatformAuditLog;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Support\PlatformPermissions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Paddle\Subscription;

class DashboardController extends BaseSuperAdminController
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request, PlatformPermissions::ANALYTICS_VIEW);

        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');
        $ownersQuery = User::query()->where('role_id', $ownerRoleId);
        $owners = (clone $ownersQuery)->get(['id', 'created_at']);
        $ownerIds = $owners->pluck('id');

        $totalCompanies = (clone $ownersQuery)->count();
        $onboardedCompanies = (clone $ownersQuery)->whereNotNull('onboarding_completed_at')->count();

        $rangeStart = now()->subDays(30)->startOfDay();
        $rangeEnd = now()->endOfDay();

        $acquisitionRows = (clone $ownersQuery)
            ->where('created_at', '>=', $rangeStart)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $acquisitionSeries = $this->fillDateSeries($rangeStart, $rangeEnd, $acquisitionRows);
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

        $subscriptionStats = $this->subscriptionStats();
        $cohorts = $this->cohortStats($owners, $firstActivityMap);
        $dataQuality = $this->dataQualityStats();

        $healthStats = $this->healthStats();
        $recentAudits = PlatformAuditLog::query()
            ->with('user:id,name,email')
            ->latest()
            ->limit(10)
            ->get(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'created_at']);

        return Inertia::render('SuperAdmin/Dashboard', [
            'metrics' => [
                'companies_total' => $totalCompanies,
                'companies_onboarded' => $onboardedCompanies,
                'onboarding_conversion' => $totalCompanies > 0
                    ? round(($onboardedCompanies / $totalCompanies) * 100, 1)
                    : 0,
                'onboarding_conversion_30d' => $total30 > 0
                    ? round(($onboarded30 / $total30) * 100, 1)
                    : 0,
                'acquisition_series' => $acquisitionSeries,
                'activation_rates' => $activationRates,
                'avg_days_to_first' => [
                    'quote' => $avgQuoteDays,
                    'invoice' => $avgInvoiceDays,
                    'product' => $avgProductDays,
                    'service' => $avgServiceDays,
                    'work' => $avgWorkDays,
                ],
                'wau' => $wau,
                'mau' => $mau,
                'activity_counts' => $activityCounts,
                'services_total' => $servicesTotal,
                'products_total' => $productsTotal,
                'subscription' => $subscriptionStats,
                'cohorts' => $cohorts,
                'data_quality' => $dataQuality,
                'health' => $healthStats,
            ],
            'recent_audits' => $recentAudits,
        ]);
    }

    private function fillDateSeries(Carbon $start, Carbon $end, Collection $rows): array
    {
        $map = $rows->mapWithKeys(function ($row) {
            return [$row->date => (int) $row->count];
        });

        $series = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $dateKey = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $dateKey,
                'count' => $map[$dateKey] ?? 0,
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
            ->distinct('user_id')
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

        $pendingJobs = DB::table('jobs')->count();
        $oldestJobCreatedAt = DB::table('jobs')->min('created_at');
        $oldestJobMinutes = null;
        if ($oldestJobCreatedAt) {
            $oldestJobMinutes = round((now()->timestamp - (int) $oldestJobCreatedAt) / 60, 1);
        }

        $storageBytes = null;
        try {
            $disk = Storage::disk('public');
            $files = $disk->allFiles();
            $total = 0;
            foreach ($files as $file) {
                $total += $disk->size($file);
            }
            $storageBytes = $total;
        } catch (\Throwable $exception) {
            $storageBytes = null;
        }

        return [
            'failed_jobs_24h' => $failedLast24,
            'failed_jobs_7d' => $failedLast7,
            'failed_mail_jobs_24h' => $failedMailLast24,
            'pending_jobs' => $pendingJobs,
            'oldest_job_minutes' => $oldestJobMinutes,
            'storage_public_bytes' => $storageBytes,
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
