<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $userIds = DB::table('products')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $warehouseId = DB::table('warehouses')
                ->where('user_id', $userId)
                ->where('is_default', true)
                ->value('id');

            if (!$warehouseId) {
                $warehouseId = DB::table('warehouses')->insertGetId([
                    'user_id' => $userId,
                    'name' => 'Main warehouse',
                    'code' => 'MAIN',
                    'is_default' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $products = DB::table('products')
                ->where('user_id', $userId)
                ->get(['id', 'stock', 'minimum_stock']);

            foreach ($products as $product) {
                $exists = DB::table('product_inventories')
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('product_inventories')->insert([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'on_hand' => max(0, (int) $product->stock),
                    'reserved' => 0,
                    'damaged' => 0,
                    'minimum_stock' => max(0, (int) $product->minimum_stock),
                    'reorder_point' => max(0, (int) $product->minimum_stock),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally left blank to avoid removing user inventory data.
    }
};
