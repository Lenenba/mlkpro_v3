<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductLot;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;

class SalePaymentService
{
    public function recordManualPayment(Sale $sale, float $amount, string $method, ?User $actor = null): ?Sale
    {
        if ($amount <= 0) {
            return null;
        }

        $payment = Payment::create([
            'sale_id' => $sale->id,
            'invoice_id' => null,
            'customer_id' => $sale->customer_id,
            'user_id' => $actor?->id,
            'amount' => $amount,
            'method' => $method,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        return $this->refreshAfterPayment($sale, $payment, $method, null, null, $actor);
    }

    public function recordStripePayment(
        Sale $sale,
        float $amount,
        string $paymentIntentId,
        ?string $sessionId = null
    ): ?Sale {
        if ($amount <= 0) {
            return null;
        }

        $payment = Payment::firstOrCreate(
            [
                'provider' => 'stripe',
                'provider_reference' => $paymentIntentId,
            ],
            [
                'sale_id' => $sale->id,
                'invoice_id' => null,
                'customer_id' => $sale->customer_id,
                'user_id' => $sale->user_id,
                'amount' => $amount,
                'method' => 'stripe',
                'status' => 'completed',
                'reference' => $paymentIntentId,
                'paid_at' => now(),
            ]
        );

        return $this->refreshAfterPayment($sale, $payment, 'stripe', $paymentIntentId, $sessionId, null);
    }

    private function refreshAfterPayment(
        Sale $sale,
        Payment $payment,
        string $method,
        ?string $paymentIntentId,
        ?string $sessionId,
        ?User $actor
    ): Sale {
        $sale->refresh();

        $previousStatus = $sale->status;
        $previousFulfillment = $sale->fulfillment_status;

        $updates = [];
        if ($paymentIntentId) {
            $updates['stripe_payment_intent_id'] = $paymentIntentId;
        }
        if ($sessionId) {
            $updates['stripe_checkout_session_id'] = $sessionId;
        }
        if ($updates) {
            $sale->forceFill($updates)->save();
        }

        $amountPaid = (float) $sale->payments()
            ->where('status', 'completed')
            ->sum('amount');
        $total = (float) $sale->total;

        $fullyPaid = $total > 0 && $amountPaid >= $total;
        $canMarkPaid = !$sale->fulfillment_method || $this->isFulfillmentComplete($sale->fulfillment_status);

        if ($fullyPaid && $sale->status !== Sale::STATUS_PAID) {
            if ($canMarkPaid) {
                $this->markSalePaid(
                    $sale,
                    $method,
                    $paymentIntentId,
                    $sessionId,
                    $previousStatus,
                    $previousFulfillment,
                    $actor
                );
            } elseif (!$sale->paid_at) {
                $sale->forceFill([
                    'paid_at' => now(),
                    'payment_provider' => $method,
                ])->save();
            }
        }

        return $sale->refresh();
    }

    private function markSalePaid(
        Sale $sale,
        string $paymentProvider,
        ?string $paymentIntentId,
        ?string $sessionId,
        string $previousStatus,
        ?string $previousFulfillment,
        ?User $actor
    ): void {
        $sale->loadMissing(['items']);

        $update = [
            'status' => Sale::STATUS_PAID,
            'paid_at' => $sale->paid_at ?? now(),
            'payment_provider' => $paymentProvider,
            'stripe_payment_intent_id' => $paymentIntentId ?: $sale->stripe_payment_intent_id,
            'stripe_checkout_session_id' => $sessionId ?: $sale->stripe_checkout_session_id,
        ];

        if (!$sale->fulfillment_method && !$sale->fulfillment_status) {
            $update['fulfillment_status'] = Sale::FULFILLMENT_COMPLETED;
        }

        $sale->update($update);

        $wasPending = $previousStatus === Sale::STATUS_PENDING
            && !$this->isFulfillmentComplete($previousFulfillment);
        if ($wasPending) {
            $this->applyReservations($sale, [], $sale->user_id);
        }

        $inventoryAlreadyApplied = $previousStatus === Sale::STATUS_PAID
            || $this->isFulfillmentComplete($previousFulfillment);
        if (
            !$inventoryAlreadyApplied
            && ($sale->status === Sale::STATUS_PAID || $this->isFulfillmentComplete($sale->fulfillment_status))
        ) {
            $this->applyInventory($sale);
        }

        $timeline = app(SaleTimelineService::class);
        $changes = [];
        if ($previousStatus !== $sale->status) {
            $timeline->record($actor, $sale, 'sale_status_changed', [
                'status_from' => $previousStatus,
                'status_to' => $sale->status,
            ]);
            $changes['status'] = true;
        }
        if ($previousFulfillment !== $sale->fulfillment_status) {
            $timeline->record($actor, $sale, 'sale_fulfillment_changed', [
                'fulfillment_from' => $previousFulfillment,
                'fulfillment_to' => $sale->fulfillment_status,
            ]);
            $changes['fulfillment_status'] = true;
        }

        if ($changes) {
            app(SaleNotificationService::class)->notifyStatusChange($sale, $changes);
        }
    }

    private function applyInventory(Sale $sale): void
    {
        $items = $sale->relationLoaded('items')
            ? $sale->items
            : $sale->items()->get(['product_id', 'quantity']);
        if ($items->isEmpty()) {
            return;
        }

        $productIds = $items->pluck('product_id')->filter()->unique()->values();
        if ($productIds->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->where('user_id', $sale->user_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($sale->user_id);

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (!$product) {
                continue;
            }

            $this->applyInventoryForProduct(
                $product,
                (int) $item->quantity,
                $inventoryService,
                $sale,
                $warehouse
            );
        }
    }

    private function applyReservations(Sale $sale, array $itemsPayload, int $accountId, $currentItems = null): void
    {
        $inventoryService = app(InventoryService::class);
        $warehouse = $inventoryService->resolveDefaultWarehouse($accountId);

        if ($currentItems !== null) {
            $current = collect($currentItems);
        } else {
            $current = $sale->relationLoaded('items')
                ? $sale->items
                : $sale->items()->get(['product_id', 'quantity']);
        }

        $currentMap = $current->groupBy('product_id')
            ->map(fn($rows) => (int) $rows->sum('quantity'))
            ->toArray();

        $nextMap = collect($itemsPayload)
            ->groupBy('product_id')
            ->map(fn($rows) => (int) collect($rows)->sum('quantity'))
            ->toArray();

        $productIds = array_values(array_unique(array_merge(array_keys($currentMap), array_keys($nextMap))));
        if (!$productIds) {
            return;
        }

        $products = Product::query()
            ->where('user_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (!$product) {
                continue;
            }

            $oldQty = (int) ($currentMap[$productId] ?? 0);
            $newQty = (int) ($nextMap[$productId] ?? 0);
            $delta = $newQty - $oldQty;

            if ($delta !== 0) {
                $inventoryService->adjustReserved($product, $delta, [
                    'warehouse' => $warehouse,
                    'reference' => $sale,
                    'reason' => 'sale_reservation',
                ]);
            }
        }
    }

    private function applyInventoryForProduct(
        Product $product,
        int $quantity,
        InventoryService $inventoryService,
        Sale $sale,
        ?Warehouse $fallbackWarehouse
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $trackingType = $product->tracking_type ?? 'none';

        if ($trackingType === 'serial') {
            $serialLots = ProductLot::query()
                ->where('product_id', $product->id)
                ->whereNotNull('serial_number')
                ->where('quantity', '>', 0)
                ->with('warehouse')
                ->limit($quantity)
                ->get();

            foreach ($serialLots as $lot) {
                $inventoryService->adjust($product, 1, 'out', [
                    'warehouse' => $lot->warehouse ?? $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'serial_number' => $lot->serial_number,
                    'reference' => $sale,
                ]);
            }

            return;
        }

        if ($trackingType === 'lot') {
            $remaining = $quantity;
            $lots = ProductLot::query()
                ->where('product_id', $product->id)
                ->where('quantity', '>', 0)
                ->with('warehouse')
                ->orderByRaw('expires_at is null, expires_at asc')
                ->get();

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $useQuantity = min($remaining, (int) $lot->quantity);
                if ($useQuantity <= 0) {
                    continue;
                }

                $inventoryService->adjust($product, $useQuantity, 'out', [
                    'warehouse' => $lot->warehouse ?? $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'lot_number' => $lot->lot_number,
                    'reference' => $sale,
                ]);

                $remaining -= $useQuantity;
            }

            if ($remaining > 0) {
                $inventoryService->adjust($product, $remaining, 'out', [
                    'warehouse' => $fallbackWarehouse,
                    'reason' => 'sale',
                    'note' => 'Sale ' . $sale->number,
                    'reference' => $sale,
                ]);
            }

            return;
        }

        $inventoryService->adjust($product, $quantity, 'out', [
            'warehouse' => $fallbackWarehouse,
            'reason' => 'sale',
            'note' => 'Sale ' . $sale->number,
            'reference' => $sale,
        ]);
    }

    private function isFulfillmentComplete(?string $status): bool
    {
        return in_array($status, [Sale::FULFILLMENT_COMPLETED, Sale::FULFILLMENT_CONFIRMED], true);
    }
}
