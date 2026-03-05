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
            'automation_mode' => null,
            'tier_rules_configured' => count($automation['tier_rules']),
            'tier_rules_active' => 0,
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

        $tierRules = $this->resolveTierRulesForAccount($accountOwner, $automation['tier_rules']);
        $summary['tier_rules_active'] = count($tierRules);
        $usesTierRules = $tierRules !== [];

        if ($usesTierRules) {
            $summary['automation_mode'] = 'tier_rules';
        } else {
            $summary['automation_mode'] = 'global';
        }

        $hasGlobalThreshold = $automation['minimum_total_spend'] !== null
            || $automation['minimum_paid_orders'] !== null;
        if (!$usesTierRules && !$hasGlobalThreshold) {
            $summary['skipped_reason'] = 'missing_thresholds';
            return $summary;
        }

        $defaultTier = null;
        if (!$usesTierRules && $automation['default_tier_code'] !== '') {
            $defaultTier = VipTier::query()
                ->forAccount($accountOwner->id)
                ->where('is_active', true)
                ->where('code', $automation['default_tier_code'])
                ->first();
        }

        $windowDays = $usesTierRules
            ? collect($tierRules)
                ->pluck('evaluation_window_days')
                ->map(fn ($days) => (int) $days)
                ->filter(fn ($days) => $days > 0)
                ->unique()
                ->values()
                ->all()
            : [$automation['evaluation_window_days']];
        if ($windowDays === []) {
            $windowDays = [max(1, (int) $automation['evaluation_window_days'])];
        }

        $salesStats = $this->buildSalesStatsQuery($accountOwner->id, $windowDays);

        $excludedCustomerLookup = collect($automation['excluded_customer_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->flip()
            ->all();

        Customer::query()
            ->select($this->customerSelectFields($windowDays))
            ->leftJoinSub($salesStats, 'vip_automation_stats', function ($join): void {
                $join->on('vip_automation_stats.customer_id', '=', 'customers.id');
            })
            ->where('customers.user_id', $accountOwner->id)
            ->orderBy('customers.id')
            ->chunkById(200, function (Collection $customers) use (
                &$summary,
                $automation,
                $tierRules,
                $usesTierRules,
                $defaultTier,
                $dryRun,
                $excludedCustomerLookup
            ): void {
                foreach ($customers as $customer) {
                    if (isset($excludedCustomerLookup[$customer->id])) {
                        continue;
                    }

                    $summary['customers_processed'] += 1;

                    $currentIsVip = (bool) $customer->is_vip;
                    $currentTierId = $customer->vip_tier_id ? (int) $customer->vip_tier_id : null;
                    $currentTierCode = $customer->vip_tier_code
                        ? strtoupper((string) $customer->vip_tier_code)
                        : null;

                    $targetIsVip = false;
                    $targetTierId = null;
                    $targetTierCode = null;
                    $selectedWindowDays = max(1, (int) $automation['evaluation_window_days']);
                    $selectedMinimumSpend = $automation['minimum_total_spend'];
                    $selectedMinimumOrders = $automation['minimum_paid_orders'];
                    $totalSpend = 0.0;
                    $paidOrders = 0;

                    if ($usesTierRules) {
                        $matchedRule = $this->resolveTierRuleAssignment(
                            $customer,
                            $tierRules,
                            $automation['preserve_existing_tier']
                        );

                        if ($matchedRule) {
                            $targetIsVip = true;
                            $targetTierId = (int) $matchedRule['tier_id'];
                            $targetTierCode = strtoupper((string) $matchedRule['tier_code']);
                            $selectedWindowDays = (int) $matchedRule['evaluation_window_days'];
                            $selectedMinimumSpend = $matchedRule['minimum_total_spend'];
                            $selectedMinimumOrders = $matchedRule['minimum_paid_orders'];
                            $totalSpend = (float) ($matchedRule['matched_total_spend'] ?? 0);
                            $paidOrders = (int) ($matchedRule['matched_paid_orders'] ?? 0);
                        }
                    } else {
                        $metrics = $this->metricsForWindow($customer, $selectedWindowDays);
                        $totalSpend = (float) $metrics['total_spend'];
                        $paidOrders = (int) $metrics['paid_orders'];
                        $targetIsVip = $this->meetsAutomationThreshold($automation, $totalSpend, $paidOrders);

                        if ($targetIsVip) {
                            $hasCurrentTier = $currentTierId !== null || $currentTierCode !== null;
                            $shouldAssignDefaultTier = $defaultTier
                                && (!$automation['preserve_existing_tier'] || !$hasCurrentTier);

                            if ($shouldAssignDefaultTier) {
                                $targetTierId = (int) $defaultTier->id;
                                $targetTierCode = strtoupper((string) $defaultTier->code);
                            } elseif ($automation['preserve_existing_tier']) {
                                $targetTierId = $currentTierId;
                                $targetTierCode = $currentTierCode;
                            } else {
                                $targetTierId = null;
                                $targetTierCode = null;
                            }
                        } elseif (!$automation['preserve_existing_tier']) {
                            $targetTierId = null;
                            $targetTierCode = null;
                        }
                    }

                    if (!$targetIsVip && !$automation['downgrade_when_not_eligible']) {
                        continue;
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
                        'automation_mode' => $summary['automation_mode'],
                        'window_days' => $selectedWindowDays,
                        'minimum_total_spend' => $selectedMinimumSpend,
                        'minimum_paid_orders' => $selectedMinimumOrders,
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
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function resolveTierRulesForAccount(User $accountOwner, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $activeTiersByCode = VipTier::query()
            ->forAccount($accountOwner->id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (VipTier $tier) => strtoupper((string) $tier->code));

        return collect($rules)
            ->map(function (array $rule) use ($activeTiersByCode): ?array {
                $code = strtoupper(trim((string) ($rule['tier_code'] ?? '')));
                if ($code === '' || !$activeTiersByCode->has($code)) {
                    return null;
                }

                /** @var VipTier $tier */
                $tier = $activeTiersByCode->get($code);

                return [
                    'tier_id' => (int) $tier->id,
                    'tier_code' => strtoupper((string) $tier->code),
                    'evaluation_window_days' => max(1, (int) ($rule['evaluation_window_days'] ?? 365)),
                    'minimum_total_spend' => $rule['minimum_total_spend'],
                    'minimum_paid_orders' => $rule['minimum_paid_orders'],
                    'priority' => isset($rule['priority']) && is_numeric($rule['priority'])
                        ? (int) $rule['priority']
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $tierRules
     * @return array<string, mixed>|null
     */
    private function resolveTierRuleAssignment(
        Customer $customer,
        array $tierRules,
        bool $preserveExistingTier
    ): ?array {
        $matchingRules = [];

        foreach ($tierRules as $rule) {
            $metrics = $this->metricsForWindow($customer, (int) $rule['evaluation_window_days']);
            if (!$this->meetsAutomationThreshold(
                $rule,
                (float) $metrics['total_spend'],
                (int) $metrics['paid_orders']
            )) {
                continue;
            }

            $rule['matched_total_spend'] = (float) $metrics['total_spend'];
            $rule['matched_paid_orders'] = (int) $metrics['paid_orders'];
            $matchingRules[] = $rule;
        }

        if ($matchingRules === []) {
            return null;
        }

        if ($preserveExistingTier) {
            $currentTierCode = strtoupper(trim((string) ($customer->vip_tier_code ?? '')));
            if ($currentTierCode !== '') {
                foreach ($matchingRules as $rule) {
                    if (strtoupper((string) $rule['tier_code']) === $currentTierCode) {
                        return $rule;
                    }
                }
            }
        }

        return $matchingRules[0];
    }

    /**
     * @param array<int, int> $windowDays
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\Sale>
     */
    private function buildSalesStatsQuery(int $accountOwnerId, array $windowDays): Builder
    {
        $maxWindowDays = max($windowDays);
        $query = Sale::query()
            ->select('customer_id')
            ->where('user_id', $accountOwnerId)
            ->where('status', Sale::STATUS_PAID)
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', now()->subDays($maxWindowDays)->startOfDay());

        foreach ($windowDays as $days) {
            $windowStart = now()->subDays($days)->startOfDay()->toDateTimeString();
            $spendAlias = $this->spendAlias($days);
            $ordersAlias = $this->ordersAlias($days);

            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN created_at >= ? THEN total ELSE 0 END), 0) as {$spendAlias}",
                [$windowStart]
            );
            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) as {$ordersAlias}",
                [$windowStart]
            );
        }

        return $query->groupBy('customer_id');
    }

    /**
     * @param array<int, int> $windowDays
     * @return array<int, mixed>
     */
    private function customerSelectFields(array $windowDays): array
    {
        $fields = [
            'customers.id',
            'customers.user_id',
            'customers.is_vip',
            'customers.vip_tier_id',
            'customers.vip_tier_code',
            'customers.vip_since_at',
        ];

        foreach ($windowDays as $days) {
            $spendAlias = $this->spendAlias($days);
            $ordersAlias = $this->ordersAlias($days);

            $fields[] = DB::raw("COALESCE(vip_automation_stats.{$spendAlias}, 0) as {$spendAlias}");
            $fields[] = DB::raw("COALESCE(vip_automation_stats.{$ordersAlias}, 0) as {$ordersAlias}");
        }

        return $fields;
    }

    /**
     * @return array{total_spend: float, paid_orders: int}
     */
    private function metricsForWindow(Customer $customer, int $windowDays): array
    {
        $spendAlias = $this->spendAlias($windowDays);
        $ordersAlias = $this->ordersAlias($windowDays);

        return [
            'total_spend' => (float) ($customer->{$spendAlias} ?? 0),
            'paid_orders' => (int) ($customer->{$ordersAlias} ?? 0),
        ];
    }

    private function spendAlias(int $windowDays): string
    {
        return 'auto_total_spend_' . max(1, $windowDays);
    }

    private function ordersAlias(int $windowDays): string
    {
        return 'auto_paid_orders_' . max(1, $windowDays);
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
     *     excluded_customer_ids: array<int, int>,
     *     tier_rules: array<int, array{
     *         tier_code: string,
     *         evaluation_window_days: int,
     *         minimum_total_spend: ?float,
     *         minimum_paid_orders: ?int,
     *         priority: ?int
     *     }>
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
        $baseWindowDays = max(1, (int) ($config['evaluation_window_days'] ?? 365));

        $normalizedTierRules = collect($config['tier_rules'] ?? [])
            ->map(function ($rule, int $index) use ($baseWindowDays): ?array {
                if (!is_array($rule)) {
                    return null;
                }

                $tierCode = strtoupper(trim((string) ($rule['tier_code'] ?? '')));
                if ($tierCode === '') {
                    return null;
                }

                $minimumTotalSpend = isset($rule['minimum_total_spend']) && is_numeric($rule['minimum_total_spend'])
                    ? max(0.0, (float) $rule['minimum_total_spend'])
                    : null;
                $minimumPaidOrders = isset($rule['minimum_paid_orders']) && is_numeric($rule['minimum_paid_orders'])
                    ? max(0, (int) $rule['minimum_paid_orders'])
                    : null;

                if ($minimumTotalSpend === null && $minimumPaidOrders === null) {
                    return null;
                }

                $priority = isset($rule['priority']) && is_numeric($rule['priority'])
                    ? (int) $rule['priority']
                    : null;

                return [
                    'tier_code' => $tierCode,
                    'evaluation_window_days' => isset($rule['evaluation_window_days']) && is_numeric($rule['evaluation_window_days'])
                        ? max(1, (int) $rule['evaluation_window_days'])
                        : $baseWindowDays,
                    'minimum_total_spend' => $minimumTotalSpend,
                    'minimum_paid_orders' => $minimumPaidOrders,
                    'priority' => $priority,
                    'sequence' => $index,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $leftPriority = $left['priority'] ?? (1000 - (int) $left['sequence']);
                $rightPriority = $right['priority'] ?? (1000 - (int) $right['sequence']);

                if ($leftPriority !== $rightPriority) {
                    return $rightPriority <=> $leftPriority;
                }

                $leftSpend = $left['minimum_total_spend'] ?? -1;
                $rightSpend = $right['minimum_total_spend'] ?? -1;
                if ($leftSpend !== $rightSpend) {
                    return $rightSpend <=> $leftSpend;
                }

                $leftOrders = $left['minimum_paid_orders'] ?? -1;
                $rightOrders = $right['minimum_paid_orders'] ?? -1;
                if ($leftOrders !== $rightOrders) {
                    return $rightOrders <=> $leftOrders;
                }

                return ((int) $left['sequence']) <=> ((int) $right['sequence']);
            })
            ->values()
            ->map(function (array $rule): array {
                unset($rule['sequence']);
                return $rule;
            })
            ->all();

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'evaluation_window_days' => $baseWindowDays,
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
            'tier_rules' => $normalizedTierRules,
        ];
    }
}
