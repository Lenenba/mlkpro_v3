<?php

namespace App\Services\Social;

use App\Models\SocialAutomationRule;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Assistant\OpenAiClient;
use App\Support\LocalePreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SocialAiCreativeService
{
    public function __construct(
        private readonly OpenAiClient $client,
        private readonly SocialSuggestionService $suggestionService,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function generate(User $owner, SocialAutomationRule $rule, array $context): array
    {
        $settings = $this->settings((array) ($context['settings'] ?? []));
        $locale = LocalePreference::normalize((string) ($context['locale'] ?? $rule->language ?: $owner->locale));
        $context = array_merge($context, [
            'locale' => $locale,
            'settings' => $settings,
        ]);

        if (! config('services.openai.key')) {
            return $this->fallback($owner, $rule, $context, 'OpenAI is not configured for Pulse creative generation.');
        }

        try {
            $model = (string) config('services.openai.social_creative_model', config('services.openai.model', 'gpt-4o-mini'));
            $response = $this->client->chat(
                $this->messages($owner, $context),
                [
                    'model' => $model,
                    'temperature' => 0.7,
                    'timeout' => (int) config('services.openai.social_creative_timeout', 45),
                    'max_tokens' => 1600,
                    'json' => true,
                ]
            );

            $content = $this->client->extractMessage($response);
            $decoded = $this->decodeJson($content);
            $usage = $this->client->extractUsage($response);

            return $this->normalizeResponse($decoded, $context, (string) ($usage['model'] ?? $model), $usage);
        } catch (\Throwable $exception) {
            Log::warning('Pulse AI creative generation fell back to deterministic copy.', [
                'user_id' => $owner->id,
                'rule_id' => $rule->id,
                'source_type' => data_get($context, 'source.type'),
                'source_id' => data_get($context, 'source.id'),
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback($owner, $rule, $context, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function messages(User $owner, array $context): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->systemPrompt((string) $context['locale']),
            ],
            [
                'role' => 'user',
                'content' => $this->userPrompt($owner, $context),
            ],
        ];
    }

    private function systemPrompt(string $locale): string
    {
        $language = $this->languageLabel($locale);

        return <<<PROMPT
You are Malikia Pulse creative autopilot.
Return JSON only. Do not include markdown or explanatory prose outside the JSON object.

Write in {$language}. Create concise social post candidates for a small business.
Every candidate must be ready for human approval before publication.

Return exactly this JSON shape:
{
  "selected": {
    "text": "",
    "hashtags": [],
    "cta": "",
    "image_prompt": "",
    "score": 0,
    "score_reason": ""
  },
  "variants": [
    {
      "text": "",
      "hashtags": [],
      "cta": "",
      "image_prompt": "",
      "score": 0,
      "score_reason": ""
    }
  ]
}

Scoring is from 0 to 100 and should reward clarity, source accuracy, a strong CTA, the requested tone, channel fit, freshness, length discipline, and image prompt quality.
Keep text under 900 characters. Keep hashtags relevant and limited to 6. Do not invent prices, dates, discounts, availability, or guarantees that are not present in the source.
Image prompts must describe a realistic social visual and must not request embedded text, logos, watermarks, or UI screenshots.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(User $owner, array $context): string
    {
        $brief = [
            'company' => [
                'name' => trim((string) ($owner->company_name ?: $owner->name ?: config('app.name'))),
                'type' => trim((string) ($owner->company_type ?? 'services')),
                'sector' => trim((string) ($owner->company_sector ?? '')),
                'locale' => $context['locale'],
            ],
            'source' => [
                'type' => data_get($context, 'source.type'),
                'label' => data_get($context, 'source.label'),
                'summary' => data_get($context, 'source.summary'),
                'link_url' => data_get($context, 'source.link_url'),
                'has_image' => (bool) data_get($context, 'source.has_image', false),
            ],
            'settings' => [
                'tone' => data_get($context, 'settings.tone'),
                'goal' => data_get($context, 'settings.goal'),
                'creative_prompt' => data_get($context, 'settings.creative_prompt'),
                'image_prompt' => data_get($context, 'settings.image_prompt'),
                'image_format' => data_get($context, 'settings.image_format'),
                'variant_count' => data_get($context, 'settings.variant_count'),
            ],
            'targets' => array_values((array) ($context['targets'] ?? [])),
        ];

        $json = json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Generate the best Pulse social post candidate from this brief.

Brief JSON:
{$json}

Generate the requested number of variants. Select the highest scoring candidate.
If the source contains a link, make the call to action compatible with opening or booking from that link.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $decoded, array $context, string $model, array $usage): array
    {
        $variants = $this->normalizeVariants($decoded['variants'] ?? [], $context);
        $selected = $this->normalizeVariant($decoded['selected'] ?? [], $context);

        if ($selected['text'] !== '') {
            $variants = collect([$selected, ...$variants])
                ->unique(fn (array $variant): string => Str::lower((string) $variant['text']))
                ->values()
                ->all();
        }

        if ($variants === []) {
            throw new RuntimeException('OpenAI returned no usable Pulse creative variants.');
        }

        if ($selected['text'] === '') {
            $selected = $this->bestVariant($variants);
        }

        $selected['image_prompt'] = $selected['image_prompt'] !== ''
            ? $selected['image_prompt']
            : $this->fallbackImagePrompt($context);

        return [
            'selected' => $selected,
            'variants' => $variants,
            'model' => $model,
            'usage' => $usage,
            'generation_mode' => 'ai_creative',
            'fallback_used' => false,
            'fallback_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fallback(User $owner, SocialAutomationRule $rule, array $context, string $reason): array
    {
        $source = is_array($context['source'] ?? null) ? $context['source'] : [];
        $locale = (string) ($context['locale'] ?? LocalePreference::normalize((string) ($rule->language ?: $owner->locale)));
        $suggestions = $this->suggestionService->suggest($owner, [
            'text' => $this->normalizeString($source['summary'] ?? null, 900) ?: null,
            'link_url' => $this->normalizeString($source['link_url'] ?? null, 500) ?: null,
            'source_type' => $source['type'] ?? null,
            'source_id' => $source['id'] ?? null,
        ], $locale);

        $hashtags = $this->normalizeHashtags($suggestions['hashtags'] ?? []);
        $cta = $this->normalizeString(data_get($suggestions, 'ctas.0.text'), 180);
        $imagePrompt = $this->fallbackImagePrompt($context);

        $variants = collect((array) ($suggestions['captions'] ?? []))
            ->map(function (array $caption) use ($hashtags, $cta, $imagePrompt, $context): array {
                $variant = [
                    'key' => $this->normalizeString($caption['key'] ?? null, 80),
                    'label' => $this->normalizeString($caption['label'] ?? null, 120),
                    'text' => $this->normalizeString($caption['text'] ?? null, 900),
                    'hashtags' => $hashtags,
                    'cta' => $cta,
                    'image_prompt' => $imagePrompt,
                    'score' => 0,
                    'score_reason' => 'Deterministic Pulse fallback candidate.',
                ];
                $variant['score'] = $this->scoreVariant($variant, $context);

                return $variant;
            })
            ->filter(fn (array $variant): bool => $variant['text'] !== '')
            ->values()
            ->all();

        if ($variants === []) {
            $fallbackText = $this->fallbackText($owner, $source, $locale);
            $variants[] = [
                'key' => 'fallback',
                'label' => 'Fallback',
                'text' => $fallbackText,
                'hashtags' => $hashtags,
                'cta' => $cta,
                'image_prompt' => $imagePrompt,
                'score' => 50,
                'score_reason' => 'Minimal fallback candidate because no source caption was available.',
            ];
        }

        $postCount = (int) SocialPost::query()
            ->where('social_automation_rule_id', $rule->id)
            ->count();
        $selected = $variants[$postCount % count($variants)] ?? $this->bestVariant($variants);

        return [
            'selected' => $selected,
            'variants' => $variants,
            'model' => null,
            'usage' => null,
            'generation_mode' => 'deterministic_fallback',
            'fallback_used' => true,
            'fallback_reason' => Str::limit(trim($reason), 240, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariants(mixed $value, array $context): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($variant): bool => is_array($variant))
            ->map(fn (array $variant): array => $this->normalizeVariant($variant, $context))
            ->filter(fn (array $variant): bool => $variant['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeVariant(mixed $value, array $context): array
    {
        $variant = is_array($value) ? $value : [];
        $normalized = [
            'text' => $this->normalizeString($variant['text'] ?? null, 900),
            'hashtags' => $this->normalizeHashtags($variant['hashtags'] ?? []),
            'cta' => $this->normalizeString($variant['cta'] ?? null, 180),
            'image_prompt' => $this->normalizeString($variant['image_prompt'] ?? null, 600),
            'score' => $this->normalizeScore($variant['score'] ?? null),
            'score_reason' => $this->normalizeString($variant['score_reason'] ?? null, 240),
        ];

        if ($normalized['score'] <= 0 && $normalized['text'] !== '') {
            $normalized['score'] = $this->scoreVariant($normalized, $context);
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function bestVariant(array $variants): array
    {
        return collect($variants)
            ->sortByDesc(fn (array $variant): int => (int) ($variant['score'] ?? 0))
            ->values()
            ->first() ?? [
                'text' => '',
                'hashtags' => [],
                'cta' => '',
                'image_prompt' => '',
                'score' => 0,
                'score_reason' => '',
            ];
    }

    /**
     * @param  array<string, mixed>  $variant
     * @param  array<string, mixed>  $context
     */
    private function scoreVariant(array $variant, array $context): int
    {
        $text = (string) ($variant['text'] ?? '');
        $score = 45;

        if (Str::length($text) >= 80 && Str::length($text) <= 650) {
            $score += 14;
        }

        if ($this->normalizeString($variant['cta'] ?? null, 180) !== '') {
            $score += 12;
        }

        if ($this->normalizeHashtags($variant['hashtags'] ?? []) !== []) {
            $score += 8;
        }

        $sourceLabel = Str::lower((string) data_get($context, 'source.label', ''));
        if ($sourceLabel !== '' && Str::contains(Str::lower($text), $sourceLabel)) {
            $score += 10;
        }

        if ($this->normalizeString($variant['image_prompt'] ?? null, 600) !== '') {
            $score += 6;
        }

        if (Str::length($text) > 900) {
            $score -= 15;
        }

        return max(0, min(100, $score));
    }

    private function fallbackImagePrompt(array $context): string
    {
        $settingsPrompt = $this->normalizeString(data_get($context, 'settings.image_prompt'), 500);
        $sourceLabel = $this->normalizeString(data_get($context, 'source.label'), 140);
        $format = $this->normalizeString(data_get($context, 'settings.image_format'), 40) ?: 'square';

        return trim(implode(' ', array_filter([
            $settingsPrompt,
            $sourceLabel ? 'Realistic social media visual for '.$sourceLabel.'.' : 'Realistic social media visual for a local business update.',
            'Format: '.$format.'. No embedded text, logos, watermarks, or UI screenshots.',
        ])));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function fallbackText(User $owner, array $source, string $locale): string
    {
        $label = $this->normalizeString($source['label'] ?? null, 160)
            ?: $this->normalizeString($owner->company_name ?? null, 160)
            ?: (string) config('app.name');

        return match ($locale) {
            'en' => 'Fresh update: '.$label.'. Message us for the details.',
            'es' => 'Nueva actualizacion: '.$label.'. Escribenos para recibir los detalles.',
            default => 'Nouvelle mise a jour : '.$label.'. Ecrivez-nous pour recevoir les details.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(mixed $payload): array
    {
        $settings = is_array($payload) ? $payload : [];
        $defaults = SocialAutomationRule::defaultGenerationSettings();

        return [
            'text_ai_enabled' => $this->booleanValue($settings['text_ai_enabled'] ?? $defaults['text_ai_enabled']),
            'image_ai_enabled' => $this->booleanValue($settings['image_ai_enabled'] ?? $defaults['image_ai_enabled']),
            'creative_prompt' => $this->normalizeString($settings['creative_prompt'] ?? $defaults['creative_prompt'], 1000),
            'image_prompt' => $this->normalizeString($settings['image_prompt'] ?? $defaults['image_prompt'], 1000),
            'tone' => $this->allowedString($settings['tone'] ?? null, SocialAutomationRule::allowedAiTones(), $defaults['tone']),
            'goal' => $this->allowedString($settings['goal'] ?? null, SocialAutomationRule::allowedAiGoals(), $defaults['goal']),
            'image_mode' => $this->allowedString($settings['image_mode'] ?? null, SocialAutomationRule::allowedAiImageModes(), $defaults['image_mode']),
            'image_format' => $this->allowedString($settings['image_format'] ?? null, SocialAutomationRule::allowedAiImageFormats(), $defaults['image_format']),
            'variant_count' => max(1, min(5, (int) ($settings['variant_count'] ?? $defaults['variant_count']))),
        ];
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedString(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $filtered ?? (bool) $value;
    }

    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON for Pulse creative generation.');
        }

        return $decoded;
    }

    private function languageLabel(string $locale): string
    {
        return match ($locale) {
            'en' => 'English',
            'es' => 'Spanish',
            default => 'French',
        };
    }

    private function normalizeString(mixed $value, int $limit): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }

        return Str::limit($candidate, $limit, '');
    }

    private function normalizeScore(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeHashtags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->map(function (string $item): string {
                $tag = ltrim($item, '#');
                $tag = preg_replace('/[^A-Za-z0-9_]/', '', Str::ascii($tag)) ?: '';

                return $tag !== '' ? '#'.$tag : '';
            })
            ->filter()
            ->unique(fn (string $item): string => Str::lower($item))
            ->take(6)
            ->values()
            ->all();
    }
}
