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
        $this->call([
            PlanCatalogSeeder::class,
        ]);

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

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'public_navigation'],
            ['value' => [
                'contact_form_url' => '',
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
            'assistant_requests',
        ];

        $moduleKeys = [
            'quotes',
            'requests',
            'reservations',
            'plan_scans',
            'invoices',
            'jobs',
            'products',
            'performance',
            'presence',
            'planning',
            'sales',
            'services',
            'tasks',
            'team_members',
            'assistant',
            'campaigns',
            'loyalty',
        ];

        $planLimits = [];
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $configuredLimits = is_array($plan['default_limits'] ?? null) ? $plan['default_limits'] : [];
            foreach ($limitKeys as $limitKey) {
                $planLimits[$planKey][$limitKey] = is_numeric($configuredLimits[$limitKey] ?? null)
                    ? (int) $configuredLimits[$limitKey]
                    : null;
            }
        }

        if (! $planLimits) {
            $planLimits['free'] = array_fill_keys($limitKeys, null);
        }

        PlatformSetting::query()->firstOrCreate(
            ['key' => 'plan_limits'],
            ['value' => $planLimits]
        );

        $planModules = [];
        $defaultAssistantPlan = array_key_exists('scale', config('billing.plans', []))
            ? 'scale'
            : array_key_last(config('billing.plans', []));
        foreach (config('billing.plans', []) as $planKey => $plan) {
            $configuredModules = is_array($plan['default_modules'] ?? null) ? $plan['default_modules'] : [];
            foreach ($moduleKeys as $moduleKey) {
                if (array_key_exists($moduleKey, $configuredModules)) {
                    $planModules[$planKey][$moduleKey] = (bool) $configuredModules[$moduleKey];

                    continue;
                }

                if ($moduleKey === 'assistant') {
                    $planModules[$planKey][$moduleKey] = $planKey === $defaultAssistantPlan;

                    continue;
                }
                $planModules[$planKey][$moduleKey] = true;
            }
        }

        if (! $planModules) {
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
