<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductStockMovement;
use App\Models\ProductWork;
use App\Models\Property;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\User;
use App\Models\Work;
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
            $categories[$name] = ProductCategory::firstOrCreate(['name' => $name]);
        }

        $now = now();
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

        $productsBySku = [];
        foreach ($seedProducts as $data) {
            $category = $categories[$data['category']];
            $price = (float) $data['price'];
            $cost = (float) $data['cost_price'];
            $margin = $price > 0 ? round((($price - $cost) / $price) * 100, 2) : 0;

            $product = Product::updateOrCreate(
                ['user_id' => $user->id, 'sku' => $data['sku']],
                [
                    'name' => $data['name'],
                    'description' => $data['name'] . ' seeded for product module validation.',
                    'category_id' => $category->id,
                    'stock' => $data['stock'],
                    'minimum_stock' => $data['minimum_stock'],
                    'price' => $price,
                    'cost_price' => $cost,
                    'margin_percent' => $margin,
                    'tax_rate' => $data['tax_rate'],
                    'image' => $data['image'],
                    'barcode' => $data['barcode'],
                    'unit' => $data['unit'],
                    'supplier_name' => $data['supplier_name'],
                    'is_active' => $data['is_active'],
                ]
            );

            $product->forceFill([
                'created_at' => $data['created_at'],
                'updated_at' => $data['created_at'],
            ])->save();

            if ($data['image']) {
                ProductImage::updateOrCreate(
                    ['product_id' => $product->id, 'is_primary' => true],
                    ['path' => $data['image'], 'is_primary' => true, 'sort_order' => 0]
                );
            }

            if ($product->stockMovements()->count() === 0) {
                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'in',
                    'quantity' => max(1, (int) ($data['stock'] / 2)),
                    'note' => 'Initial stock',
                ]);

                ProductStockMovement::create([
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'out',
                    'quantity' => -max(1, (int) ($data['stock'] / 4)),
                    'note' => 'Usage for jobs',
                ]);
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
