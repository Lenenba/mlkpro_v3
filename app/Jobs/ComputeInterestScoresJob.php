<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\CustomerInterestScore;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ComputeInterestScoresJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [300, 1200];

    public function __construct(
        public ?int $accountId = null
    ) {
    }

    public function handle(): void
    {
        $accountIds = $this->accountId
            ? collect([$this->accountId])
            : User::query()
                ->where('company_type', 'products')
                ->pluck('id');

        foreach ($accountIds as $accountId) {
            $this->computeForAccount((int) $accountId);
        }
    }

    private function computeForAccount(int $accountId): void
    {
        $customers = Customer::query()
            ->where('user_id', $accountId)
            ->get(['id']);

        if ($customers->isEmpty()) {
            return;
        }

        $now = now();

        $lastPurchases = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->selectRaw('customer_id, MAX(created_at) as last_purchase_at')
            ->groupBy('customer_id')
            ->pluck('last_purchase_at', 'customer_id');

        $stats90 = Sale::query()
            ->where('user_id', $accountId)
            ->where('status', Sale::STATUS_PAID)
            ->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('customer_id, COUNT(*) as purchase_count, COALESCE(SUM(total),0) as spend_total')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $rows = [];
        foreach ($customers as $customer) {
            $customerId = (int) $customer->id;
            $lastPurchaseAt = $lastPurchases[$customerId] ?? null;
            $daysSince = $lastPurchaseAt ? now()->diffInDays($lastPurchaseAt) : 365;
            $recencyScore = max(0, min(100, 100 - ($daysSince * 2)));

            $frequency = (int) ($stats90[$customerId]->purchase_count ?? 0);
            $frequencyScore = min(100, $frequency * 12);

            $spend = (float) ($stats90[$customerId]->spend_total ?? 0);
            $spendScore = min(100, (int) floor($spend / 10));

            $score = (int) round(($recencyScore * 0.4) + ($frequencyScore * 0.3) + ($spendScore * 0.3));
            $score = max(0, min(100, $score));

            $factors = [
                'days_since_last_purchase' => $daysSince,
                'purchase_count_90d' => $frequency,
                'spend_total_90d' => $spend,
                'recency_score' => $recencyScore,
                'frequency_score' => $frequencyScore,
                'spend_score' => $spendScore,
            ];

            $rows[] = [
                'user_id' => $accountId,
                'customer_id' => $customerId,
                'score_scope' => 'global',
                'score' => $score,
                'factors' => json_encode($factors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'computed_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        CustomerInterestScore::query()->upsert(
            $rows,
            ['user_id', 'customer_id', 'score_scope'],
            ['score', 'factors', 'computed_at', 'updated_at']
        );
    }
}
