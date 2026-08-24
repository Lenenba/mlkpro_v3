<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OfferPackage;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\OfferPackages\OfferPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OfferPackageController extends Controller
{
    public function __construct(private readonly OfferPackageService $offers) {}

    public function index(Request $request)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $accountId = $user->accountOwnerId();
        $filters = $request->only(['search', 'type', 'status', 'is_public', 'sort', 'direction']);
        $filters['per_page'] = $this->resolveDataTablePerPage($request);

        $baseQuery = OfferPackage::query()
            ->forAccount($accountId)
            ->filter($filters);

        $sort = in_array($filters['sort'] ?? null, ['name', 'price', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $offers = (clone $baseQuery)
            ->with(['items.product'])
            ->withCount('items')
            ->orderBy($sort, $direction)
            ->paginate((int) $filters['per_page'])
            ->withQueryString();

        $statsBase = OfferPackage::query()->forAccount($accountId);
        $owner = User::query()->find($accountId);

        return $this->inertiaOrJson('OfferPackages/Index', [
            'filters' => $filters,
            'offers' => $offers,
            'stats' => [
                'total' => (clone $statsBase)->count(),
                'active' => (clone $statsBase)->where('status', OfferPackage::STATUS_ACTIVE)->count(),
                'packs' => (clone $statsBase)->where('type', OfferPackage::TYPE_PACK)->count(),
                'forfaits' => (clone $statsBase)->where('type', OfferPackage::TYPE_FORFAIT)->count(),
                'public' => (clone $statsBase)->where('is_public', true)->count(),
            ],
            'reporting' => $this->reporting($accountId),
            'catalogItems' => $this->catalogItems($accountId),
            'options' => [
                'types' => OfferPackage::types(),
                'statuses' => OfferPackage::statuses(),
                'unit_types' => OfferPackage::unitTypes(),
                'recurrence_frequencies' => OfferPackage::recurrenceFrequencies(),
                'currencies' => CurrencyCode::values(),
            ],
            'tenantCurrencyCode' => $owner?->businessCurrencyCode() ?? $user->businessCurrencyCode(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $offer = $this->offers->create($user, $this->validatedPayload($request));

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Offer package created.',
                'offer' => $this->payload($offer),
            ], 201);
        }

        return redirect()->route('offer-packages.index')->with('success', 'Offer package created.');
    }

    public function show(Request $request, OfferPackage $offerPackage)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $accountId = (int) $user->accountOwnerId();
        if ((int) $offerPackage->user_id !== $accountId) {
            abort(404);
        }

        $offerPackage->load(['items.product']);
        $owner = User::query()->find($accountId);
        $packDetail = $offerPackage->type === OfferPackage::TYPE_PACK
            ? $this->detailPack($offerPackage, $accountId, $user)
            : null;

        return $this->inertiaOrJson('OfferPackages/Show', [
            'offer' => $this->payload($offerPackage),
            'kpis' => $packDetail['kpis'] ?? $this->detailKpis($offerPackage, $accountId),
            'sales' => $packDetail['sales'] ?? [],
            'sales_meta' => $packDetail['sales_meta'] ?? [
                'total' => 0,
                'displayed' => 0,
            ],
            'customers' => $offerPackage->type === OfferPackage::TYPE_FORFAIT
                ? $this->detailCustomers($offerPackage, $accountId, $user)
                : [],
            'recentUsages' => $offerPackage->type === OfferPackage::TYPE_FORFAIT
                ? $this->detailRecentUsages($offerPackage, $accountId, $user)
                : [],
            'tenantCurrencyCode' => $owner?->businessCurrencyCode() ?? $user->businessCurrencyCode(),
        ]);
    }

    public function update(Request $request, OfferPackage $offerPackage)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $offer = $this->offers->update($user, $offerPackage, $this->validatedPayload($request));

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Offer package updated.',
                'offer' => $this->payload($offer),
            ]);
        }

        return redirect()->route('offer-packages.index')->with('success', 'Offer package updated.');
    }

    public function duplicate(Request $request, OfferPackage $offerPackage)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $offer = $this->offers->duplicate($user, $offerPackage);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Offer package duplicated.',
                'offer' => $this->payload($offer),
            ], 201);
        }

        return redirect()
            ->route('offer-packages.index')
            ->with('success', 'Offer package duplicated.');
    }

    public function destroy(Request $request, OfferPackage $offerPackage)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $offer = $this->offers->archive($user, $offerPackage);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Offer package archived.',
                'offer' => $this->payload($offer),
            ]);
        }

        return redirect()->route('offer-packages.index')->with('success', 'Offer package archived.');
    }

    public function restore(Request $request, OfferPackage $offerPackage)
    {
        $user = $this->authorizeOfferPackageAccess($request);

        $offer = $this->offers->reactivate($user, $offerPackage);

        if ($this->shouldReturnJson($request)) {
            return response()->json([
                'message' => 'Offer package reactivated.',
                'offer' => $this->payload($offer),
            ]);
        }

        return redirect()
            ->route('offer-packages.index')
            ->with('success', 'Offer package reactivated.');
    }

    private function authorizeOfferPackageAccess(Request $request): User
    {
        $user = $request->user();
        if (! $user || ! $this->canManageOfferPackages($user)) {
            abort(403);
        }

        return $user;
    }

    private function canManageOfferPackages(User $user): bool
    {
        if ($user->isClient()) {
            return false;
        }

        if ($user->isAccountOwner()) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : $user->teamMembership()->with('companyRole.permissions')->first();

        if (! $membership) {
            return false;
        }

        $membership->loadMissing('companyRole.permissions');

        return $membership->hasPermission('sales.manage')
            || $membership->hasPermission('quotes.edit')
            || $membership->hasPermission('services.edit')
            || $membership->hasPermission('products.edit');
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', Rule::in(OfferPackage::types())],
            'status' => ['nullable', 'string', Rule::in(OfferPackage::statuses())],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_path' => ['nullable', 'string', 'max:2048'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'currency_code' => ['nullable', 'string', Rule::in(CurrencyCode::values())],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'included_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'unit_type' => ['nullable', 'string', Rule::in(OfferPackage::unitTypes())],
            'is_public' => ['sometimes', 'boolean'],
            'is_recurring' => ['sometimes', 'boolean'],
            'recurrence_frequency' => ['nullable', 'string', Rule::in(OfferPackage::recurrenceFrequencies())],
            'renewal_notice_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'payment_grace_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'payment_reminder_days' => ['nullable', 'array', 'max:12'],
            'payment_reminder_days.*' => ['integer', 'min:0', 'max:365'],
            'carry_over_unused_balance' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.is_optional' => ['sometimes', 'boolean', 'declined'],
        ]);
    }

    private function catalogItems(int $accountId): array
    {
        return Product::query()
            ->byUser($accountId)
            ->where('is_active', true)
            ->orderBy('item_type')
            ->orderBy('name')
            ->get(['id', 'name', 'item_type', 'price', 'currency_code', 'unit'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'item_type' => $product->item_type,
                'price' => (float) $product->price,
                'currency_code' => $product->currency_code,
                'unit' => $product->unit,
            ])
            ->all();
    }

    private function payload(OfferPackage $offer): array
    {
        $offer->loadMissing(['items.product']);

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'slug' => $offer->slug,
            'type' => $offer->type,
            'status' => $offer->status,
            'description' => $offer->description,
            'image_path' => $offer->image_path,
            'price' => (float) $offer->price,
            'currency_code' => $offer->currency_code,
            'validity_days' => $offer->validity_days,
            'included_quantity' => $offer->included_quantity,
            'unit_type' => $offer->unit_type,
            'is_public' => (bool) $offer->is_public,
            'is_recurring' => (bool) $offer->is_recurring,
            'recurrence_frequency' => $offer->recurrence_frequency,
            'renewal_notice_days' => $offer->renewal_notice_days,
            'payment_grace_days' => (int) data_get($offer->metadata, 'recurrence.payment_grace_days', 7),
            'payment_reminder_days' => array_values((array) data_get($offer->metadata, 'recurrence.payment_reminder_days', [0, 3, 6])),
            'carry_over_unused_balance' => (bool) data_get($offer->metadata, 'recurrence.carry_over_unused_balance', false),
            'items_count' => $offer->items->count(),
            'created_at' => $offer->created_at,
            'updated_at' => $offer->updated_at,
            'items' => $offer->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'item_type_snapshot' => $item->item_type_snapshot,
                'name_snapshot' => $item->name_snapshot,
                'product_name' => $item->product?->name,
                'product_type' => $item->product?->item_type,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sort_order' => $item->sort_order,
            ])->values()->all(),
        ];
    }

    private function reporting(int $accountId): array
    {
        $invoiceLines = InvoiceItem::query()
            ->whereHas('invoice', fn ($query) => $query
                ->where('user_id', $accountId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'void'))
            ->get(['id', 'invoice_id', 'title', 'quantity', 'unit_price', 'total', 'currency_code', 'meta', 'created_at'])
            ->filter(fn (InvoiceItem $item): bool => (int) data_get($item->meta, 'offer_package_id') > 0
                && in_array(data_get($item->meta, 'offer_package_type'), OfferPackage::types(), true));

        $packLines = $invoiceLines
            ->filter(fn (InvoiceItem $item): bool => data_get($item->meta, 'offer_package_type') === OfferPackage::TYPE_PACK)
            ->values();

        $forfaitPackages = CustomerPackage::query()
            ->forAccount($accountId)
            ->with('offerPackage:id,name,type')
            ->get([
                'id',
                'customer_id',
                'offer_package_id',
                'status',
                'initial_quantity',
                'consumed_quantity',
                'remaining_quantity',
                'price_paid',
                'currency_code',
                'is_recurring',
                'recurrence_status',
                'renewal_count',
                'renewed_from_customer_package_id',
                'source_details',
                'metadata',
                'created_at',
            ]);

        $recurringPackages = $forfaitPackages
            ->filter(fn (CustomerPackage $package): bool => (bool) $package->is_recurring)
            ->values();

        return [
            'sales' => [
                'packs' => $this->invoiceLineReport($packLines),
                'consumable_forfaits' => $this->forfaitSalesReport($forfaitPackages),
            ],
            'recurring' => $this->recurringReport($recurringPackages),
            'carry_over' => $this->carryOverReport($forfaitPackages),
            'top_offers' => $this->topOfferRows($packLines, $forfaitPackages),
        ];
    }

    private function invoiceLineReport(Collection $lines): array
    {
        $soldCount = (float) $lines->sum(fn (InvoiceItem $line): float => (float) $line->quantity);
        $revenue = round((float) $lines->sum(fn (InvoiceItem $line): float => (float) $line->total), 2);

        return [
            'sold_count' => $this->reportQuantity($soldCount),
            'line_count' => $lines->count(),
            'revenue' => $revenue,
            'average_revenue' => $soldCount > 0 ? round($revenue / $soldCount, 2) : 0.0,
        ];
    }

    private function forfaitSalesReport(Collection $packages): array
    {
        $soldCount = $packages->count();
        $initialQuantity = (int) $packages->sum(fn (CustomerPackage $package): int => (int) $package->initial_quantity);
        $consumedQuantity = (int) $packages->sum(fn (CustomerPackage $package): int => (int) $package->consumed_quantity);
        $revenue = round((float) $packages->sum(fn (CustomerPackage $package): float => (float) $package->price_paid), 2);

        return [
            'sold_count' => $soldCount,
            'assigned_customers' => $packages->pluck('customer_id')->filter()->unique()->count(),
            'active_count' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_ACTIVE)
                ->count(),
            'consumed_count' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_CONSUMED)
                ->count(),
            'expired_count' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_EXPIRED)
                ->count(),
            'cancelled_count' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_CANCELLED)
                ->count(),
            'revenue' => $revenue,
            'average_revenue' => $soldCount > 0 ? round($revenue / $soldCount, 2) : 0.0,
            'initial_quantity' => $initialQuantity,
            'consumed_quantity' => $consumedQuantity,
            'remaining_quantity' => (int) $packages->sum(fn (CustomerPackage $package): int => (int) $package->remaining_quantity),
            'usage_rate' => $initialQuantity > 0 ? round(($consumedQuantity / $initialQuantity) * 100, 1) : 0.0,
        ];
    }

    private function recurringReport(Collection $packages): array
    {
        return [
            'total' => $packages->count(),
            'active' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_ACTIVE
                    && in_array($package->recurrence_status, [null, CustomerPackage::RECURRENCE_ACTIVE], true))
                ->count(),
            'payment_due' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->recurrence_status === CustomerPackage::RECURRENCE_PAYMENT_DUE)
                ->count(),
            'suspended' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->recurrence_status === CustomerPackage::RECURRENCE_SUSPENDED)
                ->count(),
            'cancelled' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->recurrence_status === CustomerPackage::RECURRENCE_CANCELLED
                    || $package->status === CustomerPackage::STATUS_CANCELLED)
                ->count(),
            'expired' => $packages
                ->filter(fn (CustomerPackage $package): bool => $package->status === CustomerPackage::STATUS_EXPIRED)
                ->count(),
            'renewed' => $packages
                ->filter(fn (CustomerPackage $package): bool => (int) $package->renewed_from_customer_package_id > 0)
                ->count(),
        ];
    }

    private function carryOverReport(Collection $packages): array
    {
        $carriedPackages = $packages
            ->map(fn (CustomerPackage $package): array => [
                'quantity' => (int) data_get($package->metadata, 'recurrence.carried_over_quantity', 0),
                'remaining_quantity' => (int) $package->remaining_quantity,
            ])
            ->filter(fn (array $package): bool => $package['quantity'] > 0)
            ->values();

        return [
            'packages_count' => $carriedPackages->count(),
            'quantity' => (int) $carriedPackages->sum('quantity'),
            'remaining_quantity' => (int) $carriedPackages->sum('remaining_quantity'),
        ];
    }

    private function topOfferRows(Collection $packLines, Collection $forfaitPackages): array
    {
        $rows = collect();

        $packLines
            ->groupBy(fn (InvoiceItem $line): int => (int) data_get($line->meta, 'offer_package_id'))
            ->each(function (Collection $lines, int $offerId) use ($rows): void {
                $soldCount = (float) $lines->sum(fn (InvoiceItem $line): float => (float) $line->quantity);
                $revenue = round((float) $lines->sum(fn (InvoiceItem $line): float => (float) $line->total), 2);
                $firstLine = $lines->first();

                $rows->push([
                    'id' => $offerId,
                    'name' => (string) (data_get($firstLine?->meta, 'offer_package_snapshot.name') ?: $firstLine?->title ?: 'Pack #'.$offerId),
                    'type' => OfferPackage::TYPE_PACK,
                    'sold_count' => $this->reportQuantity($soldCount),
                    'revenue' => $revenue,
                ]);
            });

        $forfaitPackages
            ->groupBy(fn (CustomerPackage $package): int => (int) $package->offer_package_id)
            ->each(function (Collection $packages, int $offerId) use ($rows): void {
                $firstPackage = $packages->first();
                $rows->push([
                    'id' => $offerId,
                    'name' => (string) ($firstPackage?->offerPackage?->name ?: data_get($firstPackage?->source_details, 'offer_package.name') ?: 'Forfait #'.$offerId),
                    'type' => OfferPackage::TYPE_FORFAIT,
                    'sold_count' => $packages->count(),
                    'revenue' => round((float) $packages->sum(fn (CustomerPackage $package): float => (float) $package->price_paid), 2),
                ]);
            });

        return $rows
            ->sortByDesc('revenue')
            ->take(5)
            ->values()
            ->all();
    }

    private function reportQuantity(float $quantity): int|float
    {
        $rounded = round($quantity, 2);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * Packs are sales lines, not consumable customer packages. Their detail sheet
     * therefore follows the invoice line snapshot that was recorded at sale time.
     *
     * @return array{kpis: array<string, mixed>, sales: array<int, array<string, mixed>>, sales_meta: array{total: int, displayed: int}}
     */
    private function detailPack(OfferPackage $offerPackage, int $accountId, User $actor): array
    {
        $lines = InvoiceItem::query()
            ->where('meta->offer_package_id', $offerPackage->id)
            ->where('meta->offer_package_type', OfferPackage::TYPE_PACK)
            ->whereHas('invoice', fn ($query) => $query
                ->where('user_id', $accountId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'void'))
            ->with([
                'invoice' => fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->whereNull('deleted_at')
                    ->with([
                        'customer' => fn ($query) => $query
                            ->where('user_id', $accountId)
                            ->select(['id', 'user_id', 'number', 'first_name', 'last_name', 'company_name', 'email', 'phone']),
                        'payments' => fn ($query) => $query
                            ->where('user_id', $accountId)
                            ->select(['id', 'invoice_id', 'user_id', 'status', 'amount', 'currency_code', 'paid_at', 'method', 'created_at'])
                            ->latest('paid_at')
                            ->latest('id'),
                    ]),
            ])
            ->latest('created_at')
            ->latest('id')
            ->get([
                'id',
                'invoice_id',
                'title',
                'quantity',
                'unit_price',
                'total',
                'currency_code',
                'meta',
                'created_at',
            ])
            ->filter(fn (InvoiceItem $line): bool => $line->invoice instanceof Invoice)
            ->values();

        $facts = $lines->map(fn (InvoiceItem $line): array => $this->packLineFacts(
            $line,
            $offerPackage,
            $accountId
        ));
        $invoices = $lines
            ->pluck('invoice')
            ->filter(fn ($invoice): bool => $invoice instanceof Invoice)
            ->unique('id')
            ->values();
        $currencyBreakdown = $facts
            ->groupBy('currency_code')
            ->map(function (Collection $currencyFacts, string $currencyCode): array {
                $quantity = (float) $currencyFacts->sum('quantity');
                $billed = round((float) $currencyFacts->sum('total'), 2);
                $collected = round((float) $currencyFacts->sum('collected_amount'), 2);

                return [
                    'currency_code' => $currencyCode,
                    'sold_count' => $this->reportQuantity($quantity),
                    'total_billed' => $billed,
                    'total_collected' => $collected,
                    'balance_due' => max(0, round($billed - $collected, 2)),
                    'average_revenue' => $quantity > 0 ? round($billed / $quantity, 2) : 0.0,
                ];
            })
            ->sortKeys()
            ->values();
        $primaryCurrency = $this->currencyCode($offerPackage->currency_code);
        $primaryTotals = $currencyBreakdown
            ->firstWhere('currency_code', $primaryCurrency) ?? [
                'sold_count' => 0,
                'total_billed' => 0.0,
                'total_collected' => 0.0,
                'balance_due' => 0.0,
                'average_revenue' => 0.0,
            ];
        $paidInvoiceCount = $invoices
            ->filter(fn (Invoice $invoice): bool => $this->invoiceIsPaid($invoice, $accountId))
            ->count();
        $outstandingInvoiceCount = $invoices
            ->reject(fn (Invoice $invoice): bool => $this->invoiceIsPaid($invoice, $accountId))
            ->count();

        return [
            'kpis' => [
                'sold_count' => $this->reportQuantity((float) $facts->sum('quantity')),
                'invoice_count' => $invoices->count(),
                'assigned_customers' => $invoices
                    ->filter(fn (Invoice $invoice): bool => $invoice->customer instanceof Customer)
                    ->pluck('customer_id')
                    ->filter()
                    ->unique()
                    ->count(),
                'active_customers' => 0,
                'active_count' => 0,
                'consumed_count' => 0,
                'expired_count' => 0,
                'cancelled_count' => 0,
                'recurring_count' => 0,
                'payment_due_count' => 0,
                'suspended_count' => 0,
                'total_revenue' => (float) $primaryTotals['total_billed'],
                'total_billed' => (float) $primaryTotals['total_billed'],
                'total_collected' => (float) $primaryTotals['total_collected'],
                'balance_due' => (float) $primaryTotals['balance_due'],
                'average_revenue' => (float) $primaryTotals['average_revenue'],
                'initial_quantity' => 0,
                'consumed_quantity' => 0,
                'remaining_quantity' => 0,
                'usage_rate' => 0.0,
                'paid_invoice_count' => $paidInvoiceCount,
                'outstanding_invoice_count' => $outstandingInvoiceCount,
                'currency_code' => $primaryCurrency,
                'has_mixed_currencies' => $currencyBreakdown->count() > 1,
                'currency_breakdown' => $currencyBreakdown->all(),
                'status_breakdown' => $invoices
                    ->countBy(fn (Invoice $invoice): string => (string) $invoice->status)
                    ->all(),
            ],
            'sales' => $lines
                ->take(25)
                ->map(fn (InvoiceItem $line): array => $this->packSalePayload($line, $offerPackage, $accountId, $actor))
                ->all(),
            'sales_meta' => [
                'total' => $lines->count(),
                'displayed' => min(25, $lines->count()),
            ],
        ];
    }

    /**
     * @return array{quantity: float, total: float, collected_amount: float, balance_due: float, currency_code: string}
     */
    private function packLineFacts(InvoiceItem $line, OfferPackage $offerPackage, int $accountId): array
    {
        /** @var Invoice $invoice */
        $invoice = $line->invoice;
        $lineTotal = round((float) $line->total, 2);
        $invoiceTotal = round((float) $invoice->total, 2);
        $settledAmount = $this->settledInvoiceAmount($invoice, $accountId);
        $coverage = $invoiceTotal > 0
            ? min(1, max(0, $settledAmount / $invoiceTotal))
            : ($this->invoiceIsPaid($invoice, $accountId) ? 1.0 : 0.0);
        $collectedAmount = round($lineTotal * $coverage, 2);

        return [
            'quantity' => (float) $line->quantity,
            'total' => $lineTotal,
            'collected_amount' => $collectedAmount,
            'balance_due' => max(0, round($lineTotal - $collectedAmount, 2)),
            'currency_code' => $this->currencyCode(
                $line->currency_code ?: $invoice->currency_code ?: $offerPackage->currency_code
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packSalePayload(
        InvoiceItem $line,
        OfferPackage $offerPackage,
        int $accountId,
        User $actor
    ): array {
        /** @var Invoice $invoice */
        $invoice = $line->invoice;
        $facts = $this->packLineFacts($line, $offerPackage, $accountId);
        $canViewInvoice = $actor->can('view', $invoice);
        $payments = $this->eligibleInvoicePayments($invoice, $accountId);
        $settledPayments = $payments
            ->whereIn('status', Payment::settledStatuses())
            ->values();

        return [
            'id' => $line->id,
            'sold_at' => $line->created_at,
            'quantity' => $this->reportQuantity((float) $line->quantity),
            'unit_price' => (float) $line->unit_price,
            'total' => $facts['total'],
            'collected_amount' => $facts['collected_amount'],
            'balance_due' => $facts['balance_due'],
            'currency_code' => $facts['currency_code'],
            'customer' => $this->customerReference($invoice->customer, $accountId, $actor),
            'invoice' => [
                'id' => $canViewInvoice ? $invoice->id : null,
                'number' => $canViewInvoice ? $invoice->number : null,
                'status' => $invoice->status,
                'total' => $canViewInvoice ? (float) $invoice->total : null,
                'currency_code' => $this->currencyCode($invoice->currency_code ?: $facts['currency_code']),
                'issued_at' => $invoice->created_at,
                'paid_at' => $canViewInvoice ? $settledPayments->max('paid_at') : null,
                'amount_paid' => $canViewInvoice ? $this->settledInvoiceAmount($invoice, $accountId) : null,
                'balance_due' => $canViewInvoice
                    ? max(0, round((float) $invoice->total - $this->settledInvoiceAmount($invoice, $accountId), 2))
                    : null,
                'can_view' => $canViewInvoice,
                'href' => $canViewInvoice ? route('invoice.show', $invoice) : null,
            ],
            'payments' => $canViewInvoice
                ? $payments
                    ->map(fn (Payment $payment): array => [
                        'id' => $payment->id,
                        'status' => $payment->status,
                        'amount' => (float) $payment->amount,
                        'currency_code' => $this->currencyCode($payment->currency_code ?: $invoice->currency_code),
                        'paid_at' => $payment->paid_at,
                        'method' => $payment->method,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return Collection<int, Payment>
     */
    private function eligibleInvoicePayments(Invoice $invoice, int $accountId): Collection
    {
        $invoiceCurrency = CurrencyCode::tryFromMixed($invoice->currency_code)?->value;

        if ($invoiceCurrency === null) {
            return collect();
        }

        return $invoice->payments
            ->filter(fn (Payment $payment): bool => (int) $payment->user_id === $accountId
                && CurrencyCode::tryFromMixed($payment->currency_code)?->value === $invoiceCurrency)
            ->values();
    }

    private function settledInvoiceAmount(Invoice $invoice, int $accountId): float
    {
        return round((float) $this->eligibleInvoicePayments($invoice, $accountId)
            ->whereIn('status', Payment::settledStatuses())
            ->sum('amount'), 2);
    }

    private function invoiceIsPaid(Invoice $invoice, int $accountId): bool
    {
        $invoiceTotal = round((float) $invoice->total, 2);

        return $invoice->status === 'paid'
            || ($invoiceTotal > 0 && $this->settledInvoiceAmount($invoice, $accountId) >= $invoiceTotal);
    }

    private function detailKpis(OfferPackage $offerPackage, int $accountId): array
    {
        $packages = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('offer_package_id', $offerPackage->id)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $accountId))
            ->get([
                'id',
                'customer_id',
                'invoice_id',
                'status',
                'initial_quantity',
                'consumed_quantity',
                'remaining_quantity',
                'price_paid',
                'currency_code',
                'is_recurring',
                'recurrence_status',
            ]);
        $initialQuantity = (int) $packages->sum('initial_quantity');
        $consumedQuantity = (int) $packages->sum('consumed_quantity');
        $remainingQuantity = (int) $packages->sum('remaining_quantity');
        $soldCount = $packages->count();
        $primaryCurrency = $this->currencyCode($offerPackage->currency_code);
        $currencyBreakdown = $packages
            ->groupBy(fn (CustomerPackage $package): string => $this->currencyCode(
                $package->currency_code ?: $primaryCurrency
            ))
            ->map(function (Collection $currencyPackages, string $currencyCode): array {
                $revenue = round((float) $currencyPackages->sum('price_paid'), 2);
                $count = $currencyPackages->count();

                return [
                    'currency_code' => $currencyCode,
                    'sold_count' => $count,
                    'total_billed' => $revenue,
                    'total_collected' => $revenue,
                    'balance_due' => 0.0,
                    'average_revenue' => $count > 0 ? round($revenue / $count, 2) : 0.0,
                ];
            })
            ->sortKeys()
            ->values();
        $primaryTotals = $currencyBreakdown
            ->firstWhere('currency_code', $primaryCurrency) ?? [
                'total_billed' => 0.0,
                'total_collected' => 0.0,
                'balance_due' => 0.0,
                'average_revenue' => 0.0,
            ];

        return [
            'sold_count' => $soldCount,
            'invoice_count' => $packages->pluck('invoice_id')->filter()->unique()->count(),
            'assigned_customers' => $packages->pluck('customer_id')->filter()->unique()->count(),
            'active_customers' => $packages
                ->where('status', CustomerPackage::STATUS_ACTIVE)
                ->pluck('customer_id')
                ->filter()
                ->unique()
                ->count(),
            'active_count' => $packages->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
            'consumed_count' => $packages->where('status', CustomerPackage::STATUS_CONSUMED)->count(),
            'expired_count' => $packages->where('status', CustomerPackage::STATUS_EXPIRED)->count(),
            'cancelled_count' => $packages->where('status', CustomerPackage::STATUS_CANCELLED)->count(),
            'recurring_count' => $packages->where('is_recurring', true)->count(),
            'payment_due_count' => $packages
                ->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)
                ->count(),
            'suspended_count' => $packages
                ->where('recurrence_status', CustomerPackage::RECURRENCE_SUSPENDED)
                ->count(),
            'total_revenue' => (float) $primaryTotals['total_billed'],
            'total_billed' => (float) $primaryTotals['total_billed'],
            'total_collected' => (float) $primaryTotals['total_collected'],
            'balance_due' => (float) $primaryTotals['balance_due'],
            'average_revenue' => (float) $primaryTotals['average_revenue'],
            'initial_quantity' => $initialQuantity,
            'consumed_quantity' => $consumedQuantity,
            'remaining_quantity' => $remainingQuantity,
            'usage_rate' => $initialQuantity > 0
                ? round(($consumedQuantity / $initialQuantity) * 100, 1)
                : 0.0,
            'paid_invoice_count' => 0,
            'outstanding_invoice_count' => 0,
            'currency_code' => $primaryCurrency,
            'has_mixed_currencies' => $currencyBreakdown->count() > 1,
            'currency_breakdown' => $currencyBreakdown->all(),
            'status_breakdown' => [
                CustomerPackage::STATUS_ACTIVE => $packages->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
                CustomerPackage::STATUS_CONSUMED => $packages->where('status', CustomerPackage::STATUS_CONSUMED)->count(),
                CustomerPackage::STATUS_EXPIRED => $packages->where('status', CustomerPackage::STATUS_EXPIRED)->count(),
                CustomerPackage::STATUS_CANCELLED => $packages->where('status', CustomerPackage::STATUS_CANCELLED)->count(),
            ],
        ];
    }

    private function detailCustomers(OfferPackage $offerPackage, int $accountId, User $actor): array
    {
        $usagesCount = CustomerPackageUsage::query()
            ->selectRaw('count(*)')
            ->where('customer_package_usages.user_id', $accountId)
            ->whereColumn('customer_package_usages.customer_package_id', 'customer_packages.id')
            ->whereColumn('customer_package_usages.customer_id', 'customer_packages.customer_id');
        $lastUsedAt = CustomerPackageUsage::query()
            ->select('used_at')
            ->where('customer_package_usages.user_id', $accountId)
            ->whereColumn('customer_package_usages.customer_package_id', 'customer_packages.id')
            ->whereColumn('customer_package_usages.customer_id', 'customer_packages.customer_id')
            ->latest('used_at')
            ->latest('id')
            ->limit(1);

        if ($this->usageReversalColumnExists()) {
            $usagesCount->whereNull('reversed_at');
            $lastUsedAt->whereNull('reversed_at');
        }

        $packages = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('offer_package_id', $offerPackage->id)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $accountId))
            ->with([
                'customer' => fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->select(['id', 'user_id', 'number', 'first_name', 'last_name', 'company_name', 'email', 'phone']),
                'invoice' => fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->whereNull('deleted_at')
                    ->select(['id', 'user_id', 'number', 'status', 'total', 'currency_code']),
            ])
            ->addSelect([
                'usages_count' => $usagesCount,
                'last_used_at' => $lastUsedAt,
            ])
            ->latest('starts_at')
            ->latest('id')
            ->limit(15)
            ->get();

        $renewalInvoices = $this->renewalInvoicesFor($packages, $accountId);

        return $packages
            ->map(function (CustomerPackage $package) use ($accountId, $actor, $renewalInvoices): array {
                $customer = $package->customer;
                $renewalInvoice = $renewalInvoices->get((int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0));

                return [
                    'id' => $package->id,
                    'customer' => $this->customerReference($customer, $accountId, $actor),
                    'invoice' => $this->invoiceReference($package->invoice, $accountId, $actor),
                    'renewal_invoice' => $this->invoiceReference($renewalInvoice, $accountId, $actor),
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
                    'recurrence_status' => $package->recurrence_status,
                    'next_renewal_at' => $package->next_renewal_at,
                    'usages_count' => (int) $package->usages_count,
                    'last_used_at' => $package->last_used_at,
                    'assigned_at' => $package->created_at,
                ];
            })
            ->values()
            ->all();
    }

    private function detailRecentUsages(OfferPackage $offerPackage, int $accountId, User $actor): array
    {
        $query = CustomerPackageUsage::query()
            ->forAccount($accountId)
            ->whereHas('customer', fn ($query) => $query->where('user_id', $accountId))
            ->whereHas('customerPackage', fn ($query) => $query
                ->forAccount($accountId)
                ->where('offer_package_id', $offerPackage->id))
            ->with([
                'customer' => fn ($query) => $query
                    ->where('user_id', $accountId)
                    ->select(['id', 'user_id', 'number', 'first_name', 'last_name', 'company_name', 'email', 'phone']),
                'creator:id,name',
                'creator.teamMembership:id,user_id,account_id,is_active',
            ]);

        if ($this->usageReversalColumnExists()) {
            $query->whereNull('reversed_at');
        }

        return $query
            ->latest('used_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (CustomerPackageUsage $usage): array => [
                'id' => $usage->id,
                'customer_package_id' => $usage->customer_package_id,
                'customer' => $this->customerReference($usage->customer, $accountId, $actor),
                'quantity' => (int) $usage->quantity,
                'used_at' => $usage->used_at,
                'note' => $usage->note,
                'source' => data_get($usage->metadata, 'source'),
                'created_by' => $this->userBelongsToAccount($usage->creator, $accountId)
                    ? $usage->creator?->name
                    : null,
            ])
            ->values()
            ->all();
    }

    private function renewalInvoicesFor($packages, int $accountId)
    {
        $invoiceIds = $packages
            ->map(fn (CustomerPackage $package): int => (int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0))
            ->filter()
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->where('user_id', $accountId)
            ->whereNull('deleted_at')
            ->whereIn('id', $invoiceIds)
            ->get(['id', 'user_id', 'number', 'status', 'total', 'currency_code'])
            ->keyBy('id');
    }

    /**
     * Return customer data only when the actor can open the customer record.
     * A hostile cross-tenant foreign key is treated as an unavailable customer.
     *
     * @return array<string, mixed>|null
     */
    private function customerReference(?Customer $customer, int $accountId, User $actor): ?array
    {
        if (! $customer || (int) $customer->user_id !== $accountId) {
            return null;
        }

        $canView = $actor->can('view', $customer);

        return [
            'id' => $canView ? $customer->id : null,
            'number' => $canView ? $customer->number : null,
            'name' => $canView ? $this->customerName($customer) : null,
            'email' => $canView ? $customer->email : null,
            'phone' => $canView ? $customer->phone : null,
            'can_view' => $canView,
            'href' => $canView ? route('customer.show', $customer) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoiceReference(?Invoice $invoice, int $accountId, User $actor): ?array
    {
        if (! $invoice
            || (int) $invoice->user_id !== $accountId
            || $invoice->deleted_at !== null) {
            return null;
        }

        $canView = $actor->can('view', $invoice);

        return [
            'id' => $canView ? $invoice->id : null,
            'number' => $canView ? $invoice->number : null,
            'status' => $invoice->status,
            'total' => $canView ? (float) $invoice->total : null,
            'currency_code' => $this->currencyCode($invoice->currency_code),
            'can_view' => $canView,
            'href' => $canView ? route('invoice.show', $invoice) : null,
        ];
    }

    private function userBelongsToAccount(?User $user, int $accountId): bool
    {
        if (! $user) {
            return false;
        }

        if ((int) $user->id === $accountId) {
            return true;
        }

        $membership = $user->relationLoaded('teamMembership')
            ? $user->teamMembership
            : null;

        return $membership
            && (int) $membership->account_id === $accountId;
    }

    private function currencyCode(mixed $currencyCode): string
    {
        return CurrencyCode::tryFromMixed($currencyCode)?->value
            ?? CurrencyCode::default()->value;
    }

    private function customerName($customer): string
    {
        return (string) (
            $customer->company_name
            ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
            ?: $customer->email
            ?: 'Client #'.$customer->id
        );
    }

    private function usageReversalColumnExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = Schema::hasColumn('customer_package_usages', 'reversed_at');
        }

        return $exists;
    }
}
