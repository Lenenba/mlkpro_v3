<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed the roles table with stable names
        foreach (['superadmin', 'admin', 'employee', 'client'] as $name) {
            Role::firstOrCreate(
                ['name' => $name],
                ['description' => ucfirst($name) . ' role']
            );
        }

        // Superadmin user
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'role_id' => 1,
            'phone_number' => '+1234567890',
        ]);

        // Admin users
        User::factory()->count(2)->create();

        $this->call([
            ProductModuleSeeder::class,
            CustomerModuleSeeder::class,
            QuoteModuleSeeder::class,
            InvoiceModuleSeeder::class,
        ]);
    }
}
