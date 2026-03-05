<?php

namespace App\Services\Campaigns;

use App\Models\CampaignAutomationRule;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;

class CampaignAutomationService
{
    public function __construct(
        private readonly CampaignService $campaignService,
    ) {
    }

    public function process(?int $accountId = null): array
    {
        $query = CampaignAutomationRule::query()
            ->where('is_active', true)
            ->with(['campaign', 'user']);

        if ($accountId) {
            $query->where('user_id', $accountId);
        }

        $processed = 0;
        $triggered = 0;
        foreach ($query->cursor() as $rule) {
            $processed += 1;
            $campaign = $rule->campaign;
            $owner = $rule->user;
            if (!$campaign || !$owner) {
                continue;
            }

            if (!$this->shouldTrigger($rule)) {
                continue;
            }

            $scheduledFor = null;
            $delayMinutes = (int) ($rule->delay_minutes ?? 0);
            if ($delayMinutes > 0) {
                $scheduledFor = now()->addMinutes($delayMinutes);
            }

            $this->campaignService->queueRun(
                $campaign,
                $owner,
                'automation',
                $scheduledFor
            );

            $rule->forceFill(['last_triggered_at' => now()])->save();
            $triggered += 1;
        }

        return [
            'processed' => $processed,
            'triggered' => $triggered,
        ];
    }

    private function shouldTrigger(CampaignAutomationRule $rule): bool
    {
        $config = is_array($rule->trigger_config) ? $rule->trigger_config : [];
        $lastTriggeredAt = $rule->last_triggered_at;

        if ($rule->trigger_type === CampaignAutomationRule::TRIGGER_PRODUCT_BACK_IN_STOCK) {
            $productId = (int) ($config['product_id'] ?? 0);
            if ($productId <= 0) {
                return false;
            }

            $product = Product::query()
                ->where('user_id', $rule->user_id)
                ->whereKey($productId)
                ->first(['id', 'stock', 'updated_at']);
            if (!$product || (int) $product->stock <= 0) {
                return false;
            }

            if (!$lastTriggeredAt) {
                return true;
            }

            return $product->updated_at && $product->updated_at->greaterThan($lastTriggeredAt);
        }

        if ($rule->trigger_type === CampaignAutomationRule::TRIGGER_PROMOTION_CREATED) {
            $windowMinutes = max(5, (int) ($config['window_minutes'] ?? 60));
            if (!$lastTriggeredAt) {
                return true;
            }

            return $lastTriggeredAt->lessThan(now()->subMinutes($windowMinutes));
        }

        if ($rule->trigger_type === CampaignAutomationRule::TRIGGER_AFTER_PURCHASE) {
            $productId = (int) ($config['product_id'] ?? 0);
            if ($productId <= 0) {
                return false;
            }

            $since = $lastTriggeredAt ?: now()->subHours(24);

            return Sale::query()
                ->where('user_id', $rule->user_id)
                ->where('status', Sale::STATUS_PAID)
                ->where('created_at', '>=', $since)
                ->whereExists(function ($query) use ($productId): void {
                    $query->selectRaw('1')
                        ->from('sale_items')
                        ->whereColumn('sale_items.sale_id', 'sales.id')
                        ->where('sale_items.product_id', $productId);
                })
                ->exists();
        }

        if ($rule->trigger_type === CampaignAutomationRule::TRIGGER_INACTIVE_CUSTOMER) {
            $days = max(30, (int) ($config['inactive_days'] ?? 60));
            $since = now()->subDays($days);

            $query = User::query()
                ->whereKey($rule->user_id)
                ->whereExists(function ($sub) use ($since): void {
                    $sub->selectRaw('1')
                        ->from('customers')
                        ->whereColumn('customers.user_id', 'users.id')
                        ->where(function ($noActivity) use ($since): void {
                            $noActivity->whereNotExists(function ($sales) use ($since): void {
                                $sales->selectRaw('1')
                                    ->from('sales')
                                    ->whereColumn('sales.customer_id', 'customers.id')
                                    ->whereColumn('sales.user_id', 'customers.user_id')
                                    ->where('sales.created_at', '>=', $since);
                            });
                        });
                });

            if (!$query->exists()) {
                return false;
            }

            if (!$lastTriggeredAt) {
                return true;
            }

            return $lastTriggeredAt->lessThan(now()->subDays(1));
        }

        return false;
    }
}
