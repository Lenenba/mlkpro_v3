<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run()
    {
        DB::table('roles')->insert([
            ['name' => 'superadmin', 'description' => 'Full access to the system'],
            ['name' => 'admin', 'description' => 'Administrative access'],
            ['name' => 'owner', 'description' => 'Account owner access'],
            ['name' => 'employee', 'description' => 'Access to employee functionalities'],
            ['name' => 'client', 'description' => 'Access to client functionalities'],
        ]);
    }
}
