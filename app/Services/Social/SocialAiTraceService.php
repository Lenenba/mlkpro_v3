<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Str;

class SocialAiTraceService
{
    /**
     * @return array<string, mixed>|null
     */
    public function payload(SocialPost $post): ?array
    {
        $metadata = is_array($post->metadata) ? $post->metadata : [];
        $ai = is_array($metadata['ai_generation'] ?? null) ? $metadata['ai_generation'] : [];
        $automation = is_array($metadata['automation'] ?? null) ? $metadata['automation'] : [];
        $campaign = is_array($metadata['campaign_batch'] ?? null) ? $metadata['campaign_batch'] : [];

        if ($ai === [] && $automation === [] && $campaign === []) {
            return null;
        }

        $score = $this->integerValue($ai['selected_score'] ?? $automation['ai_selected_score'] ?? null);
        $fallbackUsed = (bool) ($ai['fallback_used'] ?? $automation['ai_fallback_used'] ?? false);
        $sourceLabel = $this->stringValue(data_get($metadata, 'source.label'))
            ?: $this->stringValue($automation['selected_source_label'] ?? null)
            ?: $this->stringValue($campaign['name'] ?? null);
        $reason = $this->stringValue($ai['selected_score_reason'] ?? null)
            ?: $this->stringValue($campaign['reason'] ?? null)
            ?: $this->fallbackReason($fallbackUsed);

        return [
            'has_trace' => true,
            'summary' => $this->summary($sourceLabel, $score, $fallbackUsed, $reason),
            'score' => $score,
            'fallback_used' => $fallbackUsed,
            'reason' => $reason,
            'items' => $this->items($post, $ai, $automation, $campaign),
            'variants' => $this->variants($ai),
        ];
    }

    /**
     * @param  array<string, mixed>  $ai
     * @param  array<string, mixed>  $automation
     * @param  array<string, mixed>  $campaign
     * @return array<int, array<string, string>>
     */
    private function items(SocialPost $post, array $ai, array $automation, array $campaign): array
    {
        $sourceLabel = $this->stringValue(data_get($post->metadata, 'source.label'))
            ?: $this->stringValue($automation['selected_source_label'] ?? null)
            ?: $this->stringValue($campaign['name'] ?? null);

        return collect([
            ['key' => 'source', 'value' => $sourceLabel],
            ['key' => 'rule', 'value' => $this->stringValue($automation['rule_name_snapshot'] ?? null)],
            ['key' => 'campaign', 'value' => $this->stringValue($campaign['name'] ?? null)],
            ['key' => 'generation_mode', 'value' => $this->stringValue($ai['generation_mode'] ?? $automation['generation_mode'] ?? null)],
            ['key' => 'text_model', 'value' => $this->stringValue($ai['text_model'] ?? null)],
            ['key' => 'image_model', 'value' => $this->stringValue($ai['image_model'] ?? data_get($ai, 'image.model'))],
            ['key' => 'selected_score', 'value' => $this->stringValue($ai['selected_score'] ?? $automation['ai_selected_score'] ?? null)],
            ['key' => 'fallback', 'value' => $this->fallbackLabel((bool) ($ai['fallback_used'] ?? $automation['ai_fallback_used'] ?? false))],
            ['key' => 'generated_at', 'value' => $this->stringValue($ai['generated_at'] ?? $automation['generated_at'] ?? data_get($campaign, 'generated_at'))],
        ])
            ->filter(fn (array $item): bool => trim((string) $item['value']) !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $ai
     * @return array<int, array<string, mixed>>
     */
    private function variants(array $ai): array
    {
        return collect((array) ($ai['variants'] ?? []))
            ->filter(fn ($variant): bool => is_array($variant))
            ->map(fn (array $variant): array => array_filter([
                'text' => $this->stringValue($variant['text'] ?? null, 180),
                'score' => $this->integerValue($variant['score'] ?? null),
                'reason' => $this->stringValue($variant['score_reason'] ?? null, 160),
            ], fn ($value): bool => $value !== null && $value !== ''))
            ->filter()
            ->take(3)
            ->values()
            ->all();
    }

    private function summary(?string $sourceLabel, ?int $score, bool $fallbackUsed, ?string $reason): string
    {
        $parts = [];

        if ($sourceLabel) {
            $parts[] = 'Source: '.$sourceLabel;
        }

        if ($score !== null) {
            $parts[] = 'Score IA: '.$score.'/100';
        }

        if ($fallbackUsed) {
            $parts[] = 'Fallback utilise';
        }

        if ($reason) {
            $parts[] = $reason;
        }

        return Str::limit(implode(' · ', $parts), 280, '');
    }

    private function fallbackReason(bool $fallbackUsed): ?string
    {
        return $fallbackUsed ? 'Une option de secours a ete utilisee pour garder le contenu exploitable.' : null;
    }

    private function fallbackLabel(bool $fallbackUsed): string
    {
        return $fallbackUsed ? 'Oui' : 'Non';
    }

    private function stringValue(mixed $value, int $limit = 180): ?string
    {
        $candidate = Str::limit(trim((string) ($value ?? '')), $limit, '');

        return $candidate !== '' ? $candidate : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
