<?php

namespace App\Services;

use App\Models\PlatformPage;
use App\Models\PlatformSection;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PlatformPageContentService
{
    private const LOCALES = ['fr', 'en'];
    private const THEME_FONTS = ['work-sans', 'space-grotesk', 'sora', 'dm-sans'];
    private const THEME_RADII = ['sm', 'md', 'lg', 'xl'];
    private const THEME_SHADOWS = ['none', 'soft', 'deep'];
    private const THEME_BUTTON_STYLES = ['solid', 'outline', 'soft', 'ghost'];
    private const THEME_BACKGROUND_STYLES = ['solid', 'gradient'];
    private const VISIBILITY_AUTH = ['any', 'auth', 'guest'];
    private const VISIBILITY_DEVICES = ['all', 'mobile', 'desktop'];

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

    public function meta(PlatformPage $page): array
    {
        $payload = is_array($page->content) ? $page->content : [];

        return [
            'updated_at' => $payload['updated_at'] ?? null,
            'updated_by' => $payload['updated_by'] ?? $page->updated_by,
        ];
    }

    public function resolveAll(PlatformPage $page): array
    {
        $resolved = [];
        foreach ($this->locales() as $locale) {
            $resolved[$locale] = $this->resolveForLocale($page, $locale);
        }

        return $resolved;
    }

    public function resolveForLocale(PlatformPage $page, string $locale): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale, $page);
        $stored = $this->storedLocales($page)[$locale] ?? [];

        $merged = $this->mergeContent($default, is_array($stored) ? $stored : []);
        $merged['page_title'] = $this->cleanText($merged['page_title'] ?? $page->title);
        $merged['page_subtitle'] = $this->cleanHtml($merged['page_subtitle'] ?? '');

        $sections = $merged['sections'] ?? [];
        if (!is_array($sections)) {
            $sections = [];
        }

        $merged['sections'] = collect($sections)
            ->map(function ($section, $index) {
                if (!is_array($section)) {
                    return null;
                }

                return [
                    'id' => (string) ($section['id'] ?? "section-{$index}"),
                    'enabled' => array_key_exists('enabled', $section) ? (bool) $section['enabled'] : true,
                    'source_id' => $this->cleanSourceId($section['source_id'] ?? null),
                    'use_source' => array_key_exists('use_source', $section) ? (bool) $section['use_source'] : false,
                    'background_color' => $this->cleanColor($section['background_color'] ?? null) ?? '',
                    'layout' => $this->cleanLayout($section['layout'] ?? 'split'),
                    'alignment' => $this->cleanAlignment($section['alignment'] ?? 'left'),
                    'density' => $this->cleanDensity($section['density'] ?? 'normal'),
                    'tone' => $this->cleanTone($section['tone'] ?? 'default'),
                    'visibility' => $this->sanitizeVisibility($section['visibility'] ?? null),
                    'kicker' => $this->cleanText($section['kicker'] ?? ''),
                    'title' => $this->cleanText($section['title'] ?? ''),
                    'body' => $this->cleanHtml($section['body'] ?? ''),
                    'items' => $this->sanitizeStringList($section['items'] ?? []),
                    'image_url' => $this->cleanText($section['image_url'] ?? ''),
                    'image_alt' => $this->cleanText($section['image_alt'] ?? ''),
                    'primary_label' => $this->cleanText($section['primary_label'] ?? ''),
                    'primary_href' => $this->cleanText($section['primary_href'] ?? ''),
                    'secondary_label' => $this->cleanText($section['secondary_label'] ?? ''),
                    'secondary_href' => $this->cleanText($section['secondary_href'] ?? ''),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $merged['sections'] = $this->applyLibrarySections($merged['sections'], $locale);
        $merged['theme'] = $this->resolveTheme($page);

        return $merged;
    }

    public function updateLocale(PlatformPage $page, string $locale, array $incoming, ?int $userId = null, ?array $theme = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale, $page);
        $sanitized = $this->sanitizeLocaleContent($incoming, $default, $page);

        $payload = is_array($page->content) ? $page->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        $locales[$locale] = $sanitized;
        $themePayload = $theme;
        if ($themePayload === null && array_key_exists('theme', $incoming)) {
            $themePayload = is_array($incoming['theme']) ? $incoming['theme'] : null;
        }
        if ($themePayload === null && array_key_exists('theme', $payload)) {
            $themePayload = is_array($payload['theme']) ? $payload['theme'] : null;
        }

        $page->content = [
            'locales' => $locales,
            'theme' => $this->sanitizeTheme($themePayload),
            'updated_by' => $userId,
            'updated_at' => now()->toIso8601String(),
        ];
        $page->updated_by = $userId;
        $page->save();

        return $this->resolveForLocale($page, $locale);
    }

    public function defaultContent(string $locale, PlatformPage $page): array
    {
        $locale = $this->normalizeLocale($locale);

        return [
            'page_title' => $page->title,
            'page_subtitle' => '',
            'sections' => [
                $this->defaultSection('section-1'),
            ],
        ];
    }

    private function defaultSection(string $id): array
    {
        return [
            'id' => $id,
            'enabled' => true,
            'source_id' => null,
            'use_source' => false,
            'background_color' => '',
            'layout' => 'split',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => 'default',
            'visibility' => $this->defaultVisibility(),
            'kicker' => '',
            'title' => '',
            'body' => '',
            'items' => [],
            'image_url' => '',
            'image_alt' => '',
            'primary_label' => '',
            'primary_href' => '',
            'secondary_label' => '',
            'secondary_href' => '',
        ];
    }

    private function sanitizeLocaleContent(array $incoming, array $default, PlatformPage $page): array
    {
        return [
            'page_title' => $this->cleanText($incoming['page_title'] ?? $default['page_title'] ?? $page->title),
            'page_subtitle' => $this->cleanHtml($incoming['page_subtitle'] ?? $default['page_subtitle'] ?? ''),
            'sections' => $this->sanitizeSections($incoming['sections'] ?? $default['sections'] ?? []),
        ];
    }

    private function sanitizeSections($sections): array
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
                'source_id' => $this->cleanSourceId($section['source_id'] ?? null),
                'use_source' => array_key_exists('use_source', $section) ? (bool) $section['use_source'] : false,
                'background_color' => $this->cleanColor($section['background_color'] ?? null) ?? '',
                'layout' => $this->cleanLayout($section['layout'] ?? 'split'),
                'alignment' => $this->cleanAlignment($section['alignment'] ?? 'left'),
                'density' => $this->cleanDensity($section['density'] ?? 'normal'),
                'tone' => $this->cleanTone($section['tone'] ?? 'default'),
                'visibility' => $this->sanitizeVisibility($section['visibility'] ?? null),
                'kicker' => $this->cleanText($section['kicker'] ?? ''),
                'title' => $this->cleanText($section['title'] ?? ''),
                'body' => $this->cleanHtml($section['body'] ?? ''),
                'items' => $this->sanitizeStringList($section['items'] ?? []),
                'image_url' => $this->cleanText($section['image_url'] ?? ''),
                'image_alt' => $this->cleanText($section['image_alt'] ?? ''),
                'primary_label' => $this->cleanText($section['primary_label'] ?? ''),
                'primary_href' => $this->cleanText($section['primary_href'] ?? ''),
                'secondary_label' => $this->cleanText($section['secondary_label'] ?? ''),
                'secondary_href' => $this->cleanText($section['secondary_href'] ?? ''),
            ];
        }

        return array_slice(array_values($items), 0, 24);
    }

    private function storedLocales(PlatformPage $page): array
    {
        $payload = is_array($page->content) ? $page->content : [];
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
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function sanitizeStringList($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map(fn ($item) => $this->cleanText($item), $items));
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

    private function cleanSourceId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function cleanLayout($value): string
    {
        $layout = $this->cleanText($value);

        return in_array($layout, ['split', 'stack'], true) ? $layout : 'split';
    }

    private function cleanAlignment($value): string
    {
        $alignment = $this->cleanText($value);

        return in_array($alignment, ['left', 'center', 'right'], true) ? $alignment : 'left';
    }

    private function cleanDensity($value): string
    {
        $density = $this->cleanText($value);

        return in_array($density, ['compact', 'normal', 'spacious'], true) ? $density : 'normal';
    }

    private function cleanTone($value): string
    {
        $tone = $this->cleanText($value);

        return in_array($tone, ['default', 'muted', 'contrast'], true) ? $tone : 'default';
    }

    public function resolveTheme(PlatformPage $page): array
    {
        $payload = is_array($page->content) ? $page->content : [];
        $theme = is_array($payload['theme'] ?? null) ? $payload['theme'] : [];

        return $this->sanitizeTheme($theme);
    }

    private function defaultTheme(): array
    {
        return [
            'primary_color' => '#16a34a',
            'primary_soft_color' => '#dcfce7',
            'primary_contrast_color' => '#ffffff',
            'background_style' => 'gradient',
            'background_color' => '#f8fafc',
            'background_alt_color' => '#ecfdf5',
            'surface_color' => '#ffffff',
            'text_color' => '#0f172a',
            'muted_color' => '#64748b',
            'border_color' => '#e2e8f0',
            'font_body' => 'work-sans',
            'font_heading' => 'space-grotesk',
            'radius' => 'sm',
            'shadow' => 'soft',
            'button_style' => 'solid',
        ];
    }

    private function sanitizeTheme(?array $incoming): array
    {
        $default = $this->defaultTheme();
        $incoming = is_array($incoming) ? $incoming : [];
        $merged = $this->mergeContent($default, $incoming);

        $primary = $this->cleanColor($merged['primary_color'] ?? null) ?? $default['primary_color'];
        $primarySoft = $this->cleanColor($merged['primary_soft_color'] ?? null) ?? $default['primary_soft_color'];
        $primaryContrast = $this->cleanColor($merged['primary_contrast_color'] ?? null) ?? $default['primary_contrast_color'];
        $backgroundStyle = $this->cleanThemeChoice($merged['background_style'] ?? null, self::THEME_BACKGROUND_STYLES, $default['background_style']);
        $background = $this->cleanColor($merged['background_color'] ?? null) ?? $default['background_color'];
        $backgroundAlt = $this->cleanColor($merged['background_alt_color'] ?? null) ?? $default['background_alt_color'];
        $surface = $this->cleanColor($merged['surface_color'] ?? null) ?? $default['surface_color'];
        $text = $this->cleanColor($merged['text_color'] ?? null) ?? $default['text_color'];
        $muted = $this->cleanColor($merged['muted_color'] ?? null) ?? $default['muted_color'];
        $border = $this->cleanColor($merged['border_color'] ?? null) ?? $default['border_color'];
        $fontBody = $this->cleanThemeChoice($merged['font_body'] ?? null, self::THEME_FONTS, $default['font_body']);
        $fontHeading = $this->cleanThemeChoice($merged['font_heading'] ?? null, self::THEME_FONTS, $default['font_heading']);
        $radius = $this->cleanThemeChoice($merged['radius'] ?? null, self::THEME_RADII, $default['radius']);
        $shadow = $this->cleanThemeChoice($merged['shadow'] ?? null, self::THEME_SHADOWS, $default['shadow']);
        $buttonStyle = $this->cleanThemeChoice($merged['button_style'] ?? null, self::THEME_BUTTON_STYLES, $default['button_style']);

        return [
            'primary_color' => $primary,
            'primary_soft_color' => $primarySoft,
            'primary_contrast_color' => $primaryContrast,
            'background_style' => $backgroundStyle,
            'background_color' => $background,
            'background_alt_color' => $backgroundAlt,
            'surface_color' => $surface,
            'text_color' => $text,
            'muted_color' => $muted,
            'border_color' => $border,
            'font_body' => $fontBody,
            'font_heading' => $fontHeading,
            'radius' => $radius,
            'shadow' => $shadow,
            'button_style' => $buttonStyle,
        ];
    }

    private function cleanThemeChoice($value, array $allowed, string $fallback): string
    {
        $choice = $this->cleanText($value);
        return in_array($choice, $allowed, true) ? $choice : $fallback;
    }

    private function defaultVisibility(): array
    {
        return [
            'locales' => [],
            'auth' => 'any',
            'roles' => [],
            'plans' => [],
            'device' => 'all',
            'start_at' => null,
            'end_at' => null,
        ];
    }

    private function sanitizeVisibility($incoming): array
    {
        $default = $this->defaultVisibility();
        $incoming = is_array($incoming) ? $incoming : [];

        $locales = $this->cleanVisibilityLocales($incoming['locales'] ?? []);
        $auth = $this->cleanThemeChoice($incoming['auth'] ?? null, self::VISIBILITY_AUTH, $default['auth']);
        $roles = $this->cleanIdentifierList($incoming['roles'] ?? []);
        $plans = $this->cleanIdentifierList($incoming['plans'] ?? []);
        $device = $this->cleanThemeChoice($incoming['device'] ?? null, self::VISIBILITY_DEVICES, $default['device']);
        $startAt = $this->cleanDateTime($incoming['start_at'] ?? null);
        $endAt = $this->cleanDateTime($incoming['end_at'] ?? null);

        return [
            'locales' => $locales,
            'auth' => $auth,
            'roles' => $roles,
            'plans' => $plans,
            'device' => $device,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    private function cleanVisibilityLocales($locales): array
    {
        if (!is_array($locales)) {
            return [];
        }

        $list = array_map(fn ($locale) => $this->cleanText($locale), $locales);
        $list = array_filter($list, fn ($locale) => in_array($locale, $this->locales(), true));

        return array_values(array_unique($list));
    }

    private function cleanIdentifierList($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $cleaned = [];
        foreach ($values as $value) {
            $text = mb_strtolower($this->cleanText($value));
            $text = preg_replace('/[^a-z0-9_\\-]/', '', $text);
            if ($text === '') {
                continue;
            }
            $cleaned[] = $text;
        }

        return array_values(array_unique($cleaned));
    }

    private function cleanDateTime($value): ?string
    {
        $text = $this->cleanText($value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function applyLibrarySections(array $sections, string $locale): array
    {
        $sourceIds = collect($sections)
            ->filter(fn ($section) => !empty($section['use_source']) && !empty($section['source_id']))
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();

        if (!$sourceIds) {
            return $sections;
        }

        $librarySections = PlatformSection::query()
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        if ($librarySections->isEmpty()) {
            return $sections;
        }

        $contentService = app(PlatformSectionContentService::class);
        $resolved = [];
        foreach ($librarySections as $id => $section) {
            $resolved[$id] = $contentService->resolveForLocale($section, $locale);
        }

        return array_map(function (array $section) use ($resolved) {
            if (empty($section['use_source']) || empty($section['source_id'])) {
                return $section;
            }

            $source = $resolved[$section['source_id']] ?? null;
            if (!$source) {
                return $section;
            }

            return $this->mergeSectionWithSource($section, $source);
        }, $sections);
    }

    private function mergeSectionWithSource(array $section, array $source): array
    {
        $section['kicker'] = $section['kicker'] !== '' ? $section['kicker'] : ($source['kicker'] ?? '');
        $section['title'] = $section['title'] !== '' ? $section['title'] : ($source['title'] ?? '');
        $section['body'] = $section['body'] !== '' ? $section['body'] : ($source['body'] ?? '');

        if (empty($section['items'])) {
            $section['items'] = $source['items'] ?? [];
        }

        $section['image_url'] = $section['image_url'] !== '' ? $section['image_url'] : ($source['image_url'] ?? '');
        $section['image_alt'] = $section['image_alt'] !== '' ? $section['image_alt'] : ($source['image_alt'] ?? '');
        $section['primary_label'] = $section['primary_label'] !== '' ? $section['primary_label'] : ($source['primary_label'] ?? '');
        $section['primary_href'] = $section['primary_href'] !== '' ? $section['primary_href'] : ($source['primary_href'] ?? '');
        $section['secondary_label'] = $section['secondary_label'] !== '' ? $section['secondary_label'] : ($source['secondary_label'] ?? '');
        $section['secondary_href'] = $section['secondary_href'] !== '' ? $section['secondary_href'] : ($source['secondary_href'] ?? '');

        return $section;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, $this->locales(), true) ? $locale : $this->locales()[0];
    }
}
