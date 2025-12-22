<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Property;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;

class CustomerModuleSeeder extends Seeder
{
    /**
     * Seed data to validate customer KPIs, filters, and activity.
     */
    public function run(): void
    {
        $ownerRoleId = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;

        $user = User::first();
        if (!$user) {
            $user = User::factory()->create([
                'name' => 'Customer Demo',
                'email' => 'customer.demo@example.com',
                'role_id' => $ownerRoleId,
            ]);
        }

        $now = now();
        $seedCustomers = [
            [
                'email' => 'north-co@example.com',
                'first_name' => 'Ava',
                'last_name' => 'Lefebvre',
                'company_name' => 'North & Co',
                'phone' => '+15145550001',
                'city' => 'Montreal',
                'country' => 'Canada',
                'created_at' => $now->copy()->subDays(5),
                'quotes' => [3, $now->copy()->subDays(3)],
                'works' => [2, $now->copy()->subDays(2)],
            ],
            [
                'email' => 'legacy-client@example.com',
                'first_name' => 'Louis',
                'last_name' => 'Nguyen',
                'company_name' => 'Legacy Partners',
                'phone' => '+15145550002',
                'city' => 'Quebec',
                'country' => 'Canada',
                'created_at' => $now->copy()->subDays(120),
                'quotes' => [2, $now->copy()->subDays(90)],
                'works' => [0, null],
            ],
            [
                'email' => 'jobs-only@example.com',
                'first_name' => 'Mia',
                'last_name' => 'Roy',
                'company_name' => 'Mia Builds',
                'phone' => '+15145550003',
                'city' => 'Laval',
                'country' => 'Canada',
                'created_at' => $now->copy()->subDays(20),
                'quotes' => [0, null],
                'works' => [2, $now->copy()->subDays(10)],
            ],
            [
                'email' => 'no-activity@example.com',
                'first_name' => 'Sam',
                'last_name' => 'Boucher',
                'company_name' => 'Quiet Site',
                'phone' => '+15145550004',
                'city' => 'Longueuil',
                'country' => 'Canada',
                'created_at' => $now->copy()->subDays(40),
                'quotes' => [0, null],
                'works' => [0, null],
            ],
            [
                'email' => 'new-lead@example.com',
                'first_name' => 'Nina',
                'last_name' => 'Moreau',
                'company_name' => 'Nina Studio',
                'phone' => '+15145550005',
                'city' => 'Toronto',
                'country' => 'Canada',
                'created_at' => $now->copy()->subDays(7),
                'quotes' => [0, null],
                'works' => [0, null],
            ],
            [
                'email' => 'international@example.com',
                'first_name' => 'Leo',
                'last_name' => 'Martin',
                'company_name' => 'Global Renovations',
                'phone' => '+33140200000',
                'city' => 'Paris',
                'country' => 'France',
                'created_at' => $now->copy()->subDays(200),
                'quotes' => [4, $now->copy()->subDays(150)],
                'works' => [1, $now->copy()->subDays(160)],
            ],
        ];

        foreach ($seedCustomers as $data) {
            $customer = Customer::updateOrCreate(
                ['user_id' => $user->id, 'email' => $data['email']],
                [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'company_name' => $data['company_name'],
                    'phone' => $data['phone'],
                    'description' => 'Seeded customer for module validation.',
                    'logo' => 'customers/customer.png',
                    'header_image' => 'customers/customer.png',
                    'billing_same_as_physical' => true,
                    'salutation' => 'Mr',
                ]
            );

            $customer->forceFill([
                'created_at' => $data['created_at'],
                'updated_at' => $data['created_at'],
            ])->save();

            Property::updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'type' => 'physical',
                    'street1' => '100 Seed Ave',
                ],
                [
                    'street2' => null,
                    'city' => $data['city'],
                    'state' => $data['country'] === 'Canada' ? 'QC' : null,
                    'zip' => 'H1H1H1',
                    'country' => $data['country'],
                ]
            );

            [$quoteCount, $quoteDate] = $data['quotes'];
            if ($quoteCount > 0) {
                for ($i = 0; $i < $quoteCount; $i += 1) {
                    $quote = Quote::create([
                        'user_id' => $user->id,
                        'customer_id' => $customer->id,
                        'property_id' => null,
                        'job_title' => 'Seeded quote #' . ($i + 1),
                        'status' => 'draft',
                        'total' => 0,
                        'subtotal' => 0,
                        'initial_deposit' => 0,
                        'is_fixed' => false,
                    ]);

                    if ($quoteDate) {
                        $quote->forceFill([
                            'created_at' => $quoteDate,
                            'updated_at' => $quoteDate,
                        ])->save();
                    }
                }
            }

            [$workCount, $workDate] = $data['works'];
            if ($workCount > 0) {
                for ($i = 0; $i < $workCount; $i += 1) {
                    $work = Work::create([
                        'user_id' => $user->id,
                        'customer_id' => $customer->id,
                        'job_title' => 'Seeded job #' . ($i + 1),
                        'instructions' => 'Seeded job to validate activity.',
                        'start_date' => $now->toDateString(),
                        'end_date' => $now->copy()->addDay()->toDateString(),
                        'start_time' => '08:00:00',
                        'end_time' => '16:00:00',
                        'subtotal' => 0,
                        'total' => 0,
                    ]);

                    if ($workDate) {
                        $work->forceFill([
                            'created_at' => $workDate,
                            'updated_at' => $workDate,
                        ])->save();
                    }
                }
            }
        }
    }
}
