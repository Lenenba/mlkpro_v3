<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\Invoice;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\OfferPackages\OfferPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OfferPackageController extends Controller
{
    public function __construct(private readonly OfferPackageService $offers) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

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
        $offer = $this->offers->create($request->user(), $this->validatedPayload($request));

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
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $accountId = (int) $user->accountOwnerId();
        if ((int) $offerPackage->user_id !== $accountId) {
            abort(404);
        }

        $offerPackage->load(['items.product']);
        $owner = User::query()->find($accountId);

        return $this->inertiaOrJson('OfferPackages/Show', [
            'offer' => $this->payload($offerPackage),
            'kpis' => $this->detailKpis($offerPackage, $accountId),
            'customers' => $this->detailCustomers($offerPackage, $accountId),
            'recentUsages' => $this->detailRecentUsages($offerPackage, $accountId),
            'tenantCurrencyCode' => $owner?->businessCurrencyCode() ?? $user->businessCurrencyCode(),
        ]);
    }

    public function update(Request $request, OfferPackage $offerPackage)
    {
        $offer = $this->offers->update($request->user(), $offerPackage, $this->validatedPayload($request));

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
        $offer = $this->offers->duplicate($request->user(), $offerPackage);

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
        $offer = $this->offers->archive($request->user(), $offerPackage);

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
        $offer = $this->offers->reactivate($request->user(), $offerPackage);

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

    private function detailKpis(OfferPackage $offerPackage, int $accountId): array
    {
        $baseQuery = CustomerPackage::query()
            ->forAccount($accountId)
            ->where('offer_package_id', $offerPackage->id);

        $initialQuantity = (int) (clone $baseQuery)->sum('initial_quantity');
        $consumedQuantity = (int) (clone $baseQuery)->sum('consumed_quantity');
        $remainingQuantity = (int) (clone $baseQuery)->sum('remaining_quantity');
        $soldCount = (clone $baseQuery)->count();
        $assignedCustomers = (clone $baseQuery)->distinct('customer_id')->count('customer_id');

        return [
            'sold_count' => $soldCount,
            'assigned_customers' => $assignedCustomers,
            'active_customers' => (clone $baseQuery)
                ->active()
                ->distinct('customer_id')
                ->count('customer_id'),
            'active_count' => (clone $baseQuery)->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
            'consumed_count' => (clone $baseQuery)->where('status', CustomerPackage::STATUS_CONSUMED)->count(),
            'expired_count' => (clone $baseQuery)->where('status', CustomerPackage::STATUS_EXPIRED)->count(),
            'cancelled_count' => (clone $baseQuery)->where('status', CustomerPackage::STATUS_CANCELLED)->count(),
            'recurring_count' => (clone $baseQuery)->recurring()->count(),
            'payment_due_count' => (clone $baseQuery)
                ->where('recurrence_status', CustomerPackage::RECURRENCE_PAYMENT_DUE)
                ->count(),
            'suspended_count' => (clone $baseQuery)
                ->where('recurrence_status', CustomerPackage::RECURRENCE_SUSPENDED)
                ->count(),
            'total_revenue' => round((float) (clone $baseQuery)->sum('price_paid'), 2),
            'average_revenue' => $soldCount > 0
                ? round((float) (clone $baseQuery)->sum('price_paid') / $soldCount, 2)
                : 0.0,
            'initial_quantity' => $initialQuantity,
            'consumed_quantity' => $consumedQuantity,
            'remaining_quantity' => $remainingQuantity,
            'usage_rate' => $initialQuantity > 0
                ? round(($consumedQuantity / $initialQuantity) * 100, 1)
                : 0.0,
            'status_breakdown' => [
                CustomerPackage::STATUS_ACTIVE => (clone $baseQuery)->where('status', CustomerPackage::STATUS_ACTIVE)->count(),
                CustomerPackage::STATUS_CONSUMED => (clone $baseQuery)->where('status', CustomerPackage::STATUS_CONSUMED)->count(),
                CustomerPackage::STATUS_EXPIRED => (clone $baseQuery)->where('status', CustomerPackage::STATUS_EXPIRED)->count(),
                CustomerPackage::STATUS_CANCELLED => (clone $baseQuery)->where('status', CustomerPackage::STATUS_CANCELLED)->count(),
            ],
        ];
    }

    private function detailCustomers(OfferPackage $offerPackage, int $accountId): array
    {
        $usagesCount = CustomerPackageUsage::query()
            ->selectRaw('count(*)')
            ->whereColumn('customer_package_usages.customer_package_id', 'customer_packages.id');
        $lastUsedAt = CustomerPackageUsage::query()
            ->select('used_at')
            ->whereColumn('customer_package_usages.customer_package_id', 'customer_packages.id')
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
            ->with([
                'customer:id,number,first_name,last_name,company_name,email,phone',
                'invoice:id,number,status,total,currency_code',
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
            ->map(function (CustomerPackage $package) use ($renewalInvoices): array {
                $customer = $package->customer;
                $renewalInvoice = $renewalInvoices->get((int) data_get($package->metadata, 'recurrence.pending_invoice_id', 0));

                return [
                    'id' => $package->id,
                    'customer' => $customer ? [
                        'id' => $customer->id,
                        'number' => $customer->number,
                        'name' => $this->customerName($customer),
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                    ] : null,
                    'invoice' => $package->invoice ? [
                        'id' => $package->invoice->id,
                        'number' => $package->invoice->number,
                        'status' => $package->invoice->status,
                        'total' => (float) $package->invoice->total,
                        'currency_code' => $package->invoice->currency_code,
                    ] : null,
                    'renewal_invoice' => $renewalInvoice ? [
                        'id' => $renewalInvoice->id,
                        'number' => $renewalInvoice->number,
                        'status' => $renewalInvoice->status,
                        'total' => (float) $renewalInvoice->total,
                        'currency_code' => $renewalInvoice->currency_code,
                    ] : null,
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

    private function detailRecentUsages(OfferPackage $offerPackage, int $accountId): array
    {
        $query = CustomerPackageUsage::query()
            ->forAccount($accountId)
            ->whereHas('customerPackage', fn ($query) => $query->where('offer_package_id', $offerPackage->id))
            ->with([
                'customer:id,number,first_name,last_name,company_name,email',
                'creator:id,name',
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
                'customer' => $usage->customer ? [
                    'id' => $usage->customer->id,
                    'number' => $usage->customer->number,
                    'name' => $this->customerName($usage->customer),
                    'email' => $usage->customer->email,
                ] : null,
                'quantity' => (int) $usage->quantity,
                'used_at' => $usage->used_at,
                'note' => $usage->note,
                'source' => data_get($usage->metadata, 'source'),
                'created_by' => $usage->creator?->name,
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
            ->whereIn('id', $invoiceIds)
            ->get(['id', 'number', 'status', 'total', 'currency_code'])
            ->keyBy('id');
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
