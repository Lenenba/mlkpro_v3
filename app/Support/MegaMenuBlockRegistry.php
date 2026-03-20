<?php

namespace App\Support;

class MegaMenuBlockRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'navigation_group' => [
                'label' => 'Navigation group',
                'description' => 'Grouped links with optional notes and badges.',
                'default_payload' => [
                    'title' => 'Explore',
                    'description' => '',
                    'links' => [
                        [
                            'label' => 'Overview',
                            'href' => '/',
                            'note' => '',
                            'badge' => '',
                            'target' => MegaMenuOptions::TARGET_SELF,
                        ],
                    ],
                ],
            ],
            'product_showcase' => [
                'label' => 'Product showcase',
                'description' => 'Interactive product list with a hover-driven preview image.',
                'default_payload' => [
                    'title' => 'Products & Services',
                    'description' => 'Hover a product to update the preview.',
                    'items' => [
                        [
                            'label' => 'Sales & CRM',
                            'href' => '/pricing#sales-crm',
                            'note' => 'Requests, quotes, customers, and pipelines.',
                            'badge' => 'Popular',
                            'summary' => 'Capture demand, qualify opportunities, and convert faster from one workspace.',
                            'target' => MegaMenuOptions::TARGET_SELF,
                            'image_url' => '',
                            'image_alt' => '',
                            'image_title' => '',
                        ],
                    ],
                ],
            ],
            'category_list' => [
                'label' => 'Category list',
                'description' => 'Compact category links with supporting copy.',
                'default_payload' => [
                    'title' => 'Categories',
                    'description' => '',
                    'categories' => [
                        [
                            'label' => 'Category',
                            'href' => '/',
                            'meta' => '',
                        ],
                    ],
                ],
            ],
            'quick_links' => [
                'label' => 'Quick links',
                'description' => 'Compact pill-style shortcuts usually displayed in a footer row.',
                'default_payload' => [
                    'title' => 'Popular shortcuts',
                    'links' => [
                        [
                            'label' => 'Popular module',
                            'href' => '/pricing',
                            'target' => MegaMenuOptions::TARGET_SELF,
                        ],
                    ],
                ],
            ],
            'cards' => [
                'label' => 'Cards',
                'description' => 'Feature cards with image, badge, and action link.',
                'default_payload' => [
                    'title' => 'Highlights',
                    'cards' => [
                        [
                            'title' => 'Card title',
                            'body' => 'Short supporting copy.',
                            'href' => '/',
                            'badge' => '',
                            'image_url' => '',
                            'image_alt' => '',
                            'image_title' => '',
                        ],
                    ],
                ],
            ],
            'featured_content' => [
                'label' => 'Featured content',
                'description' => 'Editorial block with image and CTA.',
                'default_payload' => [
                    'eyebrow' => 'Featured',
                    'title' => 'Feature spotlight',
                    'body' => 'Add a short summary for the featured destination.',
                    'cta_label' => 'Learn more',
                    'cta_href' => '/',
                    'image_url' => '',
                    'image_alt' => '',
                    'image_title' => '',
                ],
            ],
            'image' => [
                'label' => 'Image block',
                'description' => 'Standalone image with optional caption and link.',
                'default_payload' => [
                    'image_url' => '',
                    'image_alt' => '',
                    'image_title' => '',
                    'caption' => '',
                    'href' => '',
                ],
            ],
            'promo_banner' => [
                'label' => 'Promo banner',
                'description' => 'Promotional message with strong CTA.',
                'default_payload' => [
                    'badge' => 'Promo',
                    'title' => 'Limited time offer',
                    'body' => 'Highlight a launch, campaign, or seasonal promotion.',
                    'cta_label' => 'Shop now',
                    'cta_href' => '/',
                    'image_url' => '',
                    'image_alt' => '',
                    'image_title' => '',
                ],
            ],
            'cta' => [
                'label' => 'CTA button',
                'description' => 'Focused call-to-action block.',
                'default_payload' => [
                    'title' => 'Ready to start?',
                    'body' => 'Drive the user to the next key action.',
                    'button_label' => 'Get started',
                    'button_href' => '/',
                ],
            ],
            'text' => [
                'label' => 'Text content',
                'description' => 'Short formatted copy with heading.',
                'default_payload' => [
                    'title' => 'Text block',
                    'body' => '<p>Use this block for a concise explanation.</p>',
                ],
            ],
            'html' => [
                'label' => 'HTML / rich text',
                'description' => 'Sanitized HTML for richer editorial layouts.',
                'default_payload' => [
                    'html' => '<div><strong>Custom HTML block</strong><p>Rich text content goes here.</p></div>',
                ],
            ],
            'module_shortcut' => [
                'label' => 'Module shortcut',
                'description' => 'Quick links to internal routes or modules.',
                'default_payload' => [
                    'title' => 'Shortcuts',
                    'shortcuts' => [
                        [
                            'label' => 'Dashboard',
                            'route_name' => 'dashboard',
                            'description' => 'Jump directly to the dashboard.',
                            'icon' => 'layout-dashboard',
                        ],
                    ],
                ],
            ],
            'demo_preview' => [
                'label' => 'Demo / preview block',
                'description' => 'Mini proof or product demo teaser.',
                'default_payload' => [
                    'title' => 'Live preview',
                    'body' => 'Show a small preview of a workflow or module.',
                    'preview_image_url' => '',
                    'preview_image_alt' => '',
                    'preview_image_title' => '',
                    'metrics' => [
                        [
                            'label' => 'Speed',
                            'value' => '24h',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPayload(string $type): array
    {
        return self::definitions()[$type]['default_payload'] ?? [];
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::definitions());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function builderDefinitions(): array
    {
        return collect(self::definitions())
            ->map(fn (array $definition, string $type) => [
                'type' => $type,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'default_payload' => $definition['default_payload'],
            ])
            ->values()
            ->all();
    }
}
