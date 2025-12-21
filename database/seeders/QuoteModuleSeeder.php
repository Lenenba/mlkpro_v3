<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Seeder;

class QuoteModuleSeeder extends Seeder
{
    /**
     * Seed data that exercises quote KPIs, filters, and tax workflow.
     */
    public function run(): void
    {
        $user = User::first();
        if (!$user) {
            $user = User::factory()->create([
                'name' => 'Quote Demo',
                'email' => 'quote.demo@example.com',
                'role_id' => 1,
            ]);
        }

        $taxes = [
            ['name' => 'TPS', 'rate' => 5.00],
            ['name' => 'TVQ', 'rate' => 9.975],
            ['name' => 'Service', 'rate' => 3.50],
        ];

        $taxMap = [];
        foreach ($taxes as $tax) {
            $taxModel = Tax::firstOrCreate(['name' => $tax['name']], ['rate' => $tax['rate']]);
            $taxMap[$tax['name']] = $taxModel;
        }

        $customers = Customer::byUser($user->id)->with('properties')->get();
        if ($customers->isEmpty()) {
            $customer = Customer::firstOrCreate(
                ['user_id' => $user->id, 'email' => 'quote.customer@example.com'],
                [
                    'first_name' => 'Quote',
                    'last_name' => 'Customer',
                    'company_name' => 'Quote Demo Co',
                    'phone' => '+15551230000',
                ]
            );

            Property::firstOrCreate(
                [
                    'customer_id' => $customer->id,
                    'type' => 'physical',
                    'street1' => '455 Quote Ave',
                ],
                [
                    'country' => 'Canada',
                    'city' => 'Montreal',
                    'state' => 'QC',
                    'zip' => 'H2H2H2',
                ]
            );

            $customers = Customer::byUser($user->id)->with('properties')->get();
        }

        $products = Product::byUser($user->id)->get();
        if ($products->isEmpty()) {
            $products = collect([
                Product::create([
                    'user_id' => $user->id,
                    'name' => 'Demo Labor Hour',
                    'price' => 85.00,
                    'stock' => 50,
                ]),
                Product::create([
                    'user_id' => $user->id,
                    'name' => 'Demo Materials Pack',
                    'price' => 125.00,
                    'stock' => 30,
                ]),
                Product::create([
                    'user_id' => $user->id,
                    'name' => 'Demo Equipment Rental',
                    'price' => 350.00,
                    'stock' => 10,
                ]),
            ]);
        }

        $now = now();
        $scenarios = [
            [
                'title' => 'Seeded quote - Draft remodel',
                'status' => 'draft',
                'taxes' => ['TPS', 'TVQ'],
                'deposit' => 0,
                'created_at' => $now->copy()->subDays(2),
            ],
            [
                'title' => 'Seeded quote - Sent exterior',
                'status' => 'sent',
                'taxes' => ['TPS'],
                'deposit' => 0,
                'created_at' => $now->copy()->subDays(6),
            ],
            [
                'title' => 'Seeded quote - Accepted refresh',
                'status' => 'accepted',
                'taxes' => ['TPS', 'TVQ', 'Service'],
                'deposit' => 0.2,
                'created_at' => $now->copy()->subDays(12),
            ],
            [
                'title' => 'Seeded quote - Declined project',
                'status' => 'declined',
                'taxes' => [],
                'deposit' => 0,
                'created_at' => $now->copy()->subDays(18),
            ],
        ];

        foreach ($scenarios as $index => $scenario) {
            $customer = $customers[$index % $customers->count()];
            $property = $customer->properties->first();

            $quote = Quote::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'job_title' => $scenario['title'],
                ],
                [
                    'property_id' => $property?->id,
                    'status' => $scenario['status'],
                    'subtotal' => 0,
                    'total' => 0,
                    'notes' => 'Seeded quote for module validation.',
                    'messages' => 'Thank you for your interest.',
                    'initial_deposit' => 0,
                    'is_fixed' => false,
                ]
            );

            $lineItems = $products->take(3)->values()->map(function ($product, $lineIndex) {
                $quantity = $lineIndex + 1;
                $price = (float) $product->price;
                return [
                    'id' => $product->id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => round($quantity * $price, 2),
                ];
            });

            $subtotal = $lineItems->sum('total');
            $taxLines = collect($scenario['taxes'])->map(function ($name) use ($taxMap, $subtotal) {
                if (!isset($taxMap[$name])) {
                    return null;
                }
                $tax = $taxMap[$name];
                $amount = round($subtotal * ((float) $tax->rate / 100), 2);
                return [
                    'tax_id' => $tax->id,
                    'rate' => (float) $tax->rate,
                    'amount' => $amount,
                ];
            })->filter()->values();

            $taxTotal = $taxLines->sum('amount');
            $total = round($subtotal + $taxTotal, 2);
            $deposit = $scenario['deposit'] > 0 ? round($total * $scenario['deposit'], 2) : 0;

            $quote->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'initial_deposit' => $deposit,
            ]);

            $pivotData = $lineItems->mapWithKeys(function ($item) {
                return [
                    $item['id'] => [
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                        'total' => $item['total'],
                    ],
                ];
            });
            $quote->products()->sync($pivotData);

            $quote->taxes()->delete();
            if ($taxLines->isNotEmpty()) {
                $quote->taxes()->createMany($taxLines->toArray());
            }

            if ($scenario['created_at']) {
                $quote->forceFill([
                    'created_at' => $scenario['created_at'],
                    'updated_at' => $scenario['created_at'],
                ])->save();
            }
        }
    }
}
