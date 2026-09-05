<?php

namespace Database\Seeders;

use App\Services\Rbac\RbacCatalogSynchronizer;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        app(RbacCatalogSynchronizer::class)->synchronize();
    }
}
