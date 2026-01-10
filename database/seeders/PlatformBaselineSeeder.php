<?php

namespace Database\Seeders;

use App\Models\PlatformNotificationSetting;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class PlatformBaselineSeeder extends Seeder
{
    /**
     * Seed baseline system data required by the platform.
     */
    public function run(): void
    {
        $roles = [
            'superadmin' => 'Full access to the system',
            'admin' => 'Administrative access',
            'owner' => 'Account owner access',
            'employee' => 'Access to employee functionalities',
            'client' => 'Access to client functionalities',
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate(
                ['name' => $name],
                ['description' => $description]
            );
        }

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'maintenance'],
            ['value' => ['enabled' => false, 'message' => '']]
        );

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'templates'],
            ['value' => [
                'email_default' => '',
                'quote_default' => '',
                'invoice_default' => '',
            ]]
        );

        $limitKeys = [
            'quotes',
            'requests',
            'plan_scan_quotes',
            'invoices',
            'jobs',
            'products',
            'services',
            'tasks',
            'team_members',
        ];

        $moduleKeys = [
            'quotes',
            'requests',
            'plan_scans',
            'invoices',
            'jobs',
            'products',
            'sales',
            'services',
            'tasks',
            'team_members',
            'assistant',
        ];

        $planLimits = [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            foreach ($limitKeys as $limitKey) {
                $planLimits[$planKey][$limitKey] = null;
            }
        }

        if (!$planLimits) {
            $planLimits['free'] = array_fill_keys($limitKeys, null);
        }

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_limits'],
            ['value' => $planLimits]
        );

        $planModules = [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            foreach ($moduleKeys as $moduleKey) {
                $planModules[$planKey][$moduleKey] = true;
            }
        }

        if (!$planModules) {
            $planModules['free'] = array_fill_keys($moduleKeys, true);
        }

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_modules'],
            ['value' => $planModules]
        );

        $superadminRoleId = Role::query()->where('name', 'superadmin')->value('id');
        if ($superadminRoleId) {
            $superadmins = User::query()->where('role_id', $superadminRoleId)->get();
            foreach ($superadmins as $superadmin) {
                PlatformNotificationSetting::query()->firstOrCreate(
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
            }
        }
    }
}
