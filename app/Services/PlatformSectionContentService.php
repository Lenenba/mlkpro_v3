<?php

namespace App\Services;

use App\Models\PlatformSection;

class PlatformSectionContentService
{
    private const LOCALES = ['fr', 'en'];

    private const LAYOUTS = ['split', 'duo', 'stack', 'contact', 'testimonial', 'feature_pairs', 'industry_grid', 'story_grid', 'feature_tabs', 'testimonial_grid', 'footer'];

    private const IMAGE_POSITIONS = ['left', 'right'];

    private const ALIGNMENTS = ['left', 'center', 'right'];

    private const DENSITIES = ['compact', 'normal', 'spacious'];

    private const TONES = ['default', 'muted', 'contrast'];

    private const FOOTER_GROUP_LAYOUTS = ['stack', 'split'];

    private const INDUSTRY_CARD_ICONS = [
        'tree-pine',
        'brush-cleaning',
        'construction',
        'plug-zap',
        'fan',
        'wrench',
        'shovel',
        'leaf',
        'paint-roller',
        'shower-head',
        'sparkles',
        'house',
        'hammer',
        'bug',
        'circle-dollar-sign',
        'droplets',
        'fence',
        'flame',
        'key-round',
        'shield-check',
        'sofa',
        'sprout',
        'truck',
        'warehouse',
        'waves',
    ];

    private const FEATURE_TAB_ICONS = [
        'calendar-days',
        'file-text',
        'clipboard-check',
        'clipboard-list',
        'circle-dollar-sign',
        'wrench',
    ];

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

    public function ensureSharedFooterSectionExists(?int $userId = null): PlatformSection
    {
        $existing = PlatformSection::query()
            ->where('type', 'footer')
            ->orderByDesc('is_active')
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $locales = [];
        foreach ($this->locales() as $locale) {
            $locales[$locale] = $this->defaultContent($locale, 'footer');
        }

        return PlatformSection::query()->create([
            'name' => $this->defaultSharedFooterName(),
            'type' => 'footer',
            'is_active' => true,
            'content' => [
                'locales' => $locales,
                'updated_by' => $userId,
                'updated_at' => now()->toIso8601String(),
            ],
            'updated_by' => $userId,
        ]);
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

        if (strtolower(trim((string) $type)) === 'footer') {
            return $this->defaultFooterContent($locale);
        }

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
            'note' => '',
            'stats' => [],
            'preview_cards' => [],
            'feature_items' => [],
            'secondary_enabled' => false,
            'secondary_background_color' => '',
            'secondary_kicker' => '',
            'secondary_title' => '',
            'secondary_body' => '',
            'secondary_badge' => '',
            'secondary_feature_items' => [],
            'industry_cards' => [],
            'story_cards' => [],
            'feature_tabs' => [],
            'feature_tabs_font_size' => 0,
            'testimonial_cards' => [],
            'items' => [],
            'testimonial_author' => '',
            'testimonial_role' => '',
            'aside_kicker' => '',
            'aside_title' => '',
            'aside_body' => '',
            'aside_items' => [],
            'aside_link_label' => '',
            'aside_link_href' => '',
            'aside_image_url' => '',
            'aside_image_alt' => '',
            'image_url' => '',
            'image_alt' => '',
            'primary_label' => '',
            'primary_href' => '',
            'secondary_label' => '',
            'secondary_href' => '',
            'copy' => '',
            'brand_logo_url' => '',
            'brand_logo_alt' => '',
            'brand_href' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'social_facebook_href' => '',
            'social_x_href' => '',
            'social_instagram_href' => '',
            'social_youtube_href' => '',
            'social_linkedin_href' => '',
            'google_play_href' => '',
            'app_store_href' => '',
            'footer_groups' => [],
            'legal_links' => [],
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
            'note' => $this->cleanHtml($section['note'] ?? ''),
            'stats' => $this->sanitizeStatItems($section['stats'] ?? []),
            'preview_cards' => $this->sanitizePreviewCards($section['preview_cards'] ?? []),
            'feature_items' => $this->sanitizeFeatureItems($section['feature_items'] ?? []),
            'secondary_enabled' => array_key_exists('secondary_enabled', $section) ? (bool) $section['secondary_enabled'] : false,
            'secondary_background_color' => $this->cleanColor($section['secondary_background_color'] ?? null) ?? '',
            'secondary_kicker' => $this->cleanText($section['secondary_kicker'] ?? ''),
            'secondary_title' => $this->cleanText($section['secondary_title'] ?? ''),
            'secondary_body' => $this->cleanHtml($section['secondary_body'] ?? ''),
            'secondary_badge' => $this->cleanText($section['secondary_badge'] ?? ''),
            'secondary_feature_items' => $this->sanitizeFeatureItems($section['secondary_feature_items'] ?? []),
            'industry_cards' => $this->sanitizeIndustryCards($section['industry_cards'] ?? []),
            'story_cards' => $this->sanitizeStoryCards($section['story_cards'] ?? []),
            'feature_tabs' => $this->sanitizeFeatureTabs($section['feature_tabs'] ?? []),
            'feature_tabs_font_size' => $this->cleanFeatureTabsFontSize($section['feature_tabs_font_size'] ?? null),
            'testimonial_cards' => $this->sanitizeTestimonialCards($section['testimonial_cards'] ?? []),
            'items' => $this->sanitizeStringList($section['items'] ?? []),
            'testimonial_author' => $this->cleanText($section['testimonial_author'] ?? ''),
            'testimonial_role' => $this->cleanText($section['testimonial_role'] ?? ''),
            'aside_kicker' => $this->cleanText($section['aside_kicker'] ?? ''),
            'aside_title' => $this->cleanText($section['aside_title'] ?? ''),
            'aside_body' => $this->cleanHtml($section['aside_body'] ?? ''),
            'aside_items' => $this->sanitizeStringList($section['aside_items'] ?? []),
            'aside_link_label' => $this->cleanText($section['aside_link_label'] ?? ''),
            'aside_link_href' => $this->cleanText($section['aside_link_href'] ?? ''),
            'aside_image_url' => $this->cleanText($section['aside_image_url'] ?? ''),
            'aside_image_alt' => $this->cleanText($section['aside_image_alt'] ?? ''),
            'image_url' => $this->cleanText($section['image_url'] ?? ''),
            'image_alt' => $this->cleanText($section['image_alt'] ?? ''),
            'primary_label' => $this->cleanText($section['primary_label'] ?? ''),
            'primary_href' => $this->cleanText($section['primary_href'] ?? ''),
            'secondary_label' => $this->cleanText($section['secondary_label'] ?? ''),
            'secondary_href' => $this->cleanText($section['secondary_href'] ?? ''),
            'copy' => $this->cleanText($section['copy'] ?? ''),
            'brand_logo_url' => $this->cleanImageValue($section['brand_logo_url'] ?? ''),
            'brand_logo_alt' => $this->cleanText($section['brand_logo_alt'] ?? ''),
            'brand_href' => $this->cleanLinkValue($section['brand_href'] ?? ''),
            'contact_phone' => $this->cleanText($section['contact_phone'] ?? ''),
            'contact_email' => $this->cleanText($section['contact_email'] ?? ''),
            'social_facebook_href' => $this->cleanText($section['social_facebook_href'] ?? ''),
            'social_x_href' => $this->cleanText($section['social_x_href'] ?? ''),
            'social_instagram_href' => $this->cleanText($section['social_instagram_href'] ?? ''),
            'social_youtube_href' => $this->cleanText($section['social_youtube_href'] ?? ''),
            'social_linkedin_href' => $this->cleanText($section['social_linkedin_href'] ?? ''),
            'google_play_href' => $this->cleanText($section['google_play_href'] ?? ''),
            'app_store_href' => $this->cleanText($section['app_store_href'] ?? ''),
            'footer_groups' => $this->sanitizeFooterGroups($section['footer_groups'] ?? []),
            'legal_links' => $this->sanitizeFooterLinks($section['legal_links'] ?? [], 'legal-link'),
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

    private function sanitizeFeatureItems($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $sanitized = [];
        foreach (array_values($items) as $item) {
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
        foreach (array_values($items) as $item) {
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
        foreach (array_values($items) as $item) {
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

    private function sanitizeFooterGroups($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $groups = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = $this->cleanText($item['title'] ?? '');
            $links = $this->sanitizeFooterLinks($item['links'] ?? [], 'footer-link');
            if ($title === '' && $links === []) {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $groups[] = [
                'id' => $id !== '' ? $id : 'footer-group-'.($index + 1),
                'title' => $title,
                'layout' => $this->cleanThemeChoice(
                    $item['layout'] ?? null,
                    self::FOOTER_GROUP_LAYOUTS,
                    'stack'
                ),
                'links' => $links,
            ];
        }

        return array_slice($groups, 0, 8);
    }

    private function sanitizeFooterLinks($items, string $prefix = 'footer-link'): array
    {
        if (!is_array($items)) {
            return [];
        }

        $links = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->cleanText($item['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $links[] = [
                'id' => $id !== '' ? $id : $prefix.'-'.($index + 1),
                'label' => $label,
                'href' => $this->cleanLinkValue($item['href'] ?? ''),
                'note' => $this->cleanText($item['note'] ?? ''),
            ];
        }

        return array_slice($links, 0, 16);
    }

    private function sanitizeIndustryCards($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $cards = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->cleanText($item['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $cards[] = [
                'id' => $id !== '' ? $id : 'industry-card-'.($index + 1),
                'label' => $label,
                'href' => $this->cleanText($item['href'] ?? ''),
                'icon' => $this->cleanIndustryCardIcon($item['icon'] ?? ''),
            ];
        }

        return array_slice($cards, 0, 24);
    }

    private function sanitizeStoryCards($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $cards = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = $this->cleanText($item['title'] ?? '');
            $body = $this->cleanHtml($item['body'] ?? '');
            if ($title === '' && $body === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $cards[] = [
                'id' => $id !== '' ? $id : 'story-card-'.($index + 1),
                'title' => $title,
                'body' => $body,
                'image_url' => $this->cleanText($item['image_url'] ?? ''),
                'image_alt' => $this->cleanText($item['image_alt'] ?? ''),
            ];
        }

        return array_slice($cards, 0, 6);
    }

    private function sanitizeFeatureTabs($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $tabs = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->cleanText($item['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $tabs[] = [
                'id' => $id !== '' ? $id : 'feature-tab-'.($index + 1),
                'label' => $label,
                'icon' => $this->cleanFeatureTabIcon($item['icon'] ?? ''),
                'items' => $this->sanitizeStringList($item['items'] ?? []),
                'children' => $this->sanitizeFeatureTabChildren($item['children'] ?? []),
                'title' => $this->cleanText($item['title'] ?? ''),
                'body' => $this->cleanHtml($item['body'] ?? ''),
                'image_url' => $this->cleanText($item['image_url'] ?? ''),
                'image_alt' => $this->cleanText($item['image_alt'] ?? ''),
                'cta_label' => $this->cleanText($item['cta_label'] ?? ''),
                'cta_href' => $this->cleanText($item['cta_href'] ?? ''),
                'metric' => $this->cleanText($item['metric'] ?? ''),
                'story' => $this->cleanHtml($item['story'] ?? ''),
                'person' => $this->cleanText($item['person'] ?? ''),
                'role' => $this->cleanText($item['role'] ?? ''),
                'avatar_url' => $this->cleanText($item['avatar_url'] ?? ''),
                'avatar_alt' => $this->cleanText($item['avatar_alt'] ?? ''),
            ];
        }

        return array_slice($tabs, 0, 8);
    }

    private function sanitizeTestimonialCards($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $cards = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $quote = $this->cleanHtml($item['quote'] ?? '');
            $authorName = $this->cleanText($item['author_name'] ?? '');
            if ($quote === '' && $authorName === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $cards[] = [
                'id' => $id !== '' ? $id : 'testimonial-card-'.($index + 1),
                'quote' => $quote,
                'author_name' => $authorName,
                'author_role' => $this->cleanText($item['author_role'] ?? ''),
                'author_company' => $this->cleanText($item['author_company'] ?? ''),
                'image_url' => $this->cleanText($item['image_url'] ?? ''),
                'image_alt' => $this->cleanText($item['image_alt'] ?? ''),
            ];
        }

        return array_slice($cards, 0, 12);
    }

    private function sanitizeFeatureTabChildren($items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $children = [];
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = $this->cleanText($item['label'] ?? '');
            if ($label === '') {
                continue;
            }

            $id = $this->cleanText($item['id'] ?? '');
            $children[] = [
                'id' => $id !== '' ? $id : 'feature-tab-child-'.($index + 1),
                'label' => $label,
                'title' => $this->cleanText($item['title'] ?? ''),
                'body' => $this->cleanHtml($item['body'] ?? ''),
                'image_url' => $this->cleanText($item['image_url'] ?? ''),
                'image_alt' => $this->cleanText($item['image_alt'] ?? ''),
                'cta_label' => $this->cleanText($item['cta_label'] ?? ''),
                'cta_href' => $this->cleanText($item['cta_href'] ?? ''),
            ];
        }

        return array_slice($children, 0, 12);
    }

    private function cleanIndustryCardIcon($value): string
    {
        $icon = $this->cleanText($value);

        return in_array($icon, self::INDUSTRY_CARD_ICONS, true) ? $icon : '';
    }

    private function cleanFeatureTabIcon($value): string
    {
        $icon = $this->cleanText($value);

        return in_array($icon, self::FEATURE_TAB_ICONS, true) ? $icon : '';
    }

    private function cleanFeatureTabsFontSize($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $size = (int) $value;
        if ($size <= 0) {
            return 0;
        }

        return max(18, min($size, 40));
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
            'welcome_trust' => 'stack',
            'welcome_field' => 'split',
            'welcome_cta' => 'stack',
            'welcome_custom' => 'split',
            'welcome_hero' => 'split',
            'welcome_features' => 'stack',
            'welcome_workflow' => 'split',
            'feature_pairs' => 'feature_pairs',
            'industry_grid' => 'industry_grid',
            'story_grid' => 'story_grid',
            'feature_tabs' => 'feature_tabs',
            'testimonial_grid' => 'testimonial_grid',
            'footer' => 'footer',
            default => 'split',
        };
    }

    private function defaultBackgroundColorForLayout(string $layout): string
    {
        return match ($layout) {
            'duo' => '#0f172a',
            'testimonial' => '#e5ecef',
            'industry_grid' => '#f7f2e8',
            'story_grid' => '#f7f2e8',
            'feature_tabs' => '#f7f2e8',
            'testimonial_grid' => '#f7f2e8',
            'footer' => '#062f3f',
            default => '',
        };
    }

    public function defaultFooterContent(string $locale): array
    {
        $normalized = $this->normalizeLocale($locale);

        if ($normalized === 'fr') {
            return array_merge($this->defaultSection('footer'), [
                'brand_logo_url' => '/1.svg',
                'brand_logo_alt' => 'Malikia Pro',
                'brand_href' => '/',
                'kicker' => 'Accompagnement',
                'title' => 'Parlez a notre equipe',
                'body' => '<p>Besoin d un parcours produit plus precis ou d une page publique sur mesure ? On peut vous guider.</p>',
                'items' => [
                    'Parcours public et modules metier',
                    'Support produit et accompagnement',
                    'Disponible en francais et en anglais',
                ],
                'primary_label' => 'Nous contacter',
                'primary_href' => '/pages/contact-us',
                'secondary_label' => 'Voir les tarifs',
                'secondary_href' => '/pricing',
                'copy' => 'Tous droits reserves.',
                'contact_phone' => (string) (config('app.support_phone') ?? ''),
                'contact_email' => $this->defaultFooterEmail(),
                'footer_groups' => $this->defaultFooterGroups('fr'),
                'legal_links' => $this->defaultFooterLegalLinks('fr'),
            ]);
        }

        return array_merge($this->defaultSection('footer'), [
            'brand_logo_url' => '/1.svg',
            'brand_logo_alt' => 'Malikia Pro',
            'brand_href' => '/',
            'kicker' => 'Support',
            'title' => 'Talk to our team',
            'body' => '<p>Need a sharper product journey or a custom public page setup? Our team can help.</p>',
            'items' => [
                'Public pages and business modules',
                'Product support and enablement',
                'Available in French and English',
            ],
            'primary_label' => 'Contact us',
            'primary_href' => '/pages/contact-us',
            'secondary_label' => 'View pricing',
            'secondary_href' => '/pricing',
            'copy' => 'All rights reserved.',
            'contact_phone' => (string) (config('app.support_phone') ?? ''),
            'contact_email' => $this->defaultFooterEmail(),
            'footer_groups' => $this->defaultFooterGroups('en'),
            'legal_links' => $this->defaultFooterLegalLinks('en'),
        ]);
    }

    private function defaultFooterGroups(string $locale): array
    {
        if ($locale === 'fr') {
            return [
                [
                    'id' => 'industries',
                    'title' => 'Industries desservies',
                    'layout' => 'stack',
                    'links' => [
                        ['id' => 'industries-plomberie', 'label' => 'Plomberie', 'href' => '/pages/industry-plumbing', 'note' => ''],
                        ['id' => 'industries-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'note' => ''],
                        ['id' => 'industries-electricite', 'label' => 'Electricite', 'href' => '/pages/industry-electrical', 'note' => ''],
                        ['id' => 'industries-entretien', 'label' => 'Entretien menager', 'href' => '/pages/industry-cleaning', 'note' => ''],
                        ['id' => 'industries-salon', 'label' => 'Salon & beaute', 'href' => '/pages/industry-salon-beauty', 'note' => ''],
                        ['id' => 'industries-restaurant', 'label' => 'Restaurant', 'href' => '/pages/industry-restaurant', 'note' => ''],
                    ],
                ],
                [
                    'id' => 'produits',
                    'title' => 'Produits',
                    'layout' => 'stack',
                    'links' => [
                        ['id' => 'produits-sales-crm', 'label' => 'Sales & CRM', 'href' => '/pages/sales-crm', 'note' => ''],
                        ['id' => 'produits-reservations', 'label' => 'Reservations', 'href' => '/pages/reservations', 'note' => ''],
                        ['id' => 'produits-operations', 'label' => 'Operations', 'href' => '/pages/operations', 'note' => ''],
                        ['id' => 'produits-commerce', 'label' => 'Commerce', 'href' => '/pages/commerce', 'note' => ''],
                        ['id' => 'produits-marketing', 'label' => 'Marketing & Loyalty', 'href' => '/pages/marketing-loyalty', 'note' => ''],
                        ['id' => 'produits-ai', 'label' => 'AI & Automation', 'href' => '/pages/ai-automation', 'note' => ''],
                        ['id' => 'produits-command', 'label' => 'Command Center', 'href' => '/pages/command-center', 'note' => ''],
                    ],
                ],
                [
                    'id' => 'ressources',
                    'title' => 'Ressources',
                    'layout' => 'stack',
                    'links' => [
                        ['id' => 'ressources-pricing', 'label' => 'Tarification', 'href' => '/pricing', 'note' => ''],
                        ['id' => 'ressources-terms', 'label' => 'Conditions', 'href' => '/terms', 'note' => ''],
                        ['id' => 'ressources-privacy', 'label' => 'Confidentialite', 'href' => '/privacy', 'note' => ''],
                        ['id' => 'ressources-refund', 'label' => 'Remboursement', 'href' => '/refund', 'note' => ''],
                        ['id' => 'ressources-contact', 'label' => 'Contact', 'href' => '/pages/contact-us', 'note' => ''],
                    ],
                ],
                [
                    'id' => 'solutions',
                    'title' => 'Solutions',
                    'layout' => 'stack',
                    'links' => [
                        ['id' => 'solutions-field', 'label' => 'Services terrain', 'href' => '/pages/solution-field-services', 'note' => ''],
                        ['id' => 'solutions-queues', 'label' => 'Reservations & files', 'href' => '/pages/solution-reservations-queues', 'note' => ''],
                        ['id' => 'solutions-sales', 'label' => 'Ventes & devis', 'href' => '/pages/solution-sales-quoting', 'note' => ''],
                        ['id' => 'solutions-commerce', 'label' => 'Commerce & catalogue', 'href' => '/pages/solution-commerce-catalog', 'note' => ''],
                        ['id' => 'solutions-marketing', 'label' => 'Marketing & fidelisation', 'href' => '/pages/solution-marketing-loyalty', 'note' => ''],
                        ['id' => 'solutions-oversight', 'label' => 'Supervision multi-entite', 'href' => '/pages/solution-multi-entity-oversight', 'note' => ''],
                    ],
                ],
            ];
        }

        return [
            [
                'id' => 'industries',
                'title' => 'Industries We Serve',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'industries-plumbing', 'label' => 'Plumbing', 'href' => '/pages/industry-plumbing', 'note' => ''],
                    ['id' => 'industries-hvac', 'label' => 'HVAC', 'href' => '/pages/industry-hvac', 'note' => ''],
                    ['id' => 'industries-electrical', 'label' => 'Electrical', 'href' => '/pages/industry-electrical', 'note' => ''],
                    ['id' => 'industries-cleaning', 'label' => 'Cleaning', 'href' => '/pages/industry-cleaning', 'note' => ''],
                    ['id' => 'industries-salon', 'label' => 'Salon & Beauty', 'href' => '/pages/industry-salon-beauty', 'note' => ''],
                    ['id' => 'industries-restaurant', 'label' => 'Restaurant', 'href' => '/pages/industry-restaurant', 'note' => ''],
                ],
            ],
            [
                'id' => 'products',
                'title' => 'Products',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'products-sales-crm', 'label' => 'Sales & CRM', 'href' => '/pages/sales-crm', 'note' => ''],
                    ['id' => 'products-reservations', 'label' => 'Reservations', 'href' => '/pages/reservations', 'note' => ''],
                    ['id' => 'products-operations', 'label' => 'Operations', 'href' => '/pages/operations', 'note' => ''],
                    ['id' => 'products-commerce', 'label' => 'Commerce', 'href' => '/pages/commerce', 'note' => ''],
                    ['id' => 'products-marketing', 'label' => 'Marketing & Loyalty', 'href' => '/pages/marketing-loyalty', 'note' => ''],
                    ['id' => 'products-ai', 'label' => 'AI & Automation', 'href' => '/pages/ai-automation', 'note' => ''],
                    ['id' => 'products-command', 'label' => 'Command Center', 'href' => '/pages/command-center', 'note' => ''],
                ],
            ],
            [
                'id' => 'resources',
                'title' => 'Resources',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'resources-pricing', 'label' => 'Pricing', 'href' => '/pricing', 'note' => ''],
                    ['id' => 'resources-terms', 'label' => 'Terms', 'href' => '/terms', 'note' => ''],
                    ['id' => 'resources-privacy', 'label' => 'Privacy', 'href' => '/privacy', 'note' => ''],
                    ['id' => 'resources-refund', 'label' => 'Refund', 'href' => '/refund', 'note' => ''],
                    ['id' => 'resources-contact', 'label' => 'Contact us', 'href' => '/pages/contact-us', 'note' => ''],
                ],
            ],
            [
                'id' => 'solutions',
                'title' => 'Solutions',
                'layout' => 'stack',
                'links' => [
                    ['id' => 'solutions-field', 'label' => 'Field services', 'href' => '/pages/solution-field-services', 'note' => ''],
                    ['id' => 'solutions-queues', 'label' => 'Reservations & queues', 'href' => '/pages/solution-reservations-queues', 'note' => ''],
                    ['id' => 'solutions-sales', 'label' => 'Sales & quoting', 'href' => '/pages/solution-sales-quoting', 'note' => ''],
                    ['id' => 'solutions-commerce', 'label' => 'Commerce & catalog', 'href' => '/pages/solution-commerce-catalog', 'note' => ''],
                    ['id' => 'solutions-marketing', 'label' => 'Marketing & loyalty', 'href' => '/pages/solution-marketing-loyalty', 'note' => ''],
                    ['id' => 'solutions-oversight', 'label' => 'Multi-entity oversight', 'href' => '/pages/solution-multi-entity-oversight', 'note' => ''],
                ],
            ],
        ];
    }

    private function defaultSharedFooterName(): string
    {
        return $this->normalizeLocale(app()->getLocale()) === 'fr'
            ? 'Footer partage'
            : 'Shared footer';
    }

    private function defaultFooterLegalLinks(string $locale): array
    {
        if ($locale === 'fr') {
            return [
                ['id' => 'legal-pricing', 'label' => 'Tarification', 'href' => '/pricing', 'note' => ''],
                ['id' => 'legal-terms', 'label' => 'Conditions', 'href' => '/terms', 'note' => ''],
                ['id' => 'legal-privacy', 'label' => 'Confidentialite', 'href' => '/privacy', 'note' => ''],
                ['id' => 'legal-refund', 'label' => 'Remboursement', 'href' => '/refund', 'note' => ''],
            ];
        }

        return [
            ['id' => 'legal-pricing', 'label' => 'Pricing', 'href' => '/pricing', 'note' => ''],
            ['id' => 'legal-terms', 'label' => 'Terms', 'href' => '/terms', 'note' => ''],
            ['id' => 'legal-privacy', 'label' => 'Privacy', 'href' => '/privacy', 'note' => ''],
            ['id' => 'legal-refund', 'label' => 'Refund', 'href' => '/refund', 'note' => ''],
        ];
    }

    private function defaultFooterEmail(): string
    {
        return trim((string) config('mail.from.address', ''));
    }

    private function cleanLinkValue($value): string
    {
        return $this->sanitizeUrl($this->cleanText($value), 'link') ?? '';
    }

    private function cleanImageValue($value): string
    {
        return $this->sanitizeUrl($this->cleanText($value), 'image') ?? '';
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return in_array($locale, $this->locales(), true) ? $locale : $this->locales()[0];
    }
}
