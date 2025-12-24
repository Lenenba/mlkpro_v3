<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Validation\ValidationException;
use Laravel\Paddle\Subscription;

class UsageLimitService
{
    public const LIMIT_KEYS = [
        'quotes' => 'Quotes',
        'invoices' => 'Invoices',
        'jobs' => 'Jobs',
        'products' => 'Products',
        'services' => 'Services',
        'tasks' => 'Tasks',
        'team_members' => 'Team members',
    ];

    public function buildForUser(User $user): array
    {
        $accountOwner = $this->resolveAccountOwner($user);
        $stats = $this->resolveUsageStats($accountOwner);
        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planKey = $this->resolvePlanKey($accountOwner, $planLimits);
        $planDefaults = $planKey ? ($planLimits[$planKey] ?? []) : [];
        $overrides = $accountOwner->company_limits ?? [];

        $items = [];
        foreach (self::LIMIT_KEYS as $key => $label) {
            $used = (int) ($stats[$key] ?? 0);
            $override = $overrides[$key] ?? null;
            $defaultLimit = $planDefaults[$key] ?? null;
            $limit = is_numeric($override)
                ? (int) $override
                : (is_numeric($defaultLimit) ? (int) $defaultLimit : null);

            $percent = null;
            $status = 'ok';
            if ($limit !== null) {
                if ($limit <= 0) {
                    $status = $used > 0 ? 'over' : 'warning';
                } else {
                    $percent = round(($used / $limit) * 100, 1);
                    if ($used > $limit) {
                        $status = 'over';
                    } elseif ($percent >= 90) {
                        $status = 'warning';
                    }
                }
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'status' => $status,
                'remaining' => $limit !== null ? max(0, $limit - $used) : null,
                'source' => is_numeric($override) ? 'override' : 'plan',
                'override' => is_numeric($override) ? (int) $override : null,
                'plan_limit' => is_numeric($defaultLimit) ? (int) $defaultLimit : null,
            ];
        }

        return [
            'plan_key' => $planKey,
            'plan_name' => $planKey ? (config('billing.plans.' . $planKey . '.name') ?? $planKey) : null,
            'items' => $items,
            'overrides' => $overrides,
            'plan_limits' => $planDefaults,
        ];
    }

    public function enforceLimit(User $user, string $key, int $increment = 1): void
    {
        $accountOwner = $this->resolveAccountOwner($user);
        $limit = $this->resolveLimit($accountOwner, $key);
        if ($limit === null) {
            return;
        }

        $used = (int) ($this->resolveUsageStats($accountOwner)[$key] ?? 0);
        if (($used + $increment) > $limit) {
            $label = self::LIMIT_KEYS[$key] ?? $key;
            $message = sprintf(
                'Limit reached for %s (%d/%d). Upgrade your plan or increase the limit.',
                $label,
                $used,
                $limit
            );

            throw ValidationException::withMessages([
                'limit' => $message,
            ]);
        }
    }

    private function resolveAccountOwner(User $user): User
    {
        $ownerId = $user->accountOwnerId();
        if ($ownerId === $user->id) {
            return $user;
        }

        return User::query()->findOrFail($ownerId);
    }

    private function resolveLimit(User $accountOwner, string $key): ?int
    {
        $planLimits = PlatformSetting::getValue('plan_limits', []);
        $planKey = $this->resolvePlanKey($accountOwner, $planLimits);
        $planDefaults = $planKey ? ($planLimits[$planKey] ?? []) : [];
        $overrides = $accountOwner->company_limits ?? [];

        $override = $overrides[$key] ?? null;
        $defaultLimit = $planDefaults[$key] ?? null;

        if (is_numeric($override)) {
            return (int) $override;
        }

        if (is_numeric($defaultLimit)) {
            return (int) $defaultLimit;
        }

        return null;
    }

    private function resolvePlanKey(User $accountOwner, array $planLimits): ?string
    {
        $subscription = $accountOwner->subscription(Subscription::DEFAULT_TYPE);
        $priceId = $subscription?->items()->value('price_id');

        if ($priceId) {
            foreach (config('billing.plans', []) as $key => $plan) {
                if (!empty($plan['price_id']) && $plan['price_id'] === $priceId) {
                    return $key;
                }
            }
        }

        return array_key_exists('free', $planLimits) ? 'free' : null;
    }

    private function resolveUsageStats(User $accountOwner): array
    {
        $accountId = $accountOwner->id;

        return [
            'quotes' => Quote::query()->where('user_id', $accountId)->count(),
            'invoices' => Invoice::query()->where('user_id', $accountId)->count(),
            'jobs' => Work::query()->where('user_id', $accountId)->count(),
            'products' => Product::query()
                ->where('user_id', $accountId)
                ->where('item_type', Product::ITEM_TYPE_PRODUCT)
                ->count(),
            'services' => Product::query()
                ->where('user_id', $accountId)
                ->where('item_type', Product::ITEM_TYPE_SERVICE)
                ->count(),
            'tasks' => Task::query()->where('account_id', $accountId)->count(),
            'team_members' => TeamMember::query()->where('account_id', $accountId)->active()->count(),
        ];
    }
}
