<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ProdSeeder extends Seeder
{
    /**
     * Seed the application's database for production environment.
     */
    public function run(): void
    {
        $roles = ['superadmin', 'admin', 'owner', 'employee', 'client'];

        foreach ($roles as $name) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                ['description' => ucfirst($name) . ' role']
            );
        }

        $superadminRole = Role::query()
            ->where('name', 'superadmin')
            ->firstOrFail();

        $email = mb_strtolower('bilitikjulesroger@yahoo.fr');

        // Prefer config over env() in production (config caching).
        // Add this to a config file (e.g. config/app.php):
        // 'superadmin_password' => env('SUPERADMIN_PASSWORD'),
        $plainPassword = env('SUPERADMIN_PASSWORD');

        if ($plainPassword === '') {
            throw new RuntimeException('SUPERADMIN_PASSWORD is missing. Refusing to seed superadmin without a password.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Admin',
                'role_id' => $superadminRole->id,
                'phone_number' => '+1234567890',
                'password' => Hash::make($plainPassword),
            ]
        );
    }
}
