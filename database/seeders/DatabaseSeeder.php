<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\PlatformAdmin;
use App\Models\PlatformNotificationSetting;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed the roles table with stable names
        foreach (['superadmin', 'admin', 'owner', 'employee', 'client'] as $name) {
            Role::firstOrCreate(
                ['name' => $name],
                ['description' => ucfirst($name) . ' role']
            );
        }

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');
        $adminRoleId = Role::query()->where('name', 'admin')->value('id');

        // Superadmin user
        $superadmin = User::query()->firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'role_id' => $superadminRoleId,
                'phone_number' => '+1234567890',
                'password' => 'password',
            ]
        );

        PlatformNotificationSetting::query()->firstOrCreate(
            ['user_id' => $superadmin->id],
            [
                'channels' => ['email'],
                'categories' => ['payment_failed', 'error_spike'],
                'rules' => [
                    'error_spike' => 10,
                    'payment_failed' => 3,
                    'churn_risk' => 5,
                ],
                'digest_frequency' => 'daily',
            ]
        );

        // Platform admin user
        $platformAdmin = User::query()->firstOrCreate(
            ['email' => 'platform.admin@example.com'],
            [
                'name' => 'Platform Admin',
                'role_id' => $adminRoleId,
                'must_change_password' => true,
                'password' => 'password',
            ]
        );

        PlatformAdmin::query()->firstOrCreate(
            ['user_id' => $platformAdmin->id],
            [
                'role' => 'ops',
                'permissions' => [
                    'tenants.view',
                    'tenants.manage',
                    'settings.manage',
                    'audit.view',
                ],
                'is_active' => true,
            ]
        );

        // // Admin users
        // User::factory()->count(2)->create();

        $this->call([
            ProductModuleSeeder::class,
            CustomerModuleSeeder::class,
            TeamModuleSeeder::class,
            QuoteModuleSeeder::class,
            InvoiceModuleSeeder::class,
        ]);
    }
}
