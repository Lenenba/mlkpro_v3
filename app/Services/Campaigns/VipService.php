<?php

namespace App\Services\Campaigns;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\VipTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VipService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, VipTier>
     */
    public function listTiers(User $accountOwner, array $filters = []): Collection
    {
        return VipTier::query()
            ->forAccount($accountOwner->id)
            ->when(array_key_exists('is_active', $filters), function (Builder $query) use ($filters): void {
                $query->where('is_active', (bool) $filters['is_active']);
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveTier(User $accountOwner, User $actor, array $payload, ?VipTier $tier = null): VipTier
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));

        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => 'VIP tier code and name are required.',
            ]);
        }

        if ($tier && (int) $tier->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'tier' => 'VIP tier does not belong to this tenant.',
            ]);
        }

        $exists = VipTier::query()
            ->forAccount($accountOwner->id)
            ->where('code', $code)
            ->when($tier?->exists, fn (Builder $query) => $query->where('id', '!=', $tier->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'code' => 'VIP tier code already exists for this tenant.',
            ]);
        }

        $model = $tier ?? new VipTier();
        $model->fill([
            'user_id' => $accountOwner->id,
            'created_by_user_id' => $model->created_by_user_id ?: $actor->id,
            'updated_by_user_id' => $actor->id,
            'code' => $code,
            'name' => $name,
            'perks' => $this->normalizePerks($payload['perks'] ?? null),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
        $model->save();

        return $model->fresh();
    }

    public function deleteTier(User $accountOwner, VipTier $tier): void
    {
        if ((int) $tier->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'tier' => 'VIP tier does not belong to this tenant.',
            ]);
        }

        $tier->delete();
    }

    public function updateCustomerVip(
        User $accountOwner,
        User $actor,
        Customer $customer,
        bool $isVip,
        ?int $vipTierId = null
    ): Customer {
        if ((int) $customer->user_id !== (int) $accountOwner->id) {
            throw ValidationException::withMessages([
                'customer' => 'Customer does not belong to this tenant.',
            ]);
        }

        $tier = null;
        if ($isVip && $vipTierId) {
            $tier = VipTier::query()
                ->forAccount($accountOwner->id)
                ->where('is_active', true)
                ->whereKey($vipTierId)
                ->first();
            if (!$tier) {
                throw ValidationException::withMessages([
                    'vip_tier_id' => 'Invalid VIP tier for this tenant.',
                ]);
            }
        }

        $customer->fill([
            'is_vip' => $isVip,
            'vip_tier_id' => $tier?->id,
            'vip_tier_code' => $tier?->code,
            'vip_since_at' => $isVip
                ? ($customer->vip_since_at ?: now())
                : null,
        ]);
        $customer->save();

        ActivityLog::record($actor, $customer, 'customer_vip_updated', [
            'is_vip' => $customer->is_vip,
            'vip_tier_id' => $customer->vip_tier_id,
            'vip_tier_code' => $customer->vip_tier_code,
        ], 'Customer VIP profile updated');

        return $customer->fresh('vipTier:id,user_id,code,name,perks,is_active');
    }

    /**
     * @return array<int, string>
     */
    private function normalizePerks(mixed $value): array
    {
        $items = collect();

        if (is_string($value)) {
            $items = collect(preg_split('/[\r\n,;]+/', $value) ?: []);
        } elseif (is_array($value)) {
            $items = collect($value);
        }

        return $items
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function runAutomationForAccount(User $accountOwner, bool $dryRun = false): array
    {
        $automation = $this->normalizeAutomationConfig(
            $this->marketingSettingsService->getValue($accountOwner, 'vip.automation', [])
        );

        $summary = [
            'account_id' => $accountOwner->id,
            'automation_enabled' => $automation['enabled'],
            'dry_run' => $dryRun,
            'window_days' => $automation['evaluation_window_days'],
            'minimum_total_spend' => $automation['minimum_total_spend'],
            'minimum_paid_orders' => $automation['minimum_paid_orders'],
            'customers_processed' => 0,
            'customers_updated' => 0,
            'customers_promoted' => 0,
            'customers_downgraded' => 0,
            'tiers_updated' => 0,
            'skipped_reason' => null,
        ];

        if (!$automation['enabled']) {
            $summary['skipped_reason'] = 'automation_disabled';
            return $summary;
        }

        $hasThreshold = $automation['minimum_total_spend'] !== null
            || $automation['minimum_paid_orders'] !== null;
        if (!$hasThreshold) {
            $summary['skipped_reason'] = 'missing_thresholds';
            return $summary;
        }

        $defaultTier = null;
        if ($automation['default_tier_code'] !== '') {
            $defaultTier = VipTier::query()
                ->forAccount($accountOwner->id)
                ->where('is_active', true)
                ->where('code', $automation['default_tier_code'])
                ->first();
        }

        $windowStart = now()->subDays($automation['evaluation_window_days'])->startOfDay();

        $salesStats = Sale::query()
            ->selectRaw('customer_id, COUNT(*) as paid_orders, COALESCE(SUM(total), 0) as total_spend')
            ->where('user_id', $accountOwner->id)
            ->where('status', Sale::STATUS_PAID)
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $windowStart)
            ->groupBy('customer_id');

        $excludedCustomerLookup = collect($automation['excluded_customer_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->flip()
            ->all();

        Customer::query()
            ->select([
                'customers.id',
                'customers.user_id',
                'customers.is_vip',
                'customers.vip_tier_id',
                'customers.vip_tier_code',
                'customers.vip_since_at',
                DB::raw('COALESCE(vip_automation_stats.total_spend, 0) as auto_total_spend'),
                DB::raw('COALESCE(vip_automation_stats.paid_orders, 0) as auto_paid_orders'),
            ])
            ->leftJoinSub($salesStats, 'vip_automation_stats', function ($join): void {
                $join->on('vip_automation_stats.customer_id', '=', 'customers.id');
            })
            ->where('customers.user_id', $accountOwner->id)
            ->orderBy('customers.id')
            ->chunkById(200, function (Collection $customers) use (
                &$summary,
                $automation,
                $defaultTier,
                $dryRun,
                $excludedCustomerLookup
            ): void {
                foreach ($customers as $customer) {
                    if (isset($excludedCustomerLookup[$customer->id])) {
                        continue;
                    }

                    $summary['customers_processed'] += 1;

                    $totalSpend = (float) ($customer->auto_total_spend ?? 0);
                    $paidOrders = (int) ($customer->auto_paid_orders ?? 0);
                    $isEligible = $this->meetsAutomationThreshold($automation, $totalSpend, $paidOrders);

                    $currentIsVip = (bool) $customer->is_vip;
                    $currentTierId = $customer->vip_tier_id ? (int) $customer->vip_tier_id : null;
                    $currentTierCode = $customer->vip_tier_code
                        ? strtoupper((string) $customer->vip_tier_code)
                        : null;

                    if (!$isEligible && !$automation['downgrade_when_not_eligible']) {
                        continue;
                    }

                    $targetIsVip = $isEligible;
                    $targetTierId = $currentTierId;
                    $targetTierCode = $currentTierCode;

                    if ($targetIsVip) {
                        $hasCurrentTier = $currentTierId !== null || $currentTierCode !== null;
                        $shouldAssignDefaultTier = $defaultTier
                            && (!$automation['preserve_existing_tier'] || !$hasCurrentTier);

                        if ($shouldAssignDefaultTier) {
                            $targetTierId = (int) $defaultTier->id;
                            $targetTierCode = strtoupper((string) $defaultTier->code);
                        } elseif (!$automation['preserve_existing_tier']) {
                            $targetTierId = null;
                            $targetTierCode = null;
                        }
                    } else {
                        $targetTierId = null;
                        $targetTierCode = null;
                    }

                    $targetVipSinceAt = $targetIsVip
                        ? ($customer->vip_since_at ?: now())
                        : null;

                    $tierChanged = $currentTierId !== $targetTierId
                        || $currentTierCode !== $targetTierCode;
                    $hasChanged = $currentIsVip !== $targetIsVip
                        || $tierChanged;

                    if (!$hasChanged) {
                        continue;
                    }

                    if (!$currentIsVip && $targetIsVip) {
                        $summary['customers_promoted'] += 1;
                    } elseif ($currentIsVip && !$targetIsVip) {
                        $summary['customers_downgraded'] += 1;
                    }
                    if ($tierChanged) {
                        $summary['tiers_updated'] += 1;
                    }

                    $summary['customers_updated'] += 1;

                    if ($dryRun) {
                        continue;
                    }

                    $customer->fill([
                        'is_vip' => $targetIsVip,
                        'vip_tier_id' => $targetTierId,
                        'vip_tier_code' => $targetTierCode,
                        'vip_since_at' => $targetVipSinceAt,
                    ]);
                    $customer->save();

                    ActivityLog::record(null, $customer, 'customer_vip_auto_synced', [
                        'was_vip' => $currentIsVip,
                        'is_vip' => $targetIsVip,
                        'previous_vip_tier_id' => $currentTierId,
                        'vip_tier_id' => $targetTierId,
                        'previous_vip_tier_code' => $currentTierCode,
                        'vip_tier_code' => $targetTierCode,
                        'window_days' => $automation['evaluation_window_days'],
                        'minimum_total_spend' => $automation['minimum_total_spend'],
                        'minimum_paid_orders' => $automation['minimum_paid_orders'],
                        'total_spend' => $totalSpend,
                        'paid_orders' => $paidOrders,
                    ], 'Customer VIP profile synchronized automatically');
                }
            }, 'customers.id', 'id');

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function runAutomationForTenants(?int $accountOwnerId = null, bool $dryRun = false): array
    {
        $summary = [
            'dry_run' => $dryRun,
            'accounts_scanned' => 0,
            'accounts_processed' => 0,
            'accounts_skipped' => 0,
            'customers_processed' => 0,
            'customers_updated' => 0,
            'customers_promoted' => 0,
            'customers_downgraded' => 0,
            'tiers_updated' => 0,
            'results' => [],
        ];

        $ownerRoleId = (int) Role::query()->where('name', 'owner')->value('id');
        if ($ownerRoleId <= 0) {
            return $summary;
        }

        $ownersQuery = User::query()
            ->select(['id', 'company_features'])
            ->where('role_id', $ownerRoleId)
            ->orderBy('id');

        if ($accountOwnerId !== null) {
            $ownersQuery->whereKey($accountOwnerId);
        }

        $ownersQuery->chunkById(50, function (Collection $owners) use (&$summary, $dryRun): void {
            foreach ($owners as $owner) {
                $summary['accounts_scanned'] += 1;

                $campaignsEnabled = (bool) data_get($owner->company_features ?? [], 'campaigns', false);
                if (!$campaignsEnabled) {
                    $summary['accounts_skipped'] += 1;
                    continue;
                }

                $result = $this->runAutomationForAccount($owner, $dryRun);
                $summary['results'][] = $result;

                if (!empty($result['skipped_reason'])) {
                    $summary['accounts_skipped'] += 1;
                    continue;
                }

                $summary['accounts_processed'] += 1;
                $summary['customers_processed'] += (int) ($result['customers_processed'] ?? 0);
                $summary['customers_updated'] += (int) ($result['customers_updated'] ?? 0);
                $summary['customers_promoted'] += (int) ($result['customers_promoted'] ?? 0);
                $summary['customers_downgraded'] += (int) ($result['customers_downgraded'] ?? 0);
                $summary['tiers_updated'] += (int) ($result['tiers_updated'] ?? 0);
            }
        }, 'id');

        return $summary;
    }

    /**
     * @param array<string, mixed> $automation
     */
    private function meetsAutomationThreshold(array $automation, float $totalSpend, int $paidOrders): bool
    {
        $minSpend = $automation['minimum_total_spend'];
        $minOrders = $automation['minimum_paid_orders'];

        $spendOk = $minSpend === null || $totalSpend >= (float) $minSpend;
        $ordersOk = $minOrders === null || $paidOrders >= (int) $minOrders;

        return $spendOk && $ordersOk;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     evaluation_window_days: int,
     *     minimum_total_spend: ?float,
     *     minimum_paid_orders: ?int,
     *     default_tier_code: string,
     *     preserve_existing_tier: bool,
     *     downgrade_when_not_eligible: bool,
     *     excluded_customer_ids: array<int, int>
     * }
     */
    private function normalizeAutomationConfig(mixed $value): array
    {
        $config = is_array($value) ? $value : [];
        $minimumTotalSpend = isset($config['minimum_total_spend']) && is_numeric($config['minimum_total_spend'])
            ? max(0.0, (float) $config['minimum_total_spend'])
            : null;
        $minimumPaidOrders = isset($config['minimum_paid_orders']) && is_numeric($config['minimum_paid_orders'])
            ? max(0, (int) $config['minimum_paid_orders'])
            : null;

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'evaluation_window_days' => max(1, (int) ($config['evaluation_window_days'] ?? 365)),
            'minimum_total_spend' => $minimumTotalSpend,
            'minimum_paid_orders' => $minimumPaidOrders,
            'default_tier_code' => strtoupper(trim((string) ($config['default_tier_code'] ?? ''))),
            'preserve_existing_tier' => (bool) ($config['preserve_existing_tier'] ?? true),
            'downgrade_when_not_eligible' => (bool) ($config['downgrade_when_not_eligible'] ?? false),
            'excluded_customer_ids' => collect($config['excluded_customer_ids'] ?? [])
                ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
                ->filter(fn ($id) => is_int($id) && $id > 0)
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
