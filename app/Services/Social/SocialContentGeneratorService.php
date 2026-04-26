<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\LocalePreference;
use Illuminate\Validation\ValidationException;

class SocialContentGeneratorService
{
    public function __construct(
        private readonly SocialPrefillService $prefillService,
        private readonly SocialSuggestionService $suggestionService,
        private readonly SocialMediaAssetService $mediaAssetService,
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
        $mediaPayload = $this->mediaAssetService->imageMediaPayload([
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
                    'label' => trim((string) ($prefill['source_label'] ?? $source['source_label'] ?? '')) ?: null,
                ],
                'generated_caption_key' => $selectedCaption['key'] ?? null,
                'generated_caption_label' => $selectedCaption['label'] ?? null,
                'generated_locale' => $locale,
            ],
            'content_fingerprint' => $this->fingerprint(
                $finalText,
                $imageUrl,
                $linkUrl,
                $sourceType,
                $sourceId,
                $locale
            ),
        ];
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
