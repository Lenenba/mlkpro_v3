<?php

namespace App\Services\MegaMenus;

use App\Support\MegaMenuBlockRegistry;
use App\Support\MegaMenuOptions;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class MegaMenuPayloadSanitizer
{
    private const SUPPORTED_LOCALES = ['fr', 'en'];

    private const MAX_TOP_LEVEL_ITEMS = 16;

    private const MAX_NESTED_ITEMS = 24;

    private const MAX_COLUMNS = 6;

    private const MAX_BLOCKS = 12;

    private const MAX_LINK_ROWS = 12;

    private const MAX_SHOWCASE_ROWS = 12;

    private const MAX_CARD_ROWS = 8;

    private const MAX_SHORTCUT_ROWS = 8;

    private const MAX_METRIC_ROWS = 6;

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

    /**
     * @return array<string, mixed>
     */
    public function defaultMenuSettings(): array
    {
        return [
            'theme' => 'default',
            'container_width' => 'xl',
            'accent_color' => '#16a34a',
            'panel_background' => '#ffffff',
            'open_on_hover' => true,
            'show_dividers' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultItemSettings(): array
    {
        return [
            'eyebrow' => '',
            'note' => '',
            'featured' => false,
            'highlight_color' => '',
            'dynamic_href_setting' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultColumnSettings(): array
    {
        return [
            'alignment' => 'start',
            'background_color' => '',
            'row' => 'main',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultBlockSettings(): array
    {
        return [
            'tone' => 'default',
            'show_border' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        $displayLocation = $this->cleanChoice(
            $input['display_location'] ?? MegaMenuOptions::LOCATION_HEADER,
            MegaMenuOptions::displayLocations(),
            MegaMenuOptions::LOCATION_HEADER
        );

        return [
            'title' => $this->cleanText($input['title'] ?? '', 160),
            'slug' => Str::slug((string) ($input['slug'] ?? '')),
            'status' => $this->cleanChoice(
                $input['status'] ?? MegaMenuOptions::STATUS_DRAFT,
                MegaMenuOptions::statuses(),
                MegaMenuOptions::STATUS_DRAFT
            ),
            'display_location' => $displayLocation,
            'custom_zone' => $displayLocation === MegaMenuOptions::LOCATION_CUSTOM
                ? $this->cleanIdentifier($input['custom_zone'] ?? '', 80)
                : null,
            'description' => $this->cleanText($input['description'] ?? '', 2000),
            'css_classes' => $this->cleanCssClasses($input['css_classes'] ?? ''),
            'ordering' => max(0, (int) ($input['ordering'] ?? 0)),
            'settings' => $this->sanitizeMenuSettings($input['settings'] ?? []),
            'items' => $this->sanitizeItems($input['items'] ?? [], 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function validateStructure(array $input, Validator $validator): void
    {
        $displayLocation = (string) ($input['display_location'] ?? '');
        $customZone = trim((string) ($input['custom_zone'] ?? ''));

        if ($displayLocation === MegaMenuOptions::LOCATION_CUSTOM && $customZone === '') {
            $validator->errors()->add('custom_zone', 'A custom zone is required when the display location is custom.');
        }

        if (! isset($input['items']) || ! is_array($input['items']) || count($input['items']) === 0) {
            $validator->errors()->add('items', 'At least one top-level item is required.');

            return;
        }

        if (count($input['items']) > self::MAX_TOP_LEVEL_ITEMS) {
            $validator->errors()->add('items', 'Too many top-level items were provided.');
        }

        $this->validateItems($input['items'], 'items', $validator, 0);
    }

    /**
     * @param  mixed  $items
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeItems($items, int $depth): array
    {
        if (! is_array($items)) {
            return [];
        }

        $max = $depth === 0 ? self::MAX_TOP_LEVEL_ITEMS : self::MAX_NESTED_ITEMS;
        $sanitized = [];

        foreach (array_slice(array_values($items), 0, $max) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $panelType = $this->cleanChoice(
                $item['panel_type'] ?? MegaMenuOptions::PANEL_LINK,
                MegaMenuOptions::panelTypes(),
                MegaMenuOptions::PANEL_LINK
            );
            $linkType = $this->cleanChoice(
                $item['link_type'] ?? MegaMenuOptions::LINK_NONE,
                MegaMenuOptions::linkTypes(),
                MegaMenuOptions::LINK_NONE
            );

            $sanitized[] = [
                'id' => $this->cleanExistingId($item['id'] ?? null),
                'label' => $this->cleanText($item['label'] ?? '', 160),
                'description' => $this->cleanText($item['description'] ?? '', 255),
                'link_type' => $linkType,
                'link_value' => $this->cleanLinkValue($linkType, $item['link_value'] ?? null),
                'link_target' => $this->cleanChoice(
                    $item['link_target'] ?? MegaMenuOptions::TARGET_SELF,
                    MegaMenuOptions::linkTargets(),
                    MegaMenuOptions::TARGET_SELF
                ),
                'panel_type' => $panelType,
                'icon' => $this->cleanText($item['icon'] ?? '', 120),
                'badge_text' => $this->cleanText($item['badge_text'] ?? '', 60),
                'badge_variant' => $this->cleanNullableChoice($item['badge_variant'] ?? null, MegaMenuOptions::badgeVariants()),
                'is_visible' => array_key_exists('is_visible', $item) ? (bool) $item['is_visible'] : true,
                'css_classes' => $this->cleanCssClasses($item['css_classes'] ?? ''),
                'settings' => $this->sanitizeItemSettings($item['settings'] ?? []),
                'sort_order' => $index,
                'children' => $panelType === MegaMenuOptions::PANEL_CLASSIC
                    ? $this->sanitizeItems($item['children'] ?? [], $depth + 1)
                    : [],
                'columns' => $panelType === MegaMenuOptions::PANEL_MEGA
                    ? $this->sanitizeColumns($item['columns'] ?? [])
                    : [],
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $columns
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeColumns($columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($columns), 0, self::MAX_COLUMNS) as $index => $column) {
            if (! is_array($column)) {
                continue;
            }

            $sanitized[] = [
                'id' => $this->cleanExistingId($column['id'] ?? null),
                'title' => $this->cleanText($column['title'] ?? '', 160),
                'width' => $this->cleanWidth($column['width'] ?? null),
                'css_classes' => $this->cleanCssClasses($column['css_classes'] ?? ''),
                'settings' => $this->sanitizeColumnSettings($column['settings'] ?? []),
                'sort_order' => $index,
                'blocks' => $this->sanitizeBlocks($column['blocks'] ?? []),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBlocks($blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($blocks), 0, self::MAX_BLOCKS) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $this->cleanText($block['type'] ?? '', 80);
            if (! MegaMenuBlockRegistry::exists($type)) {
                continue;
            }

            $sanitized[] = [
                'id' => $this->cleanExistingId($block['id'] ?? null),
                'type' => $type,
                'title' => $this->cleanText($block['title'] ?? '', 160),
                'css_classes' => $this->cleanCssClasses($block['css_classes'] ?? ''),
                'settings' => $this->sanitizeBlockSettings($block['settings'] ?? []),
                'payload' => $this->sanitizeBlockPayload($type, $block['payload'] ?? []),
                'sort_order' => $index,
            ];
        }

        return $sanitized;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function validateItems(array $items, string $path, Validator $validator, int $depth): void
    {
        $max = $depth === 0 ? self::MAX_TOP_LEVEL_ITEMS : self::MAX_NESTED_ITEMS;
        if (count($items) > $max) {
            $validator->errors()->add($path, 'Too many items were provided.');
        }

        foreach ($items as $index => $item) {
            $itemPath = $path.'.'.$index;
            if (! is_array($item)) {
                $validator->errors()->add($itemPath, 'Each menu item must be an object.');

                continue;
            }

            if (trim((string) ($item['label'] ?? '')) === '') {
                $validator->errors()->add($itemPath.'.label', 'The item label is required.');
            }

            $this->validateLinkReference(
                (string) ($item['link_type'] ?? MegaMenuOptions::LINK_NONE),
                $item['link_value'] ?? null,
                $itemPath.'.link_value',
                $validator
            );

            if (! in_array((string) ($item['link_target'] ?? MegaMenuOptions::TARGET_SELF), MegaMenuOptions::linkTargets(), true)) {
                $validator->errors()->add($itemPath.'.link_target', 'The link target is invalid.');
            }

            $panelType = (string) ($item['panel_type'] ?? MegaMenuOptions::PANEL_LINK);
            if (! in_array($panelType, MegaMenuOptions::panelTypes(), true)) {
                $validator->errors()->add($itemPath.'.panel_type', 'The panel type is invalid.');
            }

            if ($panelType === MegaMenuOptions::PANEL_CLASSIC) {
                $children = $item['children'] ?? [];
                if (! is_array($children) || count($children) === 0) {
                    $validator->errors()->add($itemPath.'.children', 'A classic dropdown needs at least one child item.');
                } else {
                    $this->validateItems($children, $itemPath.'.children', $validator, $depth + 1);
                }
            }

            if ($panelType === MegaMenuOptions::PANEL_MEGA) {
                $columns = $item['columns'] ?? [];
                if (! is_array($columns) || count($columns) === 0) {
                    $validator->errors()->add($itemPath.'.columns', 'A mega panel needs at least one column.');
                } else {
                    $this->validateColumns($columns, $itemPath.'.columns', $validator);
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $columns
     */
    private function validateColumns(array $columns, string $path, Validator $validator): void
    {
        if (count($columns) > self::MAX_COLUMNS) {
            $validator->errors()->add($path, 'Too many columns were provided.');
        }

        foreach ($columns as $index => $column) {
            $columnPath = $path.'.'.$index;
            if (! is_array($column)) {
                $validator->errors()->add($columnPath, 'Each column must be an object.');

                continue;
            }

            $width = trim((string) ($column['width'] ?? ''));
            if ($width !== '' && ! $this->isValidWidth($width)) {
                $validator->errors()->add($columnPath.'.width', 'Column width must use fr, %, or px values.');
            }

            $blocks = $column['blocks'] ?? [];
            if (! is_array($blocks) || count($blocks) === 0) {
                $validator->errors()->add($columnPath.'.blocks', 'Each column needs at least one block.');

                continue;
            }

            $this->validateBlocks($blocks, $columnPath.'.blocks', $validator);
        }
    }

    /**
     * @param  array<int, mixed>  $blocks
     */
    private function validateBlocks(array $blocks, string $path, Validator $validator): void
    {
        if (count($blocks) > self::MAX_BLOCKS) {
            $validator->errors()->add($path, 'Too many blocks were provided.');
        }

        foreach ($blocks as $index => $block) {
            $blockPath = $path.'.'.$index;
            if (! is_array($block)) {
                $validator->errors()->add($blockPath, 'Each block must be an object.');

                continue;
            }

            $type = trim((string) ($block['type'] ?? ''));
            if (! MegaMenuBlockRegistry::exists($type)) {
                $validator->errors()->add($blockPath.'.type', 'The block type is invalid.');

                continue;
            }

            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];

            if ($type === 'image' && trim((string) Arr::get($payload, 'image_url', '')) === '') {
                $validator->errors()->add($blockPath.'.payload.image_url', 'The image block requires an image URL.');
            }

            if ($type === 'navigation_group') {
                $links = $payload['links'] ?? [];
                if (! is_array($links) || count($links) === 0) {
                    $validator->errors()->add($blockPath.'.payload.links', 'The navigation group needs at least one link.');
                }
            }

            if ($type === 'product_showcase') {
                $items = $payload['items'] ?? [];
                if (! is_array($items) || count($items) === 0) {
                    $validator->errors()->add($blockPath.'.payload.items', 'The product showcase needs at least one product.');
                }
            }

            if ($type === 'quick_links') {
                $links = $payload['links'] ?? [];
                if (! is_array($links) || count($links) === 0) {
                    $validator->errors()->add($blockPath.'.payload.links', 'The quick links block needs at least one link.');
                }
            }

            if ($type === 'module_shortcut') {
                foreach (($payload['shortcuts'] ?? []) as $shortcutIndex => $shortcut) {
                    if (! is_array($shortcut)) {
                        continue;
                    }

                    $routeName = trim((string) ($shortcut['route_name'] ?? ''));
                    if ($routeName !== '' && ! Route::has($routeName)) {
                        $validator->errors()->add(
                            $blockPath.".payload.shortcuts.{$shortcutIndex}.route_name",
                            'The route shortcut reference is invalid.'
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  mixed  $value
     */
    private function cleanExistingId($value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @param  mixed  $value
     * @param  array<int, string>  $choices
     */
    private function cleanChoice($value, array $choices, string $fallback): string
    {
        $text = $this->cleanText($value, 120);

        return in_array($text, $choices, true) ? $text : $fallback;
    }

    /**
     * @param  mixed  $value
     * @param  array<int, string>  $choices
     */
    private function cleanNullableChoice($value, array $choices): ?string
    {
        $text = $this->cleanText($value, 120);
        if ($text === '') {
            return null;
        }

        return in_array($text, $choices, true) ? $text : null;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanText($value, int $maxLength = 255): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return Str::limit(trim(strip_tags((string) $value)), $maxLength, '');
    }

    /**
     * @param  mixed  $value
     */
    private function cleanIdentifier($value, int $maxLength = 80): string
    {
        $identifier = Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_\-]/', '-')
            ->trim('-')
            ->limit($maxLength, '');

        return (string) $identifier;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanCssClasses($value): string
    {
        $classes = preg_replace('/[^a-zA-Z0-9_\-\s:]/', '', (string) $value) ?: '';

        return trim(Str::limit($classes, 255, ''));
    }

    /**
     * @param  mixed  $value
     */
    private function cleanColor($value): string
    {
        $color = $this->cleanText($value, 12);

        if ($color === '') {
            return '';
        }

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1
            ? strtolower($color)
            : '';
    }

    /**
     * @param  mixed  $value
     */
    private function cleanWidth($value): string
    {
        $width = $this->cleanText($value, 20);

        return $this->isValidWidth($width) ? $width : '1fr';
    }

    private function isValidWidth(string $width): bool
    {
        if ($width === '') {
            return false;
        }

        return preg_match('/^\d+(\.\d+)?(fr|%|px)$/', $width) === 1;
    }

    /**
     * @param  mixed  $settings
     * @return array<string, mixed>
     */
    private function sanitizeMenuSettings($settings): array
    {
        $incoming = is_array($settings) ? $settings : [];
        $defaults = $this->defaultMenuSettings();

        return [
            'theme' => $this->cleanChoice($incoming['theme'] ?? $defaults['theme'], ['default', 'brand', 'contrast'], $defaults['theme']),
            'container_width' => $this->cleanChoice($incoming['container_width'] ?? $defaults['container_width'], ['lg', 'xl', '2xl', 'full'], $defaults['container_width']),
            'accent_color' => $this->cleanColor($incoming['accent_color'] ?? $defaults['accent_color']) ?: $defaults['accent_color'],
            'panel_background' => $this->cleanColor($incoming['panel_background'] ?? $defaults['panel_background']) ?: $defaults['panel_background'],
            'open_on_hover' => array_key_exists('open_on_hover', $incoming) ? (bool) $incoming['open_on_hover'] : (bool) $defaults['open_on_hover'],
            'show_dividers' => array_key_exists('show_dividers', $incoming) ? (bool) $incoming['show_dividers'] : (bool) $defaults['show_dividers'],
            'translations' => $this->sanitizeTranslationFields($incoming['translations'] ?? [], [
                'title' => 160,
                'description' => 2000,
            ]),
        ];
    }

    /**
     * @param  mixed  $settings
     * @return array<string, mixed>
     */
    private function sanitizeItemSettings($settings): array
    {
        $incoming = is_array($settings) ? $settings : [];
        $defaults = $this->defaultItemSettings();

        return [
            'eyebrow' => $this->cleanText($incoming['eyebrow'] ?? $defaults['eyebrow'], 80),
            'note' => $this->cleanText($incoming['note'] ?? $defaults['note'], 140),
            'featured' => array_key_exists('featured', $incoming) ? (bool) $incoming['featured'] : (bool) $defaults['featured'],
            'highlight_color' => $this->cleanColor($incoming['highlight_color'] ?? $defaults['highlight_color']),
            'dynamic_href_setting' => $this->cleanChoice(
                $incoming['dynamic_href_setting'] ?? $defaults['dynamic_href_setting'],
                ['', 'contact_form_url'],
                ''
            ),
            'translations' => $this->sanitizeTranslationFields($incoming['translations'] ?? [], [
                'label' => 160,
                'description' => 255,
                'badge_text' => 60,
                'eyebrow' => 80,
                'note' => 140,
            ]),
        ];
    }

    /**
     * @param  mixed  $settings
     * @return array<string, mixed>
     */
    private function sanitizeColumnSettings($settings): array
    {
        $incoming = is_array($settings) ? $settings : [];
        $defaults = $this->defaultColumnSettings();

        return [
            'alignment' => $this->cleanChoice($incoming['alignment'] ?? $defaults['alignment'], ['start', 'center', 'end'], $defaults['alignment']),
            'background_color' => $this->cleanColor($incoming['background_color'] ?? $defaults['background_color']),
            'row' => $this->cleanChoice($incoming['row'] ?? $defaults['row'], ['main', 'footer'], $defaults['row']),
            'translations' => $this->sanitizeTranslationFields($incoming['translations'] ?? [], [
                'title' => 160,
            ]),
        ];
    }

    /**
     * @param  mixed  $settings
     * @return array<string, mixed>
     */
    private function sanitizeBlockSettings($settings): array
    {
        $incoming = is_array($settings) ? $settings : [];
        $defaults = $this->defaultBlockSettings();

        return [
            'tone' => $this->cleanChoice($incoming['tone'] ?? $defaults['tone'], ['default', 'muted', 'contrast'], $defaults['tone']),
            'show_border' => array_key_exists('show_border', $incoming) ? (bool) $incoming['show_border'] : (bool) $defaults['show_border'],
            'translations' => $this->sanitizeTranslationFields($incoming['translations'] ?? [], [
                'title' => 160,
            ]),
        ];
    }

    /**
     * @param  mixed  $payload
     * @return array<string, mixed>
     */
    private function sanitizeBlockPayload(string $type, $payload, bool $allowTranslations = true): array
    {
        $incoming = is_array($payload) ? $payload : [];
        $translations = $allowTranslations
            ? $this->sanitizeLocalizedBlockPayloads($type, $incoming['translations'] ?? [])
            : [];

        unset($incoming['translations']);

        $default = MegaMenuBlockRegistry::defaultPayload($type);
        $payload = array_replace_recursive($default, $incoming);
        $sanitized = match ($type) {
            'navigation_group' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'description' => $this->cleanText($payload['description'] ?? '', 255),
                'links' => $this->sanitizeLinkRows($payload['links'] ?? []),
            ],
            'product_showcase' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'description' => $this->cleanText($payload['description'] ?? '', 255),
                'items' => $this->sanitizeShowcaseRows($payload['items'] ?? []),
            ],
            'category_list' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'description' => $this->cleanText($payload['description'] ?? '', 255),
                'categories' => $this->sanitizeCategoryRows($payload['categories'] ?? []),
            ],
            'quick_links' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'links' => $this->sanitizeQuickLinkRows($payload['links'] ?? []),
            ],
            'cards' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'cards' => $this->sanitizeCardRows($payload['cards'] ?? []),
            ],
            'featured_content' => [
                'eyebrow' => $this->cleanText($payload['eyebrow'] ?? '', 80),
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'body' => $this->cleanHtml($payload['body'] ?? ''),
                'cta_label' => $this->cleanText($payload['cta_label'] ?? '', 80),
                'cta_href' => $this->cleanHref($payload['cta_href'] ?? ''),
                'image_url' => $this->cleanMediaUrl($payload['image_url'] ?? ''),
                'image_alt' => $this->cleanText($payload['image_alt'] ?? '', 160),
                'image_title' => $this->cleanText($payload['image_title'] ?? '', 160),
            ],
            'image' => [
                'image_url' => $this->cleanMediaUrl($payload['image_url'] ?? ''),
                'image_alt' => $this->cleanText($payload['image_alt'] ?? '', 160),
                'image_title' => $this->cleanText($payload['image_title'] ?? '', 160),
                'caption' => $this->cleanText($payload['caption'] ?? '', 160),
                'href' => $this->cleanHref($payload['href'] ?? ''),
            ],
            'promo_banner' => [
                'badge' => $this->cleanText($payload['badge'] ?? '', 60),
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'body' => $this->cleanHtml($payload['body'] ?? ''),
                'cta_label' => $this->cleanText($payload['cta_label'] ?? '', 80),
                'cta_href' => $this->cleanHref($payload['cta_href'] ?? ''),
                'image_url' => $this->cleanMediaUrl($payload['image_url'] ?? ''),
                'image_alt' => $this->cleanText($payload['image_alt'] ?? '', 160),
                'image_title' => $this->cleanText($payload['image_title'] ?? '', 160),
            ],
            'cta' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'body' => $this->cleanHtml($payload['body'] ?? ''),
                'button_label' => $this->cleanText($payload['button_label'] ?? '', 80),
                'button_href' => $this->cleanHref($payload['button_href'] ?? ''),
            ],
            'text' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'body' => $this->cleanHtml($payload['body'] ?? ''),
            ],
            'html' => [
                'html' => $this->cleanHtml($payload['html'] ?? ''),
            ],
            'module_shortcut' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'shortcuts' => $this->sanitizeShortcutRows($payload['shortcuts'] ?? []),
            ],
            'demo_preview' => [
                'title' => $this->cleanText($payload['title'] ?? '', 160),
                'body' => $this->cleanHtml($payload['body'] ?? ''),
                'preview_image_url' => $this->cleanMediaUrl($payload['preview_image_url'] ?? ''),
                'preview_image_alt' => $this->cleanText($payload['preview_image_alt'] ?? '', 160),
                'preview_image_title' => $this->cleanText($payload['preview_image_title'] ?? '', 160),
                'metrics' => $this->sanitizeMetricRows($payload['metrics'] ?? []),
            ],
            default => $default,
        };

        if ($allowTranslations && $translations !== []) {
            $sanitized['translations'] = $translations;
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $translations
     * @param  array<string, int>  $fieldLengths
     * @return array<string, array<string, string>>
     */
    private function sanitizeTranslationFields($translations, array $fieldLengths): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $sanitized = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $values = $translations[$locale] ?? null;
            if (! is_array($values)) {
                continue;
            }

            $row = [];
            foreach ($fieldLengths as $field => $maxLength) {
                $value = $this->cleanText($values[$field] ?? '', $maxLength);
                if ($value !== '') {
                    $row[$field] = $value;
                }
            }

            if ($row !== []) {
                $sanitized[$locale] = $row;
            }
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $translations
     * @return array<string, array<string, mixed>>
     */
    private function sanitizeLocalizedBlockPayloads(string $type, $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $sanitized = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $payload = $translations[$locale] ?? null;
            if (! is_array($payload) || $payload === []) {
                continue;
            }

            $sanitized[$locale] = $this->sanitizeBlockPayload($type, $payload, false);
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeLinkRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_LINK_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'href' => $this->cleanHref($row['href'] ?? ''),
                'note' => $this->cleanText($row['note'] ?? '', 160),
                'badge' => $this->cleanText($row['badge'] ?? '', 60),
                'target' => $this->cleanChoice($row['target'] ?? MegaMenuOptions::TARGET_SELF, MegaMenuOptions::linkTargets(), MegaMenuOptions::TARGET_SELF),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeCategoryRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_LINK_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'href' => $this->cleanHref($row['href'] ?? ''),
                'meta' => $this->cleanText($row['meta'] ?? '', 120),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeShowcaseRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_SHOWCASE_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'href' => $this->cleanHref($row['href'] ?? ''),
                'note' => $this->cleanText($row['note'] ?? '', 160),
                'badge' => $this->cleanText($row['badge'] ?? '', 60),
                'summary' => $this->cleanText($row['summary'] ?? '', 320),
                'target' => $this->cleanChoice($row['target'] ?? MegaMenuOptions::TARGET_SELF, MegaMenuOptions::linkTargets(), MegaMenuOptions::TARGET_SELF),
                'image_url' => $this->cleanMediaUrl($row['image_url'] ?? ''),
                'image_alt' => $this->cleanText($row['image_alt'] ?? '', 160),
                'image_title' => $this->cleanText($row['image_title'] ?? '', 160),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeQuickLinkRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_LINK_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'href' => $this->cleanHref($row['href'] ?? ''),
                'target' => $this->cleanChoice($row['target'] ?? MegaMenuOptions::TARGET_SELF, MegaMenuOptions::linkTargets(), MegaMenuOptions::TARGET_SELF),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeCardRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_CARD_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'title' => $this->cleanText($row['title'] ?? '', 120),
                'body' => $this->cleanHtml($row['body'] ?? ''),
                'href' => $this->cleanHref($row['href'] ?? ''),
                'badge' => $this->cleanText($row['badge'] ?? '', 60),
                'image_url' => $this->cleanMediaUrl($row['image_url'] ?? ''),
                'image_alt' => $this->cleanText($row['image_alt'] ?? '', 160),
                'image_title' => $this->cleanText($row['image_title'] ?? '', 160),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeShortcutRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_SHORTCUT_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $routeName = $this->cleanText($row['route_name'] ?? '', 160);
            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'route_name' => Route::has($routeName) ? $routeName : '',
                'description' => $this->cleanText($row['description'] ?? '', 160),
                'icon' => $this->cleanText($row['icon'] ?? '', 120),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeMetricRows($rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice(array_values($rows), 0, self::MAX_METRIC_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $sanitized[] = [
                'label' => $this->cleanText($row['label'] ?? '', 120),
                'value' => $this->cleanText($row['value'] ?? '', 120),
            ];
        }

        return $sanitized;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanLinkValue(string $type, $value): ?string
    {
        return match ($type) {
            MegaMenuOptions::LINK_EXTERNAL_URL => $this->cleanExternalUrl($value),
            MegaMenuOptions::LINK_ROUTE => $this->cleanRouteName($value),
            MegaMenuOptions::LINK_INTERNAL_PAGE => $this->cleanInternalPagePath($value),
            MegaMenuOptions::LINK_ANCHOR => $this->cleanAnchor($value),
            default => null,
        };
    }

    /**
     * @param  mixed  $value
     */
    private function cleanRouteName($value): ?string
    {
        $routeName = $this->cleanText($value, 160);

        return $routeName !== '' && Route::has($routeName) ? $routeName : null;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanExternalUrl($value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanInternalPagePath($value): ?string
    {
        $path = trim((string) $value);
        if ($path === '') {
            return null;
        }

        return str_starts_with($path, '/') ? Str::limit($path, 255, '') : null;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanAnchor($value): ?string
    {
        $anchor = trim((string) $value);
        if ($anchor === '') {
            return null;
        }

        return preg_match('/^#[A-Za-z][A-Za-z0-9_\-:.]*$/', $anchor) === 1 ? $anchor : null;
    }

    /**
     * @param  mixed  $value
     */
    private function cleanHref($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '/') || str_starts_with($text, '#')) {
            return Str::limit($text, 255, '');
        }

        return $this->cleanExternalUrl($text) ?? '';
    }

    /**
     * @param  mixed  $value
     */
    private function cleanMediaUrl($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        if (str_starts_with($text, '/')) {
            return Str::limit($text, 255, '');
        }

        return $this->cleanExternalUrl($text) ?? '';
    }

    /**
     * @param  mixed  $value
     */
    private function cleanHtml($value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $html = trim((string) $value);
        if ($html === '') {
            return '';
        }

        $allowed = '<'.implode('><', self::ALLOWED_HTML_TAGS).'>';
        $html = strip_tags($html, $allowed);

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('div')->item(0);
        if (! $root) {
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
        if (! $node->hasChildNodes()) {
            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (! in_array($tag, self::ALLOWED_HTML_TAGS, true)) {
                    $text = $child->textContent ?? '';
                    $node->replaceChild($node->ownerDocument->createTextNode($text), $child);

                    continue;
                }

                $allowedAttributes = match ($tag) {
                    'a' => ['href', 'target', 'rel'],
                    'img' => ['src', 'alt', 'title', 'width', 'height'],
                    default => [],
                };

                if ($child->hasAttributes()) {
                    for ($i = $child->attributes->length - 1; $i >= 0; $i--) {
                        $attribute = $child->attributes->item($i);
                        if (! $attribute) {
                            continue;
                        }

                        if (! in_array(strtolower($attribute->name), $allowedAttributes, true)) {
                            $child->removeAttribute($attribute->name);
                        }
                    }
                }

                if ($tag === 'a') {
                    $href = $this->cleanHref($child->getAttribute('href'));
                    if ($href === '') {
                        $child->removeAttribute('href');
                    } else {
                        $child->setAttribute('href', $href);
                    }

                    if ($child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    }
                }

                if ($tag === 'img') {
                    $src = $this->cleanMediaUrl($child->getAttribute('src'));
                    if ($src === '') {
                        $node->removeChild($child);

                        continue;
                    }

                    $child->setAttribute('src', $src);
                }
            }

            $this->sanitizeHtmlNode($child);
        }
    }

    /**
     * @param  mixed  $value
     */
    private function cleanDateTime($value): ?string
    {
        $text = $this->cleanText($value, 80);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  mixed  $value
     */
    private function validateLinkReference(string $type, $value, string $path, Validator $validator): void
    {
        if ($type === MegaMenuOptions::LINK_NONE) {
            return;
        }

        $text = trim((string) $value);
        if ($text === '') {
            $validator->errors()->add($path, 'This link type requires a value.');

            return;
        }

        $isValid = match ($type) {
            MegaMenuOptions::LINK_EXTERNAL_URL => $this->cleanExternalUrl($text) !== null,
            MegaMenuOptions::LINK_ROUTE => $this->cleanRouteName($text) !== null,
            MegaMenuOptions::LINK_INTERNAL_PAGE => $this->cleanInternalPagePath($text) !== null,
            MegaMenuOptions::LINK_ANCHOR => $this->cleanAnchor($text) !== null,
            default => false,
        };

        if (! $isValid) {
            $validator->errors()->add($path, 'The selected link reference is invalid.');
        }
    }
}
