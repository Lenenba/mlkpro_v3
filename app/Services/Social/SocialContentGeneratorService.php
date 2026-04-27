<?php

namespace App\Services\Social;

use App\Models\SocialAccountConnection;
use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialContentGeneratorService
{
    public function __construct(
        private readonly SocialPrefillService $prefillService,
        private readonly SocialSuggestionService $suggestionService,
        private readonly SocialMediaAssetService $mediaAssetService,
        private readonly SocialAiCreativeService $aiCreativeService,
        private readonly SocialAiImageGenerationService $aiImageGenerationService,
        private readonly SocialBrandVoiceService $brandVoiceService,
    ) {}

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public function generate(User $owner, SocialAutomationRule $rule, array $source): array
    {
        $sourceType = trim((string) ($source['source_type'] ?? ''));
        $sourceId = (int) ($source['source_id'] ?? 0);

        $prefill = $this->prefillService->resolveComposerPrefill($owner, [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);

        if (! is_array($prefill)) {
            throw ValidationException::withMessages([
                'content_sources' => 'Pulse could not resolve one of the configured automation sources for this tenant.',
            ]);
        }

        $locale = LocalePreference::normalize((string) ($rule->language ?: $owner->locale));
        $baseText = trim((string) ($prefill['text'] ?? ''));
        $linkUrl = trim((string) ($prefill['link_url'] ?? ''));
        $imageUrl = trim((string) ($prefill['image_url'] ?? ''));
        $sourceLabel = trim((string) ($prefill['source_label'] ?? $source['source_label'] ?? ''));
        $settings = $this->generationSettings($rule);
        $brandVoice = $this->brandVoiceService->resolve($owner);
        $brandVoiceContext = $this->brandVoiceService->aiContext($brandVoice);

        $selectedCaption = null;
        $aiCreative = null;
        if ((bool) ($settings['text_ai_enabled'] ?? false)) {
            $aiCreative = $this->aiCreativeService->generate($owner, $rule, [
                'locale' => $locale,
                'settings' => $settings,
                'targets' => $this->targetPlatforms($owner, $rule),
                'brand_voice' => $brandVoiceContext,
                'source' => [
                    'type' => $sourceType,
                    'id' => $sourceId,
                    'label' => $sourceLabel,
                    'summary' => $baseText,
                    'link_url' => $linkUrl !== '' ? $linkUrl : null,
                    'image_url' => $imageUrl !== '' ? $imageUrl : null,
                    'has_image' => $imageUrl !== '',
                ],
            ]);
        }

        if (is_array($aiCreative)) {
            $finalText = $this->selectedCreativeText((array) ($aiCreative['selected'] ?? []));
        } else {
            $suggestions = $this->suggestionService->suggest($owner, [
                'text' => $baseText !== '' ? $baseText : null,
                'link_url' => $linkUrl !== '' ? $linkUrl : null,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ], $locale);

            $captions = array_values((array) ($suggestions['captions'] ?? []));
            $postCount = (int) SocialPost::query()
                ->where('social_automation_rule_id', $rule->id)
                ->count();
            $selectedCaption = $captions !== []
                ? $captions[$postCount % count($captions)]
                : null;

            $finalText = trim((string) ($selectedCaption['text'] ?? $baseText));
        }
        $finalText = $this->applyBrandVoice($finalText, $brandVoice);

        $aiImage = $this->aiImageGenerationService->generateIfNeeded($owner, $rule, $settings, [
            'company_name' => trim((string) ($owner->company_name ?: $owner->name ?: config('app.name'))),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => $sourceLabel,
            'source_summary' => $baseText,
            'source_link_url' => $linkUrl !== '' ? $linkUrl : null,
            'source_image_url' => $imageUrl !== '' ? $imageUrl : null,
            'selected_image_prompt' => is_array($aiCreative)
                ? trim((string) data_get($aiCreative, 'selected.image_prompt', ''))
                : '',
        ]);

        $mediaPayload = is_array(data_get($aiImage, 'media_payload'))
            ? (array) data_get($aiImage, 'media_payload')
            : $this->mediaAssetService->imageMediaPayload([
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
            ]);

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => trim((string) ($prefill['source_label'] ?? $source['source_label'] ?? '')),
            'language' => $locale,
            'content_payload' => $finalText !== '' ? ['text' => $finalText] : [],
            'media_payload' => $mediaPayload,
            'link_url' => $linkUrl !== '' ? $linkUrl : null,
            'metadata' => [
                'source' => [
                    'type' => $sourceType,
                    'id' => $sourceId,
                    'label' => $sourceLabel ?: null,
                ],
                'generated_caption_key' => $selectedCaption['key'] ?? null,
                'generated_caption_label' => $selectedCaption['label'] ?? null,
                'generated_locale' => $locale,
                'brand_voice' => $this->brandVoiceMetadata($brandVoice),
                'ai_generation' => $this->aiGenerationMetadata($settings, $aiCreative, $aiImage),
            ],
            'content_fingerprint' => $this->fingerprint(
                $finalText,
                $this->fingerprintImageUrl($mediaPayload, $imageUrl),
                $linkUrl,
                $sourceType,
                $sourceId,
                $locale
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $brandVoice
     */
    private function applyBrandVoice(string $text, array $brandVoice): string
    {
        $blocks = [];
        $baseText = trim($text);

        if ($baseText !== '') {
            $blocks[] = $baseText;
        }

        $cta = collect((array) ($brandVoice['preferred_ctas'] ?? []))
            ->map(fn ($item): string => trim((string) $item))
            ->first(fn (string $item): bool => $item !== '');

        if ($cta && ! Str::contains(Str::lower($baseText), Str::lower($cta))) {
            $blocks[] = $cta;
        }

        $existingHashtags = $this->hashtagsIn($baseText);
        $hashtags = collect((array) ($brandVoice['preferred_hashtags'] ?? []))
            ->map(fn ($hashtag): string => trim((string) $hashtag))
            ->filter()
            ->reject(fn (string $hashtag): bool => in_array(Str::lower($hashtag), $existingHashtags, true))
            ->take(max(0, 5 - count($existingHashtags)))
            ->values()
            ->all();

        if ($hashtags !== []) {
            $blocks[] = implode(' ', $hashtags);
        }

        return Str::limit(trim(implode("\n\n", $blocks)), 900, '');
    }

    /**
     * @return array<int, string>
     */
    private function hashtagsIn(string $text): array
    {
        preg_match_all('/#[\pL\pN_]+/u', $text, $matches);

        return collect($matches[0] ?? [])
            ->map(fn ($hashtag): string => Str::lower((string) $hashtag))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $brandVoice
     * @return array<string, mixed>
     */
    private function brandVoiceMetadata(array $brandVoice): array
    {
        return array_filter([
            'tone' => $brandVoice['tone'] ?? null,
            'language' => $brandVoice['language'] ?? null,
            'is_configured' => (bool) ($brandVoice['is_configured'] ?? false),
            'preferred_hashtags' => array_values((array) ($brandVoice['preferred_hashtags'] ?? [])),
            'preferred_ctas' => array_values((array) ($brandVoice['preferred_ctas'] ?? [])),
            'words_to_avoid_count' => count((array) ($brandVoice['words_to_avoid'] ?? [])),
        ], fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function generationSettings(SocialAutomationRule $rule): array
    {
        $settings = data_get($rule->metadata, 'generation_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $defaults = SocialAutomationRule::defaultGenerationSettings();

        $tone = strtolower(trim((string) ($settings['tone'] ?? $defaults['tone'])));
        $goal = strtolower(trim((string) ($settings['goal'] ?? $defaults['goal'])));
        $imageMode = strtolower(trim((string) ($settings['image_mode'] ?? $defaults['image_mode'])));
        $imageFormat = strtolower(trim((string) ($settings['image_format'] ?? $defaults['image_format'])));

        return [
            'text_ai_enabled' => $this->booleanValue($settings['text_ai_enabled'] ?? $defaults['text_ai_enabled']),
            'image_ai_enabled' => $this->booleanValue($settings['image_ai_enabled'] ?? $defaults['image_ai_enabled']),
            'creative_prompt' => Str::limit(trim((string) ($settings['creative_prompt'] ?? $defaults['creative_prompt'])), 1000, ''),
            'image_prompt' => Str::limit(trim((string) ($settings['image_prompt'] ?? $defaults['image_prompt'])), 1000, ''),
            'tone' => in_array($tone, SocialAutomationRule::allowedAiTones(), true) ? $tone : $defaults['tone'],
            'goal' => in_array($goal, SocialAutomationRule::allowedAiGoals(), true) ? $goal : $defaults['goal'],
            'image_mode' => in_array($imageMode, SocialAutomationRule::allowedAiImageModes(), true) ? $imageMode : $defaults['image_mode'],
            'image_format' => in_array($imageFormat, SocialAutomationRule::allowedAiImageFormats(), true) ? $imageFormat : $defaults['image_format'],
            'variant_count' => max(1, min(5, (int) ($settings['variant_count'] ?? $defaults['variant_count']))),
        ];
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered ?? (bool) $value;
    }

    /**
     * @return array<int, string>
     */
    private function targetPlatforms(User $owner, SocialAutomationRule $rule): array
    {
        $ids = collect((array) ($rule->target_connection_ids ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return SocialAccountConnection::query()
            ->byUser($owner->id)
            ->whereKey($ids)
            ->pluck('platform')
            ->map(fn ($platform): string => strtolower(trim((string) $platform)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $selected
     */
    private function selectedCreativeText(array $selected): string
    {
        $text = trim((string) ($selected['text'] ?? ''));
        $blocks = [];

        if ($text !== '') {
            $blocks[] = $text;
        }

        $cta = trim((string) ($selected['cta'] ?? ''));
        if ($cta !== '' && ! Str::contains(Str::lower($text), Str::lower($cta))) {
            $blocks[] = $cta;
        }

        $hashtags = collect((array) ($selected['hashtags'] ?? []))
            ->map(fn ($hashtag): string => trim((string) $hashtag))
            ->filter()
            ->unique(fn (string $hashtag): string => Str::lower($hashtag))
            ->values()
            ->all();
        $hashtagLine = implode(' ', $hashtags);

        if ($hashtagLine !== '' && ! Str::contains(Str::lower($text), Str::lower($hashtags[0] ?? ''))) {
            $blocks[] = $hashtagLine;
        }

        return trim(implode("\n\n", $blocks));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $creative
     * @return array<string, mixed>
     */
    private function aiGenerationMetadata(array $settings, ?array $creative, ?array $image): ?array
    {
        if (! (bool) ($settings['text_ai_enabled'] ?? false) && ! (bool) ($settings['image_ai_enabled'] ?? false)) {
            return null;
        }

        $selected = is_array($creative['selected'] ?? null) ? $creative['selected'] : [];
        $textFallbackUsed = is_array($creative) && (bool) ($creative['fallback_used'] ?? false);
        $imageFallbackUsed = is_array($image) && (bool) ($image['fallback_used'] ?? false);

        return array_filter([
            'text_enabled' => (bool) ($settings['text_ai_enabled'] ?? false),
            'image_enabled' => (bool) ($settings['image_ai_enabled'] ?? false),
            'text_model' => is_array($creative) ? ($creative['model'] ?? null) : null,
            'image_model' => is_array($image) ? ($image['model'] ?? null) : null,
            'creative_prompt' => trim((string) ($settings['creative_prompt'] ?? '')) ?: null,
            'image_prompt' => trim((string) (data_get($image, 'prompt') ?: ($selected['image_prompt'] ?? ''))) ?: null,
            'selected_score' => isset($selected['score']) ? (int) $selected['score'] : null,
            'selected_score_reason' => trim((string) ($selected['score_reason'] ?? '')) ?: null,
            'variant_count' => is_array($creative) ? count((array) ($creative['variants'] ?? [])) : null,
            'requested_variant_count' => (int) ($settings['variant_count'] ?? 1),
            'fallback_used' => $textFallbackUsed || $imageFallbackUsed,
            'fallback_reason' => trim((string) ($creative['fallback_reason'] ?? data_get($image, 'fallback_reason', ''))) ?: null,
            'generation_mode' => $creative['generation_mode'] ?? (is_array($image) ? 'ai_image' : 'ai_creative'),
            'usage' => is_array($creative['usage'] ?? null) ? $creative['usage'] : null,
            'variants' => is_array($creative) ? $this->aiVariantMetadata((array) ($creative['variants'] ?? [])) : null,
            'image' => is_array($image) ? $this->aiImageMetadata($image) : null,
            'generated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private function aiImageMetadata(array $image): array
    {
        return array_filter([
            'generated' => (bool) ($image['generated'] ?? false),
            'status' => $image['status'] ?? null,
            'outcome_code' => $image['outcome_code'] ?? null,
            'model' => $image['model'] ?? null,
            'prompt' => $image['prompt'] ?? null,
            'image_mode' => $image['image_mode'] ?? null,
            'image_format' => $image['image_format'] ?? null,
            'size' => $image['size'] ?? null,
            'usage_mode' => $image['usage_mode'] ?? null,
            'credit_balance' => $image['credit_balance'] ?? null,
            'fallback_used' => (bool) ($image['fallback_used'] ?? false),
            'fallback_reason' => $image['fallback_reason'] ?? null,
            'url' => data_get($image, 'media_payload.0.url'),
            'path' => data_get($image, 'media_payload.0.path'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, mixed>  $variants
     * @return array<int, array<string, mixed>>
     */
    private function aiVariantMetadata(array $variants): array
    {
        return collect($variants)
            ->filter(fn ($variant): bool => is_array($variant))
            ->map(fn (array $variant): array => array_filter([
                'text' => Str::limit(trim((string) ($variant['text'] ?? '')), 280, ''),
                'score' => isset($variant['score']) ? (int) $variant['score'] : null,
                'score_reason' => trim((string) ($variant['score_reason'] ?? '')) ?: null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $mediaPayload
     */
    private function fingerprintImageUrl(?array $mediaPayload, string $fallbackImageUrl): string
    {
        $url = $this->mediaAssetService->imageUrl($mediaPayload);

        return $url ?: $fallbackImageUrl;
    }

    private function fingerprint(
        string $text,
        string $imageUrl,
        string $linkUrl,
        string $sourceType,
        int $sourceId,
        string $locale
    ): string {
        $normalized = implode('|', [
            mb_strtolower(trim($text)),
            mb_strtolower(trim($imageUrl)),
            mb_strtolower(trim($linkUrl)),
            mb_strtolower(trim($sourceType)),
            $sourceId,
            mb_strtolower(trim($locale)),
        ]);

        return sha1($normalized);
    }
}
