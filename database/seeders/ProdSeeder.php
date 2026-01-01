<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProdSeeder extends Seeder
{
    /**
     * Seed the application's database for production environment.
     */
    public function run(): void
    {
        $this->call([
            PlatformBaselineSeeder::class,
        ]);
    }
}
