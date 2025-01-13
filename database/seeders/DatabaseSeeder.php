<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed the roles table
        Role::factory()->count(4)->create();

        // Superadmin user
        $Superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'role_id' => 1,
            'phone_number' => '+1234567890',
        ]);

        // Admin user
        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role_id' => 2,
            'phone_number' => '+0987654321',
        ]);
        $categories = ProductCategory::factory(5)->create();

        // Create products and associate with categories and users
        $Products = Product::factory(20)
            ->recycle($categories)
            ->recycle($Superadmin)
            ->create();

        foreach ($Products as $product) {
            $product->number = 'PROD' . str_pad($product->id, 6, '0', STR_PAD_LEFT);
            $product->save();
        };

        // Crée 10 clients avec 2 adresses chacun
        $customers = Customer::factory()
            ->count(4)
            ->recycle($Superadmin)
            ->has(Property::factory()->count(2)) // 1 adresse physique + 1 adresse de facturation
            ->create();

        foreach ($customers as $customer) {
            $customer->number = 'CUST' . str_pad($customer->id, 6, '0', STR_PAD_LEFT);
            $customer->save();
        }

    }
}
