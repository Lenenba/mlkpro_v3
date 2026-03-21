<?php

namespace App\Services;

use App\Models\PlatformSection;

class PlatformSectionContentService
{
    private const LOCALES = ['fr', 'en'];

    private const LAYOUTS = ['split', 'duo', 'stack', 'contact', 'testimonial'];

    private const IMAGE_POSITIONS = ['left', 'right'];

    private const ALIGNMENTS = ['left', 'center', 'right'];

    private const DENSITIES = ['compact', 'normal', 'spacious'];

    private const TONES = ['default', 'muted', 'contrast'];

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

    public function meta(PlatformSection $section): array
    {
        $payload = is_array($section->content) ? $section->content : [];

        return [
            'updated_at' => $payload['updated_at'] ?? null,
            'updated_by' => $payload['updated_by'] ?? $section->updated_by,
        ];
    }

    public function resolveAll(PlatformSection $section): array
    {
        $resolved = [];
        foreach ($this->locales() as $locale) {
            $resolved[$locale] = $this->resolveForLocale($section, $locale);
        }

        return $resolved;
    }

    public function resolveForLocale(PlatformSection $section, string $locale): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale, $section->type);
        $stored = $this->storedLocales($section)[$locale] ?? [];

        $merged = $this->mergeContent($default, is_array($stored) ? $stored : []);

        return $this->sanitizeSection($merged);
    }

    public function updateLocale(PlatformSection $section, string $locale, array $incoming, ?int $userId = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultContent($locale, $section->type);
        $sanitized = $this->sanitizeSection($this->mergeContent($default, $incoming));

        $payload = is_array($section->content) ? $section->content : [];
        $locales = is_array($payload['locales'] ?? null) ? $payload['locales'] : [];
        $locales[$locale] = $sanitized;

        $section->content = [
            'locales' => $locales,
            'updated_by' => $userId,
            'updated_at' => now()->toIso8601String(),
        ];
        $section->updated_by = $userId;
        $section->save();

        return $this->resolveForLocale($section, $locale);
    }

    public function defaultContent(string $locale, ?string $type = null): array
    {
        $locale = $this->normalizeLocale($locale);

        return $this->defaultSection($type);
    }

    private function defaultSection(?string $type = null): array
    {
        $defaultLayout = $this->defaultLayoutForType($type);

        return [
            'layout' => $defaultLayout,
            'background_color' => $this->defaultBackgroundColorForLayout($defaultLayout),
            'image_position' => $defaultLayout === 'testimonial' ? 'right' : 'left',
            'alignment' => 'left',
            'density' => 'normal',
            'tone' => $defaultLayout === 'duo' ? 'contrast' : 'default',
            'kicker' => '',
            'title' => '',
            'body' => '',
            'items' => [],
            'testimonial_author' => '',
            'testimonial_role' => '',
            'image_url' => '',
            'image_alt' => '',
            'primary_label' => '',
            'primary_href' => '',
            'secondary_label' => '',
            'secondary_href' => '',
        ];
    }

    private function sanitizeSection(array $section): array
    {
        return [
            'layout' => $this->cleanThemeChoice(
                $section['layout'] ?? null,
                self::LAYOUTS,
                $this->defaultLayoutForType(null)
            ),
            'background_color' => $this->cleanColor($section['background_color'] ?? null) ?? '',
            'image_position' => $this->cleanThemeChoice(
                $section['image_position'] ?? null,
                self::IMAGE_POSITIONS,
                'left'
            ),
            'alignment' => $this->cleanThemeChoice(
                $section['alignment'] ?? null,
                self::ALIGNMENTS,
                'left'
            ),
            'density' => $this->cleanThemeChoice(
                $section['density'] ?? null,
                self::DENSITIES,
                'normal'
            ),
            'tone' => $this->cleanThemeChoice(
                $section['tone'] ?? null,
                self::TONES,
                'default'
            ),
            'kicker' => $this->cleanText($section['kicker'] ?? ''),
            'title' => $this->cleanText($section['title'] ?? ''),
            'body' => $this->cleanHtml($section['body'] ?? ''),
            'items' => $this->sanitizeStringList($section['items'] ?? []),
            'testimonial_author' => $this->cleanText($section['testimonial_author'] ?? ''),
            'testimonial_role' => $this->cleanText($section['testimonial_role'] ?? ''),
            'image_url' => $this->cleanText($section['image_url'] ?? ''),
            'image_alt' => $this->cleanText($section['image_alt'] ?? ''),
            'primary_label' => $this->cleanText($section['primary_label'] ?? ''),
            'primary_href' => $this->cleanText($section['primary_href'] ?? ''),
            'secondary_label' => $this->cleanText($section['secondary_label'] ?? ''),
            'secondary_href' => $this->cleanText($section['secondary_href'] ?? ''),
        ];
    }

    private function storedLocales(PlatformSection $section): array
    {
        $payload = is_array($section->content) ? $section->content : [];
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

    private function cleanColor($value): ?string
    {
        $color = ltrim($this->cleanText($value), '#');
        if ($color === '') {
            return null;
        }

        if (preg_match('/^[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $color) !== 1) {
            return null;
        }

        return '#'.strtolower($color);
    }

    private function cleanThemeChoice($value, array $allowed, string $default): string
    {
        $choice = strtolower($this->cleanText($value));

        return in_array($choice, $allowed, true) ? $choice : $default;
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

    private function defaultLayoutForType(?string $type): string
    {
        return match (strtolower(trim((string) $type))) {
            'duo' => 'duo',
            'testimonial' => 'testimonial',
            default => 'split',
        };
    }

    private function defaultBackgroundColorForLayout(string $layout): string
    {
        return match ($layout) {
            'duo' => '#0f172a',
            'testimonial' => '#e5ecef',
            default => '',
        };
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, $this->locales(), true) ? $locale : $this->locales()[0];
    }
}
