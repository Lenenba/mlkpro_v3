<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate:fresh');
$user1 = App\Models\User::factory()->create();
$user2 = App\Models\User::factory()->create();
$roles = App\Models\Role::all();
var_dump($roles->pluck('name')->all());
