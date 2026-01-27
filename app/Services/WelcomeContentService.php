<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WelcomeContentService
{
    private const SETTING_KEY = 'welcome_builder';

    private const LOCALES = ['fr', 'en'];

    private const ALLOWED_HTML_TAGS = [
        'div',
        'p',
        'br',
        'strong',
        'em',
        'u',
        'ul',
        'ol',
        'li',
        'h2',
        'h3',
        'blockquote',
        'code',
        'pre',
        'hr',
        'a',
        'img',
        'span',
    ];

    public function locales(): array
    {
        return self::LOCALES;
    }

    public function resolveForLocale(string $locale): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale);
        $stored = $this->storedLocales()[$locale] ?? [];

        $merged = $this->mergeContent($default, $stored);

        $merged['hero']['image_url'] = $this->resolveImageUrl(
            $merged['hero']['image_url'] ?? null,
            $merged['hero']['image_path'] ?? null,
            $default['hero']['image_url']
        );
        $merged['workflow']['image_url'] = $this->resolveImageUrl(
            $merged['workflow']['image_url'] ?? null,
            $merged['workflow']['image_path'] ?? null,
            $default['workflow']['image_url']
        );
        $merged['field']['image_url'] = $this->resolveImageUrl(
            $merged['field']['image_url'] ?? null,
            $merged['field']['image_path'] ?? null,
            $default['field']['image_url']
        );

        $merged['custom_sections'] = collect($merged['custom_sections'] ?? [])
            ->map(function ($section) {
                if (!is_array($section)) {
                    return null;
                }
                $section['id'] = (string) ($section['id'] ?? Str::uuid());
                $section['enabled'] = array_key_exists('enabled', $section) ? (bool) $section['enabled'] : true;
                return $section;
            })
            ->filter()
            ->values()
            ->all();

        return $merged;
    }

    public function resolveAll(): array
    {
        $payload = [];
        foreach ($this->locales() as $locale) {
            $payload[$locale] = $this->resolveForLocale($locale);
        }

        return $payload;
    }

    public function meta(): array
    {
        $payload = PlatformSetting::getValue(self::SETTING_KEY, []);
        if (!is_array($payload)) {
            return [
                'updated_at' => null,
                'updated_by' => null,
            ];
        }

        return [
            'updated_at' => $payload['updated_at'] ?? null,
            'updated_by' => $payload['updated_by'] ?? null,
        ];
    }

    public function updateLocale(string $locale, array $incoming, array $uploads = [], ?int $userId = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale);
        $sanitized = $this->sanitizeContent($incoming, $default);

        $storedLocales = $this->storedLocales();
        $previous = $storedLocales[$locale] ?? [];

        $sanitized = $this->applyUploads($locale, $sanitized, $previous, $uploads, $default);

        $storedLocales[$locale] = $sanitized;

        PlatformSetting::setValue(self::SETTING_KEY, [
            'locales' => $storedLocales,
            'updated_by' => $userId,
            'updated_at' => now()->toIso8601String(),
        ]);

        return $this->resolveForLocale($locale);
    }

    public function defaultContent(string $locale): array
    {
        $locale = $this->normalizeLocale($locale);

        return $this->withLocale($locale, function () {
            $heroStats = [
                [
                    'value' => '8',
                    'label' => (string) trans('welcome.hero.stats.one_label'),
                ],
                [
                    'value' => '2',
                    'label' => (string) trans('welcome.hero.stats.two_label'),
                ],
                [
                    'value' => '24/7',
                    'label' => (string) trans('welcome.hero.stats.three_label'),
                ],
            ];

            $heroHighlights = [
                (string) trans('welcome.hero.highlights.one'),
                (string) trans('welcome.hero.highlights.two'),
                (string) trans('welcome.hero.highlights.three'),
            ];

            $heroPreview = [
                [
                    'title' => (string) trans('welcome.hero.preview.one'),
                    'desc' => (string) trans('welcome.hero.preview.one_desc'),
                ],
                [
                    'title' => (string) trans('welcome.hero.preview.two'),
                    'desc' => (string) trans('welcome.hero.preview.two_desc'),
                ],
            ];

            $trustItems = [
                (string) trans('welcome.trust.items.one'),
                (string) trans('welcome.trust.items.two'),
                (string) trans('welcome.trust.items.three'),
                (string) trans('welcome.trust.items.four'),
                (string) trans('welcome.trust.items.five'),
                (string) trans('welcome.trust.items.six'),
            ];

            $featureItems = [
                [
                    'key' => 'quotes',
                    'title' => (string) trans('welcome.features.items.quotes.title'),
                    'desc' => (string) trans('welcome.features.items.quotes.desc'),
                ],
                [
                    'key' => 'plans',
                    'title' => (string) trans('welcome.features.items.plans.title'),
                    'desc' => (string) trans('welcome.features.items.plans.desc'),
                ],
                [
                    'key' => 'catalog',
                    'title' => (string) trans('welcome.features.items.catalog.title'),
                    'desc' => (string) trans('welcome.features.items.catalog.desc'),
                ],
                [
                    'key' => 'ops',
                    'title' => (string) trans('welcome.features.items.ops.title'),
                    'desc' => (string) trans('welcome.features.items.ops.desc'),
                ],
                [
                    'key' => 'portal',
                    'title' => (string) trans('welcome.features.items.portal.title'),
                    'desc' => (string) trans('welcome.features.items.portal.desc'),
                ],
                [
                    'key' => 'multi',
                    'title' => (string) trans('welcome.features.items.multi.title'),
                    'desc' => (string) trans('welcome.features.items.multi.desc'),
                ],
            ];

            $newFeatureItems = [
                [
                    'key' => 'assistant',
                    'title' => (string) trans('welcome.new_features.items.assistant.title'),
                    'desc' => (string) trans('welcome.new_features.items.assistant.desc'),
                ],
                [
                    'key' => 'connect',
                    'title' => (string) trans('welcome.new_features.items.connect.title'),
                    'desc' => (string) trans('welcome.new_features.items.connect.desc'),
                ],
                [
                    'key' => 'store',
                    'title' => (string) trans('welcome.new_features.items.store.title'),
                    'desc' => (string) trans('welcome.new_features.items.store.desc'),
                ],
            ];

            $workflowSteps = [
                [
                    'title' => (string) trans('welcome.workflow.steps.one.title'),
                    'desc' => (string) trans('welcome.workflow.steps.one.desc'),
                ],
                [
                    'title' => (string) trans('welcome.workflow.steps.two.title'),
                    'desc' => (string) trans('welcome.workflow.steps.two.desc'),
                ],
                [
                    'title' => (string) trans('welcome.workflow.steps.three.title'),
                    'desc' => (string) trans('welcome.workflow.steps.three.desc'),
                ],
                [
                    'title' => (string) trans('welcome.workflow.steps.four.title'),
                    'desc' => (string) trans('welcome.workflow.steps.four.desc'),
                ],
                [
                    'title' => (string) trans('welcome.workflow.steps.five.title'),
                    'desc' => (string) trans('welcome.workflow.steps.five.desc'),
                ],
            ];

            $fieldItems = [
                (string) trans('welcome.field.items.one'),
                (string) trans('welcome.field.items.two'),
                (string) trans('welcome.field.items.three'),
                (string) trans('welcome.field.items.four'),
            ];

            return [
                'nav' => [
                    'tagline' => (string) trans('welcome.nav.tagline'),
                    'menu' => [
                        [
                            'id' => 'login',
                            'label' => (string) trans('welcome.hero.secondary_cta'),
                            'href' => 'login',
                            'style' => 'outline',
                            'enabled' => true,
                        ],
                        [
                            'id' => 'onboarding',
                            'label' => (string) trans('welcome.hero.primary_cta'),
                            'href' => 'onboarding.index',
                            'style' => 'solid',
                            'enabled' => true,
                        ],
                    ],
                ],
                'hero' => [
                    'enabled' => true,
                    'background_color' => null,
                    'eyebrow' => (string) trans('welcome.hero.eyebrow'),
                    'title' => (string) trans('welcome.hero.title'),
                    'subtitle' => (string) trans('welcome.hero.subtitle'),
                    'primary_cta' => (string) trans('welcome.hero.primary_cta'),
                    'primary_href' => 'onboarding.index',
                    'secondary_cta' => (string) trans('welcome.hero.secondary_cta'),
                    'secondary_href' => 'login',
                    'note' => (string) trans('welcome.hero.note'),
                    'stats' => $heroStats,
                    'highlights' => $heroHighlights,
                    'preview_cards' => $heroPreview,
                    'image_url' => '/images/landing/hero-dashboard.svg',
                    'image_path' => null,
                    'image_alt' => (string) trans('welcome.images.hero_alt'),
                ],
                'trust' => [
                    'enabled' => true,
                    'background_color' => null,
                    'title' => (string) trans('welcome.trust.title'),
                    'items' => $trustItems,
                ],
                'features' => [
                    'enabled' => true,
                    'background_color' => null,
                    'kicker' => (string) trans('welcome.features.kicker'),
                    'title' => (string) trans('welcome.features.title'),
                    'subtitle' => (string) trans('welcome.features.subtitle'),
                    'items' => $featureItems,
                    'new_features' => [
                        'enabled' => true,
                        'background_color' => null,
                        'kicker' => (string) trans('welcome.new_features.kicker'),
                        'title' => (string) trans('welcome.new_features.title'),
                        'subtitle' => (string) trans('welcome.new_features.subtitle'),
                        'badge' => (string) trans('welcome.new_features.badge'),
                        'items' => $newFeatureItems,
                    ],
                ],
                'workflow' => [
                    'enabled' => true,
                    'background_color' => null,
                    'kicker' => (string) trans('welcome.workflow.kicker'),
                    'title' => (string) trans('welcome.workflow.title'),
                    'subtitle' => (string) trans('welcome.workflow.subtitle'),
                    'steps' => $workflowSteps,
                    'image_url' => '/images/landing/workflow-board.svg',
                    'image_path' => null,
                    'image_alt' => (string) trans('welcome.images.workflow_alt'),
                ],
                'field' => [
                    'enabled' => true,
                    'background_color' => null,
                    'kicker' => (string) trans('welcome.field.kicker'),
                    'title' => (string) trans('welcome.field.title'),
                    'subtitle' => (string) trans('welcome.field.subtitle'),
                    'items' => $fieldItems,
                    'image_url' => '/images/landing/mobile-field.svg',
                    'image_path' => null,
                    'image_alt' => (string) trans('welcome.images.mobile_alt'),
                ],
                'cta' => [
                    'enabled' => true,
                    'background_color' => null,
                    'title' => (string) trans('welcome.cta.title'),
                    'subtitle' => (string) trans('welcome.cta.subtitle'),
                    'primary' => (string) trans('welcome.cta.primary'),
                    'primary_href' => 'onboarding.index',
                    'secondary' => (string) trans('welcome.cta.secondary'),
                    'secondary_href' => 'login',
                ],
                'custom_sections' => [],
                'footer' => [
                    'terms_label' => (string) trans('welcome.footer.terms'),
                    'terms_href' => 'terms',
                    'copy' => (string) trans('welcome.footer.copy'),
                ],
            ];
        });
    }

    private function applyUploads(string $locale, array $content, array $previous, array $uploads, array $default): array
    {
        $content['hero'] = $this->handleImageUpload(
            $locale,
            'hero',
            $content['hero'] ?? [],
            $previous['hero'] ?? [],
            $uploads['hero_image'] ?? null,
            (bool) ($uploads['hero_image_remove'] ?? false),
            $default['hero']['image_url']
        );
        $content['workflow'] = $this->handleImageUpload(
            $locale,
            'workflow',
            $content['workflow'] ?? [],
            $previous['workflow'] ?? [],
            $uploads['workflow_image'] ?? null,
            (bool) ($uploads['workflow_image_remove'] ?? false),
            $default['workflow']['image_url']
        );
        $content['field'] = $this->handleImageUpload(
            $locale,
            'field',
            $content['field'] ?? [],
            $previous['field'] ?? [],
            $uploads['field_image'] ?? null,
            (bool) ($uploads['field_image_remove'] ?? false),
            $default['field']['image_url']
        );

        return $content;
    }

    private function handleImageUpload(
        string $locale,
        string $section,
        array $current,
        array $previous,
        ?UploadedFile $file,
        bool $remove,
        string $defaultUrl
    ): array {
        $disk = Storage::disk('public');
        $previousPath = $this->extractImagePath($previous);

        if ($remove && $previousPath && $this->isManagedPath($previousPath)) {
            $disk->delete($previousPath);
            $current['image_path'] = null;
            $current['image_url'] = $defaultUrl;
        }

        if ($file) {
            if ($previousPath && $this->isManagedPath($previousPath)) {
                $disk->delete($previousPath);
            }

            $path = $file->store("welcome/{$locale}", 'public');
            $current['image_path'] = $path;
            $current['image_url'] = null;
        }

        return $current;
    }

    private function sanitizeContent(array $incoming, array $default): array
    {
        return [
            'nav' => $this->sanitizeNav($incoming['nav'] ?? [], $default['nav']),
            'hero' => $this->sanitizeHero($incoming['hero'] ?? [], $default['hero']),
            'trust' => $this->sanitizeTrust($incoming['trust'] ?? [], $default['trust']),
            'features' => $this->sanitizeFeatures($incoming['features'] ?? [], $default['features']),
            'workflow' => $this->sanitizeWorkflow($incoming['workflow'] ?? [], $default['workflow']),
            'field' => $this->sanitizeField($incoming['field'] ?? [], $default['field']),
            'cta' => $this->sanitizeCta($incoming['cta'] ?? [], $default['cta']),
            'custom_sections' => $this->sanitizeCustomSections($incoming['custom_sections'] ?? []),
            'footer' => $this->sanitizeFooter($incoming['footer'] ?? [], $default['footer']),
        ];
    }

    private function sanitizeNav(array $incoming, array $default): array
    {
        $menu = $incoming['menu'] ?? [];
        if (!is_array($menu)) {
            $menu = [];
        }

        $items = [];
        foreach ($menu as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'id' => (string) ($item['id'] ?? Str::uuid()),
                'label' => $this->cleanText($item['label'] ?? ''),
                'href' => $this->cleanText($item['href'] ?? ''),
                'style' => $this->cleanStyle($item['style'] ?? 'outline'),
                'enabled' => array_key_exists('enabled', $item) ? (bool) $item['enabled'] : true,
            ];
        }

        return [
            'tagline' => $this->cleanText($incoming['tagline'] ?? $default['tagline']),
            'menu' => array_values($items),
        ];
    }

    private function sanitizeHero(array $incoming, array $default): array
    {
        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'eyebrow' => $this->cleanText($incoming['eyebrow'] ?? $default['eyebrow']),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'subtitle' => $this->cleanHtml($incoming['subtitle'] ?? $default['subtitle']),
            'primary_cta' => $this->cleanText($incoming['primary_cta'] ?? $default['primary_cta']),
            'primary_href' => $this->cleanText($incoming['primary_href'] ?? $default['primary_href']),
            'secondary_cta' => $this->cleanText($incoming['secondary_cta'] ?? $default['secondary_cta']),
            'secondary_href' => $this->cleanText($incoming['secondary_href'] ?? $default['secondary_href']),
            'note' => $this->cleanHtml($incoming['note'] ?? $default['note']),
            'stats' => $this->sanitizeStatItems($incoming['stats'] ?? $default['stats']),
            'highlights' => $this->sanitizeStringList($incoming['highlights'] ?? $default['highlights']),
            'preview_cards' => $this->sanitizePreviewCards($incoming['preview_cards'] ?? $default['preview_cards']),
            'image_url' => $this->cleanText($incoming['image_url'] ?? ''),
            'image_path' => $this->extractImagePath($incoming) ?? $this->extractImagePath($default),
            'image_alt' => $this->cleanText($incoming['image_alt'] ?? $default['image_alt']),
        ];
    }

    private function sanitizeTrust(array $incoming, array $default): array
    {
        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'items' => $this->sanitizeStringList($incoming['items'] ?? $default['items']),
        ];
    }

    private function sanitizeFeatures(array $incoming, array $default): array
    {
        $newFeatures = $incoming['new_features'] ?? [];
        if (!is_array($newFeatures)) {
            $newFeatures = [];
        }

        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'kicker' => $this->cleanText($incoming['kicker'] ?? $default['kicker']),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'subtitle' => $this->cleanHtml($incoming['subtitle'] ?? $default['subtitle']),
            'items' => $this->sanitizeFeatureItems($incoming['items'] ?? $default['items']),
            'new_features' => [
                'enabled' => array_key_exists('enabled', $newFeatures) ? (bool) $newFeatures['enabled'] : true,
                'background_color' => $this->cleanColor($newFeatures['background_color'] ?? $default['new_features']['background_color'] ?? null),
                'kicker' => $this->cleanText($newFeatures['kicker'] ?? $default['new_features']['kicker']),
                'title' => $this->cleanText($newFeatures['title'] ?? $default['new_features']['title']),
                'subtitle' => $this->cleanHtml($newFeatures['subtitle'] ?? $default['new_features']['subtitle']),
                'badge' => $this->cleanText($newFeatures['badge'] ?? $default['new_features']['badge']),
                'items' => $this->sanitizeFeatureItems($newFeatures['items'] ?? $default['new_features']['items']),
            ],
        ];
    }

    private function sanitizeWorkflow(array $incoming, array $default): array
    {
        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'kicker' => $this->cleanText($incoming['kicker'] ?? $default['kicker']),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'subtitle' => $this->cleanHtml($incoming['subtitle'] ?? $default['subtitle']),
            'steps' => $this->sanitizePreviewCards($incoming['steps'] ?? $default['steps']),
            'image_url' => $this->cleanText($incoming['image_url'] ?? ''),
            'image_path' => $this->extractImagePath($incoming) ?? $this->extractImagePath($default),
            'image_alt' => $this->cleanText($incoming['image_alt'] ?? $default['image_alt']),
        ];
    }

    private function sanitizeField(array $incoming, array $default): array
    {
        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'kicker' => $this->cleanText($incoming['kicker'] ?? $default['kicker']),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'subtitle' => $this->cleanHtml($incoming['subtitle'] ?? $default['subtitle']),
            'items' => $this->sanitizeStringList($incoming['items'] ?? $default['items']),
            'image_url' => $this->cleanText($incoming['image_url'] ?? ''),
            'image_path' => $this->extractImagePath($incoming) ?? $this->extractImagePath($default),
            'image_alt' => $this->cleanText($incoming['image_alt'] ?? $default['image_alt']),
        ];
    }

    private function sanitizeCta(array $incoming, array $default): array
    {
        return [
            'enabled' => array_key_exists('enabled', $incoming) ? (bool) $incoming['enabled'] : true,
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $default['background_color'] ?? null),
            'title' => $this->cleanText($incoming['title'] ?? $default['title']),
            'subtitle' => $this->cleanHtml($incoming['subtitle'] ?? $default['subtitle']),
            'primary' => $this->cleanText($incoming['primary'] ?? $default['primary']),
            'primary_href' => $this->cleanText($incoming['primary_href'] ?? $default['primary_href']),
            'secondary' => $this->cleanText($incoming['secondary'] ?? $default['secondary']),
            'secondary_href' => $this->cleanText($incoming['secondary_href'] ?? $default['secondary_href']),
        ];
    }

    private function sanitizeFooter(array $incoming, array $default): array
    {
        return [
            'terms_label' => $this->cleanText($incoming['terms_label'] ?? $default['terms_label']),
            'terms_href' => $this->cleanText($incoming['terms_href'] ?? $default['terms_href']),
            'copy' => $this->cleanText($incoming['copy'] ?? $default['copy']),
        ];
    }

    private function sanitizeCustomSections($sections): array
    {
        if (!is_array($sections)) {
            return [];
        }

        $items = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $items[] = [
                'id' => (string) ($section['id'] ?? Str::uuid()),
                'enabled' => array_key_exists('enabled', $section) ? (bool) $section['enabled'] : true,
                'background_color' => $this->cleanColor($section['background_color'] ?? null),
                'kicker' => $this->cleanText($section['kicker'] ?? ''),
                'title' => $this->cleanText($section['title'] ?? ''),
                'body' => $this->cleanHtml($section['body'] ?? ''),
                'image_url' => $this->cleanText($section['image_url'] ?? ''),
                'image_alt' => $this->cleanText($section['image_alt'] ?? ''),
                'primary_label' => $this->cleanText($section['primary_label'] ?? ''),
                'primary_href' => $this->cleanText($section['primary_href'] ?? ''),
                'secondary_label' => $this->cleanText($section['secondary_label'] ?? ''),
                'secondary_href' => $this->cleanText($section['secondary_href'] ?? ''),
            ];
        }

        return array_slice(array_values($items), 0, 12);
    }

    private function sanitizeFeatureItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sanitized[] = [
                'key' => $this->cleanText($item['key'] ?? ''),
                'title' => $this->cleanText($item['title'] ?? ''),
                'desc' => $this->cleanHtml($item['desc'] ?? ''),
            ];
        }

        return array_values($sanitized);
    }

    private function sanitizePreviewCards($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sanitized[] = [
                'title' => $this->cleanText($item['title'] ?? ''),
                'desc' => $this->cleanHtml($item['desc'] ?? ''),
            ];
        }

        return array_values($sanitized);
    }

    private function sanitizeStatItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sanitized[] = [
                'value' => $this->cleanText($item['value'] ?? ''),
                'label' => $this->cleanText($item['label'] ?? ''),
            ];
        }

        return array_values($sanitized);
    }

    private function sanitizeStringList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map(fn ($item) => $this->cleanText($item), $items));
    }

    private function resolveImageUrl(?string $imageUrl, ?string $imagePath, string $defaultUrl): string
    {
        $url = $this->cleanText($imageUrl);
        if ($url !== '') {
            return $url;
        }

        $path = $this->cleanText($imagePath);
        if ($path !== '') {
            return Storage::disk('public')->url($path);
        }

        return $defaultUrl;
    }

    private function storedLocales(): array
    {
        $payload = PlatformSetting::getValue(self::SETTING_KEY, []);
        if (!is_array($payload)) {
            return [];
        }

        $locales = $payload['locales'] ?? [];
        return is_array($locales) ? $locales : [];
    }

    private function mergeContent(array $default, array $stored): array
    {
        $merged = $default;
        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, $default)) {
                continue;
            }

            if (is_array($value) && is_array($default[$key])) {
                if (array_is_list($value) || array_is_list($default[$key])) {
                    $merged[$key] = $value;
                    continue;
                }
                $merged[$key] = $this->mergeContent($default[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function extractImagePath(array $payload): ?string
    {
        $path = $payload['image_path'] ?? null;
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path);
        return $path !== '' ? $path : null;
    }

    private function isManagedPath(string $path): bool
    {
        return str_starts_with($path, 'welcome/');
    }

    private function stringify($value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function cleanText($value): string
    {
        $text = $this->stringify($value);

        return trim(strip_tags($text));
    }

    private function cleanHtml($value): string
    {
        $html = $this->stringify($value);
        if ($html === '') {
            return '';
        }

        $allowed = '<' . implode('><', self::ALLOWED_HTML_TAGS) . '>';
        $html = strip_tags($html, $allowed);

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<div>' . $html . '</div>', \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) {
            return '';
        }

        $this->sanitizeHtmlNode($root);

        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $doc->saveHTML($child);
        }

        return trim($clean);
    }

    private function sanitizeHtmlNode(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        $allowedTags = self::ALLOWED_HTML_TAGS;
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    $text = $child->textContent ?? '';
                    $node->replaceChild($node->ownerDocument->createTextNode($text), $child);
                    continue;
                }

                $allowedAttributes = $this->allowedAttributes($tag);
                if ($child->hasAttributes()) {
                    for ($i = $child->attributes->length - 1; $i >= 0; $i--) {
                        $attribute = $child->attributes->item($i);
                        if (!$attribute) {
                            continue;
                        }
                        $name = strtolower($attribute->name);
                        if (!in_array($name, $allowedAttributes, true)) {
                            $child->removeAttribute($attribute->name);
                        }
                    }
                }

                if ($tag === 'a') {
                    $href = $this->sanitizeUrl($child->getAttribute('href'), 'link');
                    if ($href === null) {
                        $child->removeAttribute('href');
                    } else {
                        $child->setAttribute('href', $href);
                    }

                    if ($child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }

                if ($tag === 'img') {
                    $src = $this->sanitizeUrl($child->getAttribute('src'), 'image');
                    if ($src === null) {
                        $node->removeChild($child);
                        continue;
                    }
                    $child->setAttribute('src', $src);
                }
            }

            $this->sanitizeHtmlNode($child);
        }
    }

    private function allowedAttributes(string $tag): array
    {
        return match ($tag) {
            'a' => ['href', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            default => [],
        };
    }

    private function sanitizeUrl(?string $url, string $context): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:text/html')) {
            return null;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }

        if (preg_match('/^(https?:|mailto:|tel:)/i', $url) === 1) {
            return $url;
        }

        if ($context === 'image' && str_starts_with($lower, 'data:image/')) {
            return $url;
        }

        return null;
    }

    private function cleanColor($value): ?string
    {
        $color = $this->cleanText($value);
        if ($color === '') {
            return null;
        }

        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            return null;
        }

        return strtolower($color);
    }

    private function cleanStyle($value): string
    {
        $style = $this->cleanText($value);
        return in_array($style, ['solid', 'outline', 'ghost'], true) ? $style : 'outline';
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        return in_array($locale, $this->locales(), true) ? $locale : $this->locales()[0];
    }

    private function withLocale(string $locale, callable $callback): array
    {
        $current = app()->getLocale();
        app()->setLocale($locale);

        try {
            /** @var array $result */
            $result = $callback();
            return $result;
        } finally {
            app()->setLocale($current);
        }
    }
}
