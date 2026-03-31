<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use App\Models\PlatformNotificationSetting;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LaunchResetSeeder extends Seeder
{
    /**
     * Seed only the platform baseline required to launch the app locally.
     * Demo companies and tenant data are now provisioned through the Demo Workspace module.
     */
    public function run(): void
    {
        $this->call([
            PlatformBaselineSeeder::class,
        ]);

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');
        $adminRoleId = Role::query()->where('name', 'admin')->value('id');

        $now = now();

        $superadmin = User::query()->updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role_id' => $superadminRoleId,
                'email_verified_at' => $now,
            ]
        );

        $platformAdmin = User::query()->updateOrCreate(
            ['email' => 'platform.admin@example.com'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'role_id' => $adminRoleId,
                'email_verified_at' => $now,
                'must_change_password' => true,
            ]
        );

        PlatformAdmin::query()->updateOrCreate(
            ['user_id' => $platformAdmin->id],
            [
                'role' => 'ops',
                'permissions' => [
                    'tenants.view',
                    'tenants.manage',
                    'settings.manage',
                    'mega_menus.manage',
                    'audit.view',
                    'announcements.manage',
                ],
                'is_active' => true,
                'require_2fa' => false,
            ]
        );

        PlatformNotificationSetting::query()->updateOrCreate(
            ['user_id' => $superadmin->id],
            [
                'channels' => ['email'],
                'categories' => [
                    'new_account',
                    'onboarding_completed',
                    'subscription_started',
                    'plan_changed',
                    'subscription_paused',
                    'subscription_resumed',
                    'subscription_canceled',
                    'payment_succeeded',
                    'payment_failed',
                    'churn_risk',
                ],
                'rules' => [
                    'error_spike' => 10,
                    'payment_failed' => 3,
                    'churn_risk' => 5,
                ],
                'digest_frequency' => 'daily',
            ]
        );

        PlatformSetting::setValue('templates', [
            'email_default' => 'Merci pour votre confiance.',
            'quote_default' => 'Veuillez trouver votre devis ci-joint.',
            'invoice_default' => 'Votre facture est disponible.',
        ]);

        $this->call([
            MegaMenuSeeder::class,
        ]);
    }
}
