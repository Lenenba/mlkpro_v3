<?php

use App\Models\MegaMenu;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds only the minimal platform baseline for launch reset', function () {
    $this->seed(\Database\Seeders\LaunchResetSeeder::class);

    expect(User::query()->where('email', 'superadmin@example.com')->exists())->toBeTrue();
    expect(User::query()->where('email', 'platform.admin@example.com')->exists())->toBeTrue();
    expect(PlatformAdmin::query()->where('user_id', User::query()->where('email', 'platform.admin@example.com')->value('id'))->exists())->toBeTrue();
    expect(User::query()->where('is_demo', true)->exists())->toBeFalse();
    expect(User::query()->whereIn('email', [
        'owner.services@example.com',
        'owner.products@example.com',
        'owner.salon@example.com',
    ])->exists())->toBeFalse();
    expect(MegaMenu::query()->where('slug', 'main-header-menu')->exists())->toBeTrue();
});
