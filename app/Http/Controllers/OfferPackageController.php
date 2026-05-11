<?php

namespace App\Http\Controllers;

use App\Enums\CurrencyCode;
use App\Models\OfferPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\OfferPackages\OfferPackageService;
use Illuminate\Http\Request;
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

        return response()->json([
            'message' => 'Offer package duplicated.',
            'offer' => $this->payload($offer),
        ], 201);
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

        return response()->json([
            'message' => 'Offer package reactivated.',
            'offer' => $this->payload($offer),
        ]);
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
            'items' => $offer->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'item_type_snapshot' => $item->item_type_snapshot,
                'name_snapshot' => $item->name_snapshot,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sort_order' => $item->sort_order,
            ])->values()->all(),
        ];
    }
}
