<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\Models\Role;
use App\Models\User;
use App\Models\Work;
use App\Services\WorkBillingService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('workflow:auto-validate', function (WorkBillingService $billingService) {
    $cutoff = now()->subHours(48);
    $works = Work::query()
        ->where('status', Work::STATUS_PENDING_REVIEW)
        ->where('updated_at', '<=', $cutoff)
        ->get();

    $count = 0;
    foreach ($works as $work) {
        $work->status = Work::STATUS_AUTO_VALIDATED;
        $work->save();
        $billingService->createInvoiceFromWork($work);
        $count += 1;
    }

    $this->info("Auto-validated {$count} jobs.");
})->purpose('Auto validate jobs after 48h')->hourly();

Artisan::command('superadmin:create {email} {password}', function () {
    $email = (string) $this->argument('email');
    $password = (string) $this->argument('password');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email address.');
        return;
    }

    if (strlen($password) < 8) {
        $this->error('Password must be at least 8 characters.');
        return;
    }

    $role = Role::firstOrCreate(
        ['name' => 'superadmin'],
        ['description' => 'Superadmin role']
    );

    $user = User::where('email', $email)->first();
    if ($user) {
        if (!$this->confirm('User already exists. Update role and password?', false)) {
            $this->info('No changes made.');
            return;
        }

        $user->update([
            'name' => $user->name ?: 'Super Admin',
            'password' => Hash::make($password),
            'role_id' => $role->id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $this->info('Superadmin user updated.');
        return;
    }

    User::create([
        'name' => 'Super Admin',
        'email' => $email,
        'password' => Hash::make($password),
        'role_id' => $role->id,
        'email_verified_at' => now(),
    ]);

    $this->info('Superadmin user created.');
})->purpose('Create or update a superadmin user');
