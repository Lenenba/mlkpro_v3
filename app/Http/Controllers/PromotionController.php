<?php

namespace App\Http\Controllers;

use App\Enums\PromotionDiscountType;
use App\Enums\PromotionStatus;
use App\Enums\PromotionTargetType;
use App\Http\Requests\PromotionRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CompanyFeatureService;
use App\Services\Promotions\PromotionPricingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        $promotions = Promotion::query()
            ->forAccount($owner->id)
            ->withCount([
                'usages as consumed_usages_count' => fn ($query) => $query
                    ->whereHas('sale', fn ($saleQuery) => $saleQuery->where('status', '!=', 'canceled')),
            ])
            ->latest()
            ->get()
            ->map(fn (Promotion $promotion) => [
                'id' => (int) $promotion->id,
                'name' => (string) $promotion->name,
                'code' => $promotion->code ? (string) $promotion->code : null,
                'target_type' => $promotion->target_type?->value ?? PromotionTargetType::GLOBAL->value,
                'target_id' => $promotion->target_id ? (int) $promotion->target_id : null,
                'discount_type' => $promotion->discount_type?->value ?? PromotionDiscountType::PERCENTAGE->value,
                'discount_value' => (float) ($promotion->discount_value ?? 0),
                'start_date' => $promotion->start_date?->toDateString(),
                'end_date' => $promotion->end_date?->toDateString(),
                'status' => $promotion->status?->value ?? PromotionStatus::INACTIVE->value,
                'usage_limit' => $promotion->usage_limit ? (int) $promotion->usage_limit : null,
                'usage_count' => (int) ($promotion->consumed_usages_count ?? 0),
                'minimum_order_amount' => $promotion->minimum_order_amount !== null
                    ? (float) $promotion->minimum_order_amount
                    : null,
                'target_label' => $this->targetLabel($promotion, $owner->id),
                'is_currently_valid' => $this->isCurrentlyValid($promotion),
                'created_at' => $promotion->created_at?->toIso8601String(),
                'updated_at' => $promotion->updated_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Promotions/Index', [
            'promotions' => $promotions,
            'customers' => Customer::query()
                ->where('user_id', $owner->id)
                ->orderBy('company_name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'company_name', 'email'])
                ->map(fn (Customer $customer) => [
                    'id' => (int) $customer->id,
                    'label' => $customer->company_name
                        ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))
                        ?: (string) $customer->email,
                ])
                ->values(),
            'products' => Product::query()
                ->where('user_id', $owner->id)
                ->where('item_type', Product::ITEM_TYPE_PRODUCT)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product) => [
                    'id' => (int) $product->id,
                    'label' => (string) $product->name,
                ])
                ->values(),
            'services' => Product::query()
                ->where('user_id', $owner->id)
                ->where('item_type', Product::ITEM_TYPE_SERVICE)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $service) => [
                    'id' => (int) $service->id,
                    'label' => (string) $service->name,
                ])
                ->values(),
            'enums' => [
                'target_types' => PromotionTargetType::values(),
                'discount_types' => PromotionDiscountType::values(),
                'statuses' => PromotionStatus::values(),
            ],
            'activePromotionCatalog' => app(PromotionPricingService::class)->frontendCatalogForAccount($owner->id),
        ]);
    }

    public function store(PromotionRequest $request)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage) {
            abort(403);
        }

        Promotion::query()->create([
            ...$request->validated(),
            'user_id' => $owner->id,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return redirect()->route('promotions.index')->with('success', 'Promotion created.');
    }

    public function update(PromotionRequest $request, Promotion $promotion)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage || (int) $promotion->user_id !== (int) $owner->id) {
            abort(404);
        }

        $promotion->update($request->validated());

        return redirect()->route('promotions.index')->with('success', 'Promotion updated.');
    }

    public function updateStatus(Request $request, Promotion $promotion)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage || (int) $promotion->user_id !== (int) $owner->id) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', \Illuminate\Validation\Rule::in(PromotionStatus::values())],
        ]);

        $promotion->update([
            'status' => $validated['status'],
        ]);

        return redirect()->route('promotions.index')->with('success', 'Promotion status updated.');
    }

    public function destroy(Request $request, Promotion $promotion)
    {
        [$owner, $canManage] = $this->resolveAccess($request->user());
        if (! $canManage || (int) $promotion->user_id !== (int) $owner->id) {
            abort(404);
        }

        $promotion->delete();

        return redirect()->route('promotions.index')->with('success', 'Promotion deleted.');
    }

    private function resolveAccess(?User $user): array
    {
        if (! $user) {
            abort(401);
        }

        $ownerId = $user->accountOwnerId();
        $owner = $ownerId === $user->id
            ? $user
            : User::query()->find($ownerId);

        if (
            ! $owner
            || $owner->company_type !== 'products'
            || ! app(CompanyFeatureService::class)->hasFeature($owner, 'promotions')
        ) {
            abort(403);
        }

        $canManage = $user->id === $owner->id;
        if (! $canManage) {
            $membership = $user->relationLoaded('teamMembership')
                ? $user->teamMembership
                : $user->teamMembership()->first();
            $canManage = $membership?->hasPermission('sales.manage') ?? false;
        }

        return [$owner, $canManage];
    }

    private function targetLabel(Promotion $promotion, int $accountId): string
    {
        $targetType = $promotion->target_type ?? PromotionTargetType::GLOBAL;
        if ($targetType === PromotionTargetType::GLOBAL) {
            return 'All clients';
        }

        if ($targetType === PromotionTargetType::CLIENT) {
            $customer = Customer::query()
                ->where('user_id', $accountId)
                ->whereKey($promotion->target_id)
                ->first(['company_name', 'first_name', 'last_name', 'email']);

            return $customer?->company_name
                ?: trim(($customer?->first_name ?? '').' '.($customer?->last_name ?? ''))
                ?: (string) ($customer?->email ?? 'Unknown client');
        }

        $item = Product::query()
            ->where('user_id', $accountId)
            ->whereKey($promotion->target_id)
            ->first(['name']);

        return (string) ($item?->name ?? 'Unknown item');
    }

    private function isCurrentlyValid(Promotion $promotion): bool
    {
        if (($promotion->status?->value ?? null) !== PromotionStatus::ACTIVE->value) {
            return false;
        }

        $today = now()->toDateString();

        return $promotion->start_date
            && $promotion->end_date
            && $promotion->start_date->toDateString() <= $today
            && $promotion->end_date->toDateString() >= $today;
    }
}
