<?php

namespace App\Services\Demo;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoAccountService
{
    public const TYPE_SERVICE = 'service';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_GUIDED = 'guided';

    public function resolveDemoAccount(string $type): User
    {
        $this->guardType($type);

        $email = $this->buildEmail($type);
        $roleId = $this->resolveOwnerRoleId();
        $companyType = $type === self::TYPE_PRODUCT ? 'products' : 'services';
        $demoRole = $this->resolveDemoRole($type);

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $this->resolveAccountName($type),
                'role_id' => $roleId,
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $user->forceFill([
            'name' => $this->resolveAccountName($type),
            'role_id' => $roleId,
            'company_name' => $this->resolveCompanyName($type),
            'company_type' => $companyType,
            'company_description' => 'Demo environment for showcasing the platform.',
            'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_demo' => true,
            'demo_type' => $type,
            'is_demo_user' => true,
            'demo_role' => $demoRole,
            'company_features' => $this->resolveDemoFeatures(),
            'company_limits' => $this->resolveDemoLimits(),
        ])->save();

        return $user->refresh();
    }

    public function resolveDemoUser(User $account, string $type): User
    {
        $account->forceFill([
            'is_demo' => true,
            'demo_type' => $type,
            'is_demo_user' => true,
            'demo_role' => $this->resolveDemoRole($type),
            'company_features' => $this->resolveDemoFeatures(),
            'company_limits' => $this->resolveDemoLimits(),
        ])->save();

        return $account->refresh();
    }

    public function buildEmail(string $type): string
    {
        $domain = config('demo.accounts_email_domain', 'example.test');
        $prefix = Str::slug($type) ?: 'demo';

        return "{$prefix}-demo@{$domain}";
    }

    private function resolveAccountName(string $type): string
    {
        return match ($type) {
            self::TYPE_SERVICE => 'Service Demo Owner',
            self::TYPE_PRODUCT => 'Product Demo Owner',
            self::TYPE_GUIDED => 'Guided Demo Owner',
            default => 'Demo Owner',
        };
    }

    private function resolveCompanyName(string $type): string
    {
        return match ($type) {
            self::TYPE_SERVICE => 'Service Demo Co.',
            self::TYPE_PRODUCT => 'Product Demo Co.',
            self::TYPE_GUIDED => 'Guided Demo Co.',
            default => 'Demo Company',
        };
    }

    private function resolveDemoRole(string $type): string
    {
        return match ($type) {
            self::TYPE_SERVICE => 'service_demo',
            self::TYPE_PRODUCT => 'product_demo',
            self::TYPE_GUIDED => 'guided_demo',
            default => 'demo',
        };
    }

    private function resolveOwnerRoleId(): int
    {
        return Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;
    }

    private function resolveDemoFeatures(): array
    {
        return [
            'requests' => true,
            'quotes' => true,
            'plan_scans' => true,
            'jobs' => true,
            'tasks' => true,
            'invoices' => true,
            'products' => true,
            'services' => true,
            'team_members' => true,
            'sales' => true,
            'assistant' => true,
        ];
    }

    private function resolveDemoLimits(): array
    {
        return [
            'quotes' => 500,
            'requests' => 200,
            'jobs' => 200,
            'tasks' => 500,
            'invoices' => 200,
            'products' => 500,
            'services' => 200,
            'team_members' => 50,
            'sales' => 200,
            'plan_scans' => 100,
        ];
    }

    private function guardType(string $type): void
    {
        if (!in_array($type, [self::TYPE_SERVICE, self::TYPE_PRODUCT, self::TYPE_GUIDED], true)) {
            abort(400, 'Invalid demo type.');
        }
    }
}
