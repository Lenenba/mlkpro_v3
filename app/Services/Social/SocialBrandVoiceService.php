<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\User;
use App\Services\Campaigns\MarketingSettingsService;
use App\Support\LocalePreference;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SocialBrandVoiceService
{
    public function __construct(
        private readonly MarketingSettingsService $marketingSettingsService,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function allowedTones(): array
    {
        return SocialAutomationRule::allowedAiTones();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $owner): array
    {
        $configured = $this->marketingSettingsService->getValue($owner, 'templates.brand_voice', []);
        $configured = is_array($configured) ? $configured : [];

        return $this->normalize($owner, $configured);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(User $owner, array $payload): array
    {
        $normalized = $this->normalize($owner, $payload);

        $this->marketingSettingsService->update($owner, [
            'templates' => [
                'brand_voice' => Arr::except($normalized, ['is_configured']),
            ],
        ]);

        return $this->resolve($owner);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalize(User $owner, array $payload): array
    {
        $tone = strtolower(trim((string) ($payload['tone'] ?? 'professional')));
        if (! in_array($tone, self::allowedTones(), true)) {
            $tone = 'professional';
        }

        $language = LocalePreference::normalize(
            trim((string) ($payload['language'] ?? '')) ?: LocalePreference::forUser($owner)
        );

        $normalized = [
            'tone' => $tone,
            'language' => $language,
            'style_notes' => $this->nullableText($payload['style_notes'] ?? null, 700),
            'words_to_avoid' => $this->textList($payload['words_to_avoid'] ?? [], 12, 48),
            'preferred_hashtags' => $this->hashtags($payload['preferred_hashtags'] ?? [], 8),
            'preferred_ctas' => $this->textList($payload['preferred_ctas'] ?? [], 5, 120),
            'sample_phrase' => $this->nullableText($payload['sample_phrase'] ?? null, 240),
        ];
        $normalized['is_configured'] = $this->isConfigured($owner, $normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $brandVoice
     * @return array<string, mixed>
     */
    public function aiContext(array $brandVoice): array
    {
        return array_filter([
            'tone' => $brandVoice['tone'] ?? null,
            'language' => $brandVoice['language'] ?? null,
            'style_notes' => $brandVoice['style_notes'] ?? null,
            'words_to_avoid' => $brandVoice['words_to_avoid'] ?? [],
            'preferred_hashtags' => $brandVoice['preferred_hashtags'] ?? [],
            'preferred_ctas' => $brandVoice['preferred_ctas'] ?? [],
            'sample_phrase' => $brandVoice['sample_phrase'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $candidate = Str::limit(trim((string) $value), $limit, '');

        return $candidate !== '' ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $brandVoice
     */
    private function isConfigured(User $owner, array $brandVoice): bool
    {
        return ((string) ($brandVoice['tone'] ?? 'professional')) !== 'professional'
            || ((string) ($brandVoice['language'] ?? LocalePreference::forUser($owner))) !== LocalePreference::forUser($owner)
            || (trim((string) ($brandVoice['style_notes'] ?? '')) !== '')
            || (trim((string) ($brandVoice['sample_phrase'] ?? '')) !== '')
            || ((array) ($brandVoice['words_to_avoid'] ?? []) !== [])
            || ((array) ($brandVoice['preferred_hashtags'] ?? []) !== [])
            || ((array) ($brandVoice['preferred_ctas'] ?? []) !== []);
    }

    /**
     * @return array<int, string>
     */
    private function textList(mixed $value, int $limit, int $itemLimit): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/\r\n|\r|\n|,/', (string) $value);

        return collect($items ?: [])
            ->map(fn ($item): string => Str::limit(trim((string) $item), $itemLimit, ''))
            ->filter()
            ->unique(fn (string $item): string => Str::lower($item))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function hashtags(mixed $value, int $limit): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/\r\n|\r|\n|,|\s+/', (string) $value);

        return collect($items ?: [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->map(function (string $item): string {
                $tag = ltrim($item, '#');
                $tag = preg_replace('/[^A-Za-z0-9_]/', '', Str::ascii($tag)) ?: '';

                return $tag !== '' ? '#'.$tag : '';
            })
            ->filter()
            ->unique(fn (string $item): string => Str::lower($item))
            ->take($limit)
            ->values()
            ->all();
    }
}
