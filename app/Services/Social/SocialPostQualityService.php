<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Str;

class SocialPostQualityService
{
    public function __construct(
        private readonly SocialBrandVoiceService $brandVoiceService,
        private readonly SocialMediaAssetService $mediaAssetService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function review(User $owner, SocialPost $post): array
    {
        $post->loadMissing('targets.socialAccountConnection');

        $text = trim((string) data_get($post->content_payload, 'text', ''));
        $imageUrl = $this->mediaAssetService->imageUrl((array) ($post->media_payload ?? []));
        $linkUrl = trim((string) ($post->link_url ?? ''));
        $linkLabel = trim((string) data_get($post->metadata, 'link_cta_label', ''));
        $targets = $post->targets
            ->map(fn ($target): array => [
                'platform' => strtolower(trim((string) ($target->socialAccountConnection?->platform ?? data_get($target->metadata, 'platform', '')))),
            ])
            ->values()
            ->all();

        return $this->score($owner, [
            'text' => $text,
            'image_url' => $imageUrl,
            'link_url' => $linkUrl,
            'link_label' => $linkLabel,
            'targets' => $targets,
            'ignore_post_id' => $post->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function score(User $owner, array $payload): array
    {
        $text = trim((string) ($payload['text'] ?? ''));
        $imageUrl = trim((string) ($payload['image_url'] ?? ''));
        $linkUrl = trim((string) ($payload['link_url'] ?? ''));
        $linkLabel = trim((string) ($payload['link_label'] ?? ''));
        $targets = collect((array) ($payload['targets'] ?? []))
            ->map(fn ($target): string => strtolower(trim((string) data_get($target, 'platform', $target))))
            ->filter()
            ->unique()
            ->values();
        $brandVoice = $this->brandVoiceService->resolve($owner);

        $issues = [];
        $score = 100;

        $deduct = function (string $key, string $level, int $points, ?string $detail = null) use (&$issues, &$score): void {
            $issues[] = array_filter([
                'key' => $key,
                'level' => $level,
                'points' => $points,
                'detail' => $detail,
            ], fn ($value): bool => $value !== null && $value !== '');
            $score -= $points;
        };

        if ($targets->isEmpty()) {
            $deduct('no_targets', 'attention', 25);
        }

        if ($text === '' && $imageUrl === '' && $linkUrl === '') {
            $deduct('empty_content', 'attention', 30);
        }

        $textLimit = $this->textLimit($targets->all());
        if (Str::length($text) > $textLimit) {
            $deduct('text_too_long', 'warning', 18);
        }

        if ($targets->contains('instagram') && $imageUrl === '') {
            $deduct('missing_image', 'notice', 8);
        }

        if ($linkLabel !== '' && $linkUrl === '') {
            $deduct('cta_without_link', 'warning', 14);
        }

        if ($this->hasWeakCta($text, $linkUrl, $linkLabel)) {
            $deduct('weak_cta', 'notice', 8);
        }

        $blockedWord = $this->firstBlockedBrandVoiceWord($text, $brandVoice);
        if ($blockedWord !== null) {
            $deduct('brand_voice_word', 'warning', 12, $blockedWord);
        }

        if ($this->isSimilarToRecent($owner, $text, (int) ($payload['ignore_post_id'] ?? 0))) {
            $deduct('similar_recent', 'warning', 16);
        }

        if ($this->hasImageTextGap($text, $imageUrl)) {
            $deduct('image_text_gap', 'notice', 7);
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'status' => $score >= 85 ? 'good' : ($score >= 65 ? 'warning' : 'attention'),
            'issues' => $issues,
            'checks' => [
                'brand_voice' => $blockedWord === null ? 'pass' : 'warning',
                'repetition' => collect($issues)->contains(fn (array $issue): bool => $issue['key'] === 'similar_recent') ? 'warning' : 'pass',
                'target_fit' => $targets->isEmpty() ? 'attention' : 'pass',
                'cta' => $this->hasWeakCta($text, $linkUrl, $linkLabel) ? 'notice' : 'pass',
                'image_text' => $this->hasImageTextGap($text, $imageUrl) ? 'notice' : 'pass',
            ],
            'brand_voice' => [
                'tone' => $brandVoice['tone'] ?? 'professional',
                'is_configured' => (bool) ($brandVoice['is_configured'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function textLimit(array $platforms): int
    {
        $limits = collect($platforms)
            ->map(fn (string $platform): int => match ($platform) {
                'x' => 280,
                'instagram' => 2200,
                'linkedin' => 3000,
                'facebook' => 5000,
                default => 900,
            })
            ->values();

        return $limits->isNotEmpty() ? (int) $limits->min() : 900;
    }

    /**
     * @param  array<string, mixed>  $brandVoice
     */
    private function firstBlockedBrandVoiceWord(string $text, array $brandVoice): ?string
    {
        $normalizedText = Str::lower($text);

        foreach ((array) ($brandVoice['words_to_avoid'] ?? []) as $word) {
            $candidate = trim((string) $word);
            if ($candidate !== '' && Str::contains($normalizedText, Str::lower($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasWeakCta(string $text, string $linkUrl, string $linkLabel): bool
    {
        if ($linkUrl === '' && $linkLabel === '') {
            return false;
        }

        if ($linkLabel !== '') {
            return false;
        }

        return ! Str::contains(Str::lower($text), [
            'reserve',
            'reservez',
            'book',
            'contact',
            'message',
            'decouvrir',
            'discover',
            'voir',
            'shop',
            'acheter',
            'buy',
            'learn',
        ]);
    }

    private function hasImageTextGap(string $text, string $imageUrl): bool
    {
        if ($imageUrl !== '' && Str::length($text) < 20) {
            return true;
        }

        if ($imageUrl === '' && Str::contains(Str::lower($text), ['photo', 'image', 'visuel', 'look', 'voir en image'])) {
            return true;
        }

        return false;
    }

    private function isSimilarToRecent(User $owner, string $text, int $ignorePostId): bool
    {
        $current = $this->comparableText($text);
        if (Str::length($current) < 40) {
            return false;
        }

        $currentWords = collect(explode(' ', $current))
            ->filter(fn (string $word): bool => Str::length($word) > 3)
            ->unique()
            ->values();

        if ($currentWords->count() < 5) {
            return false;
        }

        return SocialPost::query()
            ->byUser($owner->id)
            ->when($ignorePostId > 0, fn ($query) => $query->whereKeyNot($ignorePostId))
            ->latest('updated_at')
            ->limit(20)
            ->get(['content_payload'])
            ->contains(function (SocialPost $post) use ($current, $currentWords): bool {
                $candidate = $this->comparableText((string) data_get($post->content_payload, 'text', ''));
                if ($candidate === '') {
                    return false;
                }

                if ($candidate === $current) {
                    return true;
                }

                $candidateWords = collect(explode(' ', $candidate))
                    ->filter(fn (string $word): bool => Str::length($word) > 3)
                    ->unique()
                    ->values();

                if ($candidateWords->count() < 5) {
                    return false;
                }

                $overlap = $currentWords
                    ->filter(fn (string $word): bool => $candidateWords->contains($word))
                    ->count();

                return ($overlap / max(1, min($currentWords->count(), $candidateWords->count()))) >= 0.72;
            });
    }

    private function comparableText(string $value): string
    {
        $normalized = Str::ascii(Str::lower($value));
        $normalized = preg_replace('/https?:\/\/\S+/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9#\s]/i', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
