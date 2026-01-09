<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductLot;
use App\Models\ProductStockMovement;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Models\Warehouse;
use App\Services\NotificationPreferenceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class InventoryService
{
    public function resolveDefaultWarehouse(int $accountId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->forAccount($accountId)
            ->default()
            ->first();

        if ($warehouse) {
            return $warehouse;
        }

        $fallback = Warehouse::query()->forAccount($accountId)->first();
        if ($fallback) {
            $fallback->update(['is_default' => true]);
            return $fallback;
        }

        return Warehouse::create([
            'user_id' => $accountId,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function adjust(Product $product, int $quantity, string $type, array $context = []): ProductStockMovement
    {
        $accountId = $context['account_id'] ?? $product->user_id;
        $warehouseId = $context['warehouse_id'] ?? null;
        $warehouse = $context['warehouse'] ?? $this->resolveWarehouse($product, $warehouseId, $accountId);

        return DB::transaction(function () use ($product, $quantity, $type, $context, $warehouse) {
            $inventory = $this->ensureInventory($product, $warehouse);
            $before = (int) $inventory->on_hand;
            $meta = (array) ($context['meta'] ?? []);
            $delta = 0;

            $absQuantity = abs($quantity);

            switch ($type) {
                case 'in':
                case 'transfer_in':
                    $inventory->on_hand += $absQuantity;
                    $delta = $absQuantity;
                    break;
                case 'out':
                case 'transfer_out':
                    $applied = min($absQuantity, (int) $inventory->on_hand);
                    $inventory->on_hand -= $applied;
                    $delta = -$applied;
                    break;
                case 'adjust':
                    $inventory->on_hand = max(0, (int) $inventory->on_hand + $quantity);
                    $delta = (int) $inventory->on_hand - $before;
                    break;
                case 'damage':
                case 'spoilage':
                    $applied = min($absQuantity, (int) $inventory->on_hand);
                    $inventory->on_hand -= $applied;
                    $inventory->damaged += $applied;
                    $delta = -$applied;
                    $meta['damaged_delta'] = $applied;
                    break;
                default:
                    $inventory->on_hand = max(0, (int) $inventory->on_hand + $quantity);
                    $delta = (int) $inventory->on_hand - $before;
                    $type = 'adjust';
                    break;
            }

            $inventory->save();

            $lot = $this->resolveLot($product, $warehouse, $context, $delta);
            if ($lot) {
                $this->applyLotDelta($lot, $delta, $product->tracking_type ?? 'none');
            }

            $reference = $context['reference'] ?? null;
            $referenceType = $context['reference_type'] ?? ($reference ? get_class($reference) : null);
            $referenceId = $context['reference_id'] ?? ($reference ? $reference->getKey() : null);

            $movement = ProductStockMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'lot_id' => $lot?->id,
                'user_id' => $context['actor_id'] ?? Auth::id() ?? $product->user_id,
                'type' => $type,
                'quantity' => $delta,
                'note' => $context['note'] ?? null,
                'reason' => $context['reason'] ?? null,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'before_quantity' => $before,
                'after_quantity' => (int) $inventory->on_hand,
                'unit_cost' => $context['unit_cost'] ?? null,
                'meta' => $meta ?: null,
            ]);

            if (!($context['skip_sync'] ?? false)) {
                $this->syncProductStock($product->fresh());
            }

            return $movement;
        });
    }

    public function transfer(Product $product, int $quantity, Warehouse $from, Warehouse $to, array $context = []): array
    {
        return DB::transaction(function () use ($product, $quantity, $from, $to, $context) {
            $baseContext = array_merge($context, [
                'skip_sync' => true,
                'reason' => $context['reason'] ?? 'transfer',
            ]);

            $outMovement = $this->adjust($product, $quantity, 'transfer_out', array_merge($baseContext, [
                'warehouse' => $from,
            ]));

            $inMovement = $this->adjust($product, $quantity, 'transfer_in', array_merge($baseContext, [
                'warehouse' => $to,
            ]));

            $this->syncProductStock($product->fresh());

            return [$outMovement, $inMovement];
        });
    }

    public function adjustReserved(Product $product, int $quantity, array $context = []): void
    {
        $accountId = $context['account_id'] ?? $product->user_id;
        $warehouseId = $context['warehouse_id'] ?? null;
        $warehouse = $context['warehouse'] ?? $this->resolveWarehouse($product, $warehouseId, $accountId);

        DB::transaction(function () use ($product, $quantity, $context, $warehouse) {
            $inventory = $this->ensureInventory($product, $warehouse);
            $before = (int) $inventory->reserved;
            $next = max(0, $before + $quantity);

            if ($next === $before) {
                return;
            }

            $inventory->reserved = $next;
            $inventory->save();

            if (!($context['skip_sync'] ?? false)) {
                $this->syncProductStock($product->fresh());
            }
        });
    }

    public function recalculateProductStock(Product $product): void
    {
        $this->syncProductStock($product);
    }

    private function resolveWarehouse(Product $product, ?int $warehouseId, ?int $accountId = null): Warehouse
    {
        $accountId = $accountId ?? $product->user_id;

        if ($warehouseId) {
            $warehouse = Warehouse::query()
                ->forAccount($accountId)
                ->whereKey($warehouseId)
                ->first();

            if ($warehouse) {
                return $warehouse;
            }
        }

        return $this->resolveDefaultWarehouse($accountId);
    }

    public function ensureInventory(Product $product, Warehouse $warehouse): ProductInventory
    {
        $inventory = ProductInventory::firstOrCreate(
            [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
            ],
            [
                'on_hand' => 0,
                'reserved' => 0,
                'damaged' => 0,
                'minimum_stock' => (int) $product->minimum_stock,
                'reorder_point' => (int) $product->minimum_stock,
            ]
        );

        if ($inventory->wasRecentlyCreated && (int) $inventory->on_hand === 0 && (int) $product->stock > 0) {
            $inventory->on_hand = (int) $product->stock;
            $inventory->save();
            $this->syncProductStock($product->fresh());
        }

        return $inventory;
    }

    private function resolveLot(Product $product, Warehouse $warehouse, array $context, int $delta): ?ProductLot
    {
        $trackingType = $product->tracking_type ?? 'none';
        if ($trackingType === 'none') {
            return null;
        }

        $lotNumber = $context['lot_number'] ?? null;
        $serialNumber = $context['serial_number'] ?? null;

        if ($trackingType === 'serial' && !$serialNumber) {
            return null;
        }

        if ($trackingType === 'lot' && !$lotNumber) {
            return null;
        }

        $query = ProductLot::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id);

        if ($trackingType === 'serial') {
            $query->where('serial_number', $serialNumber);
        } else {
            $query->where('lot_number', $lotNumber);
        }

        $lot = $query->first();
        $shouldCreate = $delta > 0;

        if (!$lot && $shouldCreate) {
            $lot = ProductLot::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'lot_number' => $lotNumber,
                'serial_number' => $serialNumber,
                'expires_at' => $context['expires_at'] ?? null,
                'received_at' => $context['received_at'] ?? null,
                'quantity' => 0,
                'notes' => $context['lot_note'] ?? null,
            ]);
        }

        return $lot;
    }

    private function applyLotDelta(ProductLot $lot, int $delta, string $trackingType): void
    {
        if ($trackingType === 'serial') {
            $lot->quantity = $delta > 0 ? 1 : 0;
        } else {
            $lot->quantity = max(0, (int) $lot->quantity + $delta);
        }

        $lot->save();
    }

    private function syncProductStock(Product $product): void
    {
        $inventories = ProductInventory::query()
            ->where('product_id', $product->id)
            ->get(['on_hand', 'reserved']);

        $available = $inventories->sum(fn (ProductInventory $inventory) => max(0, $inventory->on_hand - $inventory->reserved));
        $previousStock = (int) $product->stock;
        $product->forceFill(['stock' => $available])->save();

        $this->notifyLowStockIfNeeded($product, $previousStock, (int) $available);
    }

    private function notifyLowStockIfNeeded(Product $product, int $previousStock, int $currentStock): void
    {
        $minimumStock = (int) $product->minimum_stock;
        if ($minimumStock <= 0) {
            return;
        }
        if ($previousStock > $minimumStock && $currentStock <= $minimumStock) {
            $this->sendLowStockAlert($product, $currentStock, $minimumStock);
        }
    }

    private function sendLowStockAlert(Product $product, int $currentStock, int $minimumStock): void
    {
        $owner = User::query()
            ->select(['id', 'company_type'])
            ->find($product->user_id);
        if (!$owner || $owner->company_type !== 'products') {
            return;
        }

        $teamMembers = TeamMember::query()
            ->forAccount($owner->id)
            ->active()
            ->get(['user_id', 'permissions']);

        $userIds = $teamMembers
            ->filter(fn (TeamMember $member) => $member->hasPermission('sales.manage') || $member->hasPermission('sales.pos'))
            ->pluck('user_id')
            ->push($owner->id)
            ->unique()
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->with('teamMembership')
            ->get(['id', 'role_id', 'notification_settings', 'email']);

        $preferences = app(NotificationPreferenceService::class);
        $eligibleUsers = $users->filter(fn (User $user) => $preferences->shouldNotify(
            $user,
            NotificationPreferenceService::CATEGORY_STOCK,
            NotificationPreferenceService::CHANNEL_IN_APP
        ));

        if ($eligibleUsers->isEmpty()) {
            return;
        }

        Notification::send($eligibleUsers, new LowStockNotification($product, $currentStock, $minimumStock));
    }
}
