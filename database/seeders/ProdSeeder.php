<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;


class ProdSeeder extends Seeder
{
    /**
     * Seed the application's database for production environment.
     */
    public function run(): void
    {
        // Ensure essential roles exist
        foreach (['superadmin', 'admin', 'owner', 'employee', 'client'] as $name) {
            Role::firstOrCreate(
                ['name' => $name],
                ['description' => ucfirst($name) . ' role']
            );
        }

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');

        // Create or update the superadmin user
        User::query()->updateOrCreate(
            ['email' => 'bilitikjulesroger@yahoo.fr'],
            [
                'name' => 'Super Admin',
                'role_id' => $superadminRoleId,
                'phone_number' => '+1234567890',
                'password' => env('SUPERADMIN_PASSWORD'),
            ]
        );
    }
}
