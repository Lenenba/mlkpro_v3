<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ProductLot;
use App\Models\ProductStockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        return ProductInventory::firstOrCreate(
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
        $product->forceFill(['stock' => $available])->save();
    }
}
