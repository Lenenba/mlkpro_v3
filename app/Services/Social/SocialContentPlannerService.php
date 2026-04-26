<?php

namespace App\Services\Social;

use App\Models\Campaign;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\SocialAutomationRule;
use App\Models\SocialPostTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SocialContentPlannerService
{
    public function __construct(
        private readonly SocialAutomationRuleService $ruleService,
        private readonly SocialContentRotationService $rotationService,
    ) {}

    /**
     * @return Collection<int, SocialAutomationRule>
     */
    public function dueRules(?int $accountId = null, ?int $ruleId = null): Collection
    {
        $query = SocialAutomationRule::query()
            ->with(['user', 'createdBy'])
            ->active()
            ->due();

        if ($accountId) {
            $query->where('user_id', $accountId);
        }

        if ($ruleId) {
            $query->whereKey($ruleId);
        }

        return $query
            ->orderBy('next_generation_at')
            ->orderBy('id')
            ->get();
    }

    public function isDue(SocialAutomationRule $rule, ?Carbon $now = null): bool
    {
        $resolvedNow = $now ?? now();

        return ! $rule->next_generation_at || $rule->next_generation_at->lessThanOrEqualTo($resolvedNow);
    }

    public function nextGenerationAt(SocialAutomationRule $rule, ?Carbon $from = null): Carbon
    {
        return $this->ruleService->calculateNextGenerationAt($rule, $from, $rule->user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectSource(User $owner, SocialAutomationRule $rule, ?Carbon $now = null): ?array
    {
        $pool = $this->expandSourcePool($owner, $rule, $now);

        return $this->rotationService->chooseSource($rule, $pool, $now);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function expandSourcePool(User $owner, SocialAutomationRule $rule, ?Carbon $now = null): array
    {
        $sources = is_array($rule->content_sources) ? $rule->content_sources : [];
        $resolvedNow = $now ?? now();

        return collect($sources)
            ->flatMap(fn (array $source) => $this->expandConfiguredSource($owner, $source, $resolvedNow))
            ->map(function (array $item): array {
                return [
                    'source_type' => (string) ($item['source_type'] ?? ''),
                    'source_id' => (int) ($item['source_id'] ?? 0),
                    'source_label' => (string) ($item['source_label'] ?? ''),
                ];
            })
            ->filter(fn (array $item): bool => $item['source_type'] !== '' && $item['source_id'] > 0)
            ->unique(fn (array $item) => sprintf('%s:%d', $item['source_type'], $item['source_id']))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $source
     * @return Collection<int, array<string, mixed>>
     */
    private function expandConfiguredSource(User $owner, array $source, Carbon $now): Collection
    {
        $type = strtolower(trim((string) ($source['type'] ?? '')));
        $mode = strtolower(trim((string) ($source['mode'] ?? 'all')));
        $selectedIds = collect((array) ($source['ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return match ($type) {
            SocialPrefillService::SOURCE_PRODUCT => $this->catalogSources($owner, Product::ITEM_TYPE_PRODUCT, $mode, $selectedIds),
            SocialPrefillService::SOURCE_SERVICE => $this->catalogSources($owner, Product::ITEM_TYPE_SERVICE, $mode, $selectedIds),
            SocialPrefillService::SOURCE_PROMOTION => $this->promotionSources($owner, $mode, $selectedIds, $now),
            SocialPrefillService::SOURCE_CAMPAIGN => $this->campaignSources($owner, $mode, $selectedIds),
            SocialPrefillService::SOURCE_TEMPLATE => $this->templateSources($owner, $mode, $selectedIds),
            default => collect(),
        };
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return Collection<int, array<string, mixed>>
     */
    private function catalogSources(User $owner, string $itemType, string $mode, array $selectedIds): Collection
    {
        if (! $owner->hasCompanyFeature('products') && $itemType === Product::ITEM_TYPE_PRODUCT) {
            return collect();
        }

        if (! $owner->hasCompanyFeature('services') && $itemType === Product::ITEM_TYPE_SERVICE) {
            return collect();
        }

        $query = Product::query()
            ->where('user_id', $owner->id)
            ->where('item_type', $itemType);

        if ($mode === 'selected_ids') {
            if ($selectedIds === []) {
                return collect();
            }

            $query->whereKey($selectedIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $product): array => [
                'source_type' => $itemType === Product::ITEM_TYPE_SERVICE
                    ? SocialPrefillService::SOURCE_SERVICE
                    : SocialPrefillService::SOURCE_PRODUCT,
                'source_id' => (int) $product->id,
                'source_label' => trim((string) $product->name) !== ''
                    ? trim((string) $product->name)
                    : ucfirst($itemType).' #'.$product->id,
            ]);
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return Collection<int, array<string, mixed>>
     */
    private function promotionSources(User $owner, string $mode, array $selectedIds, Carbon $now): Collection
    {
        if (! $owner->hasCompanyFeature('promotions')) {
            return collect();
        }

        $query = Promotion::query()
            ->forAccount($owner->id)
            ->active()
            ->availableOn($now);

        if ($mode === 'selected_ids') {
            if ($selectedIds === []) {
                return collect();
            }

            $query->whereKey($selectedIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Promotion $promotion): array => [
                'source_type' => SocialPrefillService::SOURCE_PROMOTION,
                'source_id' => (int) $promotion->id,
                'source_label' => trim((string) $promotion->name) !== ''
                    ? trim((string) $promotion->name)
                    : 'Promotion #'.$promotion->id,
            ]);
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return Collection<int, array<string, mixed>>
     */
    private function campaignSources(User $owner, string $mode, array $selectedIds): Collection
    {
        if (! $owner->hasCompanyFeature('campaigns')) {
            return collect();
        }

        $query = Campaign::query()
            ->where('user_id', $owner->id);

        if ($mode === 'selected_ids') {
            if ($selectedIds === []) {
                return collect();
            }

            $query->whereKey($selectedIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Campaign $campaign): array => [
                'source_type' => SocialPrefillService::SOURCE_CAMPAIGN,
                'source_id' => (int) $campaign->id,
                'source_label' => trim((string) $campaign->name) !== ''
                    ? trim((string) $campaign->name)
                    : 'Campaign #'.$campaign->id,
            ]);
    }

    /**
     * @param  array<int, int>  $selectedIds
     * @return Collection<int, array<string, mixed>>
     */
    private function templateSources(User $owner, string $mode, array $selectedIds): Collection
    {
        if (! $owner->hasCompanyFeature('social')) {
            return collect();
        }

        $query = SocialPostTemplate::query()->byUser($owner->id);

        if ($mode === 'selected_ids') {
            if ($selectedIds === []) {
                return collect();
            }

            $query->whereKey($selectedIds);
        }

        return $query
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SocialPostTemplate $template): array => [
                'source_type' => SocialPrefillService::SOURCE_TEMPLATE,
                'source_id' => (int) $template->id,
                'source_label' => trim((string) $template->name) !== ''
                    ? trim((string) $template->name)
                    : 'Template #'.$template->id,
            ]);
    }
}
