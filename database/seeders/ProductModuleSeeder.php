<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductWork;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Work;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

class ProductModuleSeeder extends Seeder
{
    /**
     * Seed data that exercises product KPIs, filters, and workflows.
     */
    public function run(): void
    {
        $user = User::first();
        if (!$user) {
            $user = User::factory()->create([
                'name' => 'Product Demo',
                'email' => 'product.demo@example.com',
                'role_id' => 1,
            ]);
        }

        $categoryNames = [
            'Materials',
            'Labor',
            'Equipment',
            'Supplies',
            'Finishes',
        ];

        $categories = [];
        foreach ($categoryNames as $name) {
            $categories[$name] = ProductCategory::resolveForAccount($user->id, $user->id, $name);
        }

        $inventoryService = app(InventoryService::class);
        $mainWarehouse = $inventoryService->resolveDefaultWarehouse($user->id);
        $overflowWarehouse = Warehouse::updateOrCreate(
            ['user_id' => $user->id, 'code' => 'OVERFLOW'],
            [
                'name' => 'Overflow warehouse',
                'is_default' => false,
                'is_active' => true,
            ]
        );

        $now = now();
        $unsplashPhotoIds = [
            '1500530855697-b586d89ba3ee',
            '1523275335684-37898b6baf30',
            '1503602642458-232111445657',
            '1496307042754-b4aa456c4a2d',
            '1498050108023-c5249f4df085',
            '1489515217757-5fd1be406fef',
            '1473186578172-c141e6798cf4',
            '1469474968028-56623f02e42e',
            '1488998427799-e3362cec87c3',
            '1497366216548-37526070297c',
        ];

        $resolveProductImage = function (array $data) use ($unsplashPhotoIds): string {
            $seed = trim(($data['sku'] ?? '') . ' ' . ($data['name'] ?? '') . ' ' . ($data['category'] ?? 'product'));
            $hash = (int) sprintf('%u', crc32($seed));
            $photoId = $unsplashPhotoIds[$hash % count($unsplashPhotoIds)];
            return "https://images.unsplash.com/photo-{$photoId}?auto=format&fit=crop&w=800&q=80";
        };
        $seedProducts = [
            [
                'name' => 'Concrete Mix',
                'sku' => 'MAT-001',
                'barcode' => '0123456789012',
                'unit' => 'piece',
                'category' => 'Materials',
                'price' => 125.00,
                'cost_price' => 90.00,
                'stock' => 50,
                'minimum_stock' => 15,
                'supplier_name' => 'Alpha Supply',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(3),
            ],
            [
                'name' => 'Premium Paint',
                'sku' => 'FIN-002',
                'barcode' => '0123456789013',
                'unit' => 'piece',
                'category' => 'Finishes',
                'price' => 75.00,
                'cost_price' => 45.00,
                'stock' => 8,
                'minimum_stock' => 10,
                'supplier_name' => 'ColorWorks',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(12),
            ],
            [
                'name' => 'Safety Gloves',
                'sku' => 'SUP-001',
                'barcode' => '0123456789014',
                'unit' => 'piece',
                'category' => 'Supplies',
                'price' => 12.00,
                'cost_price' => 4.00,
                'stock' => 0,
                'minimum_stock' => 5,
                'supplier_name' => 'SecurePro',
                'tax_rate' => 5.00,
                'is_active' => true,
                'image' => null,
                'created_at' => $now->copy()->subDays(25),
            ],
            [
                'name' => 'Scaffolding Rental',
                'sku' => 'EQP-010',
                'barcode' => '0123456789015',
                'unit' => 'piece',
                'category' => 'Equipment',
                'price' => 550.00,
                'cost_price' => 320.00,
                'stock' => 2,
                'minimum_stock' => 1,
                'supplier_name' => 'LiftIt',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(1),
            ],
            [
                'name' => 'Crew Labor Hour',
                'sku' => 'LAB-001',
                'barcode' => '0123456789016',
                'unit' => 'hour',
                'category' => 'Labor',
                'price' => 85.00,
                'cost_price' => 50.00,
                'stock' => 120,
                'minimum_stock' => 40,
                'supplier_name' => 'North Crew',
                'tax_rate' => 0,
                'is_active' => true,
                'image' => null,
                'created_at' => $now->copy()->subDays(18),
            ],
            [
                'name' => 'Tile Adhesive',
                'sku' => 'MAT-014',
                'barcode' => '0123456789017',
                'unit' => 'piece',
                'category' => 'Materials',
                'price' => 30.00,
                'cost_price' => 18.00,
                'stock' => 6,
                'minimum_stock' => 8,
                'supplier_name' => 'BondCo',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(6),
            ],
            [
                'name' => 'Sealant Tube',
                'sku' => 'SUP-014',
                'barcode' => '0123456789018',
                'unit' => 'piece',
                'category' => 'Supplies',
                'price' => 9.50,
                'cost_price' => 3.00,
                'stock' => 200,
                'minimum_stock' => 25,
                'supplier_name' => 'SealCo',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(30),
            ],
            [
                'name' => 'Archived Drill Bit',
                'sku' => 'SUP-099',
                'barcode' => '0123456789019',
                'unit' => 'piece',
                'category' => 'Supplies',
                'price' => 40.00,
                'cost_price' => 25.00,
                'stock' => 15,
                'minimum_stock' => 5,
                'supplier_name' => 'ToolStop',
                'tax_rate' => 14.975,
                'is_active' => false,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(40),
            ],
            [
                'name' => 'Flooring Panel',
                'sku' => 'FIN-120',
                'barcode' => '0123456789020',
                'unit' => 'm2',
                'category' => 'Finishes',
                'price' => 95.00,
                'cost_price' => 70.00,
                'stock' => 4,
                'minimum_stock' => 4,
                'supplier_name' => 'FloorCo',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(9),
            ],
            [
                'name' => 'Dust Mask Pack',
                'sku' => 'SUP-021',
                'barcode' => '0123456789021',
                'unit' => 'piece',
                'category' => 'Supplies',
                'price' => 18.00,
                'cost_price' => 7.00,
                'stock' => 30,
                'minimum_stock' => 10,
                'supplier_name' => 'SafeAir',
                'tax_rate' => 14.975,
                'is_active' => true,
                'image' => 'products/product.jpg',
                'created_at' => $now->copy()->subDays(4),
            ],
        ];

        $warehouseMap = [
            'main' => $mainWarehouse,
            'overflow' => $overflowWarehouse,
        ];

        $inventoryPlans = [
            'MAT-001' => [
                'tracking_type' => 'lot',
                'lots' => [
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-MAT-001A',
                        'quantity' => 30,
                        'expires_at' => $now->copy()->addMonths(8),
                        'received_at' => $now->copy()->subDays(12),
                    ],
                    [
                        'warehouse' => 'overflow',
                        'lot_number' => 'LOT-MAT-001B',
                        'quantity' => 20,
                        'expires_at' => $now->copy()->addMonths(2),
                        'received_at' => $now->copy()->subDays(25),
                    ],
                ],
                'reserved' => [
                    [
                        'warehouse' => 'main',
                        'quantity' => 5,
                    ],
                ],
                'bins' => [
                    'main' => 'A-01',
                    'overflow' => 'B-04',
                ],
            ],
            'FIN-002' => [
                'tracking_type' => 'lot',
                'lots' => [
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-FIN-002A',
                        'quantity' => 5,
                        'expires_at' => $now->copy()->addDays(12),
                        'received_at' => $now->copy()->subDays(5),
                    ],
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-FIN-002B',
                        'quantity' => 3,
                        'expires_at' => $now->copy()->addMonths(5),
                        'received_at' => $now->copy()->subDays(30),
                    ],
                ],
                'damaged' => [
                    'warehouse' => 'main',
                    'quantity' => 1,
                ],
                'bins' => [
                    'main' => 'C-02',
                ],
            ],
            'EQP-010' => [
                'tracking_type' => 'serial',
                'serials' => [
                    [
                        'warehouse' => 'main',
                        'serial_number' => 'SN-EQP-010-01',
                    ],
                    [
                        'warehouse' => 'main',
                        'serial_number' => 'SN-EQP-010-02',
                    ],
                ],
                'bins' => [
                    'main' => 'E-01',
                ],
            ],
            'SUP-014' => [
                'tracking_type' => 'none',
                'secondary_stock' => 40,
                'reserved' => [
                    [
                        'warehouse' => 'main',
                        'quantity' => 12,
                    ],
                ],
                'bins' => [
                    'main' => 'D-11',
                    'overflow' => 'D-12',
                ],
            ],
            'FIN-120' => [
                'tracking_type' => 'lot',
                'lots' => [
                    [
                        'warehouse' => 'main',
                        'lot_number' => 'LOT-FIN-120A',
                        'quantity' => 4,
                        'expires_at' => $now->copy()->addMonths(4),
                        'received_at' => $now->copy()->subDays(14),
                    ],
                ],
                'bins' => [
                    'main' => 'F-01',
                ],
            ],
        ];

        $productsBySku = [];
        foreach ($seedProducts as $data) {
            $category = $categories[$data['category']];
            $price = (float) $data['price'];
            $cost = (float) $data['cost_price'];
            $margin = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0;
            $plan = $inventoryPlans[$data['sku']] ?? [];
            $trackingType = $plan['tracking_type'] ?? 'none';
            $imageUrl = $resolveProductImage($data);

            $product = Product::updateOrCreate(
                ['user_id' => $user->id, 'sku' => $data['sku']],
                [
                    'name' => $data['name'],
                    'description' => $data['name'] . ' seeded for product module validation.',
                    'category_id' => $category->id,
                    'stock' => 0,
                    'minimum_stock' => $data['minimum_stock'],
                    'price' => $price,
                    'cost_price' => $cost,
                    'margin_percent' => $margin,
                    'tax_rate' => $data['tax_rate'],
                    'image' => $imageUrl,
                    'barcode' => $data['barcode'],
                    'unit' => $data['unit'],
                    'supplier_name' => $data['supplier_name'],
                    'is_active' => $data['is_active'],
                    'tracking_type' => $trackingType,
                ]
            );

            $product->forceFill([
                'created_at' => $data['created_at'],
                'updated_at' => $data['created_at'],
            ])->save();

            if ($imageUrl) {
                ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'is_primary' => true],
                    ['path' => $imageUrl, 'is_primary' => true, 'sort_order' => 0]
                );
            }

            $hasSeedMovements = $product->stockMovements()->exists();
            $hasSeedLots = $product->lots()->exists();

            if (!$hasSeedMovements && !$hasSeedLots) {
                $seedStock = (int) $data['stock'];

                $inventoryService->ensureInventory($product, $mainWarehouse);
                $inventoryService->ensureInventory($product, $overflowWarehouse);

                if (!empty($plan['lots'])) {
                    foreach ($plan['lots'] as $lot) {
                        $warehouse = $warehouseMap[$lot['warehouse']] ?? $mainWarehouse;
                        $inventoryService->adjust($product, (int) $lot['quantity'], 'in', [
                            'warehouse' => $warehouse,
                            'reason' => 'seed',
                            'note' => 'Seeded lot stock',
                            'lot_number' => $lot['lot_number'] ?? null,
                            'expires_at' => $lot['expires_at'] ?? null,
                            'received_at' => $lot['received_at'] ?? null,
                        ]);
                    }
                } elseif (!empty($plan['serials'])) {
                    foreach ($plan['serials'] as $serial) {
                        $warehouse = $warehouseMap[$serial['warehouse']] ?? $mainWarehouse;
                        $inventoryService->adjust($product, 1, 'in', [
                            'warehouse' => $warehouse,
                            'reason' => 'seed',
                            'note' => 'Seeded serial stock',
                            'serial_number' => $serial['serial_number'] ?? null,
                        ]);
                    }
                } elseif ($seedStock > 0) {
                    $secondaryStock = (int) ($plan['secondary_stock'] ?? 0);
                    $mainStock = max(0, $seedStock - $secondaryStock);
                    if ($mainStock > 0) {
                        $inventoryService->adjust($product, $mainStock, 'in', [
                            'warehouse' => $mainWarehouse,
                            'reason' => 'seed',
                            'note' => 'Seeded stock',
                        ]);
                    }
                    if ($secondaryStock > 0) {
                        $inventoryService->adjust($product, $secondaryStock, 'in', [
                            'warehouse' => $overflowWarehouse,
                            'reason' => 'seed',
                            'note' => 'Seeded overflow stock',
                        ]);
                    }
                }

                if (!empty($plan['damaged'])) {
                    $warehouse = $warehouseMap[$plan['damaged']['warehouse']] ?? $mainWarehouse;
                    $inventoryService->adjust($product, (int) $plan['damaged']['quantity'], 'damage', [
                        'warehouse' => $warehouse,
                        'reason' => 'seed',
                        'note' => 'Seeded damaged stock',
                    ]);
                }

                if (!empty($plan['reserved'])) {
                    foreach ($plan['reserved'] as $reservation) {
                        $warehouse = $warehouseMap[$reservation['warehouse']] ?? $mainWarehouse;
                        $inventory = $inventoryService->ensureInventory($product, $warehouse);
                        $inventory->update([
                            'reserved' => (int) $reservation['quantity'],
                        ]);
                    }
                }

                if (!empty($plan['bins'])) {
                    foreach ($plan['bins'] as $warehouseKey => $binLocation) {
                        $warehouse = $warehouseMap[$warehouseKey] ?? null;
                        if (!$warehouse) {
                            continue;
                        }
                        $inventory = $inventoryService->ensureInventory($product, $warehouse);
                        $inventory->update([
                            'bin_location' => $binLocation,
                        ]);
                    }
                }

                $inventoryService->recalculateProductStock($product);
            }

            $productsBySku[$data['sku']] = $product;
        }

        $customer = Customer::firstOrCreate(
            ['user_id' => $user->id, 'email' => 'product.customer@example.com'],
            [
                'first_name' => 'Demo',
                'last_name' => 'Customer',
                'company_name' => 'Product Demo Co',
                'phone' => '+15551234567',
                'billing_same_as_physical' => true,
            ]
        );

        $property = Property::firstOrCreate(
            [
                'customer_id' => $customer->id,
                'type' => 'physical',
                'street1' => '123 Seed St',
            ],
            [
                'country' => 'Canada',
                'city' => 'Montreal',
                'state' => 'QC',
                'zip' => 'H1H1H1',
            ]
        );

        $quote = Quote::firstOrCreate(
            [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'job_title' => 'Product KPI Quote',
            ],
            [
                'property_id' => $property->id,
                'status' => 'draft',
                'total' => 0,
                'subtotal' => 0,
            ]
        );

        $work = Work::firstOrCreate(
            [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'job_title' => 'Product KPI Work',
            ],
            [
                'quote_id' => $quote->id,
                'instructions' => 'Seeded work to validate product usage metrics.',
                'start_date' => $now->toDateString(),
                'end_date' => $now->copy()->addDay()->toDateString(),
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'subtotal' => 0,
                'total' => 0,
            ]
        );

        $quoteItems = [
            ['sku' => 'MAT-001', 'quantity' => 14],
            ['sku' => 'SUP-014', 'quantity' => 30],
            ['sku' => 'FIN-002', 'quantity' => 6],
        ];

        foreach ($quoteItems as $item) {
            if (!isset($productsBySku[$item['sku']])) {
                continue;
            }
            $product = $productsBySku[$item['sku']];
            $price = (float) $product->price;
            $quantity = (int) $item['quantity'];

            QuoteProduct::updateOrCreate(
                ['quote_id' => $quote->id, 'product_id' => $product->id],
                [
                    'quantity' => $quantity,
                    'price' => $price,
                    'description' => 'Seeded quote usage',
                    'total' => $price * $quantity,
                ]
            );
        }

        $workItems = [
            ['sku' => 'LAB-001', 'quantity' => 40],
            ['sku' => 'SUP-001', 'quantity' => 12],
            ['sku' => 'FIN-120', 'quantity' => 4],
        ];

        foreach ($workItems as $item) {
            if (!isset($productsBySku[$item['sku']])) {
                continue;
            }
            $product = $productsBySku[$item['sku']];
            $price = (float) $product->price;
            $quantity = (int) $item['quantity'];

            ProductWork::updateOrCreate(
                ['work_id' => $work->id, 'product_id' => $product->id],
                [
                    'quote_id' => $quote->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'description' => 'Seeded work usage',
                    'total' => $price * $quantity,
                ]
            );
        }
    }
}
