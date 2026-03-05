<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignAudienceSourceLogic;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AudienceResolver
{
    public function __construct(
        private readonly ConsentService $consentService,
        private readonly FatigueLimiter $fatigueLimiter,
    ) {
    }

    public function estimateForCampaign(Campaign $campaign): array
    {
        $resolved = $this->resolveForCampaign($campaign);

        return $resolved['counts'];
    }

    public function resolveForCampaign(Campaign $campaign): array
    {
        $campaign->loadMissing(['audience', 'audienceSegment', 'channels', 'offers.offer', 'products', 'user']);
        $enabledChannels = $campaign->channels
            ->where('is_enabled', true)
            ->pluck('channel')
            ->map(fn ($channel) => strtoupper((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($enabledChannels === []) {
            return [
                'eligible' => [],
                'blocked' => [],
                'counts' => [
                    'total_eligible' => 0,
                    'eligible_by_channel' => [],
                    'blocked_by_channel' => [],
                    'blocked_by_reason' => [],
                ],
            ];
        }

        $audience = $campaign->audience;
        $dynamicQuery = Customer::query()->where('user_id', $campaign->user_id);

        $segmentFilters = is_array($campaign->audienceSegment?->filters)
            ? $campaign->audienceSegment->filters
            : null;
        $segmentExclusions = is_array($campaign->audienceSegment?->exclusions)
            ? $campaign->audienceSegment->exclusions
            : null;

        $smartFilters = is_array($audience?->smart_filters) && $audience->smart_filters !== []
            ? $audience->smart_filters
            : $segmentFilters;
        if ($smartFilters) {
            $this->applyGroup($dynamicQuery, $smartFilters);
        }

        $exclusions = is_array($audience?->exclusion_filters) && $audience->exclusion_filters !== []
            ? $audience->exclusion_filters
            : $segmentExclusions;
        if ($exclusions) {
            $dynamicQuery->whereNot(function (Builder $builder) use ($exclusions): void {
                $this->applyGroup($builder, $exclusions);
            });
        }

        $dynamicCustomerIds = $dynamicQuery
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique()
            ->values();

        $manualCustomerIds = $this->normalizeCustomerIds($audience?->manual_customer_ids ?? []);
        $includeMailingListIds = $this->normalizeCustomerIds($audience?->include_mailing_list_ids ?? []);
        $excludeMailingListIds = $this->normalizeCustomerIds($audience?->exclude_mailing_list_ids ?? []);

        $includeMailingListCustomerIds = $this->mailingListCustomerIds(
            $campaign->user_id,
            $includeMailingListIds->all()
        );
        $excludeMailingListCustomerIds = $this->mailingListCustomerIds(
            $campaign->user_id,
            $excludeMailingListIds->all()
        );

        $sourceLogic = CampaignAudienceSourceLogic::normalize((string) ($audience?->source_logic ?? null));
        $resolvedCustomerIds = $this->resolveCustomerIdsBySourceLogic(
            $sourceLogic,
            $dynamicCustomerIds,
            $includeMailingListCustomerIds,
            $manualCustomerIds
        );

        if ($excludeMailingListCustomerIds->isNotEmpty()) {
            $resolvedCustomerIds = $resolvedCustomerIds
                ->diff($excludeMailingListCustomerIds)
                ->values();
        }

        $customers = collect();
        if ($resolvedCustomerIds->isNotEmpty()) {
            $customers = Customer::query()
                ->where('user_id', $campaign->user_id)
                ->whereIn('id', $resolvedCustomerIds->all())
                ->with([
                    'defaultProperty:id,customer_id,city',
                    'portalUser:id,locale',
                    'vipTier:id,user_id,code,name,perks',
                ])
                ->get([
                    'id',
                    'user_id',
                    'portal_user_id',
                    'first_name',
                    'last_name',
                    'company_name',
                    'email',
                    'phone',
                    'tags',
                    'is_vip',
                    'vip_tier_id',
                    'vip_tier_code',
                    'created_at',
                ]);
        }

        $manualContacts = $this->normalizeManualContacts($audience?->manual_contacts);
        $dedupe = [];
        $eligible = [];
        $blocked = [];
        $eligibleByChannel = [];
        $blockedByChannel = [];
        $blockedByReason = [];

        foreach ($customers as $customer) {
            foreach ($enabledChannels as $channel) {
                $destination = $this->destinationForCustomer($channel, $customer);
                $decision = $this->consentService->canReceive(
                    $campaign->user,
                    $customer,
                    $channel,
                    $destination
                );

                if (!($decision['allowed'] ?? false)) {
                    $this->pushBlocked(
                        $blocked,
                        $blockedByChannel,
                        $blockedByReason,
                        $channel,
                        (string) ($decision['reason'] ?? 'consent_denied'),
                        $customer->id,
                        $destination
                    );
                    continue;
                }

                $fatigueDecision = $this->fatigueLimiter->canSend($campaign->user, $customer, $channel, $campaign);
                if (!($fatigueDecision['allowed'] ?? false)) {
                    $this->pushBlocked(
                        $blocked,
                        $blockedByChannel,
                        $blockedByReason,
                        $channel,
                        (string) ($fatigueDecision['reason'] ?? 'fatigue_denied'),
                        $customer->id,
                        (string) $decision['destination']
                    );
                    continue;
                }

                $normalizedDestination = (string) $decision['destination'];
                $destinationHash = CampaignRecipient::destinationHash($normalizedDestination)
                    ?: hash('sha256', $channel . ':' . $normalizedDestination);
                $dedupeKey = $channel . '|' . $destinationHash;

                if (isset($dedupe[$dedupeKey])) {
                    $this->pushBlocked(
                        $blocked,
                        $blockedByChannel,
                        $blockedByReason,
                        $channel,
                        'duplicate_destination',
                        $customer->id,
                        $normalizedDestination
                    );
                    continue;
                }

                $dedupe[$dedupeKey] = true;
                $eligibleByChannel[$channel] = (int) ($eligibleByChannel[$channel] ?? 0) + 1;
                $eligible[] = [
                    'customer_id' => $customer->id,
                    'channel' => $channel,
                    'destination' => $normalizedDestination,
                    'destination_hash' => $destinationHash,
                    'metadata' => [
                        'source' => 'customer',
                    ],
                ];
            }
        }

        foreach ($manualContacts as $contact) {
            $channels = $contact['channel']
                ? [strtoupper((string) $contact['channel'])]
                : $this->inferChannels((string) $contact['destination'], $enabledChannels);

            foreach ($channels as $channel) {
                if (!in_array($channel, $enabledChannels, true)) {
                    continue;
                }

                $decision = $this->consentService->canReceive(
                    $campaign->user,
                    null,
                    $channel,
                    (string) $contact['destination']
                );

                if (!($decision['allowed'] ?? false)) {
                    $this->pushBlocked(
                        $blocked,
                        $blockedByChannel,
                        $blockedByReason,
                        $channel,
                        (string) ($decision['reason'] ?? 'consent_denied'),
                        null,
                        (string) $contact['destination']
                    );
                    continue;
                }

                $normalizedDestination = (string) $decision['destination'];
                $destinationHash = CampaignRecipient::destinationHash($normalizedDestination)
                    ?: hash('sha256', $channel . ':' . $normalizedDestination);
                $dedupeKey = $channel . '|' . $destinationHash;

                if (isset($dedupe[$dedupeKey])) {
                    $this->pushBlocked(
                        $blocked,
                        $blockedByChannel,
                        $blockedByReason,
                        $channel,
                        'duplicate_destination',
                        null,
                        $normalizedDestination
                    );
                    continue;
                }

                $dedupe[$dedupeKey] = true;
                $eligibleByChannel[$channel] = (int) ($eligibleByChannel[$channel] ?? 0) + 1;
                $eligible[] = [
                    'customer_id' => null,
                    'channel' => $channel,
                    'destination' => $normalizedDestination,
                    'destination_hash' => $destinationHash,
                    'metadata' => [
                        'source' => 'manual_contact',
                    ],
                ];
            }
        }

        return [
            'eligible' => $eligible,
            'blocked' => $blocked,
            'counts' => [
                'total_eligible' => count($eligible),
                'eligible_by_channel' => $eligibleByChannel,
                'blocked_by_channel' => $blockedByChannel,
                'blocked_by_reason' => $blockedByReason,
            ],
        ];
    }

    /**
     * @param mixed $values
     * @return Collection<int, int>
     */
    private function normalizeCustomerIds(mixed $values): Collection
    {
        if (!is_array($values)) {
            return collect();
        }

        return collect($values)
            ->map(fn ($value) => is_numeric($value) ? (int) $value : null)
            ->filter(fn ($value) => is_int($value) && $value > 0)
            ->unique()
            ->values();
    }

    /**
     * @param array<int, int> $mailingListIds
     * @return Collection<int, int>
     */
    private function mailingListCustomerIds(int $accountOwnerId, array $mailingListIds): Collection
    {
        if ($mailingListIds === []) {
            return collect();
        }

        if (!DB::getSchemaBuilder()->hasTable('mailing_lists') || !DB::getSchemaBuilder()->hasTable('mailing_list_customers')) {
            return collect();
        }

        return DB::table('mailing_list_customers')
            ->join('mailing_lists', 'mailing_lists.id', '=', 'mailing_list_customers.mailing_list_id')
            ->where('mailing_lists.user_id', $accountOwnerId)
            ->whereIn('mailing_lists.id', $mailingListIds)
            ->pluck('mailing_list_customers.customer_id')
            ->map(fn ($value) => (int) $value)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int, int> $dynamicCustomerIds
     * @param Collection<int, int> $includeMailingListCustomerIds
     * @param Collection<int, int> $manualCustomerIds
     * @return Collection<int, int>
     */
    private function resolveCustomerIdsBySourceLogic(
        CampaignAudienceSourceLogic $sourceLogic,
        Collection $dynamicCustomerIds,
        Collection $includeMailingListCustomerIds,
        Collection $manualCustomerIds
    ): Collection {
        if ($sourceLogic === CampaignAudienceSourceLogic::INTERSECT) {
            $intersection = $includeMailingListCustomerIds->isNotEmpty()
                ? (
                    $dynamicCustomerIds->isNotEmpty()
                        ? $dynamicCustomerIds->intersect($includeMailingListCustomerIds)->values()
                        : $includeMailingListCustomerIds->values()
                )
                : $dynamicCustomerIds->values();

            return $intersection
                ->concat($manualCustomerIds)
                ->unique()
                ->values();
        }

        return $dynamicCustomerIds
            ->concat($includeMailingListCustomerIds)
            ->concat($manualCustomerIds)
            ->unique()
            ->values();
    }

    private function destinationForCustomer(string $channel, Customer $customer): ?string
    {
        return match (strtoupper($channel)) {
            Campaign::CHANNEL_EMAIL => $customer->email,
            Campaign::CHANNEL_SMS => $customer->phone,
            Campaign::CHANNEL_IN_APP => $customer->portal_user_id ? (string) $customer->portal_user_id : null,
            default => null,
        };
    }

    private function normalizeManualContacts(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = [];
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $destination = trim($entry);
                if ($destination === '') {
                    continue;
                }

                $items[] = [
                    'destination' => $destination,
                    'channel' => null,
                ];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $destination = trim((string) ($entry['destination'] ?? $entry['value'] ?? $entry['email'] ?? $entry['phone'] ?? ''));
            if ($destination === '') {
                continue;
            }

            $channel = $entry['channel'] ?? null;
            $items[] = [
                'destination' => $destination,
                'channel' => $channel ? strtoupper((string) $channel) : null,
            ];
        }

        return $items;
    }

    private function inferChannels(string $destination, array $enabledChannels): array
    {
        if (str_contains($destination, '@') && in_array(Campaign::CHANNEL_EMAIL, $enabledChannels, true)) {
            return [Campaign::CHANNEL_EMAIL];
        }

        if (preg_match('/[0-9\+\-\(\)\s]{8,}/', $destination) && in_array(Campaign::CHANNEL_SMS, $enabledChannels, true)) {
            return [Campaign::CHANNEL_SMS];
        }

        return [];
    }

    private function pushBlocked(
        array &$blocked,
        array &$blockedByChannel,
        array &$blockedByReason,
        string $channel,
        string $reason,
        ?int $customerId,
        ?string $destination
    ): void {
        $blockedByChannel[$channel] = (int) ($blockedByChannel[$channel] ?? 0) + 1;
        $blockedByReason[$reason] = (int) ($blockedByReason[$reason] ?? 0) + 1;

        $blocked[] = [
            'customer_id' => $customerId,
            'channel' => $channel,
            'destination' => $destination,
            'reason' => $reason,
        ];
    }

    private function applyGroup(Builder $query, array $group): void
    {
        $operator = strtoupper((string) ($group['operator'] ?? 'AND'));
        $rules = $group['rules'] ?? null;
        if (!is_array($rules)) {
            $rules = array_is_list($group) ? $group : [];
        }

        if ($rules === []) {
            return;
        }

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $isNested = is_array($rule['rules'] ?? null);
            $method = $operator === 'OR' ? 'orWhere' : 'where';

            if ($index === 0 && $operator === 'OR') {
                $method = 'where';
            }

            $query->{$method}(function (Builder $builder) use ($rule, $isNested): void {
                if ($isNested) {
                    $this->applyGroup($builder, $rule);
                    return;
                }

                $this->applyRule($builder, $rule);
            });
        }
    }

    private function applyRule(Builder $query, array $rule): void
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = strtolower((string) ($rule['operator'] ?? 'equals'));
        $value = $rule['value'] ?? null;

        if ($field === '') {
            return;
        }

        switch ($field) {
            case 'tags':
                $this->applyTagsRule($query, $operator, $value);
                return;
            case 'segment_status':
                $this->applySegmentStatusRule($query, $value);
                return;
            case 'city':
                $query->whereHas('properties', function (Builder $builder) use ($operator, $value): void {
                    $this->applyStringComparison($builder, 'city', $operator, $value);
                });
                return;
            case 'language':
                $query->whereHas('portalUser', function (Builder $builder) use ($operator, $value): void {
                    $this->applyStringComparison($builder, 'locale', $operator, $value);
                });
                return;
            case 'has_email':
                $this->applyBooleanPresence($query, 'email', $value);
                return;
            case 'has_phone':
                $this->applyBooleanPresence($query, 'phone', $value);
                return;
            case 'has_app_account':
                if ((bool) $value) {
                    $query->whereNotNull('portal_user_id');
                } else {
                    $query->whereNull('portal_user_id');
                }
                return;
            case 'is_vip':
                $this->applyVipStatusRule($query, $operator, $value);
                return;
            case 'vip_tier_id':
                $this->applyVipTierIdRule($query, $operator, $value);
                return;
            case 'vip_tier':
            case 'vip_tier_code':
                $this->applyVipTierCodeRule($query, $operator, $value);
                return;
            case 'total_spend':
                $comparison = $this->comparisonOperator($operator);
                if (!$comparison || !is_numeric($value)) {
                    return;
                }

                $query->whereRaw(
                    "(SELECT COALESCE(SUM(total),0) FROM sales WHERE sales.user_id = customers.user_id AND sales.customer_id = customers.id AND sales.status = ?) {$comparison} ?",
                    ['paid', (float) $value]
                );
                return;
            case 'booking_frequency_per_month':
                $comparison = $this->comparisonOperator($operator);
                if (!$comparison || !is_numeric($value)) {
                    return;
                }

                $query->whereRaw(
                    "(SELECT COUNT(*) FROM reservations WHERE reservations.account_id = customers.user_id AND reservations.client_id = customers.id AND reservations.created_at >= ?) {$comparison} ?",
                    [now()->subDays(30), (int) $value]
                );
                return;
            case 'last_activity_days':
                $comparison = $this->comparisonOperator($operator);
                if (!$comparison || !is_numeric($value)) {
                    return;
                }

                $activityExpression = "COALESCE(
                    (SELECT MAX(created_at) FROM sales WHERE sales.user_id = customers.user_id AND sales.customer_id = customers.id),
                    (SELECT MAX(created_at) FROM quotes WHERE quotes.user_id = customers.user_id AND quotes.customer_id = customers.id),
                    (SELECT MAX(created_at) FROM reservations WHERE reservations.account_id = customers.user_id AND reservations.client_id = customers.id),
                    customers.created_at
                )";
                $query->whereRaw("TIMESTAMPDIFF(DAY, {$activityExpression}, NOW()) {$comparison} ?", [(int) $value]);
                return;
            case 'purchased_product_id':
                $this->applyPurchasedProductRule($query, $operator, $value);
                return;
            case 'purchased_category_id':
                $this->applyPurchasedCategoryRule($query, $operator, $value);
                return;
            case 'interest_score':
                $comparison = $this->comparisonOperator($operator);
                if (!$comparison || !is_numeric($value)) {
                    return;
                }

                $query->whereExists(function ($builder) use ($comparison, $value): void {
                    $builder->selectRaw('1')
                        ->from('customer_interest_scores')
                        ->whereColumn('customer_interest_scores.user_id', 'customers.user_id')
                        ->whereColumn('customer_interest_scores.customer_id', 'customers.id')
                        ->where('customer_interest_scores.score_scope', 'global')
                        ->whereRaw("customer_interest_scores.score {$comparison} ?", [(int) $value]);
                });
                return;
            case 'behavior_event':
                $this->applyBehaviorEventRule($query, $operator, $value);
                return;
            default:
                return;
        }
    }

    private function applyTagsRule(Builder $query, string $operator, mixed $value): void
    {
        $values = collect(Arr::wrap($value))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        if ($operator === 'contains_all') {
            foreach ($values as $tag) {
                $query->whereJsonContains('tags', $tag);
            }
            return;
        }

        if ($operator === 'not_contains') {
            foreach ($values as $tag) {
                $query->where(function (Builder $builder) use ($tag): void {
                    $builder->whereNull('tags')
                        ->orWhereJsonDoesntContain('tags', $tag);
                });
            }
            return;
        }

        $query->where(function (Builder $builder) use ($values): void {
            foreach ($values as $index => $tag) {
                $method = $index === 0 ? 'whereJsonContains' : 'orWhereJsonContains';
                $builder->{$method}('tags', $tag);
            }
        });
    }

    private function applySegmentStatusRule(Builder $query, mixed $value): void
    {
        $statuses = collect(Arr::wrap($value))
            ->map(fn ($item) => strtolower(trim((string) $item)))
            ->filter()
            ->values();

        if ($statuses->isEmpty()) {
            return;
        }

        $activeSince = now()->subDays(90);
        $lostSince = now()->subDays(180);

        $query->where(function (Builder $builder) use ($statuses, $activeSince, $lostSince): void {
            foreach ($statuses as $index => $status) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}(function (Builder $nested) use ($status, $activeSince, $lostSince): void {
                    if ($status === 'new') {
                        $nested->where('customers.created_at', '>=', now()->subDays(30));
                        return;
                    }

                    if ($status === 'active') {
                        $nested->whereExists(function ($exists) use ($activeSince): void {
                            $exists->selectRaw('1')
                                ->from('sales')
                                ->whereColumn('sales.user_id', 'customers.user_id')
                                ->whereColumn('sales.customer_id', 'customers.id')
                                ->where('sales.created_at', '>=', $activeSince);
                        });
                        return;
                    }

                    if ($status === 'inactive') {
                        $nested->whereNotExists(function ($exists) use ($activeSince): void {
                            $exists->selectRaw('1')
                                ->from('sales')
                                ->whereColumn('sales.user_id', 'customers.user_id')
                                ->whereColumn('sales.customer_id', 'customers.id')
                                ->where('sales.created_at', '>=', $activeSince);
                        });
                        return;
                    }

                    if ($status === 'lost') {
                        $nested->whereNotExists(function ($exists) use ($lostSince): void {
                            $exists->selectRaw('1')
                                ->from('sales')
                                ->whereColumn('sales.user_id', 'customers.user_id')
                                ->whereColumn('sales.customer_id', 'customers.id')
                                ->where('sales.created_at', '>=', $lostSince);
                        });
                    }
                });
            }
        });
    }

    private function applyBooleanPresence(Builder $query, string $field, mixed $value): void
    {
        if ((bool) $value) {
            $query->whereNotNull($field)->where($field, '!=', '');
            return;
        }

        $query->where(function (Builder $builder) use ($field): void {
            $builder->whereNull($field)->orWhere($field, '');
        });
    }

    private function applyVipStatusRule(Builder $query, string $operator, mixed $value): void
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            return;
        }

        if (in_array($operator, ['not_equals', 'not_in', 'exclude'], true)) {
            $query->where('is_vip', '!=', (bool) $parsed);
            return;
        }

        $query->where('is_vip', (bool) $parsed);
    }

    private function applyVipTierIdRule(Builder $query, string $operator, mixed $value): void
    {
        $values = collect(Arr::wrap($value))
            ->map(fn ($item) => is_numeric($item) ? (int) $item : null)
            ->filter(fn ($item) => is_int($item) && $item > 0)
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        if (in_array($operator, ['not_equals', 'not_in', 'exclude'], true)) {
            $query->where(function (Builder $builder) use ($values): void {
                $builder->whereNull('vip_tier_id')
                    ->orWhereNotIn('vip_tier_id', $values->all());
            });
            return;
        }

        $query->whereIn('vip_tier_id', $values->all());
    }

    private function applyVipTierCodeRule(Builder $query, string $operator, mixed $value): void
    {
        $values = collect(Arr::wrap($value))
            ->map(fn ($item) => strtoupper(trim((string) $item)))
            ->filter()
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        if (in_array($operator, ['not_equals', 'not_in', 'exclude'], true)) {
            $query->where(function (Builder $builder) use ($values): void {
                $builder->whereNull('vip_tier_code')
                    ->orWhereNotIn('vip_tier_code', $values->all());
            });
            return;
        }

        $query->whereIn('vip_tier_code', $values->all());
    }

    private function applyPurchasedProductRule(Builder $query, string $operator, mixed $value): void
    {
        $input = is_array($value) ? $value : ['product_id' => $value];
        $productId = (int) ($input['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        $days = isset($input['days']) && is_numeric($input['days']) ? (int) $input['days'] : null;
        $mode = in_array($operator, ['not_equals', 'not_in', 'exclude'], true) ? 'exclude' : 'include';

        $callback = function ($exists) use ($productId, $days): void {
            $exists->selectRaw('1')
                ->from('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->whereColumn('sales.user_id', 'customers.user_id')
                ->whereColumn('sales.customer_id', 'customers.id')
                ->where('sales.status', 'paid')
                ->where('sale_items.product_id', $productId);

            if ($days !== null && $days > 0) {
                $exists->where('sales.created_at', '>=', now()->subDays($days));
            }
        };

        if ($mode === 'exclude') {
            $query->whereNotExists($callback);
            return;
        }

        $query->whereExists($callback);
    }

    private function applyPurchasedCategoryRule(Builder $query, string $operator, mixed $value): void
    {
        $input = is_array($value) ? $value : ['category_id' => $value];
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        $days = isset($input['days']) && is_numeric($input['days']) ? (int) $input['days'] : null;
        $mode = in_array($operator, ['not_equals', 'not_in', 'exclude'], true) ? 'exclude' : 'include';

        $callback = function ($exists) use ($categoryId, $days): void {
            $exists->selectRaw('1')
                ->from('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('products', 'products.id', '=', 'sale_items.product_id')
                ->whereColumn('sales.user_id', 'customers.user_id')
                ->whereColumn('sales.customer_id', 'customers.id')
                ->where('sales.status', 'paid')
                ->where('products.category_id', $categoryId);

            if ($days !== null && $days > 0) {
                $exists->where('sales.created_at', '>=', now()->subDays($days));
            }
        };

        if ($mode === 'exclude') {
            $query->whereNotExists($callback);
            return;
        }

        $query->whereExists($callback);
    }

    private function applyBehaviorEventRule(Builder $query, string $operator, mixed $value): void
    {
        $input = is_array($value) ? $value : ['event_type' => $value];
        $eventType = trim((string) ($input['event_type'] ?? ''));
        if ($eventType === '') {
            return;
        }

        $days = isset($input['days']) && is_numeric($input['days']) ? (int) $input['days'] : null;
        $mode = in_array($operator, ['not_equals', 'not_in', 'exclude'], true) ? 'exclude' : 'include';

        $callback = function ($exists) use ($eventType, $days): void {
            $exists->selectRaw('1')
                ->from('customer_behavior_events')
                ->whereColumn('customer_behavior_events.user_id', 'customers.user_id')
                ->whereColumn('customer_behavior_events.customer_id', 'customers.id')
                ->where('customer_behavior_events.event_type', $eventType);

            if ($days !== null && $days > 0) {
                $exists->where('customer_behavior_events.occurred_at', '>=', now()->subDays($days));
            }
        };

        if ($mode === 'exclude') {
            $query->whereNotExists($callback);
            return;
        }

        $query->whereExists($callback);
    }

    private function applyStringComparison(Builder $query, string $field, string $operator, mixed $value): void
    {
        $values = Arr::wrap($value);

        if (in_array($operator, ['in', 'contains_any'], true)) {
            $query->whereIn($field, $values);
            return;
        }

        if ($operator === 'not_in') {
            $query->whereNotIn($field, $values);
            return;
        }

        if ($operator === 'contains') {
            $query->where($field, 'like', '%' . trim((string) $value) . '%');
            return;
        }

        if ($operator === 'not_contains') {
            $query->where($field, 'not like', '%' . trim((string) $value) . '%');
            return;
        }

        if ($operator === 'not_equals') {
            $query->where($field, '!=', (string) $value);
            return;
        }

        $query->where($field, '=', (string) $value);
    }

    private function comparisonOperator(string $operator): ?string
    {
        return match ($operator) {
            'equals', 'eq' => '=',
            'not_equals', 'neq' => '!=',
            'gt', 'greater_than' => '>',
            'gte', 'greater_or_equal' => '>=',
            'lt', 'less_than' => '<',
            'lte', 'less_or_equal' => '<=',
            default => null,
        };
    }
}
