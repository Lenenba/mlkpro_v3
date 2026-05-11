<?php

namespace App\Queries\Customers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\Invoice;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyProgram;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\Request as LeadRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\VipTier;
use App\Models\Work;
use App\Services\CompanyFeatureService;
use App\Support\CRM\CrmActivityLinking;
use App\Support\CRM\MeetingEventTaxonomy;
use App\Support\CRM\MessageEventTaxonomy;
use App\Support\CRM\SalesActivityTaxonomy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildCustomerDetailViewData
{
    public function __construct(
        private readonly CompanyFeatureService $featureService
    ) {}

    public function execute(
        Customer $customer,
        ?User $user,
        ?User $accountOwner,
        int $accountId,
        bool $canEdit,
        array $filters
    ): array {
        $isProductAccount = (bool) ($accountOwner && $accountOwner->company_type === 'products');

        $customer->load(['properties', 'vipTier']);
        if (! $isProductAccount) {
            $customer->load([
                'quotes' => fn ($query) => $query
                    ->without(['products', 'property'])
                    ->with('property:id,street1,city,country')
                    ->select(CustomerReadSelects::detailQuoteColumns())
                    ->latest()
                    ->limit(10),
                'works' => fn ($query) => $query
                    ->with('invoice:id,work_id')
                    ->select(CustomerReadSelects::detailWorkColumns())
                    ->latest()
                    ->limit(10),
                'requests' => fn ($query) => $query
                    ->select(CustomerReadSelects::detailRequestColumns())
                    ->latest()
                    ->limit(10)
                    ->with('quote:id,request_id,number,status,customer_id'),
                'serviceRequests' => fn ($query) => $query
                    ->select(CustomerReadSelects::detailServiceRequestColumns())
                    ->latest()
                    ->limit(10)
                    ->with('prospect:id,customer_id,status,title,contact_name,contact_email,contact_phone'),
                'invoices' => fn ($query) => $query
                    ->select(CustomerReadSelects::detailInvoiceColumns())
                    ->withSum(['payments as payments_sum_amount' => fn ($paymentQuery) => $paymentQuery->whereIn('status', Payment::settledStatuses())], 'amount')
                    ->latest()
                    ->limit(10),
            ]);
        }

        $campaignsFeatureEnabled = $accountOwner
            ? $this->featureService->hasFeature($accountOwner, 'campaigns')
            : false;
        $canManageMailingLists = $this->canManageMailingLists($user, $accountOwner, $campaignsFeatureEnabled);
        $loyalty = $accountOwner
            ? $this->featureService->resolveFeatureValue(
                $accountOwner,
                'loyalty',
                fn (): array => $this->buildLoyalty($customer, $accountId),
            )
            : null;
        $vipTiers = $campaignsFeatureEnabled
            ? VipTier::query()
                ->where('user_id', $accountId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'perks', 'is_active'])
            : collect();

        $sales = collect();
        $salesSummary = null;
        $salesInsights = null;
        $topProducts = collect();
        $stats = [];
        $tasks = collect();
        $upcomingJobs = collect();
        $recentPayments = collect();
        $billing = [
            'total_invoiced' => 0,
            'total_paid' => 0,
            'balance_due' => 0,
        ];

        if ($isProductAccount) {
            [
                'sales' => $sales,
                'salesSummary' => $salesSummary,
                'salesInsights' => $salesInsights,
                'topProducts' => $topProducts,
            ] = $this->buildSalesData($customer, $accountId);
        } else {
            [
                'stats' => $stats,
                'tasks' => $tasks,
                'upcomingJobs' => $upcomingJobs,
                'recentPayments' => $recentPayments,
                'billing' => $billing,
            ] = $this->buildServiceData($customer, $accountId);
        }

        $activity = $this->buildActivity($customer, $accountId, $isProductAccount);
        $customerPackages = $this->buildCustomerPackages($customer, $accountId);

        return [
            'customer' => $customer,
            'canEdit' => $canEdit,
            'filters' => $filters,
            'stats' => $stats,
            'sales' => $sales,
            'salesSummary' => $salesSummary,
            'salesInsights' => $salesInsights,
            'topProducts' => $topProducts,
            'schedule' => [
                'tasks' => $tasks,
                'upcomingJobs' => $upcomingJobs,
            ],
            'billing' => [
                'summary' => $billing,
                'recentPayments' => $recentPayments,
            ],
            'loyalty' => $loyalty,
            'activity' => $activity,
            'lastInteraction' => $activity->first(),
            'customerPackages' => $customerPackages,
            'customerPackageSummary' => $this->buildCustomerPackageSummary($customer, $accountId),
            'customerPackageOptions' => $this->buildCustomerPackageOptions($accountId),
            'vipTiers' => $vipTiers,
            'campaignsFeatureEnabled' => $campaignsFeatureEnabled,
            'canManageMailingLists' => $canManageMailingLists,
        ];
    }

    private function canManageMailingLists(?User $user, ?User $accountOwner, bool $campaignsFeatureEnabled): bool
    {
        if (! $campaignsFeatureEnabled || ! $user) {
            return false;
        }

        if ((int) $user->id === (int) ($accountOwner?->id ?? 0)) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->first();

        return (bool) (
            $membership?->hasPermission('campaigns.manage')
            || $membership?->hasPermission('sales.manage')
        );
    }

    private function buildLoyalty(Customer $customer, int $accountId): array
    {
        $loyalty = [
            'enabled' => false,
            'label' => 'points',
            'balance' => (int) ($customer->loyalty_points_balance ?? 0),
            'rate' => 1.0,
            'minimum_spend' => 0.0,
            'rounding_mode' => 'floor',
            'recent' => [],
        ];

        $loyaltyProgram = LoyaltyProgram::query()
            ->where('user_id', $accountId)
            ->first();

        if ($loyaltyProgram) {
            $loyalty['enabled'] = (bool) $loyaltyProgram->is_enabled;
            $loyalty['label'] = (string) ($loyaltyProgram->points_label ?: 'points');
            $loyalty['rate'] = (float) $loyaltyProgram->points_per_currency_unit;
            $loyalty['minimum_spend'] = (float) $loyaltyProgram->minimum_spend;
            $loyalty['rounding_mode'] = (string) $loyaltyProgram->rounding_mode;
        }

        $loyalty['recent'] = LoyaltyPointLedger::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest('processed_at')
            ->latest('id')
            ->limit(8)
            ->get([
                ...CustomerReadSelects::detailLoyaltyLedgerColumns(),
            ])
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'payment_id' => $entry->payment_id,
                'event' => $entry->event,
                'points' => (int) $entry->points,
                'amount' => (float) $entry->amount,
                'processed_at' => $entry->processed_at ?: $entry->created_at,
            ])
            ->values()
            ->all();

        return $loyalty;
    }

    private function buildSalesData(Customer $customer, int $accountId): array
    {
        $salesQuery = Sale::query()
            ->where('user_id', $accountId)
            ->where('customer_id', $customer->id);

        $sales = (clone $salesQuery)
            ->latest()
            ->limit(10)
            ->get(CustomerReadSelects::detailSalesColumns());

        $salesCount = (clone $salesQuery)->count();
        $salesTotal = (float) (clone $salesQuery)->sum('total');
        $salesPaid = (float) (clone $salesQuery)->where('status', Sale::STATUS_PAID)->sum('total');
        $lastPurchaseAt = (clone $salesQuery)->latest()->value('created_at');
        $daysSinceLast = $lastPurchaseAt ? now()->diffInDays($lastPurchaseAt) : null;
        $recent30Count = (clone $salesQuery)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $saleDates = (clone $salesQuery)
            ->orderBy('created_at')
            ->pluck('created_at')
            ->filter();

        $purchaseFrequency = null;
        if ($saleDates->count() > 1) {
            $intervals = [];
            for ($index = 1; $index < $saleDates->count(); $index++) {
                $current = $saleDates[$index];
                $previous = $saleDates[$index - 1];
                if ($current && $previous) {
                    $intervals[] = $current->diffInDays($previous);
                }
            }
            if ($intervals) {
                $purchaseFrequency = round(array_sum($intervals) / count($intervals), 1);
            }
        }

        ['preferred_day' => $preferredDay, 'preferred_period' => $preferredPeriod] = $this->purchasePreferences($saleDates);

        $itemsQuery = SaleItem::query()
            ->whereHas('sale', function ($query) use ($accountId, $customer) {
                $query->where('user_id', $accountId)
                    ->where('customer_id', $customer->id);
            });

        $distinctProducts = (clone $itemsQuery)->distinct('product_id')->count('product_id');
        $totalUnits = (int) (clone $itemsQuery)->sum('quantity');
        $averageItems = $salesCount > 0 ? round($totalUnits / $salesCount, 1) : 0;

        $topProducts = (clone $itemsQuery)
            ->select('product_id', DB::raw('SUM(quantity) as quantity'), DB::raw('SUM(total) as total'))
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->orderByDesc('quantity')
            ->limit(5)
            ->with('product:id,name,sku,image')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->product_id,
                'name' => $row->product?->name,
                'sku' => $row->product?->sku,
                'image' => $row->product?->image_url ?? $row->product?->image,
                'quantity' => (int) $row->quantity,
                'total' => (float) $row->total,
            ])
            ->values();

        return [
            'sales' => $sales,
            'salesSummary' => [
                'count' => $salesCount,
                'total' => $salesTotal,
                'paid' => $salesPaid,
            ],
            'salesInsights' => [
                'average_order_value' => $salesCount > 0 ? round($salesTotal / $salesCount, 2) : 0,
                'average_items' => $averageItems,
                'last_purchase_at' => $lastPurchaseAt,
                'days_since_last_purchase' => $daysSinceLast,
                'purchase_frequency_days' => $purchaseFrequency,
                'recent_30_count' => $recent30Count,
                'preferred_day' => $preferredDay,
                'preferred_period' => $preferredPeriod,
                'distinct_products' => $distinctProducts,
            ],
            'topProducts' => $topProducts,
        ];
    }

    private function purchasePreferences(Collection $saleDates): array
    {
        if ($saleDates->isEmpty()) {
            return [
                'preferred_day' => null,
                'preferred_period' => null,
            ];
        }

        $dayLabels = [
            'Mon' => 'Lun',
            'Tue' => 'Mar',
            'Wed' => 'Mer',
            'Thu' => 'Jeu',
            'Fri' => 'Ven',
            'Sat' => 'Sam',
            'Sun' => 'Dim',
        ];
        $periodLabels = [
            'morning' => 'Matin',
            'afternoon' => 'Apres-midi',
            'evening' => 'Soiree',
            'night' => 'Nuit',
        ];
        $dayCounts = [];
        $periodCounts = [];

        foreach ($saleDates as $date) {
            if (! $date) {
                continue;
            }

            $dayKey = $date->format('D');
            $dayCounts[$dayKey] = ($dayCounts[$dayKey] ?? 0) + 1;

            $hour = (int) $date->format('H');
            $periodKey = match (true) {
                $hour >= 5 && $hour < 12 => 'morning',
                $hour >= 12 && $hour < 17 => 'afternoon',
                $hour >= 17 && $hour < 21 => 'evening',
                default => 'night',
            };
            $periodCounts[$periodKey] = ($periodCounts[$periodKey] ?? 0) + 1;
        }

        $preferredDay = null;
        if ($dayCounts) {
            arsort($dayCounts);
            $preferredDayKey = array_key_first($dayCounts);
            $preferredDay = $dayLabels[$preferredDayKey] ?? $preferredDayKey;
        }

        $preferredPeriod = null;
        if ($periodCounts) {
            arsort($periodCounts);
            $preferredPeriodKey = array_key_first($periodCounts);
            $preferredPeriod = $periodLabels[$preferredPeriodKey] ?? $preferredPeriodKey;
        }

        return [
            'preferred_day' => $preferredDay,
            'preferred_period' => $preferredPeriod,
        ];
    }

    private function buildServiceData(Customer $customer, int $accountId): array
    {
        $serviceRequestCount = ServiceRequest::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->count();
        $legacyRequestCount = LeadRequest::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->count();

        $tasks = Task::query()
            ->forAccount($accountId)
            ->where('customer_id', $customer->id)
            ->with(['assignee.user:id,name'])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(CustomerReadSelects::detailTaskColumns())
            ->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'due_date' => $task->due_date,
                'completed_at' => $task->completed_at,
                'assignee' => $task->assignee?->user?->name,
            ])
            ->values();

        $upcomingJobs = Work::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->whereDate('start_date', '>=', now()->toDateString())
            ->orderBy('start_date')
            ->limit(8)
            ->get(CustomerReadSelects::detailUpcomingWorkColumns())
            ->map(fn ($work) => [
                'id' => $work->id,
                'job_title' => $work->job_title,
                'status' => $work->status,
                'start_date' => $work->start_date,
                'end_date' => $work->end_date,
                'created_at' => $work->created_at,
            ])
            ->values();

        $recentPayments = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->with('invoice:id,number')
            ->orderByRaw('CASE WHEN paid_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(CustomerReadSelects::detailPaymentColumns())
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'status' => $payment->status,
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at,
                'created_at' => $payment->created_at,
                'invoice' => $payment->invoice ? [
                    'id' => $payment->invoice_id,
                    'number' => $payment->invoice->number,
                ] : null,
            ])
            ->values();

        $totalInvoiced = (float) Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->whereNotIn('status', ['void'])
            ->sum('total');
        $totalPaid = (float) Payment::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->sum('amount');

        return [
            'stats' => [
                'active_works' => Work::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->whereIn('status', $this->activeWorkStatuses())
                    ->count(),
                'requests' => $serviceRequestCount > 0 ? $serviceRequestCount : $legacyRequestCount,
                'quotes' => Quote::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->whereNull('archived_at')
                    ->count(),
                'jobs' => Work::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->count(),
                'invoices' => Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $accountId)
                    ->count(),
            ],
            'tasks' => $tasks,
            'upcomingJobs' => $upcomingJobs,
            'recentPayments' => $recentPayments,
            'billing' => [
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'balance_due' => max(0, round($totalInvoiced - $totalPaid, 2)),
            ],
        ];
    }

    private function buildCustomerPackages(Customer $customer, int $accountId): Collection
    {
        $packages = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('customer_id', $customer->id)
            ->with([
                'offerPackage:id,name,type,status,is_recurring,recurrence_frequency,renewal_notice_days',
                'usages' => fn ($query) => $query
                    ->with('creator:id,name')
                    ->limit(5),
            ])
            ->latest('starts_at')
            ->latest('id')
            ->limit(12)
            ->get();

        $renewalInvoices = Invoice::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $packages
                ->map(fn (CustomerPackage $package): int => (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0))
                ->filter()
                ->unique()
                ->values())
            ->get(['id', 'number', 'status', 'total', 'currency_code'])
            ->keyBy('id');

        return $packages
            ->map(function (CustomerPackage $package) use ($renewalInvoices): array {
                $renewalInvoice = $renewalInvoices->get((int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0));

                return [
                'id' => $package->id,
                'offer_package_id' => $package->offer_package_id,
                'name' => data_get($package->source_details, 'offer_package.name')
                    ?: $package->offerPackage?->name
                    ?: 'Forfait',
                'status' => $package->status,
                'starts_at' => $package->starts_at,
                'expires_at' => $package->expires_at,
                'initial_quantity' => (int) $package->initial_quantity,
                'consumed_quantity' => (int) $package->consumed_quantity,
                'remaining_quantity' => (int) $package->remaining_quantity,
                'unit_type' => $package->unit_type,
                'price_paid' => (float) $package->price_paid,
                'currency_code' => $package->currency_code,
                'is_recurring' => (bool) $package->is_recurring,
                'recurrence_frequency' => $package->recurrence_frequency,
                'recurrence_status' => $package->recurrence_status,
                'current_period_starts_at' => $package->current_period_starts_at,
                'current_period_ends_at' => $package->current_period_ends_at,
                'next_renewal_at' => $package->next_renewal_at,
                'renewal_count' => (int) $package->renewal_count,
                'renewed_from_customer_package_id' => $package->renewed_from_customer_package_id,
                'renewal_invoice' => $renewalInvoice ? [
                    'id' => $renewalInvoice->id,
                    'number' => $renewalInvoice->number,
                    'status' => $renewalInvoice->status,
                    'total' => (float) $renewalInvoice->total,
                    'currency_code' => $renewalInvoice->currency_code,
                ] : null,
                'assigned_at' => $package->created_at,
                'usages' => $package->usages
                    ->map(fn ($usage): array => [
                        'id' => $usage->id,
                        'quantity' => (int) $usage->quantity,
                        'used_at' => $usage->used_at,
                        'note' => $usage->note,
                        'created_by' => $usage->creator?->name,
                    ])
                    ->values()
                    ->all(),
                ];
            })
            ->values();
    }

    private function buildCustomerPackageSummary(Customer $customer, int $accountId): array
    {
        $baseQuery = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('customer_id', $customer->id);
        $activeQuery = (clone $baseQuery)->active();

        return [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $activeQuery)->count(),
            'remaining_quantity' => (int) (clone $activeQuery)->sum('remaining_quantity'),
            'expiring_soon' => (clone $activeQuery)
                ->whereBetween('expires_at', [today()->toDateString(), today()->addDays(30)->toDateString()])
                ->count(),
        ];
    }

    private function buildCustomerPackageOptions(int $accountId): array
    {
        return OfferPackage::query()
            ->forAccount($accountId)
            ->active()
            ->where('type', OfferPackage::TYPE_FORFAIT)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'price',
                'currency_code',
                'included_quantity',
                'unit_type',
                'validity_days',
                'is_recurring',
                'recurrence_frequency',
                'renewal_notice_days',
            ])
            ->map(fn (OfferPackage $offer): array => [
                'id' => $offer->id,
                'name' => $offer->name,
                'description' => $offer->description,
                'price' => (float) $offer->price,
                'currency_code' => $offer->currency_code,
                'included_quantity' => $offer->included_quantity,
                'unit_type' => $offer->unit_type,
                'validity_days' => $offer->validity_days,
                'is_recurring' => (bool) $offer->is_recurring,
                'recurrence_frequency' => $offer->recurrence_frequency,
                'renewal_notice_days' => $offer->renewal_notice_days,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function activeWorkStatuses(): array
    {
        return array_values(array_diff(
            Work::STATUSES,
            array_merge(Work::COMPLETED_STATUSES, [Work::STATUS_CANCELLED])
        ));
    }

    private function buildActivity(Customer $customer, int $accountId, bool $isProductAccount): Collection
    {
        if ($isProductAccount) {
            return ActivityLog::query()
                ->where('subject_type', Customer::class)
                ->where('subject_id', $customer->id)
                ->with('user:id,name')
                ->latest()
                ->limit(12)
                ->get(CustomerReadSelects::detailActivityColumns())
                ->map(fn ($log) => $this->serializeActivityLog($log, 'Customer'))
                ->values();
        }

        $subjectLabels = [
            LeadRequest::class => 'Request',
            Quote::class => 'Quote',
            Work::class => 'Job',
            Invoice::class => 'Invoice',
            Payment::class => 'Payment',
            Customer::class => 'Customer',
        ];

        $requestIds = LeadRequest::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest()
            ->limit(250)
            ->pluck('id');
        $quoteIds = Quote::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest()
            ->limit(250)
            ->pluck('id');
        $workIds = Work::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest()
            ->limit(250)
            ->pluck('id');
        $invoiceIds = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest()
            ->limit(250)
            ->pluck('id');
        $paymentIds = Payment::query()
            ->where('customer_id', $customer->id)
            ->where('user_id', $accountId)
            ->latest()
            ->limit(250)
            ->pluck('id');

        return ActivityLog::query()
            ->where(function ($query) use ($customer, $requestIds, $quoteIds, $workIds, $invoiceIds, $paymentIds) {
                $query->where(function ($sub) use ($customer) {
                    $sub->where('subject_type', Customer::class)
                        ->where('subject_id', $customer->id);
                });

                if ($requestIds->isNotEmpty()) {
                    $query->orWhere(function ($sub) use ($requestIds) {
                        $sub->where('subject_type', LeadRequest::class)
                            ->whereIn('subject_id', $requestIds);
                    });
                }

                if ($quoteIds->isNotEmpty()) {
                    $query->orWhere(function ($sub) use ($quoteIds) {
                        $sub->where('subject_type', Quote::class)
                            ->whereIn('subject_id', $quoteIds);
                    });
                }

                if ($workIds->isNotEmpty()) {
                    $query->orWhere(function ($sub) use ($workIds) {
                        $sub->where('subject_type', Work::class)
                            ->whereIn('subject_id', $workIds);
                    });
                }

                if ($invoiceIds->isNotEmpty()) {
                    $query->orWhere(function ($sub) use ($invoiceIds) {
                        $sub->where('subject_type', Invoice::class)
                            ->whereIn('subject_id', $invoiceIds);
                    });
                }

                if ($paymentIds->isNotEmpty()) {
                    $query->orWhere(function ($sub) use ($paymentIds) {
                        $sub->where('subject_type', Payment::class)
                            ->whereIn('subject_id', $paymentIds);
                    });
                }
            })
            ->with('user:id,name')
            ->latest()
            ->limit(12)
            ->get(CustomerReadSelects::detailActivityColumns())
            ->map(fn ($log) => $this->serializeActivityLog(
                $log,
                $subjectLabels[$log->subject_type] ?? 'Item'
            ))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActivityLog(ActivityLog $log, string $subjectLabel): array
    {
        $properties = (array) ($log->properties ?? []);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'properties' => $properties,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'is_sales_activity' => SalesActivityTaxonomy::isSalesActivity($log->action),
            'sales_activity' => SalesActivityTaxonomy::present(
                $log->action,
                $properties
            ),
            'crm_links' => CrmActivityLinking::present(
                $log->subject_type,
                $log->subject_id,
                $properties
            ),
            'is_meeting_event' => MeetingEventTaxonomy::isMeetingEvent($log->action),
            'meeting_event' => MeetingEventTaxonomy::present(
                $log->action,
                $properties
            ),
            'is_message_event' => MessageEventTaxonomy::isMessageEvent($log->action),
            'message_event' => MessageEventTaxonomy::present(
                $log->action,
                $properties
            ),
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject' => $subjectLabel,
            'created_at' => $log->created_at,
        ];
    }
}
