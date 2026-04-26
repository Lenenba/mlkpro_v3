<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use Illuminate\Support\Carbon;

class SocialContentRotationService
{
    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @return array<string, mixed>|null
     */
    public function chooseSource(SocialAutomationRule $rule, array $pool, ?Carbon $now = null): ?array
    {
        if ($pool === []) {
            return null;
        }

        $resolvedNow = ($now ?? now())->copy();
        $cutoff = $resolvedNow->copy()->subHours(max(1, (int) ($rule->min_hours_between_similar_posts ?: 24)));
        $recentUsage = $this->recentUsageMap($rule, $cutoff);

        return collect($pool)
            ->map(function (array $source) use ($recentUsage): array {
                $key = $this->sourceKey(
                    (string) ($source['source_type'] ?? ''),
                    (int) ($source['source_id'] ?? 0)
                );

                return [
                    ...$source,
                    '__source_key' => $key,
                    '__last_used_at' => $recentUsage[$key] ?? null,
                ];
            })
            ->filter(fn (array $source): bool => ($source['__last_used_at'] ?? null) === null)
            ->sortBy(fn (array $source) => (string) ($source['source_label'] ?? ''))
            ->map(function (array $source): array {
                unset($source['__source_key'], $source['__last_used_at']);

                return $source;
            })
            ->first();
    }

    public function sourceKey(string $sourceType, int $sourceId): string
    {
        return sprintf('%s:%d', $sourceType, $sourceId);
    }

    /**
     * @return array<string, Carbon>
     */
    private function recentUsageMap(SocialAutomationRule $rule, Carbon $cutoff): array
    {
        return SocialPost::query()
            ->where('social_automation_rule_id', $rule->id)
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('created_at')
            ->get(['metadata', 'created_at'])
            ->reduce(function (array $carry, SocialPost $post): array {
                $sourceType = trim((string) data_get($post->metadata, 'automation.selected_source_type'));
                $sourceId = (int) data_get($post->metadata, 'automation.selected_source_id');

                if ($sourceType === '' || $sourceId <= 0) {
                    return $carry;
                }

                $key = $this->sourceKey($sourceType, $sourceId);
                if (! isset($carry[$key])) {
                    $carry[$key] = $post->created_at instanceof Carbon
                        ? $post->created_at->copy()
                        : Carbon::parse((string) $post->created_at);
                }

                return $carry;
            }, []);
    }
}
